#!/usr/bin/env python3
"""Local-only XLSX to UTF-8 CSV. Standard library, no formulas or network."""
import argparse, csv, io, os, re, tempfile, zipfile
from pathlib import Path
from xml.etree import ElementTree as ET
NS='{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
REL='{http://schemas.openxmlformats.org/package/2006/relationships}'
ID='{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id'
MAX_XML=32*1024*1024
MAX_FILE=32*1024*1024
MAX_ROWS=100000

def part(z,name,limit=MAX_XML,optional=False):
    try: info=z.getinfo(name)
    except KeyError:
        if optional: return None
        raise ValueError('Bagian XLSX tidak ditemukan: '+name)
    if info.file_size>limit: raise ValueError('XML terlalu besar, pecah workbook.')
    with z.open(info) as f: data=f.read(limit+1)
    if len(data)!=info.file_size or len(data)>limit: raise ValueError('Ukuran XML tidak cocok.')
    if b'\0' in data or re.search(rb'<!\s*(DOCTYPE|ENTITY)\b',data,re.I): raise ValueError('XML tidak aman.')
    data.decode('utf-8-sig')
    return data

def text(v):
    if len(v.encode('utf-8'))>32768: raise ValueError('Sel terlalu panjang.')
    return v

def group(el):
    return text(''.join(t.text or '' for t in list(el.findall(NS+'t'))+[t for r in el.findall(NS+'r') for t in r.findall(NS+'t')]))

def cell(el,strings):
    typ=el.get('t','n'); v=el.findtext(NS+'v',default='')
    if el.find(NS+'f') is not None or typ in ('e','str'): raise ValueError('Formula/sel error: salin sebagai nilai dahulu.')
    if typ=='s':
        if not re.fullmatch(r'\d{1,9}',v) or int(v)>=len(strings): raise ValueError('Indeks shared string tidak valid.')
        v=strings[int(v)]
    elif typ=='inlineStr':
        item=el.find(NS+'is');v=group(item) if item is not None else ''
    elif typ not in ('n','b','d'): raise ValueError('Tipe sel tidak didukung.')
    if re.match(r'^[\s\ufeff]*[=+@-]',v) and not re.fullmatch(r'-?\d+(?:\.\d+)?',v): raise ValueError('Teks berawalan karakter formula. Bersihkan sel.')
    return text(v)

def row(el,strings):
    vals={};last=-1
    for c in el.findall(NS+'c'):
        m=re.fullmatch(r'([A-Z]{1,3})([1-9][0-9]{0,6})',c.get('r',''))
        if not m: raise ValueError('Referensi sel tidak valid.')
        col=0
        for ch in m[1]: col=col*26+ord(ch)-64
        col-=1
        if col<=last or col>=150 or int(m[2])>1048576 or (el.get('r') and int(m[2])!=int(el.get('r'))): raise ValueError('Urutan atau batas sel tidak valid.')
        last=col;vals[col]=cell(c,strings)
    return vals

def convert(src,dst,sheet):
    if src.suffix.lower()!='.xlsx' or src.stat().st_size>MAX_FILE or not 1<=sheet<=100: raise ValueError('XLSX maksimal 32 MiB, sheet 1–100.')
    if dst.suffix.lower()!='.csv' or dst.exists(): raise ValueError('Tujuan harus CSV baru (tidak menimpa file).')
    with zipfile.ZipFile(src) as z:
        names=z.namelist()
        if len(names)>512 or len(set(names))!=len(names) or any('..' in n.split('/') or '\\' in n for n in names) or 'xl/vbaProject.bin' in names: raise ValueError('ZIP tidak aman atau bermakro.')
        wb=ET.fromstring(part(z,'xl/workbook.xml',262144)); sheets=wb.findall(NS+'sheets/'+NS+'sheet')
        if sheet>len(sheets): raise ValueError('Sheet tidak ditemukan.')
        ident=sheets[sheet-1].get(ID)
        rels=ET.fromstring(part(z,'xl/_rels/workbook.xml.rels',262144)); matches=[r for r in rels.findall(REL+'Relationship') if r.get('Id')==ident]
        if len(matches)!=1: raise ValueError('Relasi sheet tidak valid.')
        r=matches[0]; target=r.get('Target','')
        if r.get('TargetMode')=='External' or r.get('Type')!='http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet' or not re.fullmatch(r'(?:/xl/)?worksheets/[A-Za-z0-9_.-]+\.xml',target): raise ValueError('Relasi sheet tidak aman.')
        target=target[1:] if target.startswith('/') else 'xl/'+target
        shared=part(z,'xl/sharedStrings.xml',optional=True); strings=[]
        if shared is not None:
            for _,el in ET.iterparse(io.BytesIO(shared),events=('end',)):
                if el.tag==NS+'si':
                    if len(strings)>=300000: raise ValueError('Terlalu banyak shared strings.')
                    strings.append(group(el));el.clear()
        xml=part(z,target); header=None; seen=0; scanned=0; tmp=None
        try:
            with tempfile.NamedTemporaryFile(mode='w',encoding='utf-8',newline='',dir=dst.parent,delete=False) as f:
                tmp=Path(f.name);writer=csv.writer(f)
                for _,el in ET.iterparse(io.BytesIO(xml),events=('end',)):
                    if el.tag!=NS+'row': continue
                    scanned+=1
                    if scanned>MAX_ROWS+1000: raise ValueError('Terlalu banyak baris terformat.')
                    vals=row(el,strings);el.clear()
                    if not any(v.strip() for v in vals.values()): continue
                    if header is None:
                        width=max(i for i,v in vals.items() if v.strip())+1
                        header=[re.sub(r'[^a-z0-9]+','_',vals.get(i,'').strip().lower()).strip('_') for i in range(width)]
                        if '' in header or len(set(header))!=len(header) or not set(header)&{'npsn','nomor_pokok_sekolah_nasional','nomor_pokok_satuan_pendidikan_nasional','npsn_sekolah','npsn_madrasah'}: raise ValueError('Header kosong/duplikat atau NPSN tidak ditemukan.')
                        writer.writerow(header);continue
                    if any(i>=len(header) and v.strip() for i,v in vals.items()): raise ValueError('Nilai di luar header.')
                    seen+=1
                    if seen>MAX_ROWS: raise ValueError('Batas 100.000 rekaman. Pecah workbook.')
                    writer.writerow([vals.get(i,'') for i in range(len(header))])
                if not seen: raise ValueError('Tidak ada data.')
            if dst.exists(): raise ValueError('Tujuan sudah ada.')
            os.replace(tmp,dst);tmp=None
        finally:
            if tmp is not None: tmp.unlink(missing_ok=True)
    return seen

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('xlsx',type=Path);p.add_argument('csv',type=Path);p.add_argument('--sheet',type=int,default=1);p.add_argument('--authorized',action='store_true');a=p.parse_args()
    if not a.authorized:p.error('Konfirmasi izin dengan --authorized.')
    try: print(f'{convert(a.xlsx,a.csv,a.sheet)} rekaman; CSV: {a.csv}')
    except (ValueError,OSError,zipfile.BadZipFile,ET.ParseError,RuntimeError) as e:p.exit(1,f'Gagal: {e}\n')
if __name__=='__main__':main()
