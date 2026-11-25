import sys, pathlib, tempfile, csv, threading, unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[2]/'tools'))
import benchmark_local as b
class Handler(BaseHTTPRequestHandler):
    def log_message(self,*a): pass
    def do_GET(self):
        if self.path=='/redirect': self.send_response(302);self.send_header('Location','https://example.com');self.end_headers();return
        if self.path=='/big': data=b'x'*(b.CAP+1)
        else: data=b'fixture'
        self.send_response(200);self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data)
class Tests(unittest.TestCase):
    def test_code(self): self.assertEqual(b.code(0),'TEST0000');self.assertEqual(len(b.code(499999)),8);self.assertNotEqual(b.code(0),b.code(499999))
    def test_generate(self):
        with tempfile.TemporaryDirectory() as d:
            p=pathlib.Path(d)/'test.csv';self.assertEqual(b.generate(p,1000)['rows'],1000)
            with p.open() as f: rows=list(csv.DictReader(f))
            self.assertEqual(len(rows),1000);self.assertEqual(len({r['npsn'] for r in rows}),1000);self.assertTrue(all('Fiktif' in r['nama'] for r in rows))
            with self.assertRaises(FileExistsError): b.generate(p,1)
            with self.assertRaises(ValueError): b.generate(p,500001)
    def test_target(self):
        self.assertEqual(b.target('http://127.0.0.1:8080/a?x=y','site.local')[1:],[8080,'/a?x=y','site.local'] if False else (8080,'/a?x=y','site.local'))
        for url in ['https://127.0.0.1/','http://example.com/','http://192.168.1.1/','http://127.0.0.1@evil.org/','http://127.0.0.1/#x']:
            with self.assertRaises(ValueError): b.target(url)
        with self.assertRaises(ValueError): b.target('http://127.0.0.1/','example.org')
    def test_limits(self):
        for n,w in [(0,1),(101,1),(1,5)]:
            with self.assertRaises(ValueError): b.load('http://127.0.0.1/',n,w)
    def test_summary(self):
        self.assertIsNone(b.summary([],0)['p95_ms']); self.assertEqual(b.summary([{'ms':1,'body_bytes':4,'error':None}],1)['mean_success_body_bytes'],4)
    def test_http(self):
        server=ThreadingHTTPServer(('127.0.0.1',0),Handler);th=threading.Thread(target=server.serve_forever,daemon=True);th.start()
        try:
            url=f'http://127.0.0.1:{server.server_port}'
            r=b.load(url+'/',4,2);self.assertEqual(r['ok'],4);self.assertEqual(r['body_bytes_total'],28)
            r=b.load(url+'/redirect',4,1);self.assertEqual(r['attempts'],1);self.assertEqual(r['samples'][0]['status'],302)
            r=b.load(url+'/big',4,1);self.assertEqual(r['attempts'],1);self.assertEqual(r['samples'][0]['error'],'body_cap')
        finally: server.shutdown();server.server_close();th.join()
if __name__=='__main__': unittest.main(verbosity=2)
