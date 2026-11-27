package com.mzeevtu.service

import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.util.Constants
import com.mzeevtu.util.PreferenceManager
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * Handles incoming FCM push notifications and FCM token refresh events.
 *
 * Push notification format expected from the server:
 *   { "data": { "type": "app_update" } }
 *
 * When type == "app_update", the service triggers AppUpdateManager to check
 * for a new APK version and show an update dialog on the next foreground launch.
 *
 * Token lifecycle:
 *   - onNewToken() is called when FCM issues a new registration token.
 *   - The token is posted to web/api/register-device.php so the admin can
 *     broadcast push notifications to all registered devices.
 */
class AppFirebaseMessagingService : FirebaseMessagingService() {

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        val prefs = PreferenceManager(applicationContext)
        prefs.saveString(Constants.KEY_FCM_TOKEN, token)
        // Only register server-side when the user is logged in
        if (prefs.isLoggedIn()) {
            registerTokenWithServer(token, prefs.getApiKey())
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val type = message.data["type"] ?: return
        if (type == "app_update") {
            // Persist a flag so that when the user next opens the app, the
            // update dialog is shown from MainActivity / SplashActivity.
            PreferenceManager(applicationContext)
                .saveBoolean(Constants.KEY_PENDING_UPDATE_CHECK, true)
        } else if (type == "kyc_status" || type == "kyb_status") {
            val prefs = PreferenceManager(applicationContext)
            prefs.saveBoolean("has_unread_compliance_notif", true)
            message.data["kyc_status"]?.toIntOrNull()?.let { st ->
                prefs.saveBoolean("kyc_gate_locked", st != 2)
            }
            sendBroadcast(
                android.content.Intent(com.mzeevtu.util.RequiredSetup.ACTION_COMPLIANCE_UPDATE)
                    .setPackage(packageName)
            )
            showComplianceNotification(
                message.data["title"] ?: message.notification?.title ?: "Compliance Update",
                message.data["body"] ?: message.notification?.body ?: "Your verification status has been updated."
            )
        }
    }

    private fun showComplianceNotification(title: String, body: String) {
        try {
            if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.TIRAMISU &&
                androidx.core.content.ContextCompat.checkSelfPermission(
                    applicationContext,
                    android.Manifest.permission.POST_NOTIFICATIONS
                ) != android.content.pm.PackageManager.PERMISSION_GRANTED
            ) return
            val nm = getSystemService(android.content.Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
            val chId = "compliance_updates"
            if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.O) {
                nm.createNotificationChannel(
                    android.app.NotificationChannel(
                        chId,
                        "Compliance & KYC Updates",
                        android.app.NotificationManager.IMPORTANCE_HIGH
                    )
                )
            }
            val intent = android.content.Intent(applicationContext, com.mzeevtu.ui.MainActivity::class.java)
                .addFlags(android.content.Intent.FLAG_ACTIVITY_CLEAR_TOP or android.content.Intent.FLAG_ACTIVITY_SINGLE_TOP)
            val pi = android.app.PendingIntent.getActivity(
                applicationContext, 3001, intent,
                android.app.PendingIntent.FLAG_UPDATE_CURRENT or android.app.PendingIntent.FLAG_IMMUTABLE
            )
            val notif = androidx.core.app.NotificationCompat.Builder(applicationContext, chId)
                .setSmallIcon(com.mzeevtu.R.drawable.ic_home)
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(androidx.core.app.NotificationCompat.BigTextStyle().bigText(body))
                .setAutoCancel(true)
                .setContentIntent(pi)
                .build()
            nm.notify((System.currentTimeMillis() % 100000).toInt(), notif)
        } catch (_: Exception) { }
    }

    private fun registerTokenWithServer(token: String, apiKey: String) {
        if (apiKey.isEmpty()) return
        serviceScope.launch {
            try {
                RetrofitClient.getService().registerDevice(
                    mapOf("api_key" to apiKey, "fcm_token" to token)
                )
            } catch (_: Exception) {
                // Silent — token will be re-registered on next login
            }
        }
    }
}
