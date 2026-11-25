"""Runner flow simulation: actual preflight + stand-in smoke, not a live WordPress test."""
import pathlib, shutil, subprocess, tempfile, unittest
ROOT=pathlib.Path(__file__).resolve().parent
class Runner(unittest.TestCase):
    def simulate(self, case, smoke_fail=False, missing=False):
        with tempfile.TemporaryDirectory() as directory:
            d=pathlib.Path(directory)
            for f in ('test-runner.php','sdp-run-localwp.php','sdp-preflight-local.php'):
                shutil.copyfile(ROOT/f,d/f)
            if not missing:
                (d/'sdp-smoke-rc6.php').write_text('<?php echo "SMOKE_CALLED\\n";'+('WP_CLI::error("fixture failure");' if smoke_fail else ''))
            return subprocess.run(['php','-d','disable_functions=extension_loaded',str(d/'test-runner.php'),case],capture_output=True,text=True,timeout=10)
    def test_success(self):
        r=self.simulate('pass'); self.assertEqual(r.returncode,0); self.assertIn('SMOKE_CALLED',r.stdout); self.assertIn('| COMPLETE',r.stdout)
    def test_preflight_blockers(self):
        for case in ('production','unsafe','external','indexed','unauthorized','no_mysqli','schema','db_error','nonempty'):
            with self.subTest(case=case):
                r=self.simulate(case); self.assertNotEqual(r.returncode,0); self.assertNotIn('SMOKE_CALLED',r.stdout); self.assertNotIn('| COMPLETE',r.stdout)
    def test_confirmation(self):
        r=self.simulate('missing_confirmation'); self.assertNotEqual(r.returncode,0); self.assertNotIn('SCHEMA_CALLED',r.stdout); self.assertNotIn('SMOKE_CALLED',r.stdout)
    def test_missing_file(self):
        r=self.simulate('pass',missing=True); self.assertNotEqual(r.returncode,0); self.assertNotIn('SCHEMA_CALLED',r.stdout)
    def test_smoke_error(self):
        r=self.simulate('pass',smoke_fail=True); self.assertNotEqual(r.returncode,0); self.assertIn('SMOKE_CALLED',r.stdout); self.assertNotIn('| COMPLETE',r.stdout)
if __name__=='__main__': unittest.main(verbosity=2)
