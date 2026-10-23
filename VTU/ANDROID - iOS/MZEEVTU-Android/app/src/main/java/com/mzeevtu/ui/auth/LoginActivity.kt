package com.mzeevtu.ui.auth

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.databinding.ActivityLoginBinding
import com.mzeevtu.data.model.ApiResult
import com.mzeevtu.data.repository.AuthRepository
import com.mzeevtu.ui.security.LockActivity
import com.mzeevtu.util.Constants
import com.mzeevtu.util.PreferenceManager
import com.mzeevtu.util.hide
import com.mzeevtu.util.show
import com.mzeevtu.util.showSnack
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.launch

class LoginActivity : AppCompatActivity() {

    private lateinit var binding: ActivityLoginBinding
    private val authRepo by lazy { AuthRepository(this) }
    private lateinit var prefs: PreferenceManager

    override fun onCreate(savedInstanceState: Bundle?) {
        // Apply saved dark mode preference before inflation
        prefs = PreferenceManager(this)
        androidx.appcompat.app.AppCompatDelegate.setDefaultNightMode(
            if (prefs.getBoolean(Constants.KEY_DARK_MODE, false))
                androidx.appcompat.app.AppCompatDelegate.MODE_NIGHT_YES
            else
                androidx.appcompat.app.AppCompatDelegate.MODE_NIGHT_NO
        )
        super.onCreate(savedInstanceState)
        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnLogin.setOnClickListener { attemptLogin() }
        binding.tvRegister.setOnClickListener {
            startActivity(Intent(this, RegisterActivity::class.java))
        }

        setupBiometricButton()
    }

    private var biometricPromptShown = false

    private fun setupBiometricButton() {
        val biometricEnabled = prefs.getBoolean(Constants.KEY_BIOMETRIC_ENABLED, false)
        val loggedInBefore = prefs.getString(Constants.KEY_API_KEY).isNotEmpty()
        if (!biometricEnabled || !loggedInBefore) return

        val biometricManager = BiometricManager.from(this)
        val canAuthenticate = biometricManager.canAuthenticate(
            BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL
        )
        if (canAuthenticate != BiometricManager.BIOMETRIC_SUCCESS) return

        binding.btnBiometricLogin.visibility = View.VISIBLE
        binding.btnBiometricLogin.setOnClickListener { showBiometricPrompt() }

        // Auto-trigger once per activity instance (not on rotation or back-navigation)
        if (!biometricPromptShown) {
            biometricPromptShown = true
            showBiometricPrompt()
        }
    }

    private fun showBiometricPrompt() {
        val executor = ContextCompat.getMainExecutor(this)
        val callback = object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                super.onAuthenticationSucceeded(result)
                navigateToMain()
            }

            override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                super.onAuthenticationError(errorCode, errString)
                // Only show error for non-cancellation codes
                if (errorCode != BiometricPrompt.ERROR_USER_CANCELED &&
                    errorCode != BiometricPrompt.ERROR_NEGATIVE_BUTTON
                ) {
                    binding.root.showSnack(errString.toString())
                }
            }

            override fun onAuthenticationFailed() {
                super.onAuthenticationFailed()
                binding.root.showSnack("Biometric authentication failed")
            }
        }

        val biometricPrompt = BiometricPrompt(this, executor, callback)
        val promptInfo = BiometricPrompt.PromptInfo.Builder()
            .setTitle("Biometric Login")
            .setSubtitle("Authenticate to access MZEEVTU")
            .setAllowedAuthenticators(
                BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL
            )
            .build()

        biometricPrompt.authenticate(promptInfo)
    }

    private fun registerFcmToken() {
        // Subscribe to the broadcast topic so the admin can push to all users at once
        FirebaseMessaging.getInstance().subscribeToTopic(Constants.FCM_TOPIC_APP_UPDATES)

        // Also register the individual token for device-level analytics
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            if (token.isNotEmpty()) {
                prefs.saveString(Constants.KEY_FCM_TOKEN, token)
                val apiKey = prefs.getApiKey()
                lifecycleScope.launch {
                    try {
                        com.mzeevtu.api.RetrofitClient.getService()
                            .registerDevice(mapOf("api_key" to apiKey, "fcm_token" to token))
                    } catch (_: Exception) { }
                }
            }
        }
    }

    private fun navigateToMain() {
        // Route through the security-question gate on every login (password or biometric) —
        // LockActivity itself decides verify-vs-setup based on the server's has_quest state,
        // then hands off to MainActivity once resolved.
        startActivity(Intent(this, LockActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        })
        finish()
    }

    private fun attemptLogin() {
        val username = binding.etUsername.text?.toString()?.trim() ?: ""
        val password = binding.etPassword.text?.toString()?.trim() ?: ""

        if (username.isEmpty() || password.isEmpty()) {
            binding.root.showSnack("Please enter username and password")
            return
        }

        binding.btnLogin.isEnabled = false
        binding.progressBar.show()

        lifecycleScope.launch {
            when (val result = authRepo.login(username, password)) {
                is ApiResult.Success -> {
                    binding.progressBar.hide()
                    registerFcmToken()
                    navigateToMain()
                }
                is ApiResult.Error -> {
                    binding.progressBar.hide()
                    binding.btnLogin.isEnabled = true
                    binding.root.showSnack(result.message)
                }
                else -> {}
            }
        }
    }
}

// Extension workaround for import clarity
private object Extensions {
    fun android.view.View.hide() { visibility = android.view.View.GONE }
    fun android.view.View.show() { visibility = android.view.View.VISIBLE }
    fun android.view.View.showSnack(msg: String) {
        com.google.android.material.snackbar.Snackbar.make(this, msg, com.google.android.material.snackbar.Snackbar.LENGTH_LONG).show()
    }
}
