package com.payhub.guest.api

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
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Query

/** Talks to the PHP endpoints under web/guest-api/ — the stateless, unauthenticated Guest Mode backend. */
interface GuestApiService {

    @GET("web/guest-api/catalog.php")
    suspend fun getAirtimeCatalog(@Query("service") service: String = "airtime"): Response<AirtimeCatalogResponse>

    @GET("web/guest-api/catalog.php")
    suspend fun getDataCatalog(@Query("service") service: String = "data"): Response<DataCatalogResponse>

    @GET("web/guest-api/catalog.php")
    suspend fun getCableCatalog(@Query("service") service: String = "cable"): Response<CableCatalogResponse>

    @GET("web/guest-api/catalog.php")
    suspend fun getElectricCatalog(@Query("service") service: String = "electric"): Response<ElectricCatalogResponse>

    @GET("web/guest-api/catalog.php")
    suspend fun getExamCatalog(@Query("service") service: String = "exam"): Response<ExamCatalogResponse>

    @GET("web/guest-api/catalog.php")
    suspend fun getBettingCatalog(@Query("service") service: String = "betting"): Response<BettingCatalogResponse>

    @GET("web/guest-api/identify-network.php")
    suspend fun identifyNetwork(@Query("phone") phone: String): Response<NetworkDetectResponse>

    @POST("web/guest-api/verify.php")
    suspend fun verifyCustomer(@Body body: Map<String, @JvmSuppressWildcards Any?>): Response<VerifyCustomerResponse>

    @POST("web/guest-api/checkout-init.php")
    suspend fun initCheckout(@Body body: Map<String, @JvmSuppressWildcards Any?>): Response<CheckoutInitResponse>

    @GET("web/guest-api/status.php")
    suspend fun getOrderStatus(@Query("reference") reference: String): Response<GuestOrderStatusResponse>

    @GET("web/guest-api/site-info.php")
    suspend fun getSiteInfo(): Response<SiteInfoResponse>
}
