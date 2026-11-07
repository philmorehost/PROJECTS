package com.mzeevtu.util

import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.Uri
import android.os.Build
import android.os.Environment
import androidx.core.content.pm.PackageInfoCompat
import androidx.core.content.FileProvider
import com.mzeevtu.api.RetrofitClient
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

/**
 * Checks the server for a newer APK version and, if found, prompts the user to
 * download and install it.  The APK file is hosted in the cPanel file manager
 * at a URL returned by web/api/app-update.php.
 *
 * Usage:
 *   AppUpdateManager(context).checkForUpdate(scope)
 */
class AppUpdateManager(private val context: Context) {

    fun checkForUpdate(scope: CoroutineScope) {
        scope.launch(Dispatchers.IO) {
            try {
                val resp = RetrofitClient.getService().checkAppUpdate()
                if (!resp.isSuccessful) return@launch
                val body = resp.body() ?: return@launch
                if ((body["status"] as? String) != "update_available") return@launch

                val latestCode = (body["version_code"] as? Number)?.toInt() ?: return@launch
                val latestName = body["version_name"] as? String ?: return@launch
                val apkUrl = body["apk_url"] as? String ?: return@launch
                val changelog = body["changelog"] as? String ?: ""

                @Suppress("DEPRECATION")
                val packageInfo = context.packageManager
                    .getPackageInfo(context.packageName, 0)
                val currentCode = PackageInfoCompat.getLongVersionCode(packageInfo).toInt()

                if (latestCode > currentCode) {
                    withContext(Dispatchers.Main) {
                        promptUpdate(latestName, apkUrl, changelog)
                    }
                }
            } catch (_: Exception) {
                // Silent Ã¢â¬” do not disrupt the user experience on network errors
            }
        }
    }

    private fun promptUpdate(versionName: String, apkUrl: String, changelog: String) {
        val message = buildString {
            append("Version $versionName is available.\n")
            if (changelog.isNotEmpty()) append("\nWhat's new:\n$changelog")
        }
        MaterialAlertDialogBuilder(context)
            .setTitle("App Update Available")
            .setMessage(message)
            .setPositiveButton("Update Now") { _, _ ->
                val appId = context.packageName
                try {
                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=$appId")).apply {
                        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    }
                    context.startActivity(intent)
                } catch (e: Exception) {
                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse("https://play.google.com/store/apps/details?id=$appId")).apply {
                        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    }
                    context.startActivity(intent)
                }
            }
            .setNegativeButton("Later", null)
            .show()
    }
}
