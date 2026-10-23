package com.payhub.guest.util

import android.content.Context
import android.content.Intent
import android.os.Process
import androidx.core.content.edit
import kotlin.system.exitProcess

/**
 * Guest Mode has no crash-reporting SDK wired in, and this app is often tested outside Android
 * Studio (signed release builds via CI), so a raw crash otherwise just silently kills the app
 * with nothing to go on. This captures the full stack trace, shows it in CrashActivity with a
 * copy-to-clipboard button, then exits — so the exact failure can be traced without logcat.
 */
object CrashHandler {
    private const val PREFS = "guest_crash_log"
    private const val KEY_LOG = "last_crash"

    fun install(appContext: Context) {
        Thread.setDefaultUncaughtExceptionHandler { _, throwable ->
            val trace = throwable.stackTraceToString()
            try {
                appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit { putString(KEY_LOG, trace) }
                val intent = Intent(appContext, CrashActivity::class.java).apply {
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
                    putExtra(CrashActivity.EXTRA_TRACE, trace)
                }
                appContext.startActivity(intent)
            } catch (_: Throwable) {
                // If we can't even show the crash screen, at least the log below the process
                // kill still lands wherever the OS captures killed-process output.
            }
            Process.killProcess(Process.myPid())
            exitProcess(10)
        }
    }

    fun lastCrash(context: Context): String? =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_LOG, null)

    fun clearLastCrash(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit { remove(KEY_LOG) }
    }
}
