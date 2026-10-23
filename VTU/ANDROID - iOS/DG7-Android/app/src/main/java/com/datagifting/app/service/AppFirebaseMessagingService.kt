package com.datagifting.app.service

import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.util.Constants
import com.datagifting.app.util.PreferenceManager
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
        }
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
