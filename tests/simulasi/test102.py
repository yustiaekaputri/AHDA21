import csv, pathlib, tempfile, unittest, sys, subprocess, json
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[2]/'tools'))
import validate_source as v
class Tests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.path=pathlib.Path(self.tmp.name)/'input.csv';self.out=self.path.with_name('output.csv')
  self.header=['NPSN','Nama Satuan Pendidikan','Bentuk','Jenjang','Status','Provinsi','Kota/Kab','Kecamatan','NIK','Nama Siswa']
  self.row=['01234567','Sekolah Contoh','SD','PENDIDIKAN DASAR','NEGERI','Jawa Barat','Kab. Contoh','Contoh','secret','private']
 def write(self,header,rows):
  with self.path.open('w',encoding='utf-8-sig',newline='') as f: w=csv.writer(f);w.writerow(header);w.writerows(rows)
 def val(self,kind='identity',edition='2025/2026',**kw):return v.validate(self.path,kind,'https://example.org/data',edition,'izin contoh',**kw)
 def test_identity(self):
  self.write(self.header,[self.row]);r,d=self.val();self.assertEqual(r['status'],'format_pass');self.assertEqual(d[0]['npsn'],'01234567');self.assertEqual(d[0]['jenjang'],'SD');self.assertNotIn('private',str(d));self.assertEqual(r['ignored_columns'],3);self.assertEqual(len(r['sha256_input']),64)
 def test_duplicate(self):self.write(self.header,[self.row,self.row]);self.assertEqual(self.val()[0]['status'],'rejected')
 def test_missing_province(self):self.write(self.header[:5]+self.header[6:],[self.row[:5]+self.row[6:]]);self.assertRaises(ValueError,self.val)
 def test_aggregate(self):self.write(['provinsi','jumlah_siswa'],[['Jawa Barat','100']]);self.assertRaises(ValueError,self.val,'statistics')
 def test_stats(self):self.write(['NPSN','periode','jumlah_siswa'],[['01234567','2025/2026','0']]);self.assertEqual(self.val('statistics')[1][0]['jumlah_siswa'],'0')
 def test_period(self):self.write(['NPSN','periode','jumlah_siswa'],[['01234567','2024/2025','100']]);self.assertEqual(self.val('statistics')[0]['status'],'rejected')
 def test_negative(self):self.write(['NPSN','periode','jumlah_siswa'],[['01234567','2025/2026','-1']]);self.assertEqual(self.val('statistics')[0]['status'],'rejected')
 def test_accreditation(self):self.write(['NPSN','akreditasi','tahun_akreditasi','nomor_sk'],[['01234567','A','2025','1/SK']]);self.assertEqual(self.val('accreditation')[0]['status'],'format_pass')
 def test_formula(self):r=self.row.copy();r[1]='=HYPERLINK("x")';self.write(self.header,[r]);self.assertEqual(self.val()[0]['status'],'rejected')
 def test_demo(self):r=self.row.copy();r[0]='TEST0001';self.write(self.header,[r]);self.assertEqual(self.val()[0]['status'],'rejected')
 def test_header_ambiguity(self):self.write(self.header+['nama_sekolah'],[self.row+['Another']]);self.assertRaises(ValueError,self.val)
 def test_generic_jenjang(self):self.write(self.header[:2]+self.header[3:],[self.row[:2]+self.row[3:]]);self.assertRaises(ValueError,self.val);self.assertEqual(self.val(canonical_form=True)[0]['status'],'rejected')
 def test_limit(self):self.write(self.header,[self.row]*1001);self.assertRaises(ValueError,self.val)
 def test_rights(self):self.write(self.header,[self.row]);self.assertRaises(ValueError,v.validate,self.path,'identity','https://example.org','2025','')
 def test_cli(self):
  self.write(self.header,[self.row]);args=[sys.executable,v.__file__,str(self.path),'--kind','identity','--source','https://example.org','--edition','2025','--rights','izin contoh','--output',str(self.out)];r=subprocess.run(args,capture_output=True);self.assertEqual(r.returncode,0);self.assertIn('sha256_output',json.loads(r.stdout));self.assertNotIn('private',self.out.read_text());self.assertEqual(subprocess.run(args,capture_output=True).returncode,2)
 def test_reject_no_output(self):
  self.write(self.header,[self.row,self.row]);r=subprocess.run([sys.executable,v.__file__,str(self.path),'--kind','identity','--source','https://example.org','--edition','2025','--rights','izin contoh','--output',str(self.out)],capture_output=True);self.assertEqual(r.returncode,2);self.assertFalse(self.out.exists())
if __name__=='__main__':unittest.main(verbosity=2)
