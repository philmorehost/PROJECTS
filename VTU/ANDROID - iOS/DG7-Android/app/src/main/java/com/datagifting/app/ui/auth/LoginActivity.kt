package com.datagifting.app.ui.auth

import android.content.Intent
import android.os.Bundle
import android.text.InputType
import android.view.View
import android.widget.EditText
import android.widget.LinearLayout
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.databinding.ActivityLoginBinding
import com.datagifting.app.data.model.ApiResult
import com.datagifting.app.data.repository.LoginOutcome
import com.datagifting.app.util.OtpDialogHelper
import com.datagifting.app.data.repository.AuthRepository
import com.datagifting.app.ui.security.LockActivity
import com.datagifting.app.util.Constants
import com.datagifting.app.util.PreferenceManager
import com.datagifting.app.util.hide
import com.datagifting.app.util.show
import com.datagifting.app.util.showSnack
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
        binding.tvForgotPassword.setOnClickListener { showForgotPasswordDialog() }

        applySiteAuthFlags()
        setupBiometricButton()
    }

    override fun onResume() {
        super.onResume()
        applySiteAuthFlags()
        refreshSiteAuthFlags()
    }

    private fun applySiteAuthFlags() {
        val regEnabled = prefs.getBoolean(Constants.KEY_REGISTRATION_ENABLED, true)
        val resetEnabled = prefs.getBoolean(Constants.KEY_PASSWORD_RESET_ENABLED, true)
        binding.tvRegister.visibility = if (regEnabled) View.VISIBLE else View.GONE
        binding.tvForgotPassword.visibility = if (resetEnabled) View.VISIBLE else View.GONE
    }

    private fun refreshSiteAuthFlags() {
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().getSiteInfo()
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val data = resp.body()?.get("data") as? Map<String, Any>
                    val services = data?.get("services") as? Map<*, *>
                    if (services != null) {
                        val regFlag = services["registration"]
                        val regOn = (regFlag as? Number)?.toInt() != 0 && regFlag != false && regFlag?.toString() != "0"
                        prefs.saveBoolean(Constants.KEY_REGISTRATION_ENABLED, regOn)
                        val resetFlag = services["password_reset"]
                        val resetOn = (resetFlag as? Number)?.toInt() != 0 && resetFlag != false && resetFlag?.toString() != "0"
                        prefs.saveBoolean(Constants.KEY_PASSWORD_RESET_ENABLED, resetOn)
                        applySiteAuthFlags()
                    }
                }
            } catch (_: Exception) { }
        }
    }

    private var biometricPromptShown = false

    private fun setupBiometricButton() {
        val biometricEnabled = prefs.getBoolean(Constants.KEY_BIOMETRIC_ENABLED, false)
        val storedToken = prefs.getString(Constants.KEY_BIOMETRIC_SESSION_TOKEN)
            .ifBlank { prefs.getString(Constants.KEY_API_KEY) }
        if (!biometricEnabled || storedToken.isBlank()) return

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
                completeBiometricLogin()
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
        val appTitle = prefs.getString(Constants.KEY_SITE_TITLE).ifBlank { getString(R.string.app_name) }
        val promptInfo = BiometricPrompt.PromptInfo.Builder()
            .setTitle("Biometric Login")
            .setSubtitle("Authenticate to access $appTitle")
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
                        com.datagifting.app.api.RetrofitClient.getService()
                            .registerDevice(mapOf("api_key" to apiKey, "fcm_token" to token))
                    } catch (_: Exception) { }
                }
            }
        }
    }

    private fun completeBiometricLogin() {
        val token = prefs.getString(Constants.KEY_BIOMETRIC_SESSION_TOKEN)
            .ifBlank { prefs.getString(Constants.KEY_API_KEY) }
        if (token.isBlank()) {
            binding.root.showSnack("Please sign in with your password once to re-enable biometrics.")
            return
        }
        binding.progressBar.show()
        lifecycleScope.launch {
            try {
                prefs.saveString(Constants.KEY_API_KEY, token)
                prefs.saveBoolean(Constants.KEY_IS_LOGGED_IN, true)
                val resp = RetrofitClient.getService().getProfile(mapOf("api_key" to token))
                val body = resp.body()
                if (resp.isSuccessful && body?.get("status") == "success") {
                    @Suppress("UNCHECKED_CAST")
                    val data = body["data"] as? Map<String, Any> ?: emptyMap()
                    prefs.saveLoginData(
                        apiKey = token,
                        username = data["username"] as? String ?: prefs.getString(Constants.KEY_BIOMETRIC_USERNAME),
                        firstname = data["firstname"] as? String ?: "",
                        lastname = data["lastname"] as? String ?: "",
                        email = data["email"] as? String ?: "",
                        phone = data["phone"] as? String ?: "",
                        balance = data["balance"]?.toString()?.toDoubleOrNull() ?: 0.0,
                        accountLevel = (data["account_level"] as? Number)?.toInt() ?: 1,
                        pinSet = data["security_pin_set"] as? Boolean ?: false
                    )
                    com.datagifting.app.util.OnboardingCoordinator.syncFlags(prefs, data)
                    binding.progressBar.hide()
                    registerFcmToken()
                    navigateToMain()
                } else {
                    binding.progressBar.hide()
                    prefs.saveString(Constants.KEY_API_KEY, "")
                    prefs.saveBoolean(Constants.KEY_IS_LOGGED_IN, false)
                    prefs.saveString(Constants.KEY_BIOMETRIC_SESSION_TOKEN, "")
                    binding.btnBiometricLogin.visibility = View.GONE
                    binding.root.showSnack("Your saved biometric session expired. Please sign in with your password.")
                }
            } catch (e: Exception) {
                binding.progressBar.hide()
                prefs.saveString(Constants.KEY_API_KEY, "")
                prefs.saveBoolean(Constants.KEY_IS_LOGGED_IN, false)
                binding.root.showSnack(e.message ?: "Unable to verify biometric session. Check your connection.")
            }
        }
    }

    private fun navigateToMain() {
        startActivity(com.datagifting.app.util.OnboardingCoordinator.nextActivityIntent(this, prefs))
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
            when (val result = authRepo.loginStart(username, password)) {
                is LoginOutcome.Success -> {
                    binding.progressBar.hide()
                    registerFcmToken()
                    navigateToMain()
                }
                is LoginOutcome.OtpRequired -> {
                    binding.progressBar.hide()
                    promptLoginOtp(result)
                }
                is LoginOutcome.Failure -> {
                    binding.progressBar.hide()
                    binding.btnLogin.isEnabled = true
                    binding.root.showSnack(result.message)
                }
            }
        }
    }

    /**
     * The password was accepted but the vendor requires an emailed code. Nothing (api_key,
     * balance) has been handed to the app yet - it arrives only after this step succeeds.
     */
    private fun promptLoginOtp(step: LoginOutcome.OtpRequired) {
        OtpDialogHelper.prompt(
            this,
            "Verify Sign-In",
            step.message,
            onCode = { code ->
                if (code.length != 6) {
                    binding.root.showSnack("Enter the 6-digit code")
                    promptLoginOtp(step)
                } else {
                    verifyLoginOtp(step, code)
                }
            }
        )
    }

    private fun showForgotPasswordDialog() {
        val input = EditText(this).apply {
            hint = "Username or email"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS
            setText(binding.etUsername.text?.toString()?.trim() ?: "")
        }
        val pad = (16 * resources.displayMetrics.density).toInt()
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(pad, pad / 2, pad, 0)
            addView(input)
        }
        AlertDialog.Builder(this)
            .setTitle("Reset Password")
            .setMessage("Enter your username or email to receive a 6-digit verification code.")
            .setView(box)
            .setPositiveButton("Send Code") { _, _ ->
                val target = input.text.toString().trim()
                if (target.isEmpty()) {
                    binding.root.showSnack("Enter your username or email")
                } else {
                    requestPasswordResetCode(target)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun extractApiMessage(resp: retrofit2.Response<Map<String, Any>>, fallback: String): String {
        val bodyMsg = resp.body()?.get("message") as? String
        if (!bodyMsg.isNullOrBlank()) return bodyMsg
        return try {
            val raw = resp.errorBody()?.string().orEmpty()
            val msg = org.json.JSONObject(raw).optString("message", "")
            if (msg.isNotBlank()) msg else fallback
        } catch (_: Exception) {
            fallback
        }
    }

    private fun requestPasswordResetCode(user: String) {
        binding.progressBar.show()
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().forgotPassword(mapOf("user" to user))
                binding.progressBar.hide()
                val status = resp.body()?.get("status") as? String ?: ""
                val msg = extractApiMessage(resp, "Unable to send reset code")
                if (resp.isSuccessful && (status == "otp_required" || status == "success")) {
                    binding.root.showSnack(msg)
                    showForgotPasswordConfirmDialog(user)
                } else {
                    binding.root.showSnack(msg)
                }
            } catch (e: Exception) {
                binding.progressBar.hide()
                binding.root.showSnack(e.message ?: "Network error")
            }
        }
    }

    private fun showForgotPasswordConfirmDialog(user: String) {
        val otpInput = EditText(this).apply {
            hint = "6-digit verification code"
            inputType = InputType.TYPE_CLASS_NUMBER
        }
        val passInput = EditText(this).apply {
            hint = "New password (min 8 chars)"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        }
        val pad = (16 * resources.displayMetrics.density).toInt()
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(pad, pad / 2, pad, 0)
            addView(otpInput)
            addView(passInput)
        }
        AlertDialog.Builder(this)
            .setTitle("Verify & Set Password")
            .setMessage("Enter the 6-digit code sent to your email and your new password.")
            .setView(box)
            .setPositiveButton("Reset Password") { _, _ ->
                val code = otpInput.text.toString().trim()
                val newPass = passInput.text.toString()
                if (code.length != 6 || newPass.length < 8) {
                    binding.root.showSnack("Enter a 6-digit code and a password of at least 8 characters")
                } else {
                    confirmPasswordReset(user, code, newPass)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun confirmPasswordReset(user: String, otp: String, newPassword: String) {
        binding.progressBar.show()
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().forgotPassword(
                    mapOf("user" to user, "otp" to otp, "new_password" to newPassword)
                )
                binding.progressBar.hide()
                val msg = extractApiMessage(resp, "Unable to reset password")
                binding.root.showSnack(msg)
            } catch (e: Exception) {
                binding.progressBar.hide()
                binding.root.showSnack(e.message ?: "Network error")
            }
        }
    }

    private fun verifyLoginOtp(step: LoginOutcome.OtpRequired, code: String) {
        binding.progressBar.show()
        lifecycleScope.launch {
            when (val result = authRepo.loginWithOtp(step.challenge, code)) {
                is LoginOutcome.Success -> {
                    binding.progressBar.hide()
                    registerFcmToken()
                    navigateToMain()
                }
                is LoginOutcome.OtpRequired -> {
                    binding.progressBar.hide()
                    promptLoginOtp(result)
                }
                is LoginOutcome.Failure -> {
                    binding.progressBar.hide()
                    binding.btnLogin.isEnabled = true
                    binding.root.showSnack(result.message)
                }
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
