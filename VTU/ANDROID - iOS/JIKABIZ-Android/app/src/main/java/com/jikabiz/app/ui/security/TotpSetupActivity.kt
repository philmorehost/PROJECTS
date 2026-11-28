package com.jikabiz.app.ui.security

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.text.InputFilter
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.jikabiz.app.api.RetrofitClient
import com.jikabiz.app.ui.auth.LoginActivity
import com.jikabiz.app.util.BrandTheme
import com.jikabiz.app.util.Constants
import com.jikabiz.app.util.OnboardingCoordinator
import com.jikabiz.app.util.PreferenceManager
import kotlinx.coroutines.launch

class TotpSetupActivity : AppCompatActivity() {

    private lateinit var prefs: PreferenceManager
    private var setupGrant: String = ""
    private var secretUri: String = ""

    private lateinit var statusTv: TextView
    private lateinit var progressBar: ProgressBar
    private lateinit var emailSection: LinearLayout
    private lateinit var totpSection: LinearLayout
    private lateinit var secretTv: TextView
    private lateinit var etEmailCode: EditText
    private lateinit var etTotpCode: EditText
    private lateinit var btnSendEmail: Button
    private lateinit var btnVerifyEmail: Button
    private lateinit var btnCopySecret: Button
    private lateinit var btnOpenAuthApp: Button
    private lateinit var btnConfirmTotp: Button

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = PreferenceManager(this)

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                moveTaskToBack(true)
            }
        })

        val dp = { v: Int -> (v * resources.displayMetrics.density).toInt() }
        val bg = BrandTheme.color(this, "background")
        val onSurface = BrandTheme.color(this, "on_surface")
        val primary = BrandTheme.color(this, "primary")
        val onPrimary = BrandTheme.color(this, "on_primary")

        val scroll = ScrollView(this).apply { setBackgroundColor(bg) }
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(24), dp(48), dp(24), dp(32))
        }

        root.addView(TextView(this).apply {
            text = "Set Up Google Authenticator"
            textSize = 22f
            setTextColor(onSurface)
            setTypeface(null, android.graphics.Typeface.BOLD)
        })
        root.addView(TextView(this).apply {
            text = "Your platform requires Google Authenticator for account protection.\n1. Verify your email address.\n2. Add the setup key to Google Authenticator.\n3. Enter the 6-digit code to finish."
            textSize = 14f
            setTextColor(onSurface)
            alpha = 0.8f
            setPadding(0, dp(8), 0, dp(16))
        })

        statusTv = TextView(this).apply {
            textSize = 14f
            setTextColor(primary)
            setPadding(0, 0, 0, dp(12))
            visibility = View.GONE
        }
        root.addView(statusTv)

        progressBar = ProgressBar(this).apply { visibility = View.GONE }
        root.addView(progressBar)

        emailSection = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        btnSendEmail = Button(this).apply {
            text = "Send Email Verification Code"
            setBackgroundColor(primary)
            setTextColor(onPrimary)
            setOnClickListener { sendEmailCode() }
        }
        emailSection.addView(btnSendEmail)

        etEmailCode = EditText(this).apply {
            hint = "Enter 6-digit email code"
            inputType = InputType.TYPE_CLASS_NUMBER
            filters = arrayOf(InputFilter.LengthFilter(6))
            gravity = Gravity.CENTER
            setTextColor(onSurface)
        }
        emailSection.addView(etEmailCode, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
            topMargin = dp(12)
        })

        btnVerifyEmail = Button(this).apply {
            text = "Verify Email & Generate Key"
            setBackgroundColor(primary)
            setTextColor(onPrimary)
            setOnClickListener { verifyEmailCode() }
        }
        emailSection.addView(btnVerifyEmail, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
            topMargin = dp(8)
        })
        root.addView(emailSection)

        totpSection = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            visibility = View.GONE
        }
        totpSection.addView(TextView(this).apply {
            text = "Setup Key (Time-based):"
            textSize = 13f
            setTextColor(onSurface)
            setPadding(0, dp(8), 0, dp(4))
        })
        secretTv = TextView(this).apply {
            textSize = 18f
            setTypeface(android.graphics.Typeface.MONOSPACE, android.graphics.Typeface.BOLD)
            setTextColor(onSurface)
            setTextIsSelectable(true)
            setPadding(dp(12), dp(12), dp(12), dp(12))
            setBackgroundColor(0x18000000)
        }
        totpSection.addView(secretTv)

        btnCopySecret = Button(this).apply {
            text = "Copy Setup Key"
            setOnClickListener {
                val cm = getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                cm.setPrimaryClip(ClipData.newPlainText("Authenticator Key", secretTv.text))
                Toast.makeText(this@TotpSetupActivity, "Setup key copied", Toast.LENGTH_SHORT).show()
            }
        }
        totpSection.addView(btnCopySecret, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
            topMargin = dp(8)
        })

        btnOpenAuthApp = Button(this).apply {
            text = "Open Authenticator App"
            setOnClickListener {
                if (secretUri.isNotBlank()) {
                    runCatching { startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(secretUri))) }
                        .onFailure { Toast.makeText(this@TotpSetupActivity, "Copy the key above and add it manually in Google Authenticator.", Toast.LENGTH_LONG).show() }
                }
            }
        }
        totpSection.addView(btnOpenAuthApp, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
            topMargin = dp(6)
        })

        etTotpCode = EditText(this).apply {
            hint = "6-digit code from Authenticator app"
            inputType = InputType.TYPE_CLASS_NUMBER
            filters = arrayOf(InputFilter.LengthFilter(6))
            gravity = Gravity.CENTER
            setTextColor(onSurface)
        }
        totpSection.addView(etTotpCode, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
            topMargin = dp(12)
        })

        btnConfirmTotp = Button(this).apply {
            text = "Save & Enable Google Authenticator"
            setBackgroundColor(primary)
            setTextColor(onPrimary)
            setOnClickListener { confirmTotp() }
        }
        totpSection.addView(btnConfirmTotp, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
            topMargin = dp(8)
        })
        root.addView(totpSection)

        root.addView(Button(this).apply {
            text = "Sign Out"
            setOnClickListener {
                prefs.signOutPreservingBiometric()
                startActivity(Intent(this@TotpSetupActivity, LoginActivity::class.java).apply {
                    flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
                })
                finish()
            }
        }, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
            topMargin = dp(24)
        })

        scroll.addView(root)
        setContentView(scroll)
    }

    private fun setBusy(busy: Boolean, msg: String? = null) {
        progressBar.visibility = if (busy) View.VISIBLE else View.GONE
        btnSendEmail.isEnabled = !busy
        btnVerifyEmail.isEnabled = !busy
        btnConfirmTotp.isEnabled = !busy
        if (!msg.isNullOrBlank()) {
            statusTv.text = msg
            statusTv.visibility = View.VISIBLE
        }
    }

    private fun sendEmailCode() {
        setBusy(true, "Sending verification code to your email…")
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().totpSetup(mapOf("api_key" to prefs.getApiKey(), "action" to "send"))
                val body = resp.body()
                val msg = body?.get("message") as? String ?: "Check your email inbox."
                if (body?.get("status") == "success") {
                    if (body["already_enabled"] == true) {
                        prefs.saveBoolean(Constants.KEY_TOTP_ENABLED, true)
                        proceed()
                        return@launch
                    }
                    setBusy(false, msg)
                } else {
                    setBusy(false, msg)
                }
            } catch (e: Exception) {
                setBusy(false, e.message ?: "Network error")
            }
        }
    }

    private fun verifyEmailCode() {
        val code = etEmailCode.text?.toString()?.trim().orEmpty()
        if (code.length != 6) { setBusy(false, "Enter the 6-digit email code."); return }
        setBusy(true, "Verifying email code…")
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().totpSetup(mapOf("api_key" to prefs.getApiKey(), "action" to "verify", "email_code" to code))
                val body = resp.body()
                if (body?.get("status") == "success") {
                    setupGrant = body["setup_grant"] as? String ?: ""
                    val beginResp = RetrofitClient.getService().totpSetup(mapOf("api_key" to prefs.getApiKey(), "action" to "begin", "setup_grant" to setupGrant))
                    val beginBody = beginResp.body()
                    if (beginBody?.get("status") == "success") {
                        secretTv.text = beginBody["secret"] as? String ?: ""
                        secretUri = beginBody["uri"] as? String ?: ""
                        emailSection.visibility = View.GONE
                        totpSection.visibility = View.VISIBLE
                        setBusy(false, beginBody["message"] as? String ?: "Add this key to Google Authenticator.")
                    } else {
                        setBusy(false, beginBody?.get("message") as? String ?: "Could not generate key.")
                    }
                } else {
                    setBusy(false, body?.get("message") as? String ?: "Invalid email code.")
                }
            } catch (e: Exception) {
                setBusy(false, e.message ?: "Network error")
            }
        }
    }

    private fun confirmTotp() {
        val code = etTotpCode.text?.toString()?.trim().orEmpty()
        if (code.length != 6) { setBusy(false, "Enter the 6-digit code from Google Authenticator."); return }
        setBusy(true, "Saving Google Authenticator…")
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().totpSetup(mapOf(
                    "api_key" to prefs.getApiKey(),
                    "action" to "confirm",
                    "setup_grant" to setupGrant,
                    "totp_code" to code
                ))
                val body = resp.body()
                if (body?.get("status") == "success") {
                    prefs.saveBoolean(Constants.KEY_TOTP_ENABLED, true)
                    OnboardingCoordinator.syncFlags(prefs, body)
                    Toast.makeText(this@TotpSetupActivity, "Google Authenticator enabled!", Toast.LENGTH_SHORT).show()
                    proceed()
                } else {
                    setBusy(false, body?.get("message") as? String ?: "Code did not match.")
                }
            } catch (e: Exception) {
                setBusy(false, e.message ?: "Network error")
            }
        }
    }

    private fun proceed() {
        startActivity(OnboardingCoordinator.nextActivityIntent(this, prefs))
        finish()
    }
}
