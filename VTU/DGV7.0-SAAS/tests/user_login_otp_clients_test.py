"""Source-level contract checks for the website and all shipped mobile clients.

Run: python3 tests/user_login_otp_clients_test.py
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
APPS = ROOT.parent / 'ANDROID - iOS'


class LoginOtpClients(unittest.TestCase):
    def test_web_and_api_gate_before_success(self):
        web = (ROOT / 'web/Login.php').read_text()
        self.assertIn("SELECT * FROM sas_vendors WHERE id='$login_vendor_id'", web)
        self.assertIn("bc_user_login_otp_required($select_vendor_table)", web)
        self.assertIn("bc_verify_otp('user_login'", web)
        api = (ROOT / 'web/api/login.php').read_text()
        self.assertLess(api.index('bc_user_login_otp_required($get_vendor)'), api.index('bc_user_login_payload('))
        self.assertIn('"status" => "otp_required"', api)
        verify = (ROOT / 'web/api/login-otp.php').read_text()
        self.assertLess(verify.index("bc_verify_otp('user_login'"), verify.index('bc_user_login_payload('))
        self.assertIn("(int)$token_row['vendor_id'] !== (int)$vendor_id", verify)

    def test_ios_handles_challenge_without_storing_credentials(self):
        views = list(APPS.glob('*-iOS/*/Views/LoginView.swift'))
        self.assertEqual(len(views), 3)
        for view in views:
            with self.subTest(view=view):
                src = view.read_text(encoding='utf-8-sig')
                challenge = src[src.index('if response.status == "otp_required"'):src.index('func verifyOtp()')]
                self.assertNotIn('apiKey =', challenge)
                self.assertIn('challenge = token', challenge)
                self.assertIn('request("login-otp.php", params: ["challenge": challenge, "otp": otp])', src)
                success = src[src.index('func finishLogin('):]
                self.assertLess(success.index('response.status == "success"'), success.index('apiKey ='))

    def test_android_clients_support_otp(self):
        repos = list(APPS.glob('*-Android/app/src/main/java/**/data/repository/AuthRepository.kt'))
        self.assertEqual(len(repos), 3)
        for repo in repos:
            with self.subTest(repo=repo):
                src = repo.read_text()
                self.assertIn('OtpRequired', src)
                self.assertIn('loginOtp', src)

    def test_ios_register_supports_otp_and_resend(self):
        views = list(APPS.glob('*-iOS/*/Views/RegisterView.swift'))
        self.assertEqual(len(views), 3)
        for view in views:
            with self.subTest(view=view):
                src = view.read_text(encoding='utf-8-sig')
                self.assertIn('otp_required', src)
                self.assertIn('registration_token', src)
                self.assertIn('register-otp.php', src)
                self.assertIn('login-otp.php', src)
        nets = list(APPS.glob('*-iOS/*/Services/AppNetworkService.swift'))
        self.assertEqual(len(nets), 3)
        for net in nets:
            with self.subTest(net=net):
                self.assertIn('"register-otp.php"', net.read_text(encoding='utf-8-sig'))


if __name__ == '__main__':
    unittest.main()
