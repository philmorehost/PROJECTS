package com.payhub.guest.data

import android.content.Context
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.payhub.guest.data.model.GuestReceipt

/**
 * Guest Mode has no login/session, so there is nothing on the server to page through — history
 * is cached on-device only, as a JSON list in SharedPreferences, keyed by transaction reference.
 */
object GuestHistoryStore {
    private const val PREFS_NAME = "guest_history"
    private const val KEY_RECEIPTS = "receipts"
    private const val MAX_ENTRIES = 50

    private val gson = Gson()
    private val listType = object : TypeToken<List<GuestReceipt>>() {}.type

    fun load(context: Context): List<GuestReceipt> {
        val json = prefs(context).getString(KEY_RECEIPTS, null) ?: return emptyList()
        return try {
            gson.fromJson<List<GuestReceipt>>(json, listType)?.sortedByDescending { it.dateMillis } ?: emptyList()
        } catch (e: Exception) {
            emptyList()
        }
    }

    fun save(context: Context, receipt: GuestReceipt): List<GuestReceipt> {
        val deduped = load(context).filterNot { it.reference == receipt.reference }
        val updated = (listOf(receipt) + deduped).sortedByDescending { it.dateMillis }.take(MAX_ENTRIES)
        prefs(context).edit().putString(KEY_RECEIPTS, gson.toJson(updated)).apply()
        return updated
    }

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
}
