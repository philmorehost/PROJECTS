package com.jikabiz.app.util

import android.app.Activity
import android.app.Application
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import okhttp3.Response
import org.json.JSONObject
import com.jikabiz.app.ui.auth.LoginActivity

/** Backend remains authoritative. Never trust a response-supplied redirect or attach app tokens to browser URLs. */
object RequiredSetup {
    private lateinit var app: Application
    private val main = Handler(Looper.getMainLooper())
    private val key get() = "required-setup:" + Constants.BASE_URL
    private val store get() = app.getSharedPreferences("required_security_setup", 0)
    fun install(application: Application) { app = application }
    fun pending(): Boolean = ::app.isInitialized && store.getBoolean(key, false)
    fun inspect(response: Response) {
        if (response.code != 403 || !::app.isInitialized) return
        val code = runCatching { JSONObject(response.peekBody(8192).string()).optString("code") }.getOrNull()
        if (code != "AUTHENTICATOR_SETUP_REQUIRED" && code != "PASSWORD_CHANGE_REQUIRED") return
        store.edit().putBoolean(key, true).apply()
        TransactionCredentials.clear()
        main.post {
            PreferenceManager(app).apply {
                saveBoolean(Constants.KEY_IS_LOGGED_IN, false)
                saveString(Constants.KEY_API_KEY, "")
            }
            app.startActivity(Intent(app, RequiredSetupActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK))
        }
    }
    fun route(activity: Activity): Boolean {
        if (!pending() || activity is RequiredSetupActivity) return false
        activity.startActivity(Intent(activity, RequiredSetupActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK))
        return true
    }
    fun retry(activity: Activity) {
        store.edit().remove(key).apply()
        activity.startActivity(Intent(activity, LoginActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK))
    }
}

private object TransactionCredentials {
    fun clear() { com.jikabiz.app.api.TransactionPin.current = null }
}

class RequiredSetupActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) { override fun handleOnBackPressed() {} })
        val layout = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(32, 64, 32, 32)
            setBackgroundColor(BrandTheme.color(context, "background"))
        }
        layout.addView(TextView(this).apply {
            text = "Required security setup\n\nComplete the required password change or Google Authenticator setup on your website. Sign in there, finish setup, then return here and sign in again. App actions remain locked until the server confirms completion."
            textSize = 19f
            setTextColor(BrandTheme.color(context, "on_surface"))
        })
        layout.addView(Button(this).apply {
            text = "Open secure website"
            setOnClickListener {
                val url = Uri.parse(Constants.BASE_URL).buildUpon().encodedPath("/web/SecuritySetup.php").clearQuery().fragment(null).build()
                if (url.scheme == "https" && !url.host.isNullOrBlank()) runCatching { startActivity(Intent(Intent.ACTION_VIEW, url)) }
            }
        })
        layout.addView(Button(this).apply { text = "Return to sign in"; setOnClickListener { RequiredSetup.retry(this@RequiredSetupActivity) } })
        setContentView(layout)
    }
}
