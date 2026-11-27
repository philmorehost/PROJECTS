package com.mzeevtu.util

import android.app.Activity
import android.app.Application
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.content.Context
import android.content.res.ColorStateList
import android.content.res.Configuration
import android.graphics.Color
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import android.widget.ImageView
import androidx.core.graphics.ColorUtils
import androidx.core.view.WindowInsetsControllerCompat
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.google.android.material.bottomnavigation.BottomNavigationView
import okhttp3.*
import org.json.JSONObject
import java.io.IOException
import java.util.WeakHashMap
import java.util.concurrent.TimeUnit

/** Public, origin-scoped branding only. Never stores or sends account credentials. */
object BrandTheme : Application.ActivityLifecycleCallbacks {
    private lateinit var app: Application
    private val main = Handler(Looper.getMainLooper())
    private val activities = java.util.Collections.newSetFromMap(WeakHashMap<Activity, Boolean>())
    private val applied = WeakHashMap<View, String>()
    private val externalRoots = java.util.Collections.newSetFromMap(WeakHashMap<View, Boolean>())
    private val barColors = WeakHashMap<Activity, Int>()
    private val inheritedInk = WeakHashMap<View, Boolean>()
    private val listeners = WeakHashMap<Activity, android.view.ViewTreeObserver.OnGlobalLayoutListener>()
    private var theme: JSONObject? = null
    private var inFlight = false
    private var lastAttempt = 0L
    private val client = OkHttpClient.Builder().callTimeout(8, TimeUnit.SECONDS).followRedirects(false).build()
    private val cacheKey get() = "theme_v1:" + Constants.BASE_URL
    private val cache get() = app.getSharedPreferences("public_brand_theme", Context.MODE_PRIVATE)
    private val ticker = object : Runnable {
        override fun run() { if (activities.isNotEmpty()) { refresh(); main.postDelayed(this, 60_000) } }
    }
    fun install(application: Application) {
        app = application
        theme = runCatching { JSONObject(cache.getString(cacheKey, "") ?: "").takeIf { valid(it) } }.getOrNull()
        app.registerActivityLifecycleCallbacks(this)
    }
    private fun valid(data: JSONObject): Boolean = runCatching {
        require(data.getInt("schema_version") == 1)
        require(data.getString("version").matches(Regex("[0-9a-f]{64}")))
        for (mode in listOf("light", "dark")) {
            val p = data.getJSONObject("modes").getJSONObject(mode)
            for (key in listOf("primary", "on_primary", "primary_text", "background", "surface", "on_surface", "outline")) require(p.getString(key).matches(Regex("#[0-9A-Fa-f]{6}")))
            fun c(key: String) = Color.parseColor(p.getString(key))
            require(ColorUtils.calculateContrast(c("on_primary"), c("primary")) >= 4.5)
            for (surface in listOf("background", "surface")) require(ColorUtils.calculateContrast(c("primary_text"), c(surface)) >= 4.5)
            require(ColorUtils.calculateContrast(c("on_surface"), c("surface")) >= 4.5)
            require(ColorUtils.calculateContrast(c("outline"), c("surface")) >= 3.0)
        }
        true
    }.getOrDefault(false)
    fun color(context: Context, token: String): Int {
        val dark = context.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK == Configuration.UI_MODE_NIGHT_YES
        val saved = theme?.optJSONObject("modes")?.optJSONObject(if (dark) "dark" else "light")?.optString(token)
        if (!saved.isNullOrBlank()) return Color.parseColor(saved)
        // Neutral accessible offline seed, used only until a valid API/cached theme exists.
        return when (token) {
            "primary" -> Color.rgb(40, 123, 255)
            "on_primary" -> Color.BLACK
            "background", "surface" -> if (dark) Color.rgb(15,23,42) else Color.WHITE
            else -> if (dark) Color.WHITE else Color.BLACK
        }
    }
    // site-info carries the same validated public palette; reuse its startup response.
    fun acceptSiteTheme(data: JSONObject) {
        if (!valid(data)) return
        theme = data
        cache.edit().putString(cacheKey, data.toString()).apply()
        activities.toList().forEach { apply(it) }
        externalRoots.toList().filter { it.isAttachedToWindow }.forEach { paint(it) }
    }
    fun primary(context: Context) = color(context, "primary")
    fun onPrimary(context: Context) = color(context, "on_primary")
    fun ink(context: Context) = color(context, "primary_text")
    // PDF paper is white even when the device is in dark mode.
    fun printInk(): Int = theme?.optJSONObject("modes")?.optJSONObject("light")?.optString("primary_text")?.takeIf { it.matches(Regex("#[0-9A-Fa-f]{6}")) }?.let { Color.parseColor(it) } ?: Color.BLACK
    fun mark(view: View, roles: String) { view.tag = "brand:" + roles }
    fun refresh() {
        val now = android.os.SystemClock.elapsedRealtime()
        if (inFlight || (lastAttempt != 0L && now-lastAttempt < 60_000)) return
        val url = Constants.BASE_URL.trimEnd('/') + "/web/api/theme.php"
        if (!url.startsWith("https://")) return
        lastAttempt = now; inFlight = true
        client.newCall(Request.Builder().url(url).header("Cache-Control", "no-cache").build()).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) { main.post { inFlight = false } }
            override fun onResponse(call: Call, response: Response) {
                val data = response.use { res -> if (!res.isSuccessful) null else runCatching {
                    val body = JSONObject(res.body?.string() ?: "")
                    if (body.optString("status") == "success") body.getJSONObject("data").takeIf { valid(it) } else null
                }.getOrNull() }
                main.post {
                    inFlight = false
                    if (data != null) {
                        theme = data; cache.edit().putString(cacheKey, data.toString()).apply()
                        activities.toList().forEach { apply(it) }
                        externalRoots.toList().filter { it.isAttachedToWindow }.forEach { paint(it) }
                    }
                }
            }
        })
    }
    fun applyView(view: View) {
        externalRoots.add(view); paint(view)
        for(id in listOf(android.R.id.button1, android.R.id.button2, android.R.id.button3)) view.findViewById<TextView>(id)?.let { mark(it, "text=primary_text"); paint(it) }
    }
    private fun paint(view: View, parentBrand: Boolean = false) {
        val roles = ((view.tag as? String)?.takeIf { it.startsWith("brand:") }?.removePrefix("brand:") ?: "")
            .split(';').mapNotNull { val parts=it.split('='); if(parts.size==2) parts[0] to parts[1] else null }.toMap()
        val onBrand = if (roles.containsKey("bg")) roles["bg"] == "primary" else parentBrand
        if (view is TextView && onBrand && (view.currentTextColor == Color.WHITE || roles["text"] == "on_primary")) inheritedInk[view] = true
        val signature = (theme?.optString("version") ?: "seed") + ":" + view.resources.configuration.uiMode + ":" + roles + ":" + onBrand
        if (applied[view] != signature) {
            applied[view] = signature
            fun c(token: String) = color(view.context, token)
            roles["bg"]?.let { token ->
                if (view is MaterialCardView) view.setCardBackgroundColor(c(token))
                else if (view is androidx.cardview.widget.CardView) view.setCardBackgroundColor(c(token))
                else view.backgroundTintList = ColorStateList.valueOf(c(token))
            }
            if (view is TextView) {
                val token = if(onBrand && (roles["text"] == "primary_text" || inheritedInk[view] == true)) "on_primary" else roles["text"]
                token?.let { view.setTextColor(c(it)) }
            }
            if (view is ImageView) roles["icon"]?.let { view.imageTintList = ColorStateList.valueOf(c(if(onBrand) "on_primary" else it)) }
            if (view is MaterialButton && roles.containsKey("button")) {
                val outlined = roles["button"] == "outline"
                view.setTextColor(c(if (outlined) "primary_text" else "on_primary"))
                view.iconTint = ColorStateList.valueOf(c(if(outlined) "primary_text" else "on_primary"))
                view.backgroundTintList = ColorStateList.valueOf(if(outlined) Color.TRANSPARENT else c("primary"))
                view.strokeColor = ColorStateList.valueOf(c("outline"))
                view.strokeWidth = maxOf(view.strokeWidth, view.resources.displayMetrics.density.toInt().coerceAtLeast(1))
            }
            if (view is android.widget.CompoundButton) {
                view.buttonTintList = ColorStateList(arrayOf(intArrayOf(android.R.attr.state_checked), intArrayOf()), intArrayOf(c("primary_text"), c("on_surface")))
            }
            if (view is android.widget.ProgressBar) view.indeterminateTintList = ColorStateList.valueOf(c(if(onBrand) "on_primary" else "primary_text"))
            if (view is com.google.android.material.textfield.TextInputLayout) {
                view.boxStrokeColor = c("primary_text")
                view.setStartIconTintList(ColorStateList.valueOf(c("primary_text")))
                view.setEndIconTintList(ColorStateList.valueOf(c("primary_text")))
            }
            if (view is BottomNavigationView) {
                val states = ColorStateList(arrayOf(intArrayOf(android.R.attr.state_checked), intArrayOf()), intArrayOf(c("primary_text"), c("on_surface")))
                view.itemIconTintList = ColorStateList(arrayOf(intArrayOf(android.R.attr.state_checked), intArrayOf()), intArrayOf(c("on_primary"), c("on_surface")))
                view.itemTextColor = states
                view.itemActiveIndicatorColor = ColorStateList.valueOf(c("primary"))
                view.setBackgroundColor(c("surface"))
            }
        }
        if (view is ViewGroup) for (i in 0 until view.childCount) paint(view.getChildAt(i), onBrand)
    }
    fun applyNow(activity: Activity) { apply(activity) }
    private fun apply(activity: Activity) {
        paint(activity.window.decorView)
        val surface = color(activity, if (activity is com.mzeevtu.ui.splash.SplashActivity) "primary" else "surface")
        if (activity is com.mzeevtu.ui.splash.SplashActivity && barColors[activity] != surface) activity.window.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(surface))
        if(activity.window.statusBarColor != surface) activity.window.statusBarColor = surface
        if(activity.window.navigationBarColor != surface) activity.window.navigationBarColor = surface
        if (barColors[activity] != surface) {
            barColors[activity] = surface
            val darkInk = ColorUtils.calculateLuminance(surface) > .5
            WindowInsetsControllerCompat(activity.window, activity.window.decorView).apply { isAppearanceLightStatusBars = darkInk; isAppearanceLightNavigationBars = darkInk }
        }
    }
    override fun onActivityCreated(activity: Activity, state: Bundle?) {
        val listener = android.view.ViewTreeObserver.OnGlobalLayoutListener { apply(activity) }
        listeners[activity] = listener; activity.window.decorView.viewTreeObserver.addOnGlobalLayoutListener(listener)
    }
    override fun onActivityResumed(activity: Activity) { if (RequiredSetup.route(activity)) return; activities.add(activity); apply(activity); refresh(); main.removeCallbacks(ticker); main.postDelayed(ticker,60_000) }
    override fun onActivityPaused(activity: Activity) { activities.remove(activity); if(activities.isEmpty()) main.removeCallbacks(ticker) }
    override fun onActivityDestroyed(activity: Activity) { activities.remove(activity); listeners.remove(activity)?.let { if(activity.window.decorView.viewTreeObserver.isAlive) activity.window.decorView.viewTreeObserver.removeOnGlobalLayoutListener(it) } }
    override fun onActivityStarted(activity: Activity) {}
    override fun onActivityStopped(activity: Activity) {}
    override fun onActivitySaveInstanceState(activity: Activity, state: Bundle) {}
}
