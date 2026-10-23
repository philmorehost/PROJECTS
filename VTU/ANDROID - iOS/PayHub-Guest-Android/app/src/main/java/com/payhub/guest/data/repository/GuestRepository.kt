package com.payhub.guest.data.repository

import com.payhub.guest.api.RetrofitClient
import com.payhub.guest.data.model.AirtimeCatalogResponse
import com.payhub.guest.data.model.BettingCatalogResponse
import com.payhub.guest.data.model.CableCatalogResponse
import com.payhub.guest.data.model.CheckoutInitResponse
import com.payhub.guest.data.model.DataCatalogResponse
import com.payhub.guest.data.model.ElectricCatalogResponse
import com.payhub.guest.data.model.ExamCatalogResponse
import com.payhub.guest.data.model.GuestOrderStatusResponse
import com.payhub.guest.data.model.NetworkDetectResponse
import com.payhub.guest.data.model.SiteInfoResponse
import com.payhub.guest.data.model.VerifyCustomerResponse
import retrofit2.Response
import java.io.IOException

/** Thin wrapper around GuestApiService — turns network/HTTP failures into ApiResult.Error
 *  uniformly so every ViewModel handles errors the same way. */
class GuestRepository {

    private val api = RetrofitClient.getService()

    suspend fun getAirtimeCatalog() = safeCall { api.getAirtimeCatalog() }
    suspend fun getDataCatalog() = safeCall { api.getDataCatalog() }
    suspend fun getCableCatalog() = safeCall { api.getCableCatalog() }
    suspend fun getElectricCatalog() = safeCall { api.getElectricCatalog() }
    suspend fun getExamCatalog() = safeCall { api.getExamCatalog() }
    suspend fun getBettingCatalog() = safeCall { api.getBettingCatalog() }

    suspend fun identifyNetwork(phone: String) = safeCall { api.identifyNetwork(phone) }

    suspend fun verifyCustomer(body: Map<String, Any?>) = safeCall { api.verifyCustomer(body) }

    suspend fun initCheckout(body: Map<String, Any?>) = safeCall { api.initCheckout(body) }

    suspend fun getOrderStatus(reference: String) = safeCall { api.getOrderStatus(reference) }

    suspend fun getSiteInfo() = safeCall { api.getSiteInfo() }

    private suspend fun <T> safeCall(block: suspend () -> Response<T>): ApiResult<T> {
        return try {
            val response = block()
            val body = response.body()
            if (response.isSuccessful && body != null) {
                ApiResult.Success(body)
            } else {
                ApiResult.Error(extractErrorMessage(response))
            }
        } catch (e: IOException) {
            ApiResult.Error("Network error — please check your connection and try again.")
        } catch (e: Exception) {
            ApiResult.Error(e.message ?: "Something went wrong. Please try again.")
        }
    }

    private fun <T> extractErrorMessage(response: Response<T>): String {
        return try {
            val errorBody = response.errorBody()?.string()
            if (!errorBody.isNullOrBlank()) {
                val obj = com.google.gson.JsonParser.parseString(errorBody).asJsonObject
                obj.get("desc")?.asString ?: "Request failed (${response.code()})"
            } else {
                "Request failed (${response.code()})"
            }
        } catch (e: Exception) {
            "Request failed (${response.code()})"
        }
    }
}
