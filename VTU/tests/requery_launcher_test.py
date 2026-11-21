"""Offline launcher contracts: synthetic PHP executables; never loads app config."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]

class LauncherTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='requery fixture ')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        (self.root / 'cron').mkdir()
        self.launcher = self.root / 'cron/run_requery_queue.sh'
        shutil.copy(ROOT / 'DGV7.0-SAAS/cron/run_requery_queue.sh', self.launcher)
        self.php = self.root / 'fake php'
        self.php.write_text('''#!/bin/sh
if [ "$1" = '-r' ]; then printf '%s' "${TEST_PROBE:-BC_REQUERY_CLI_OK}"; exit 0; fi
printf 'WORKER:%s:%s\\n' "$1" "${2:-run}"
exit "${TEST_EXIT:-0}"
''')
        self.php.chmod(0o700)

    def run_launcher(self, *args, **extra):
        env = dict(os.environ, BC_REQUERY_PHP=str(self.php), **extra)
        return subprocess.run(['/bin/sh', str(self.launcher), *args], env=env,
                              cwd='/', text=True, capture_output=True)

    def test_check_forwards_exact_path_and_has_no_redirect(self):
        p = self.run_launcher('--check')
        self.assertEqual(p.returncode, 0, p.stderr)
        self.assertIn(f'WORKER:{self.root}/cron/process_requery_queue.php:--check', p.stdout)
        self.assertFalse((self.root / 'logs').exists())

    def test_run_logs_and_preserves_worker_exit(self):
        p = self.run_launcher(TEST_EXIT='7')
        self.assertEqual(p.returncode, 7)
        self.assertEqual(p.stdout, '')
        self.assertIn('WORKER:', (self.root / 'logs/requery_queue.log').read_text())

    def test_cgi_header_is_not_cli(self):
        p = self.run_launcher(TEST_PROBE='Content-type: text/html; charset=UTF-8\nBC_REQUERY_CLI_OK')
        self.assertEqual(p.returncode, 2)
        self.assertNotIn('WORKER:', p.stdout)
        self.assertFalse((self.root / 'logs').exists())

    def test_relative_config_refused(self):
        p = subprocess.run(['/bin/sh', str(self.launcher)], env=dict(os.environ, BC_REQUERY_PHP='php'), text=True, capture_output=True)
        self.assertEqual(p.returncode, 2)
        self.assertIn('absolute', p.stderr)

    def test_bad_arguments_refused(self):
        self.assertEqual(self.run_launcher('--purchase').returncode, 2)

    def test_setup_pages_publish_complete_schedule(self):
        for ed in ['SAAS', 'NON-SAAS']:
            page = (ROOT / f'DGV7.0-{ed}/bc-admin/AccountSettings.php').read_text()
            self.assertIn('value="* * * * * /bin/sh', page)
            self.assertIn('/cron/run_requery_queue.sh', page)
            self.assertIn('paste only the /bin/sh command', page)
        platform = (ROOT / 'DGV7.0-SAAS/bc-spadmin/CronJobs.php').read_text()
        self.assertIn('$root = dirname(__DIR__);', platform)
        self.assertIn('Confirmed Working Launcher', platform)

    def test_editions_identical(self):
        self.assertEqual((ROOT / 'DGV7.0-SAAS/cron/run_requery_queue.sh').read_bytes(),
                         (ROOT / 'DGV7.0-NON-SAAS/cron/run_requery_queue.sh').read_bytes())

if __name__ == '__main__':
    unittest.main()
