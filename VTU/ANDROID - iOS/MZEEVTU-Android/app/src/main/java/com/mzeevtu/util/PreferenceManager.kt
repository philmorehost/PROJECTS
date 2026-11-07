package com.mzeevtu.util

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

class PreferenceManager(context: Context) {

    private val prefs: SharedPreferences = run {
        val masterKey = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        EncryptedSharedPreferences.create(
            context,
            Constants.PREF_NAME,
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
    }

    fun saveString(key: String, value: String) = prefs.edit().putString(key, value).apply()
    fun getString(key: String, default: String = "") = prefs.getString(key, default) ?: default

    fun saveBoolean(key: String, value: Boolean) = prefs.edit().putBoolean(key, value).apply()
    fun getBoolean(key: String, default: Boolean = false) = prefs.getBoolean(key, default)

    fun saveDouble(key: String, value: Double) = prefs.edit().putString(key, value.toString()).apply()
    fun getDouble(key: String, default: Double = 0.0) = prefs.getString(key, default.toString())?.toDoubleOrNull() ?: default

    fun saveLong(key: String, value: Long) = prefs.edit().putLong(key, value).apply()
    fun getLong(key: String, default: Long = 0L) = prefs.getLong(key, default)

    fun isLoggedIn() = getBoolean(Constants.KEY_IS_LOGGED_IN, false)
    fun getApiKey() = getString(Constants.KEY_API_KEY)

    fun saveLoginData(
        apiKey: String, username: String, firstname: String, lastname: String,
        email: String, phone: String, balance: Double, accountLevel: Int, pinSet: Boolean
    ) {
        prefs.edit().apply {
            putString(Constants.KEY_API_KEY, apiKey)
            putString(Constants.KEY_USERNAME, username)
            putString(Constants.KEY_FIRSTNAME, firstname)
            putString(Constants.KEY_LASTNAME, lastname)
            putString(Constants.KEY_EMAIL, email)
            putString(Constants.KEY_PHONE, phone)
            putString(Constants.KEY_BALANCE, balance.toString())
            putString(Constants.KEY_ACCOUNT_LEVEL, accountLevel.toString())
            putBoolean(Constants.KEY_PIN_SET, pinSet)
            putBoolean(Constants.KEY_IS_LOGGED_IN, true)
        }.apply()
    }

    fun clear() = prefs.edit().clear().apply()

    fun saveEnabledServices(services: Set<String>) =
        prefs.edit().putStringSet(Constants.KEY_ENABLED_SERVICES, services).apply()

    fun getEnabledServices(): Set<String> =
        prefs.getStringSet(Constants.KEY_ENABLED_SERVICES, emptySet()) ?: emptySet()

    fun saveAiVoiceStatus(status: Int) = prefs.edit().putInt("ai_voice_status", status).apply()
    fun getAiVoiceStatus(): Int = prefs.getInt("ai_voice_status", 0)

    fun saveTrustScore(score: Int) = prefs.edit().putInt("ai_trust_score", score).apply()
    fun getTrustScore(): Int = prefs.getInt("ai_trust_score", 50)
}
