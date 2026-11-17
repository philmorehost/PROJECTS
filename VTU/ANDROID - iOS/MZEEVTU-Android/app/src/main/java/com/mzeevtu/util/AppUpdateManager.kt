package com.mzeevtu.util

import android.content.Context
import kotlinx.coroutines.CoroutineScope

/**
 * Retired sideloaded-APK updater.
 *
 * Downloading an APK from our own server and firing an install intent is not permitted for a
 * Play-distributed app, and the install step could never succeed because REQUEST_INSTALL_PACKAGES
 * is not declared in the manifest. [AppUpdateChecker] replaces it with the Play In-App Updates
 * API, which is compliant and actually installs the update.
 *
 * The class is kept only so the call site in old code / push handlers still compiles; it does
 * nothing. Do not reintroduce an APK download-and-install path here.
 */
@Suppress("UNUSED_PARAMETER")
class AppUpdateManager(private val context: Context) {
    fun checkForUpdate(scope: CoroutineScope) = Unit
}