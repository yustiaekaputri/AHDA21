#!/usr/bin/env python3
"""Local Nginx + synthetic FastCGI smoke test. Never touches system Nginx."""
import http.client, os, pathlib, shutil, socket, socketserver, ssl, struct, subprocess, tempfile, threading, time
BASE=pathlib.Path(__file__).resolve().parents[1]
class FCGI(socketserver.StreamRequestHandler):
    def handle(self):
        while True:
            h=self.rfile.read(8)
            if len(h)!=8:return
            ver,kind,rid,n,pad,_=struct.unpack('!BBHHBB',h)
            self.rfile.read(n+pad)
            if kind==5 and n==0:break
        with self.server.lock:
            self.server.count+=1
            body=f'response {self.server.count}'.encode()
            extra=self.server.extra
        def rec(k,data): return struct.pack('!BBHHBB',1,k,rid,len(data),0,0)+data
        out=b'Status: 200 OK\r\nContent-Type: text/plain\r\n'+extra+b'\r\n'+body
        self.wfile.write(rec(6,out)+rec(6,b'')+rec(3,b'\0'*8))
class Backend(socketserver.ThreadingTCPServer):
    allow_reuse_address=True
    daemon_threads=True

def freeport():
    with socket.socket() as s:
        s.bind(('127.0.0.1',0));return s.getsockname()[1]

def main():
    for name in ('nginx','openssl'):
        if not shutil.which(name):raise SystemExit(f'{name} not found')
    b=Backend(('127.0.0.1',0),FCGI);b.count=0;b.extra=b'';b.lock=threading.Lock()
    threading.Thread(target=b.serve_forever,daemon=True).start()
    n=0
    def ok(v,label):
        nonlocal n
        if not v:raise AssertionError(label)
        n+=1;print('PASS',label)
    try:
        for mode,extra in [('off',b''),('on',b''),('private',b'Cache-Control: private, no-store\r\n'),('cookie',b'Set-Cookie: test=1; Path=/\r\n')]:
            with tempfile.TemporaryDirectory(prefix='sdp-nginx-') as d:
                p=pathlib.Path(d);os.chmod(p,0o755)
                root=p/'public';root.mkdir();(root/'index.php').write_text('<?php // fixture');(root/'wp-config.php').write_text('SECRET');(root/'.env').write_text('SECRET');(root/'backup.sql').write_text('SECRET');up=root/'wp-content/uploads';up.mkdir(parents=True);(up/'evil.php').write_text('SECRET');(up/'image.png').write_bytes(b'fixture')
                (p/'cache').mkdir();os.chmod(p/'cache',0o777)
                subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=example.invalid','-keyout',str(p/'key.pem'),'-out',str(p/'cert.pem')],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
                http_port,https=freeport(),freeport();site=(BASE/'nginx/sdp-site.conf').read_text().replace('listen 80; ',f'listen 127.0.0.1:{http_port}; ').replace('listen 80;',f'listen 127.0.0.1:{http_port};').replace('listen 443 ssl;',f'listen 127.0.0.1:{https} ssl;').replace('/var/www/sekolah/public',str(root)).replace('/etc/letsencrypt/live/example.invalid/fullchain.pem',str(p/'cert.pem')).replace('/etc/letsencrypt/live/example.invalid/privkey.pem',str(p/'key.pem')).replace('unix:/run/php/php8.3-fpm-sdp.sock',f'127.0.0.1:{b.server_address[1]}')
                if mode!='off':site=site.replace('fastcgi_cache off;', (BASE/'nginx/sdp-cache-optional.conf').read_text())
                maps=(BASE/'nginx/00-sdp-http.conf').read_text().replace('/var/cache/nginx/sdp',str(p/'cache')) if mode!='off' else ''
                cfg=p/'nginx.conf';cfg.write_text(f'worker_processes 1; pid {p}/nginx.pid; error_log {p}/error.log; events {{ worker_connections 64; }} http {{ access_log off; client_body_temp_path {p}/body; fastcgi_temp_path {p}/fcgi; {maps}\n{site}\n}}')
                command=['nginx','-p',d,'-c',str(cfg)]
                result=subprocess.run(command+['-t'],capture_output=True,text=True);ok(result.returncode==0,mode+' nginx -t: '+result.stderr.strip())
                b.extra=extra
                proc=subprocess.Popen(command+['-g','daemon off;'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
                try:
                    time.sleep(.25)
                    def get(path,headers=None,method='GET'):
                        c=http.client.HTTPSConnection('127.0.0.1',https,context=ssl._create_unverified_context(),timeout=3)
                        c.request(method,path,headers={'Host':'example.invalid',**(headers or {})});r=c.getresponse();out=(r.status,r.getheader('X-SDP-Cache'),r.read(),r.getheader('Cache-Control'));c.close();return out
                    a=get('/tentang-kami/');z=get('/tentang-kami/')
                    ok(a[0]==200 and z[0]==200,mode+' FastCGI response')
                    if mode=='on':
                        ok(a[1]=='MISS' and z[1]=='HIT' and a[2]==z[2],'anonymous allowlist cache MISS then HIT')
                        for label,path,h,m in [('cookie','/tentang-kami/',{'Cookie':'wordpress_logged_in=abc'},'GET'),('authorization','/tentang-kami/',{'Authorization':'Basic abc'},'GET'),('query','/tentang-kami/?a=1',{},'GET'),('range','/tentang-kami/',{'Range':'bytes=0-1'},'GET'),('post','/tentang-kami/',{},'POST'),('school','/sekolah/satu/',{},'GET'),('operator','/portal-operator/',{},'GET'),('case','/TENTANG-KAMI/',{},'GET'),('api','/wp-json/sdp/v1/schools',{},'GET')]:
                            q=get(path,h,m);ok((q[1]=='BYPASS' or (m=='POST' and q[1] is None)) and q[2]!=a[2],label+' bypass')
                    else:ok(a[2]!=z[2] and z[1]!='HIT',mode+' never reused')
                    if mode=='off':
                        for path in ('/wp-config.php','/.env','/backup.sql','/wp-content/uploads/evil.php'):
                            ok(get(path)[0]==403,'deny '+path)
                        s=get('/wp-content/uploads/image.png');ok(s[0]==200 and 'max-age=604800' in (s[3] or ''),'static browser cache')
                        c=http.client.HTTPConnection('127.0.0.1',http_port,timeout=3);c.request('GET','/test');r=c.getresponse();ok(r.status==301 and r.getheader('Location')=='https://example.invalid/test','HTTP redirects fixed HTTPS host');c.close()
                finally:
                    proc.terminate();proc.wait(timeout=5)
    finally:b.shutdown();b.server_close()
    print(f'TOTAL {n} checks')
if __name__=='__main__':main()
