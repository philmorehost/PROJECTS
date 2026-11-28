package com.mzeevtu.ui.splash

import android.animation.ObjectAnimator
import android.content.Intent
import android.os.Bundle
import android.view.animation.LinearInterpolator
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.BuildConfig
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.ActivitySplashBinding
import com.mzeevtu.ui.MainActivity
import com.mzeevtu.ui.auth.LoginActivity
import com.mzeevtu.util.PreferenceManager
import kotlinx.coroutines.async
import kotlinx.coroutines.withTimeoutOrNull
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class SplashActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySplashBinding
    private lateinit var prefs: PreferenceManager

    override fun onCreate(savedInstanceState: Bundle?) {
        // Apply saved dark mode preference before inflation
        prefs = PreferenceManager(this)
        val darkModeEnabled = prefs.getBoolean(com.mzeevtu.util.Constants.KEY_DARK_MODE, false)
        androidx.appcompat.app.AppCompatDelegate.setDefaultNightMode(
            if (darkModeEnabled) androidx.appcompat.app.AppCompatDelegate.MODE_NIGHT_YES
            else androidx.appcompat.app.AppCompatDelegate.MODE_NIGHT_NO
        )

        super.onCreate(savedInstanceState)
        binding = ActivitySplashBinding.inflate(layoutInflater)
        setContentView(binding.root)
        com.mzeevtu.util.BrandTheme.applyNow(this)

        // Read the version from the build rather than hardcoding it — the old literal
        // "v1.0.0" never changed when versionName was bumped to 1.0.9.
        binding.tvVersion.text = getString(R.string.app_version_format, BuildConfig.VERSION_NAME)

        // Spin logo once (360° in 900ms)
        ObjectAnimator.ofFloat(binding.ivLogo, "rotation", 0f, 360f).apply {
            duration = 900
            interpolator = LinearInterpolator()
            start()
        }

        lifecycleScope.launch {
            // In parallel with public branding; bounded so offline launch cannot hang on this check.
            val policyCheck = async {
                if (prefs.isLoggedIn()) {
                    try { withTimeoutOrNull(5000) { RetrofitClient.getService().getProfile(mapOf("api_key" to prefs.getApiKey())) } } catch (_: Exception) { }
                }
            }
            // Fetch site info to get dynamic branding
            try {
                val resp = RetrofitClient.getService().getSiteInfo()
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val data = resp.body()?.get("data") as? Map<String, Any>
                    data?.let {
                        (it["theme"] as? Map<*, *>)?.let { palette -> com.mzeevtu.util.BrandTheme.acceptSiteTheme(org.json.JSONObject(palette)) }
                        // Show the server's brand on the splash so it can be changed from the
                        // admin panel without shipping a new APK. @string/app_name — the brand
                        // compiled into this APK — is the fallback when the server sends none.
                        val siteTitle = (it["site_title"] as? String)?.takeIf { t -> t.isNotBlank() }
                        prefs.saveString(
                            com.mzeevtu.util.Constants.KEY_SITE_TITLE,
                            siteTitle ?: getString(R.string.app_name)
                        )
                        if (siteTitle != null) binding.tvAppName.text = siteTitle
                        prefs.saveString(com.mzeevtu.util.Constants.KEY_LOGO_URL, it["logo_url"] as? String ?: "")
                        val support = it["support"] as? Map<*, *>
                        prefs.saveString(com.mzeevtu.util.Constants.KEY_SUPPORT_EMAIL, support?.get("email") as? String ?: "")
                        prefs.saveString(com.mzeevtu.util.Constants.KEY_SUPPORT_WHATSAPP, support?.get("whatsapp") as? String ?: "")
                        val services = it["services"] as? Map<*, *>
                        if (services != null) {
                            val regFlag = services["registration"]
                            val regOn = (regFlag as? Number)?.toInt() != 0 && regFlag != false && regFlag?.toString() != "0"
                            prefs.saveBoolean(com.mzeevtu.util.Constants.KEY_REGISTRATION_ENABLED, regOn)
                            val resetFlag = services["password_reset"]
                            val resetOn = (resetFlag as? Number)?.toInt() != 0 && resetFlag != false && resetFlag?.toString() != "0"
                            prefs.saveBoolean(com.mzeevtu.util.Constants.KEY_PASSWORD_RESET_ENABLED, resetOn)
                        }
                    }
                }
            } catch (_: Exception) {}

            delay(1000) // Ensure spin animation completes
            // Revalidate returning sessions before opening cached dashboard UI.
            val profileResp = policyCheck.await()
            if (profileResp is retrofit2.Response<*> && profileResp.isSuccessful) {
                @Suppress("UNCHECKED_CAST")
                val data = (profileResp.body() as? Map<String, Any>)?.get("data") as? Map<String, Any>
                if (data != null) com.mzeevtu.util.OnboardingCoordinator.syncFlags(prefs, data)
            }
            if (!com.mzeevtu.util.RequiredSetup.route(this@SplashActivity)) navigateNext()
        }
    }

    private fun navigateNext() {
        val intent = when {
            !prefs.isLoggedIn() -> Intent(this, LoginActivity::class.java)
            // When biometric is enabled, route through LoginActivity so the prompt is shown
            prefs.getBoolean(com.mzeevtu.util.Constants.KEY_BIOMETRIC_ENABLED, false) -> {
                if (prefs.getString(com.mzeevtu.util.Constants.KEY_BIOMETRIC_SESSION_TOKEN).isBlank() && prefs.getApiKey().isNotBlank()) {
                    prefs.saveString(com.mzeevtu.util.Constants.KEY_BIOMETRIC_SESSION_TOKEN, prefs.getApiKey())
                }
                Intent(this, LoginActivity::class.java)
            }
            else -> com.mzeevtu.util.OnboardingCoordinator.nextActivityIntent(this, prefs)
        }
        startActivity(intent)
        finish()
    }
}
