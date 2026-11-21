package com.datagifting.app.data.repository

import com.google.gson.JsonParser

/** Preserve structured policy/OTP errors on non-2xx responses; never show raw HTML. */
internal object LoginHttpError {
    fun message(status: Int, body: String?): String {
        val detail = try {
            val json = JsonParser.parseString(body.orEmpty()).asJsonObject
            sequenceOf("message", "desc").mapNotNull { name ->
                json.get(name)?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isString }
                    ?.asString?.trim()?.takeIf { it.isNotEmpty() && it.length <= 500 && !it.contains('<') }
            }.firstOrNull()
        } catch (_: Exception) { null }
        return detail ?: when (status) {
            401 -> "Sign-in could not be verified. Please sign in again."
            403 -> "Sign-in was denied (HTTP 403). Please contact support."
            429 -> "Too many sign-in attempts. Please wait and try again."
            in 500..599 -> "The service is temporarily unavailable. Please try again shortly."
            else -> "Unable to sign in (HTTP $status). Please try again."
        }
    }
}
