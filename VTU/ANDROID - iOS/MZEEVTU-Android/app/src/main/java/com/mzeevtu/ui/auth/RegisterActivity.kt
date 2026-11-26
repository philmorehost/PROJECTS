package com.mzeevtu.ui.auth

import android.content.Intent
import android.os.Bundle
import android.widget.ArrayAdapter
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.data.model.ApiResult
import com.mzeevtu.data.repository.AuthRepository
import com.mzeevtu.data.repository.LoginOutcome
import com.mzeevtu.data.repository.RegisterOutcome
import com.mzeevtu.util.OtpDialogHelper
import com.mzeevtu.databinding.ActivityRegisterBinding
import com.mzeevtu.ui.MainActivity
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch
import java.util.Locale

class RegisterActivity : AppCompatActivity() {

    private lateinit var binding: ActivityRegisterBinding
    private val authRepo by lazy { AuthRepository(this) }
    private lateinit var countryCodes: List<String>

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityRegisterBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setupCountrySpinner()
        binding.btnBack.setOnClickListener { finish() }
        binding.btnRegister.setOnClickListener { attemptRegister() }
        binding.tvLogin.setOnClickListener { finish() }
    }

    private fun setupCountrySpinner() {
        val pairs = Locale.getISOCountries()
            .map { code -> code to Locale("", code).getDisplayCountry(Locale.ENGLISH) }
            .filter { it.second.isNotBlank() }
            .sortedBy { it.second }
        countryCodes = pairs.map { it.first }
        val labels = pairs.map { "${it.second} (${it.first})" }
        val adapter = ArrayAdapter(this, android.R.layout.simple_spinner_item, labels)
        adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item)
        binding.spCountry.adapter = adapter
        val defaultCode = Locale.getDefault().country.uppercase(Locale.ENGLISH).ifBlank { "NG" }
        val idx = countryCodes.indexOf(defaultCode).let { if (it >= 0) it else countryCodes.indexOf("NG") }
        if (idx >= 0) binding.spCountry.setSelection(idx)
    }

    private fun attemptRegister() {
        val username = binding.etUsername.text?.toString()?.trim() ?: ""
        val password = binding.etPassword.text?.toString()?.trim() ?: ""
        val confirmPass = binding.etConfirmPassword.text?.toString()?.trim() ?: ""
        val firstname = binding.etFirstname.text?.toString()?.trim() ?: ""
        val lastname = binding.etLastname.text?.toString()?.trim() ?: ""
        val email = binding.etEmail.text?.toString()?.trim() ?: ""
        val countryPos = binding.spCountry.selectedItemPosition
        val country = if (countryPos in countryCodes.indices) countryCodes[countryPos] else "NG"
        val rawPhone = binding.etPhone.text?.toString()?.trim() ?: ""
        val phoneDigits = rawPhone.filter { it.isDigit() }
        val cleanPhone = if (rawPhone.startsWith("+")) "+$phoneDigits" else phoneDigits
        val address = binding.etAddress.text?.toString()?.trim() ?: ""
        val referral = binding.etReferral.text?.toString()?.trim() ?: ""

        when {
            username.length < 6 -> snack("Username must be at least 6 characters")
            password.length < 6 -> snack("Password must be at least 6 characters")
            password != confirmPass -> snack("Passwords do not match")
            firstname.isEmpty() || lastname.isEmpty() -> snack("Please enter your full name")
            email.isEmpty() || !android.util.Patterns.EMAIL_ADDRESS.matcher(email).matches() -> snack("Invalid email address")
            !Regex("^\\+?[0-9\\s().-]{7,20}$").matches(rawPhone) || phoneDigits.length !in 7..15 ->
                snack("Enter a valid phone number (7–15 digits)")
            else -> doRegister(username, password, firstname, lastname, email, country, cleanPhone, address, referral)
        }
    }

    private fun doRegister(
        username: String, password: String, firstname: String, lastname: String,
        email: String, country: String, phone: String, address: String, referral: String
    ) {
        binding.btnRegister.isEnabled = false
        binding.progressBar.visibility = android.view.View.VISIBLE

        lifecycleScope.launch {
            val result = authRepo.register(
                mapOf(
                    "user" to username, "pass" to password,
                    "first" to firstname, "last" to lastname,
                    "email" to email, "country" to country, "phone" to phone,
                    "address" to address, "referral" to referral
                )
            )
            binding.progressBar.visibility = android.view.View.GONE
            binding.btnRegister.isEnabled = true

            when (result) {
                is RegisterOutcome.Success -> autoLogin(username, password)
                is RegisterOutcome.OtpRequired -> promptRegistrationOtp(result, username, password)
                is RegisterOutcome.Failure -> snack(result.message)
            }
        }
    }

    /**
     * The account is NOT created yet: the server has emailed a 6-digit code. Only after the
     * code is confirmed does registerVerify() create the account, which is what stops people
     * registering with addresses they do not own.
     */
    private fun promptRegistrationOtp(step: RegisterOutcome.OtpRequired, username: String, password: String) {
        OtpDialogHelper.prompt(
            this,
            "Verify Your Email",
            step.message,
            onCode = { code ->
                if (code.length != 6) {
                    snack("Enter the 6-digit code")
                    promptRegistrationOtp(step, username, password)
                } else {
                    verifyRegistrationOtp(step, code, username, password)
                }
            },
            onResend = {
                lifecycleScope.launch {
                    when (val r = authRepo.resendRegisterOtp(step.token)) {
                        is ApiResult.Success -> snack(r.data)
                        is ApiResult.Error -> snack(r.message)
                        else -> {}
                    }
                }
            }
        )
    }

    private fun verifyRegistrationOtp(step: RegisterOutcome.OtpRequired, code: String, username: String, password: String) {
        binding.progressBar.visibility = android.view.View.VISIBLE
        binding.btnRegister.isEnabled = false
        lifecycleScope.launch {
            val result = authRepo.registerVerify(step.token, code)
            binding.progressBar.visibility = android.view.View.GONE
            binding.btnRegister.isEnabled = true
            when (result) {
                is ApiResult.Success -> autoLogin(username, password)
                is ApiResult.Error -> {
                    snack(result.message)
                    promptRegistrationOtp(step, username, password)
                }
                else -> {}
            }
        }
    }

    private fun autoLogin(username: String, password: String) {
        lifecycleScope.launch {
            when (val login = authRepo.loginStart(username, password)) {
                is LoginOutcome.Success -> {
                    startActivity(Intent(this@RegisterActivity, MainActivity::class.java).apply {
                        flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
                    })
                    finish()
                }
                is LoginOutcome.OtpRequired -> {
                    // Login OTP is on for this vendor: send the user to the sign-in screen
                    // rather than guessing a session state here.
                    snack("Account created. Please sign in with the code we email you.")
                    finish()
                }
                is LoginOutcome.Failure -> {
                    snack("Registered! Please login.")
                    finish()
                }
            }
        }
    }
    private fun snack(msg: String) {
        Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()
    }
}
