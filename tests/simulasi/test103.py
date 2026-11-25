import csv,pathlib,tempfile,unittest,sys,hashlib,json
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[2]/'tools'))
import prepare_pilot as p
class Tests(unittest.TestCase):
 def setUp(self):
  t=tempfile.TemporaryDirectory();self.addCleanup(t.cleanup);self.base=pathlib.Path(t.name)
  self.c={'provinsi':'Jawa Barat','kabupaten':'Kota Banjar','identity':self.meta('i.csv','2025'),'statistics':self.meta('s.csv','2025/2026')}
  self.write('i.csv',['npsn','nama','bentuk','status','provinsi','kabupaten','kecamatan'],[['01234567','Sekolah Uji','SD','NEGERI','Jawa Barat','Kota Banjar','Banjar'],['12345678','Sekolah Uji Dua','SD','NEGERI','Jawa Barat','Kabupaten Banjar','Banjar']]);self.write('s.csv',['npsn','periode','peserta didik','guru'],[['01234567','2025/2026','0','2'],['12345678','2025/2026','3','1']])
 def meta(self,f,e):return {'file':f,'source':'https://example.org/data','edition':e,'rights':'uji lokal'}
 def write(self,n,h,rows):
  with (self.base/n).open('w',newline='') as f:w=csv.writer(f);w.writerow(h);w.writerows(rows)
 def test_select(self):
  r,o=p.prepare(self.c,self.base);self.assertEqual(r['schools'],1);self.assertEqual(r['coverage']['statistics']['outside_pilot'],1);self.assertIn(b'01234567',o['identity.csv']);self.assertNotIn(b'12345678',o['statistics.csv']);self.assertEqual(r['sources']['identity']['sha256_pilot'],hashlib.sha256(o['identity.csv']).hexdigest());self.assertNotIn(b'jumlah_siswa',o['identity.csv']);self.assertIn(b'0,2',o['statistics.csv'])
 def test_no_match(self):self.c['kabupaten']='Unknown';self.assertRaises(ValueError,p.prepare,self.c,self.base)
 def test_missing_supplement(self):
  self.write('s.csv',['npsn','periode','guru'],[['12345678','2025/2026','1']]);r,o=p.prepare(self.c,self.base);self.assertNotIn('statistics.csv',o);self.assertEqual(r['coverage']['statistics']['missing_pilot'],1)
 def test_duplicate(self):self.write('s.csv',['npsn','periode','guru'],[['01234567','2025/2026','1']]*2);self.assertRaises(ValueError,p.prepare,self.c,self.base)
 def test_limits(self):self.assertRaises(ValueError,p.prepare,self.c,self.base,101)
 def test_no_identity(self):del self.c['identity'];self.assertRaises(ValueError,p.prepare,self.c,self.base)
 def test_period(self):self.c['statistics']['edition']='2024/2025';self.assertRaises(ValueError,p.prepare,self.c,self.base)
 def test_optional(self):del self.c['statistics'];r,o=p.prepare(self.c,self.base);self.assertEqual(list(o),['identity.csv'])
if __name__=='__main__':unittest.main(verbosity=2)
