package com.mzeevtu.util

import android.app.Activity
import com.google.android.play.core.appupdate.AppUpdateManagerFactory
import com.google.android.play.core.install.InstallStateUpdatedListener
import com.google.android.play.core.install.model.AppUpdateType
import com.google.android.play.core.install.model.InstallStatus
import com.google.android.play.core.install.model.UpdateAvailability
import java.util.concurrent.TimeUnit

/**
 * Play In-App Updates, checked at most once a week.
 *
 * WHY POLL AT ALL
 *   Google Play already knows when a new version is live; nothing is pushed from here. The app
 *   asks Play "is there a newer version of my package?" and hands off to Play's own update UI
 *   when the answer is yes. No Firebase, no APK hosting, no version manifest to maintain.
 *
 * WHY WEEKLY
 *   Every check is a network round trip and a possible dialog. Gating on a stored timestamp keeps
 *   it to at most one check per 7 days per install, so a user who deliberately stays on the
 *   current version is not nagged on every launch.
 *
 * Play-installed builds only
 *   For an APK sideloaded from the website, Play has no record of the install and always reports
 *   UPDATE_NOT_AVAILABLE, so this does nothing on those devices. The server-driven flow in
 *   web/api/app-update.php remains the path for APK builds.
 *
 * Usage (from an Activity's onCreate, after setContentView):
 *   AppUpdateChecker.check(this)
 */
object AppUpdateChecker {

    /** Preference key holding the epoch-millis of the last update check. */
    const val KEY_LAST_UPDATE_CHECK = "last_update_check_at"

    private val CHECK_INTERVAL_MS = TimeUnit.DAYS.toMillis(7)

    /** Kept alive for the lifetime of the process so Play can report download progress. */
    private var installListener: InstallStateUpdatedListener? = null

    /**
     * Runs a weekly update check. Safe to call on every launch - returns immediately while the
     * 7-day window is still open, and never throws into the caller.
     *
     * @param force ignores the stored timestamp (e.g. a "Check for updates" menu action).
     */
    fun check(activity: Activity, force: Boolean = false) {
        try {
            val prefs = PreferenceManager(activity)
            val now = System.currentTimeMillis()
            val last = prefs.getLong(KEY_LAST_UPDATE_CHECK, 0L)

            if (!force && last > 0L && now - last < CHECK_INTERVAL_MS) return

            // Stamp BEFORE the network call: a failing check must not turn into a retry on every
            // launch, which would be worse for the user than waiting another week.
            prefs.saveLong(KEY_LAST_UPDATE_CHECK, now)

            val manager = AppUpdateManagerFactory.create(activity)

            val existing = installListener
            if (existing == null) {
                val listener = InstallStateUpdatedListener { state ->
                    // FLEXIBLE downloads in the background; when it reaches DOWNLOADED Play shows
                    // its own "update ready to install" prompt, so there is nothing to do here.
                    if (state.installStatus() == InstallStatus.DOWNLOADED) {
                        manager.completeUpdate()
                    }
                }
                installListener = listener
                manager.registerListener(listener)
            }

            manager.appUpdateInfo
                .addOnSuccessListener { info ->
                    if (info.updateAvailability() != UpdateAvailability.UPDATE_AVAILABLE) return@addOnSuccessListener
                    if (activity.isFinishing || activity.isDestroyed) return@addOnSuccessListener

                    // IMMEDIATE blocks the user until they update - correct for versions Play
                    // flags with a high update priority (security fixes). Otherwise stay FLEXIBLE.
                    val type = if (info.isUpdateTypeAllowed(AppUpdateType.IMMEDIATE)) {
                        AppUpdateType.IMMEDIATE
                    } else {
                        AppUpdateType.FLEXIBLE
                    }
                    try {
                        manager.startUpdateFlowForResult(info, type, activity, REQUEST_CODE)
                    } catch (_: Exception) {
                        // Play may refuse (e.g. activity not resumed). Never crash the screen.
                    }
                }
                .addOnFailureListener {
                    // Play services unavailable or offline: stay silent, try again next week.
                }
        } catch (_: Exception) {
            // Never let an update check break app start-up.
        }
    }

    /**
     * Call from onResume: resumes an IMMEDIATE update the user interrupted, so the old code
     * cannot keep running silently.
     */
    fun resumePendingImmediateUpdate(activity: Activity) {
        try {
            val manager = AppUpdateManagerFactory.create(activity)
            manager.appUpdateInfo.addOnSuccessListener { info ->
                if (activity.isFinishing || activity.isDestroyed) return@addOnSuccessListener
                if (info.updateAvailability() == UpdateAvailability.DEVELOPER_TRIGGERED_UPDATE_IN_PROGRESS) {
                    try {
                        manager.startUpdateFlowForResult(info, AppUpdateType.IMMEDIATE, activity, REQUEST_CODE)
                    } catch (_: Exception) {
                    }
                }
            }
        } catch (_: Exception) {
        }
    }

    /** Request code passed to startUpdateFlowForResult. */
    const val REQUEST_CODE = 21501
}