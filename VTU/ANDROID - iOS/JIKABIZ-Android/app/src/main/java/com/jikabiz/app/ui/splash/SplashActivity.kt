package com.jikabiz.app.ui.splash

import android.animation.ObjectAnimator
import android.content.Intent
import android.os.Bundle
import android.view.animation.LinearInterpolator
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.jikabiz.app.BuildConfig
import com.jikabiz.app.R
import com.jikabiz.app.api.RetrofitClient
import com.jikabiz.app.databinding.ActivitySplashBinding
import com.jikabiz.app.ui.MainActivity
import com.jikabiz.app.ui.auth.LoginActivity
import com.jikabiz.app.util.PreferenceManager
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class SplashActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySplashBinding
    private lateinit var prefs: PreferenceManager

    override fun onCreate(savedInstanceState: Bundle?) {
        // Apply saved dark mode preference before inflation
        prefs = PreferenceManager(this)
        val darkModeEnabled = prefs.getBoolean(com.jikabiz.app.util.Constants.KEY_DARK_MODE, false)
        androidx.appcompat.app.AppCompatDelegate.setDefaultNightMode(
            if (darkModeEnabled) androidx.appcompat.app.AppCompatDelegate.MODE_NIGHT_YES
            else androidx.appcompat.app.AppCompatDelegate.MODE_NIGHT_NO
        )

        super.onCreate(savedInstanceState)
        binding = ActivitySplashBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Read the version from the build rather than hardcoding it — the old literal
        // "v1.0.0" never changed when versionName was bumped, so it was 3 releases stale.
        binding.tvVersion.text = getString(R.string.app_version_format, BuildConfig.VERSION_NAME)

        // Spin logo once (360° in 900ms)
        ObjectAnimator.ofFloat(binding.ivLogo, "rotation", 0f, 360f).apply {
            duration = 900
            interpolator = LinearInterpolator()
            start()
        }

        lifecycleScope.launch {
            // Fetch site info to get dynamic branding
            try {
                val resp = RetrofitClient.getService().getSiteInfo()
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val data = resp.body()?.get("data") as? Map<String, Any>
                    data?.let {
                        // Show the server's brand on the splash so it can be changed from the
                        // admin panel without shipping a new APK. @string/app_name — the brand
                        // compiled into this APK — is the fallback when the server sends none.
                        val siteTitle = (it["site_title"] as? String)?.takeIf { t -> t.isNotBlank() }
                        prefs.saveString(
                            com.jikabiz.app.util.Constants.KEY_SITE_TITLE,
                            siteTitle ?: getString(R.string.app_name)
                        )
                        if (siteTitle != null) binding.tvAppName.text = siteTitle
                        prefs.saveString(com.jikabiz.app.util.Constants.KEY_LOGO_URL, it["logo_url"] as? String ?: "")
                        prefs.saveString(com.jikabiz.app.util.Constants.KEY_PRIMARY_COLOR, it["primary_color"] as? String ?: "#0d6efd")
                        val support = it["support"] as? Map<*, *>
                        prefs.saveString(com.jikabiz.app.util.Constants.KEY_SUPPORT_EMAIL, support?.get("email") as? String ?: "")
                        prefs.saveString(com.jikabiz.app.util.Constants.KEY_SUPPORT_WHATSAPP, support?.get("whatsapp") as? String ?: "")
                    }
                }
            } catch (_: Exception) {}

            delay(1000) // Ensure spin animation completes
            navigateNext()
        }
    }

    private fun navigateNext() {
        val target = when {
            !prefs.isLoggedIn() -> LoginActivity::class.java
            // When biometric is enabled, route through LoginActivity so the prompt is shown
            prefs.getBoolean(com.jikabiz.app.util.Constants.KEY_BIOMETRIC_ENABLED, false) -> LoginActivity::class.java
            else -> MainActivity::class.java
        }
        startActivity(Intent(this, target))
        finish()
    }
}
