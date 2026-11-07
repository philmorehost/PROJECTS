package com.datagifting.app.util

object Constants {
    const val BASE_URL = "https://v6.datagifting.com.ng/"
    const val APP_SOURCE_HEADER = "dgv6-android"

    // Shared Prefs keys
    const val PREF_NAME = "dg_secure_prefs"
    const val KEY_API_KEY = "api_key"
    const val KEY_IS_LOGGED_IN = "is_logged_in"
    const val KEY_USERNAME = "username"
    const val KEY_FIRSTNAME = "firstname"
    const val KEY_LASTNAME = "lastname"
    const val KEY_EMAIL = "email"
    const val KEY_PHONE = "phone"
    const val KEY_BALANCE = "balance"
    const val KEY_ACCOUNT_LEVEL = "account_level"
    const val KEY_PIN_SET = "security_pin_set"
    const val KEY_PIN_REQUIRED = "pin_required"
    const val KEY_PRIMARY_COLOR = "primary_color"
    const val KEY_LOGO_URL = "logo_url"
    const val KEY_SITE_TITLE = "site_title"
    const val KEY_SUPPORT_EMAIL = "support_email"
    const val KEY_SUPPORT_WHATSAPP = "support_whatsapp"
    const val KEY_BIOMETRIC_ENABLED = "biometric_enabled"
    const val KEY_DARK_MODE = "dark_mode"

    // Security question lock screen
    const val KEY_SECURITY_VERIFIED_AT = "security_verified_at"
    const val KEY_HAS_SECURITY_QUEST = "has_security_quest"
    const val LOCK_TIMEOUT_MINUTES = 5L

    // Notification
    const val CHANNEL_BULK = "bulk_transactions"
    const val WORK_BULK_POLL = "bulk_poll_work"

    // FCM / Push Notifications
    const val KEY_FCM_TOKEN = "fcm_token"
    const val KEY_PENDING_UPDATE_CHECK = "pending_update_check"
    const val FCM_TOPIC_APP_UPDATES = "app_updates"

    // Service Control
    const val KEY_ENABLED_SERVICES = "enabled_services"
}
