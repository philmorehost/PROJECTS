package com.mzeevtu

import android.app.Application
import android.content.Intent
import androidx.lifecycle.DefaultLifecycleObserver
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.ProcessLifecycleOwner
import com.mzeevtu.ui.security.LockActivity
import com.mzeevtu.util.Constants
import com.mzeevtu.util.PreferenceManager

/**
 * Observes whole-app foreground/background transitions (not per-Activity, since this is a
 * single-Activity nav-host app — per-Fragment onStart checks would be fragile/duplicated).
 * When the app returns to the foreground after being idle past Constants.LOCK_TIMEOUT_MINUTES,
 * shows LockActivity requiring the user re-answer their security question before continuing —
 * mirrors web/SecurityQuest.php's protection for a browser session, adapted for "phone left
 * unlocked on a table" instead of "shared desktop session."
 */
class DGApp : Application(), DefaultLifecycleObserver {

    override fun onCreate() {
        super<Application>.onCreate()
        ProcessLifecycleOwner.get().lifecycle.addObserver(this)
    }

    override fun onStart(owner: LifecycleOwner) {
        val prefs = PreferenceManager(this)
        if (!prefs.isLoggedIn()) return

        val lastVerified = prefs.getLong(Constants.KEY_SECURITY_VERIFIED_AT, 0L)
        val timeoutMs = Constants.LOCK_TIMEOUT_MINUTES * 60_000L
        val elapsed = System.currentTimeMillis() - lastVerified

        if (elapsed > timeoutMs) {
            startActivity(Intent(this, LockActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
            })
        }
    }
}
