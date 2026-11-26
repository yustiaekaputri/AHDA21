-- Run only on a disposable database. The tables use the WordPress column types
-- involved in the directory join, with utf8mb4 and InnoDB.
CREATE TABLE sdp_test_index_posts (
    ID bigint unsigned NOT NULL PRIMARY KEY,
    post_type varchar(20) NOT NULL,
    post_status varchar(20) NOT NULL,
    post_password varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sdp_test_index_schools (
    npsn varchar(8) NOT NULL PRIMARY KEY,
    post_id bigint unsigned NOT NULL,
    status varchar(24) NOT NULL,
    provinsi varchar(120) NOT NULL,
    KEY post_id (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO sdp_test_index_posts VALUES
    (1,'sdp_school','publish',''), (2,'sdp_school','publish',''),
    (3,'sdp_school','private',''), (4,'sdp_school','publish','fixture'),
    (5,'sdp_school','draft',''), (6,'page','publish','');
INSERT INTO sdp_test_index_schools VALUES
    ('TEST0001',1,'Negeri','Jawa Barat'), ('TEST0002',2,'Swasta','Jawa Timur'),
    ('TEST0003',3,'Swasta','Privat'), ('TEST0004',4,'Negeri','Sandi'),
    ('TEST0005',5,'Negeri','Draf'), ('TEST0006',6,'Negeri','Bukan profil'),
    ('TEST0007',7,'Negeri','Tanpa artikel');

ALTER TABLE sdp_test_index_posts
    ADD INDEX sdp_test_public (post_type, post_status, post_password, ID),
    ALGORITHM=INPLACE, LOCK=NONE;

SELECT 'ringkasan', COUNT(*), SUM(s.status='Negeri'), SUM(s.status='Swasta')
FROM sdp_test_index_schools s JOIN sdp_test_index_posts p ON p.ID=s.post_id
WHERE p.post_type='sdp_school' AND p.post_status='publish' AND p.post_password='';

CREATE VIEW sdp_test_index_visible AS
SELECT s.provinsi, s.status
FROM sdp_test_index_posts p FORCE INDEX (sdp_test_public)
STRAIGHT_JOIN sdp_test_index_schools s ON s.post_id=p.ID
WHERE p.post_type='sdp_school' AND p.post_status='publish' AND p.post_password='';

SELECT 'ringkasan', COUNT(*), SUM(status='Negeri'), SUM(status='Swasta')
FROM sdp_test_index_visible;
SELECT DISTINCT 'provinsi', provinsi FROM sdp_test_index_visible WHERE provinsi<>'' ORDER BY provinsi LIMIT 1000;
SELECT 'provinsi kandidat',
    (SELECT s.provinsi FROM sdp_test_index_schools s JOIN sdp_test_index_posts p ON p.ID=s.post_id
     WHERE p.post_type='sdp_school' AND p.post_status='publish' AND p.post_password=''
       AND s.provinsi=facet.candidate LIMIT 1) AS value
FROM (SELECT DISTINCT provinsi AS candidate FROM sdp_test_index_schools WHERE provinsi<>'') facet
HAVING value IS NOT NULL ORDER BY value LIMIT 1000;
UPDATE sdp_test_index_posts SET post_status='private' WHERE ID=1;
UPDATE sdp_test_index_posts SET post_password='fixture' WHERE ID=2;
SELECT 'terlihat setelah perubahan', COUNT(*) FROM sdp_test_index_visible;
UPDATE sdp_test_index_posts SET post_password='' WHERE ID=2;
DELETE FROM sdp_test_index_posts WHERE ID=2;
SELECT 'terlihat setelah penghapusan', COUNT(*) FROM sdp_test_index_visible;

DROP VIEW sdp_test_index_visible;
ALTER TABLE sdp_test_index_posts DROP INDEX sdp_test_public, ALGORITHM=INPLACE, LOCK=NONE;
SELECT 'indeks tersisa', COUNT(*) FROM information_schema.statistics
WHERE table_schema=DATABASE() AND table_name='sdp_test_index_posts' AND index_name='sdp_test_public';
DROP TABLE sdp_test_index_schools, sdp_test_index_posts;
