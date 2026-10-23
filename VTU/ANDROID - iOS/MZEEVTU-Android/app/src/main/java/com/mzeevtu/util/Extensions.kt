package com.mzeevtu.util

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.navigation.NavController
import androidx.navigation.NavDirections
import com.google.android.material.snackbar.Snackbar
import java.text.NumberFormat
import java.util.Locale

fun View.show() { visibility = View.VISIBLE }
fun View.hide() { visibility = View.GONE }
fun View.invisible() { visibility = View.INVISIBLE }

fun Double.toNaira(): String {
    val fmt = NumberFormat.getInstance(Locale.US)
    fmt.minimumFractionDigits = 2
    fmt.maximumFractionDigits = 2
    return "\u20A6${fmt.format(this)}"
}

fun Context.showToast(msg: String) = Toast.makeText(this, msg, Toast.LENGTH_SHORT).show()

fun View.showSnack(msg: String, action: String? = null, onAction: (() -> Unit)? = null) {
    val s = Snackbar.make(this, msg, Snackbar.LENGTH_LONG)
    if (action != null && onAction != null) s.setAction(action) { onAction() }
    s.show()
}

fun Context.copyToClipboard(label: String, text: String) {
    val cm = getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
    cm.setPrimaryClip(ClipData.newPlainText(label, text))
    showToast("Copied to clipboard")
}

/**
 * Safe wrapper around [NavController.navigate] to prevent crashes caused by rapid/double taps
 * or stale click handlers that trigger navigation after the destination has already changed.
 */
fun NavController.safeNavigate(resId: Int, args: Bundle? = null) {
    try {
        navigate(resId, args)
    } catch (_: IllegalArgumentException) {}
}

fun NavController.safeNavigate(directions: NavDirections) {
    try {
        navigate(directions)
    } catch (_: IllegalArgumentException) {}
}

/**
 * A human-readable reason the endpoint's reply is unusable, or null when it is safe to render.
 *
 * This exists because the failure is otherwise invisible. If an endpoint file has not been deployed,
 * the request 404s, the body is null, and every field on the screen renders blank — which looks like
 * a broken screen rather than a missing server file. That is exactly how it was misdiagnosed once:
 * the referral code showed as "—" and the Copy/Share buttons stayed disabled.
 */
fun endpointError(httpOk: Boolean, httpCode: Int, body: Map<String, Any>?): String? {
    if (!httpOk) {
        return if (httpCode == 404) {
            "This feature is not available on the server yet (HTTP 404). " +
                "The endpoint may not have been uploaded."
        } else {
            "The server returned an error (HTTP $httpCode). Please try again shortly."
        }
    }
    val status = (body?.get("status") as? String)?.lowercase()
    if (status != "success") {
        return (body?.get("message") as? String) ?: "The server did not return any data."
    }
    return null
}
