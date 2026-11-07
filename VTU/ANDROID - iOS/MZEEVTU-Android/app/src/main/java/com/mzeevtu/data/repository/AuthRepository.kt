package com.mzeevtu.data.repository

import android.content.Context
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.data.model.ApiResult
import com.mzeevtu.util.PreferenceManager

class AuthRepository(private val context: Context) {

    private val api = RetrofitClient.getService()
    private val prefs = PreferenceManager(context)

    suspend fun login(username: String, password: String): ApiResult<Map<String, Any>> {
        return try {
            val response = api.login(mapOf("user" to username, "pass" to password))
            if (response.isSuccessful) {
                val body = response.body() ?: return ApiResult.Error("Empty response")
                val status = body["status"] as? String ?: "error"
                if (status == "success") {
                    @Suppress("UNCHECKED_CAST")
                    val data = body["data"] as? Map<String, Any> ?: emptyMap()
                    prefs.saveLoginData(
                        apiKey = data["api_key"] as? String ?: "",
                        username = data["username"] as? String ?: "",
                        firstname = data["firstname"] as? String ?: "",
                        lastname = data["lastname"] as? String ?: "",
                        email = data["email"] as? String ?: "",
                        phone = data["phone"] as? String ?: "",
                        balance = (data["balance"] as? String)?.toDoubleOrNull() ?: 0.0,
                        accountLevel = (data["account_level"] as? Double)?.toInt() ?: 1,
                        pinSet = data["security_pin_set"] as? Boolean ?: false
                    )
                    ApiResult.Success(data)
                } else {
                    ApiResult.Error(body["message"] as? String ?: "Login failed")
                }
            } else {
                ApiResult.Error("Server error: ${response.code()}")
            }
        } catch (e: Exception) {
            ApiResult.Error(e.message ?: "Network error")
        }
    }

    suspend fun register(params: Map<String, Any>): ApiResult<String> {
        return try {
            val response = api.register(params)
            if (response.isSuccessful) {
                val body = response.body() ?: return ApiResult.Error("Empty response")
                val status = body["status"] as? String ?: "error"
                if (status == "success") {
                    ApiResult.Success(body["message"] as? String ?: "Registration successful")
                } else {
                    ApiResult.Error(body["message"] as? String ?: "Registration failed")
                }
            } else {
                ApiResult.Error("Server error: ${response.code()}")
            }
        } catch (e: Exception) {
            ApiResult.Error(e.message ?: "Network error")
        }
    }

    fun logout() = prefs.clear()
}
