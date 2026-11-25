#!/usr/bin/env python3
"""Prepare a bounded school pilot. Offline; never writes WordPress or merges sources into one CSV."""
import argparse,csv,hashlib,io,json,sys
from pathlib import Path
import validate_source as v

def prepare(config, base, limit=100):
    if not 1<=limit<=100: raise ValueError('Pilot 1–100 sekolah.')
    if not isinstance(config,dict) or set(config)-{'identity','statistics','accreditation','provinsi','kabupaten'}: raise ValueError('Konfigurasi tidak dikenal.')
    region=[config.get(k,'').strip() for k in ('provinsi','kabupaten')]
    if not all(region): raise ValueError('Provinsi dan kabupaten/kota wajib; nama persis sesuai dataset.')
    batches={}; reports={}
    for kind in v.FIELDS:
        if kind not in config: continue
        c=config[kind]
        if set(c)-{'file','source','edition','rights','delimiter','canonical_form'}: raise ValueError('Konfigurasi sumber tidak dikenal.')
        r,rows=v.validate(base/c['file'],kind,c['source'],c['edition'],c['rights'],c.get('delimiter',','),c.get('canonical_form',False))
        if r['status']!='format_pass': raise ValueError(kind+' gagal validasi: '+str(r['errors_first_30']))
        reports[kind]=r; batches[kind]=rows
    if 'identity' not in batches: raise ValueError('Identitas wajib.')
    selected=[r for r in batches['identity'] if [r[k].casefold() for k in ('provinsi','kabupaten')]==[k.casefold() for k in region]]
    if not selected: raise ValueError('Tidak ada sekolah cocok wilayah; tidak menebak nama.')
    selected=sorted(selected,key=lambda r:r['npsn'])[:limit]
    ids={r['npsn'] for r in selected}; output={}; coverage={}
    for kind,rows in batches.items():
        matched=[r for r in rows if r['npsn'] in ids]
        matched.sort(key=lambda r:r['npsn'])
        coverage[kind]={'input_rows':len(rows),'matched':len(matched),'outside_pilot':len(rows)-len(matched),'missing_pilot':len(ids)-len(matched)}
        if not matched: continue
        b=io.StringIO(newline='');w=csv.DictWriter(b,fieldnames=v.FIELDS[kind],lineterminator='\n');w.writeheader();w.writerows(matched);raw=b.getvalue().encode('utf-8')
        if len(raw)>v.LIMIT: raise ValueError('Output terlalu besar.')
        output[kind+'.csv']=raw;reports[kind]['sha256_pilot']=hashlib.sha256(raw).hexdigest()
    return {'status':'prepared_not_imported','region':dict(zip(('provinsi','kabupaten'),region)),'schools':len(ids),'coverage':coverage,'sources':reports,'note':'Cocok NPSN saja; periode, fakta dan hak publikasi wajib diperiksa. Pelengkap terpisah; tidak ada data kosong diisi tebakan.'},output

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('config',type=Path);p.add_argument('output',type=Path);p.add_argument('--limit',type=int,default=100);a=p.parse_args()
    try:
        with a.config.open('rb') as f: raw=f.read(65537)
        if len(raw)>65536:raise ValueError('Konfigurasi terlalu besar.')
        r,out=prepare(json.loads(raw),a.config.parent,a.limit)
        a.output.mkdir() # no overwrite
        for name,data in out.items(): (a.output/name).write_bytes(data)
        (a.output/'manifest.json').write_text(json.dumps(r,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
        print(json.dumps(r,ensure_ascii=False,indent=2));return 0
    except (OSError,ValueError,KeyError,TypeError,AttributeError,csv.Error) as e:
        print('Gagal: '+str(e),file=sys.stderr);return 2
if __name__=='__main__':sys.exit(main())
