package com.mzeevtu.data.repository

import android.content.Context
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.data.model.ApiResult
import com.mzeevtu.util.PreferenceManager

class ServicesRepository(private val context: Context) {

    private val api = RetrofitClient.getService()
    private val prefs = PreferenceManager(context)
    private val apiKey get() = prefs.getApiKey()

    suspend fun getProfile(): ApiResult<Map<String, Any>> {
        return try {
            val response = api.getProfile(mapOf("api_key" to apiKey))
            if (response.isSuccessful) {
                val body = response.body() ?: return ApiResult.Error("Empty response")
                val status = body["status"] as? String
                if (status == "success") {
                    @Suppress("UNCHECKED_CAST")
                    val data = body["data"] as? Map<String, Any> ?: emptyMap()
                    // Refresh balance in prefs
                    val bal = (data["balance"] as? String)?.toDoubleOrNull() ?: 0.0
                    prefs.saveDouble(com.mzeevtu.util.Constants.KEY_BALANCE, bal)
                    ApiResult.Success(data)
                } else {
                    ApiResult.Error(body["message"] as? String ?: "Failed")
                }
            } else ApiResult.Error("Server error: ${response.code()}")
        } catch (e: Exception) {
            ApiResult.Error(e.message ?: "Network error")
        }
    }

    suspend fun getTransactions(limit: Int = 50, offset: Int = 0): ApiResult<List<*>> {
        return try {
            val response = api.getTransactions(mapOf("api_key" to apiKey, "limit" to limit, "offset" to offset))
            if (response.isSuccessful) {
                val body = response.body() ?: return ApiResult.Error("Empty response")
                @Suppress("UNCHECKED_CAST")
                val data = body["data"] as? List<*> ?: emptyList<Any>()
                ApiResult.Success(data)
            } else ApiResult.Error("Server error: ${response.code()}")
        } catch (e: Exception) {
            ApiResult.Error(e.message ?: "Network error")
        }
    }

    suspend fun getEnabledServices(): ApiResult<Set<String>> {
        return try {
            val response = api.getServices(mapOf("api_key" to apiKey))
            if (response.isSuccessful) {
                val body = response.body() ?: return ApiResult.Error("Empty response")
                @Suppress("UNCHECKED_CAST")
                val list = body["services"] as? List<String> ?: emptyList()
                val set = list.toSet()
                prefs.saveEnabledServices(set)
                ApiResult.Success(set)
            } else ApiResult.Error("Server error: ${response.code()}")
        } catch (e: Exception) {
            ApiResult.Error(e.message ?: "Network error")
        }
    }

    suspend fun genericPost(
        endpoint: suspend (Map<String, Any>) -> retrofit2.Response<Map<String, Any>>,
        body: Map<String, Any>
    ): ApiResult<Map<String, Any>> {
        return try {
            val fullBody = body.toMutableMap().also { it["api_key"] = apiKey }
            val response = endpoint(fullBody)
            if (response.isSuccessful) {
                val rb = response.body() ?: return ApiResult.Error("Empty response")
                val status = rb["status"] as? String ?: rb["status_msg"] as? String ?: "failed"
                if (status == "success" || status.contains("success", ignoreCase = true)) {
                    ApiResult.Success(rb)
                } else {
                    ApiResult.Error(rb["message"] as? String ?: rb["desc"] as? String ?: "Transaction failed")
                }
            } else ApiResult.Error("Server error: ${response.code()}")
        } catch (e: Exception) {
            ApiResult.Error(e.message ?: "Network error")
        }
    }
}
