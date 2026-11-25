#!/usr/bin/env python3
"""Local preflight for institution-level CSV. No network or WordPress writes."""
import argparse, csv, hashlib, io, json, re, sys
from pathlib import Path
from urllib.parse import urlsplit
from datetime import date

LIMIT=2*1024*1024
MAX_ROWS=1000
ALIASES={
 'npsn':('npsn','nomor_pokok_sekolah_nasional','npsn_sekolah','npsn_madrasah'),
 'nama':('nama','nama_satuan_pendidikan','nama_sekolah','nama_madrasah'),
 'jenjang':('bentuk','bentuk_pendidikan','bentuk_sekolah','jenis_madrasah'),
 'status':('status','status_sekolah','status_satuan_pendidikan'),
 'naungan':('naungan','pembina','kementerian_pembina'),
 'provinsi':('provinsi','propinsi','nama_provinsi'),
 'kabupaten':('kabupaten','kota_kab','kab_kota','kabupaten_kota','nama_kabupaten_kota'),
 'kecamatan':('kecamatan','nama_kecamatan'), 'desa':('desa','kelurahan','desa_kelurahan'),
 'alamat':('alamat','alamat_sekolah'),
 'jumlah_siswa':('jumlah_siswa','jumlah_peserta_didik','jumlah_pd','total_siswa','peserta_didik'),
 'jumlah_guru':('jumlah_guru','total_guru','guru'), 'jumlah_tendik':('jumlah_tendik','tenaga_kependidikan'),
 'jumlah_rombel':('jumlah_rombel','rombel','jumlah_rombongan_belajar'),
 'akreditasi':('akreditasi','peringkat_akreditasi','status_akreditasi'),
 'tahun_akreditasi':('tahun_akreditasi','tahun_penilaian'),
 'nomor_sk_akreditasi':('nomor_sk_akreditasi','nomor_sk','no_sk'),
 'tahun_data':('tahun_data','periode','semester'),
}
FIELDS={'identity':('npsn','nama','jenjang','status','naungan','provinsi','kabupaten','kecamatan','desa','alamat'),
 'statistics':('npsn','tahun_data','jumlah_siswa','jumlah_guru','jumlah_tendik','jumlah_rombel'),
 'accreditation':('npsn','akreditasi','tahun_akreditasi','nomor_sk_akreditasi')}
REQUIRED={'identity':('npsn','nama','jenjang','status','provinsi','kabupaten','kecamatan'),
 'statistics':('npsn','tahun_data'), 'accreditation':('npsn','akreditasi','tahun_akreditasi','nomor_sk_akreditasi')}
