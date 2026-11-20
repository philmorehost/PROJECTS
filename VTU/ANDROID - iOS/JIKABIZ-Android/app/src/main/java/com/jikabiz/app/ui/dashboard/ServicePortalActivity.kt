package com.jikabiz.app.ui.dashboard

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.webkit.*
import android.widget.*
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.jikabiz.app.api.RetrofitClient
import com.jikabiz.app.util.Constants
import com.jikabiz.app.util.PreferenceManager
import kotlinx.coroutines.launch
import java.net.URLEncoder

/** Ephemeral first-party browser; no JavaScript bridge or API key in a URL. */
class ServicePortalActivity : AppCompatActivity() {
    private lateinit var browser: WebView
    private var upload: ValueCallback<Array<Uri>>? = null
    private val picker=registerForActivityResult(androidx.activity.result.contract.ActivityResultContracts.OpenMultipleDocuments()) { uris -> upload?.onReceiveValue(uris.toTypedArray()); upload=null }
    private val origin get() = Uri.parse(Constants.BASE_URL)
    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        val layout=LinearLayout(this).apply { orientation=LinearLayout.VERTICAL }
        layout.addView(Button(this).apply { text="Close service"; setOnClickListener { finish() } })
        val message=TextView(this).apply { text="Opening secure service…"; setPadding(24,24,24,24) }; layout.addView(message)
        browser=WebView(this); layout.addView(browser,LinearLayout.LayoutParams(-1,0,1f)); setContentView(layout)
        browser.settings.apply { javaScriptEnabled=true; domStorageEnabled=true; allowFileAccess=false; allowContentAccess=false; mixedContentMode=WebSettings.MIXED_CONTENT_NEVER_ALLOW; javaScriptCanOpenWindowsAutomatically=false }
        CookieManager.getInstance().setAcceptThirdPartyCookies(browser,false)
        browser.webChromeClient=object: WebChromeClient() {
            override fun onShowFileChooser(webView: WebView?, callback: ValueCallback<Array<Uri>>?, params: FileChooserParams?): Boolean {
                upload?.onReceiveValue(null); upload=callback; picker.launch(arrayOf("image/*","application/pdf","video/*")); return true
            }
        }
        browser.webViewClient=object: WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                val url=request?.url ?: return true
                if(url.scheme=="https" && url.host==origin.host && url.port==origin.port)return false
                if(url.scheme=="https" || url.scheme=="mailto") { try { startActivity(Intent(Intent.ACTION_VIEW,url)) } catch(_: Exception) {} }
                return true
            }
            override fun onPageFinished(view: WebView?, url: String?) { message.visibility=android.view.View.GONE }
            override fun onReceivedSslError(view: WebView?, handler: SslErrorHandler?, error: android.net.http.SslError?) { handler?.cancel(); message.text="Secure connection failed. Close and try again."; message.visibility=android.view.View.VISIBLE }
        }
        lifecycleScope.launch {
            try {
                val slug=intent.getStringExtra("service") ?: ""
                val response=RetrofitClient.getService().mobileHome(mapOf("api_key" to PreferenceManager(this@ServicePortalActivity).getApiKey(),"action" to "handoff","service" to slug))
                val body=response.body(); val token=body?.get("token") as? String
                if(!response.isSuccessful || body?.get("status")!="success" || token.isNullOrBlank()) { message.text="Service unavailable. Close and try again."; return@launch }
                if(origin.scheme!="https") { message.text="A secure platform URL is required."; return@launch }
                CookieManager.getInstance().removeAllCookies {
                    if(!isFinishing)browser.postUrl(Constants.BASE_URL.trimEnd('/')+"/web/AppPortal.php",("token="+URLEncoder.encode(token,"UTF-8")+"&service="+URLEncoder.encode(slug,"UTF-8")).toByteArray(Charsets.UTF_8))
                }
            } catch(_: Exception) { message.text="Connection unavailable. Close and try again." }
        }
    }
    override fun onDestroy() { upload?.onReceiveValue(null); if(::browser.isInitialized) { browser.stopLoading(); browser.destroy(); CookieManager.getInstance().removeAllCookies(null) }; super.onDestroy() }
}
