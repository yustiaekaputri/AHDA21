#!/usr/bin/env python3
"""Local evidence checklist. A PASS is an operator attestation, not automated certification."""
import argparse, hashlib, json, pathlib, sys
GATES={
 'local_install':'WordPress + MySQL/MariaDB + plugin RC4 + tema v7.0 pada clone lokal',
 'smoke':'Smoke test RC6 selesai, termasuk pembersihan fixture',
 'real_data':'Minimal 100 NPSN nyata, sumber/izin dan periode diverifikasi; impor serta pembaruan berhasil',
 'integrity':'Audit kedua arah seluruh pilot selesai; tidak ada konflik identitas belum ditangani',
 'frontend':'7 shortcode, profil, filter wilayah, mobile, galeri dan perbandingan diuji di browser',
 'operator':'Dua akun operator; klaim, usulan, moderasi, pencabutan, akses silang ditolak',
 'automation':'Scheduler, batas jaringan, safe mode dan penghentian antrean diuji dengan sumber berizin',
 'seo':'Canonical, sitemap, robots dan tidak ada data fiktif publik; indexing hanya setelah disetujui',
 'backup':'Backup database dan file dipulihkan pada clone terpisah dan diperiksa',
 'performance':'Benchmark lokal dan pengukuran CPU/RAM/latensi VPS representatif ditinjau',
 'vps':'Staging VPS HTTPS, Nginx/PHP-FPM/MariaDB, privasi cache, log dan smoke test',
 'security':'Tinjauan keamanan, hak akses, jaringan keluar, serta temuan kritis ditutup',
}
def template():
    return {'candidate':'RC6 / plugin 10.0.0-rc.4','gates':{k:{'description':v,'status':'pending','evidence':'','sha256':'','reviewer':'','notes':''} for k,v in GATES.items()}}
def review(data,root):
    if not isinstance(data,dict) or data.get('candidate')!='RC6 / plugin 10.0.0-rc.4' or not isinstance(data.get('gates'),dict) or set(data['gates'])!=set(GATES):raise ValueError('Kandidat atau daftar gate tidak cocok')
    root=root.resolve();out={}
    for k in GATES:
        row=data['gates'][k]
        if not isinstance(row,dict) or row.get('status') not in ('pending','fail','pass'):raise ValueError(f'{k}: status tidak valid')
        if row['status']!='pass':out[k]=row['status'];continue
        if not all(isinstance(row.get(f),str) and row[f].strip() for f in ('evidence','sha256','reviewer','notes')):out[k]='missing_evidence';continue
        try:
            p=(root/row['evidence']).resolve();p.relative_to(root)
            if not p.is_file() or not p.stat().st_size or p.stat().st_size>20*1024*1024:raise ValueError('invalid evidence file')
            h=hashlib.sha256()
            with p.open('rb') as f:
                for chunk in iter(lambda:f.read(65536),b''):h.update(chunk)
            actual=h.hexdigest()
            out[k]='documented_pass' if actual==row['sha256'].lower() else 'hash_mismatch'
        except (OSError,ValueError):out[k]='invalid_evidence'
    return {'gate_results':out,'all_documented':all(v=='documented_pass' for v in out.values()),'notice':'Status pass merupakan pernyataan pemeriksa, bukan verifikasi isi bukti atau sertifikasi produksi. Keputusan rilis tetap manual.'}
def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('command',choices=['template','review']);p.add_argument('file',type=pathlib.Path);a=p.parse_args()
    if a.command=='template':
        with a.file.open('x',encoding='utf-8') as f:json.dump(template(),f,indent=2,ensure_ascii=False);f.write('\n')
        return 0
    with a.file.open(encoding='utf-8') as f:r=review(json.load(f),a.file.parent)
    print(json.dumps(r,indent=2,ensure_ascii=False));return 0 if r['all_documented'] else 2
if __name__=='__main__':
    try:sys.exit(main())
    except (OSError,ValueError) as e:sys.exit(str(e))
