#!/usr/bin/env python3
"""Offline fixture generator and bounded HTTP benchmark, standard library only."""
import argparse, csv, concurrent.futures, http.client, ipaddress, json, math, re, time
from urllib.parse import urlsplit

CAP=2*1024*1024

def code(n):
    chars='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; out=''
    for _ in range(4): n,r=divmod(n,36); out=chars[r]+out
    if n: raise ValueError('NPSN overflow')
    return 'TEST'+out

def generate(path, count):
    if not 1<=count<=500000: raise ValueError('rows: 1..500000')
    start=time.monotonic()
    with open(path,'x',encoding='utf-8',newline='') as f:
        w=csv.writer(f); w.writerow(['npsn','nama','jenjang','status','akreditasi','provinsi','kabupaten','kecamatan','jumlah_siswa','jumlah_guru','sumber'])
        for i in range(count):
            w.writerow([code(i),f'Sekolah Fiktif {i:06d}',('SD','MI','SMP','MTs','SMA','MA','SMK')[i%7],('Negeri','Swasta')[i%2],('A','B','C','')[i%4],f'Provinsi Fiktif {i%38:02d}',f'Kabupaten Fiktif {(i//38)%14:02d}',f'Kecamatan Fiktif {(i//532)%15:02d}',100+i%900,10+i%50,'Dataset simulasi - bukan data resmi'])
    return {'rows':count,'seconds':round(time.monotonic()-start,3),'warning':'Fiktif. Hanya untuk database lokal disposable.'}

def target(url,host=None):
    u=urlsplit(url)
    if u.scheme!='http' or u.username or u.password or u.fragment or not u.hostname: raise ValueError('Hanya HTTP loopback tanpa kredensial/fragment')
    try: ip=ipaddress.ip_address(u.hostname)
    except ValueError: raise ValueError('Gunakan literal 127.0.0.1 atau ::1') from None
    if not ip.is_loopback: raise ValueError('Target wajib loopback')
    if host and not re.fullmatch(r'[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.(?:local|test)(?::[0-9]{1,5})?',host): raise ValueError('Host virtual hanya .local/.test')
    if any(ord(c)<33 or ord(c)>126 for c in u.path+u.query): raise ValueError('URL harus di-encode')
    return str(ip),u.port or 80,(u.path or '/')+('?' + u.query if u.query else ''),host

def request(t):
    ip,port,path,host=t; start=time.monotonic(); n=0; status=None; error=None
    conn=http.client.HTTPConnection(ip,port,timeout=5)
    try:
        headers={'Accept-Encoding':'identity','User-Agent':'SDP-LocalBenchmark/8.9','Connection':'close'}
        if host: headers['Host']=host
        conn.request('GET',path,headers=headers); r=conn.getresponse();status=r.status
        # Absolute cap and soft elapsed limit; never follow redirects.
        while n<=CAP:
            if time.monotonic()-start>10: raise TimeoutError('response time cap')
            b=r.read1(min(65536,CAP+1-n));n+=len(b)
            if not b: break
        if n>CAP: error='body_cap'
        elif status!=200: error='http_status'
    except (OSError,http.client.HTTPException,ValueError) as e: error=type(e).__name__
    finally: conn.close()
    return {'ms':round((time.monotonic()-start)*1000,3),'body_bytes':n,'status':status,'error':error}

def summary(rows,elapsed):
    good=[r for r in rows if not r['error']]; times=sorted(r['ms'] for r in good)
    def p(x): return times[math.ceil(x*len(times))-1] if times else None
    return {'attempts':len(rows),'ok':len(good),'errors':len(rows)-len(good),'elapsed_s':round(elapsed,3),'p50_ms':p(.5),'p95_ms':p(.95),'max_ms':p(1),'successful_rps':round(len(good)/max(elapsed,.001),3),'body_bytes_total':sum(r['body_bytes'] for r in rows),'mean_success_body_bytes':round(sum(r['body_bytes'] for r in good)/len(good)) if good else None,'samples':rows,'note':'Body HTTP identity saja; bukan total transfer browser/CDN atau kapasitas produksi.'}

def load(url,requests,workers,host=None):
    if not 1<=requests<=100 or not 1<=workers<=4: raise ValueError('requests 1..100; workers 1..4')
    t=target(url,host); start=time.monotonic(); rows=[]
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as pool:
        while len(rows)<requests and time.monotonic()-start<60:
            batch=list(pool.map(request,[t]*min(workers,requests-len(rows)))); rows.extend(batch)
            if any(r['error'] for r in batch): break
            time.sleep(.1)
    return summary(rows,time.monotonic()-start)

def main():
    parser=argparse.ArgumentParser(description=__doc__);sub=parser.add_subparsers(dest='command',required=True)
    g=sub.add_parser('generate');g.add_argument('file');g.add_argument('--rows',type=int,default=100)
    h=sub.add_parser('http');h.add_argument('url');h.add_argument('--host');h.add_argument('--requests',type=int,default=10);h.add_argument('--workers',type=int,default=1);h.add_argument('--local-test',action='store_true',required=True)
    a=parser.parse_args()
    try: result=generate(a.file,a.rows) if a.command=='generate' else load(a.url,a.requests,a.workers,a.host)
    except (ValueError,OSError) as e: parser.error(str(e))
    print(json.dumps(result,indent=2));return int(bool(result.get('errors')))
if __name__=='__main__': raise SystemExit(main())
