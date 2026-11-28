package com.jikabiz.app.util

import android.content.Context
import android.content.Intent
import android.widget.Toast
import androidx.biometric.BiometricManager
import androidx.fragment.app.FragmentActivity
import com.jikabiz.app.ui.MainActivity
import com.jikabiz.app.ui.security.LockActivity
import com.jikabiz.app.ui.security.TotpSetupActivity
import com.google.android.material.dialog.MaterialAlertDialogBuilder

object OnboardingCoordinator {

    fun syncFlags(prefs: PreferenceManager, data: Map<String, Any>) {
        if (data.containsKey("security_pin_set")) {
            prefs.saveBoolean(Constants.KEY_PIN_SET, data["security_pin_set"] as? Boolean ?: false)
        }
        if (data.containsKey("pin_required")) {
            prefs.saveBoolean(Constants.KEY_PIN_REQUIRED, data["pin_required"] as? Boolean ?: false)
        }
        if (data.containsKey("totp_required")) {
            prefs.saveBoolean(Constants.KEY_TOTP_REQUIRED, data["totp_required"] as? Boolean ?: false)
        }
        if (data.containsKey("totp_enabled")) {
            val totp = (data["totp_enabled"] as? Number)?.toInt() == 1 || (data["totp_enabled"] as? Boolean) == true
            prefs.saveBoolean(Constants.KEY_TOTP_ENABLED, totp)
        }
        if (data.containsKey("security_quest_required")) {
            prefs.saveBoolean(Constants.KEY_SECURITY_QUEST_REQUIRED, data["security_quest_required"] as? Boolean ?: true)
        }
        if (data.containsKey("security_quest_set")) {
            val sqSet = data["security_quest_set"] as? Boolean ?: false
            prefs.saveBoolean(Constants.KEY_SECURITY_QUEST_SET, sqSet)
            prefs.saveBoolean(Constants.KEY_HAS_SECURITY_QUEST, sqSet)
        }
        if (data.containsKey("kyc_gate_locked")) {
            prefs.saveBoolean("kyc_gate_locked", data["kyc_gate_locked"] as? Boolean ?: false)
        } else if (data.containsKey("kyc_enforced") && data.containsKey("kyc_status")) {
            val enforced = data["kyc_enforced"] as? Boolean ?: false
            val approved = (data["kyc_status"] as? Number)?.toInt() == 2
            prefs.saveBoolean("kyc_gate_locked", enforced && !approved)
        }
    }

    fun needsTotp(prefs: PreferenceManager): Boolean =
        prefs.getBoolean(Constants.KEY_TOTP_REQUIRED, false) && !prefs.getBoolean(Constants.KEY_TOTP_ENABLED, false)

    fun isKycLocked(prefs: PreferenceManager): Boolean =
        prefs.getBoolean("kyc_gate_locked", false)

    fun needsPin(prefs: PreferenceManager): Boolean =
        !prefs.getBoolean(Constants.KEY_PIN_SET, false)

    fun needsSecurityQuest(prefs: PreferenceManager): Boolean {
        if (!prefs.getBoolean(Constants.KEY_SECURITY_QUEST_REQUIRED, true)) return false
        if (!prefs.getBoolean(Constants.KEY_SECURITY_QUEST_SET, false)) return true
        val last = prefs.getLong(Constants.KEY_SECURITY_VERIFIED_AT, 0L)
        return (System.currentTimeMillis() - last) > Constants.LOCK_TIMEOUT_MINUTES * 60_000L
    }

    fun nextActivityIntent(context: Context, prefs: PreferenceManager): Intent {
        return when {
            needsTotp(prefs) -> Intent(context, TotpSetupActivity::class.java)
            isKycLocked(prefs) -> Intent(context, MainActivity::class.java)
            needsPin(prefs) -> Intent(context, MainActivity::class.java)
            needsSecurityQuest(prefs) -> Intent(context, LockActivity::class.java)
            else -> Intent(context, MainActivity::class.java)
        }.apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        }
    }

    fun isOnboardingComplete(prefs: PreferenceManager): Boolean =
        !needsTotp(prefs) && !isKycLocked(prefs) && !needsPin(prefs) && !needsSecurityQuest(prefs)

    fun maybeOfferBiometric(activity: FragmentActivity, prefs: PreferenceManager) {
        if (!isOnboardingComplete(prefs)) return
        if (prefs.getBoolean(Constants.KEY_BIOMETRIC_ENABLED, false)) {
            if (prefs.getApiKey().isNotBlank()) {
                prefs.saveString(Constants.KEY_BIOMETRIC_SESSION_TOKEN, prefs.getApiKey())
                prefs.saveString(Constants.KEY_BIOMETRIC_USERNAME, prefs.getString(Constants.KEY_USERNAME))
            }
            return
        }
        if (prefs.getBoolean(Constants.KEY_BIOMETRIC_PROMPT_SHOWN, false)) return

        val bm = BiometricManager.from(activity)
        val canAuth = bm.canAuthenticate(
            BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL
        ) == BiometricManager.BIOMETRIC_SUCCESS
        if (!canAuth) return

        prefs.saveBoolean(Constants.KEY_BIOMETRIC_PROMPT_SHOWN, true)
        MaterialAlertDialogBuilder(activity)
            .setTitle("Enable Biometric Login?")
            .setMessage("Sign in faster next time using your fingerprint or face recognition.")
            .setPositiveButton("Enable Now") { _, _ ->
                prefs.saveBoolean(Constants.KEY_BIOMETRIC_ENABLED, true)
                prefs.saveString(Constants.KEY_BIOMETRIC_SESSION_TOKEN, prefs.getApiKey())
                prefs.saveString(Constants.KEY_BIOMETRIC_USERNAME, prefs.getString(Constants.KEY_USERNAME))
                Toast.makeText(activity, "Biometric login enabled", Toast.LENGTH_SHORT).show()
            }
            .setNegativeButton("Maybe Later", null)
            .show()
    }
}
