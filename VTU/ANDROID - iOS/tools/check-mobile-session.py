"""Source contracts only; native builds and on-device verification are separate."""
from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[1]
class MobileSession(unittest.TestCase):
    def test_android_session_after_otp_and_no_key_fallback(self):
        for brand, package in [('DG7-Android','com.datagifting.app'),('JIKABIZ-Android','com.jikabiz.app'),('MZEEVTU-Android','com.mzeevtu')]:
            with self.subTest(brand=brand):
                base=ROOT/brand/'app/src/main/java'/package.replace('.','/')
                auth=(base/'data/repository/AuthRepository.kt').read_text()
                persist=auth[auth.index('private fun persistLogin'):]
                self.assertIn('data["app_token"]',persist)
                self.assertNotIn('data["api_key"]',persist)
                self.assertLess(persist.index('require(session.isNotEmpty())'),persist.index('prefs.saveLoginData('))
                self.assertIn('persistLogin(data)',auth[auth.index('suspend fun loginWithOtp'):])
                transport=(base/'api/RetrofitClient.kt').read_text()
                self.assertIn('builder.header("Authorization", "Bearer $token")',transport)
                self.assertIn('builder.header("X-App-Token", token)',transport)
                lock=(base/'ui/security/LockActivity.kt').read_text()
                self.assertEqual(lock.count('"app_token" to prefs.getApiKey()'),4)
                self.assertNotIn('"api_key" to prefs.getApiKey()',lock)
                self.assertIn('APP_TOKEN_REQUIRED',lock)
                self.assertIn('APP_SESSION_INVALID',lock)

                self.assertIn('!publicAuth',transport)
                self.assertIn('PreferenceManager(it).getApiKey()',transport)
                self.assertIn('RetrofitClient.install(this)',(base/'DGApp.kt').read_text())
                self.assertIn('app_session_token_v2',(base/'util/Constants.kt').read_text())
                self.assertIn('getApiKey().isNotBlank()',(base/'util/PreferenceManager.kt').read_text())
                repo=(base/'data/repository/ServicesRepository.kt').read_text()
                self.assertIn('if (body["status"] != "success")',repo)
                self.assertIn('if (bal != null)',repo)
                self.assertIn('"action" to "get"',(base/'ui/security/LockActivity.kt').read_text())
    def test_ios_session_and_all_request_forms(self):
        for brand,folder in [('DG7-iOS','DG6-iOS'),('JIKABIZ-iOS','JIKABIZ'),('MZEEVTU-iOS','MZEEVTU-iOS')]:
            with self.subTest(brand=brand):
                base=ROOT/brand/folder
                login=(base/'Views/LoginView.swift').read_text()
                self.assertIn('guard let session = data.app_token',login)
                self.assertIn('apiKey = session',login)
                self.assertNotIn('apiKey = data.api_key',login)
                net=(base/'Services/AppNetworkService.swift').read_text()
                self.assertIn('platform.app-session.v2',net)
                for func in ['func request<T:', 'func requestObject(']:
                    section=net[net.index(func):]
                    self.assertIn('finalParams["app_token"] = apiKey',section)
                    self.assertIn('forHTTPHeaderField: "Authorization"',section)
                    self.assertIn('!isPublicAuth(endpoint)',section)
                self.assertIn('if !key.isEmpty { onRegistered() }',(base/'Views/RegisterView.swift').read_text())
    def test_server_keeps_session_and_security_enforcement(self):
        for edition in ['SAAS','NON-SAAS']:
            base=ROOT.parent/('DGV7.0-'+edition)
            home=(base/'web/api/mobile-home.php').read_text()
            self.assertIn("($GLOBALS['bc_api_auth_mode']??'api_key')!=='app_token'",home)
            self.assertIn('!bc_fintech_mobile_app_allowed($u)',home)
            bridge=(base/'func/bc-fintech-gate.php').read_text()
            self.assertIn('bc_app_session_user(',bridge)
            self.assertIn('bc_enrollment_api_guard(',bridge)
            self.assertIn('APP_SESSION_INVALID',bridge)
if __name__=='__main__':unittest.main()