LEVELS=set('PAUD TK RA KB TPA SPS SD MI SMP MTS SMA MA SMK MAK SLB PKBM SKB PESANTREN LAINNYA'.split())
MAX={'nama':191,'jenjang':32,'status':24,'provinsi':120,'kabupaten':120,'kecamatan':120,'akreditasi':32}
MISSING={'','-','null','n/a','tidak tersedia'}
def key(s):return re.sub(r'[^a-z0-9]+','_',s.strip().lower()).strip('_')
def validate(path, kind, source, edition, rights, delimiter=',', canonical_form=False):
    if not rights or not rights.strip():raise ValueError('Catat dasar izin penggunaan/publikasi; bukan bukti verifikasi otomatis.')
    url=urlsplit(source)
    if url.scheme!='https' or not url.hostname or url.username or url.password or url.query or url.fragment or url.port not in (None,443):raise ValueError('URL sumber HTTPS tanpa kredensial, query atau fragment diperlukan.')
    if not edition.strip() or len(edition)>120 or len(rights)>500:raise ValueError('Edisi atau dasar izin tidak valid.')
    with Path(path).open('rb') as f: raw=f.read(LIMIT+1)
    if len(raw)>LIMIT:raise ValueError('CSV melebihi 2 MiB; pecah dataset secara lokal.')
    reader=csv.reader(io.StringIO(raw.decode('utf-8-sig'),newline=''),delimiter=delimiter,strict=True)
    try: header=next(reader)
    except StopIteration: raise ValueError('CSV kosong')
    keys=[key(h) for h in header]
    if len(keys)>128 or not all(keys) or len(set(keys))!=len(keys):raise ValueError('Header kosong, duplikat atau melebihi 128 kolom.')
    mapping={}
    for field in FIELDS[kind]:
        aliases=ALIASES[field]
        if field=='jenjang' and canonical_form:aliases=aliases+('jenjang',)
        hits=[i for i,k in enumerate(keys) if k in aliases]
        if len(hits)>1:raise ValueError('Header ambigu: '+field)
        if hits:mapping[field]=hits[0]
    if any(k not in mapping for k in REQUIRED[kind]):raise ValueError('Kolom wajib tidak tersedia: '+', '.join(k for k in REQUIRED[kind] if k not in mapping))
    if kind=='statistics' and not any(k.startswith('jumlah_') for k in mapping):raise ValueError('Tidak ada statistik per sekolah yang dikenali.')
    errors=[]; output=[]; seen=set(); count=0
    for row in reader:
        count+=1
        if count>MAX_ROWS:raise ValueError('Maksimal 1.000 rekaman per CSV.')
        if len(row)!=len(header):errors.append([count,'jumlah kolom tidak sesuai']);continue
        d={k:row[i].strip() for k,i in mapping.items()};d={k:v for k,v in d.items() if v.lower() not in MISSING}
        reason=[]
        if any(not d.get(k) for k in REQUIRED[kind]):reason.append('atribut wajib kosong')
        n=d.get('npsn','').upper();d['npsn']=n
        if not re.fullmatch(r'[A-Z0-9]{8}',n) or n.startswith(('TEST','DEMO','SIMU')):reason.append('NPSN tidak valid atau uji')
        if n in seen:reason.append('NPSN duplikat')
        seen.add(n)
        for k,v in d.items():
            if v.startswith(('=','+','-','@')) or any(ord(c)<32 for c in v):reason.append('formula atau karakter kontrol');break
            if len(v.encode('utf-8'))>MAX.get(k,1000):reason.append('nilai terlalu panjang');break
        if kind=='identity':
            if d.get('jenjang','').upper() not in LEVELS:reason.append('bentuk pendidikan belum didukung; perlu pemetaan manual')
            if d.get('status','').upper() not in {'NEGERI','SWASTA'}:reason.append('status sekolah belum dikenali')
            if re.search(r'\b(fiktif|dummy|simulasi)\b',d.get('nama',''),re.I):reason.append('nama data uji')
        elif kind=='statistics':
            nums=[v for k,v in d.items() if k.startswith('jumlah_')]
            if not nums or any(not re.fullmatch(r'[0-9]{1,9}',v) for v in nums):reason.append('statistik kosong atau bukan bilangan bulat nonnegatif')
            if d.get('tahun_data')!=edition:reason.append('periode berbeda dari edisi yang dikonfirmasi')
        else:
            if d.get('akreditasi','').upper() not in {'A','B','C','D','TERAKREDITASI','TIDAK TERAKREDITASI','TIDAK TERAKREDITASI SEMENTARA'}:reason.append('status akreditasi belum dikenali; perlu pemeriksaan manual')
            y=d.get('tahun_akreditasi','')
            if not re.fullmatch(r'[0-9]{4}',y) or not 1900<=int(y)<=date.today().year:reason.append('tahun akreditasi tidak valid')
        if reason:errors.append([count,'; '.join(reason)])
        else:output.append(d)
    if not count:raise ValueError('Tidak ada rekaman.')
    report={'status':'rejected' if errors else 'format_pass','kind':kind,'source_url':source,'edition':edition,'rights_assertion':rights,'sha256_input':hashlib.sha256(raw).hexdigest(),'rows':count,'valid_rows':len(output),'error_count':len(errors),'errors_first_30':errors[:30],'mapping':{k:header[i] for k,i in mapping.items()},'ignored_columns':len(header)-len(mapping),'note':'Format saja; fakta, izin, NPSN aktual, dan konsistensi lintas file belum diverifikasi.'}
    return report,output

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('input',type=Path);p.add_argument('--kind',required=True,choices=FIELDS);p.add_argument('--source',required=True);p.add_argument('--edition',required=True);p.add_argument('--rights',required=True);p.add_argument('--delimiter',choices=[',',';','\t'],default=',');p.add_argument('--canonical-form',action='store_true',help='Konfirmasi bahwa kolom jenjang berisi bentuk spesifik seperti SD/MI, bukan jenjang umum.');p.add_argument('--output',type=Path,help='CSV baru hanya jika semua baris lulus; tanpa opsi ini audit saja.');a=p.parse_args()
    try:
        report,rows=validate(a.input,a.kind,a.source,a.edition,a.rights,a.delimiter,a.canonical_form)
        if report['status']=='format_pass' and a.output:
            # Build bounded output first, so a rejected input cannot leave a partial CSV.
            b=io.StringIO(newline='');w=csv.DictWriter(b,fieldnames=FIELDS[a.kind],lineterminator='\n');w.writeheader();w.writerows(rows);payload=b.getvalue().encode('utf-8')
            if len(payload)>LIMIT:raise ValueError('CSV keluaran melebihi batas 2 MiB.')
            with a.output.open('xb') as f:f.write(payload)
            report['sha256_output']=hashlib.sha256(payload).hexdigest()
        print(json.dumps(report,ensure_ascii=False,indent=2));return 0 if report['status']=='format_pass' else 2
    except (OSError,ValueError,csv.Error) as e:
        print(json.dumps({'status':'error','message':str(e)},ensure_ascii=False),file=sys.stderr);return 2
if __name__=='__main__':sys.exit(main())
