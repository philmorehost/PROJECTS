package com.mzeevtu.api

import retrofit2.Response
import retrofit2.http.*

interface VtuApiService {

    // Ã¢”â¬Ã¢”â¬ Auth Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/login.php")
    suspend fun login(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/register.php")
    suspend fun register(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ Profile & Site Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/profile.php")
    suspend fun getProfile(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/services.php")
    suspend fun getServices(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @GET("web/api/site-info.php")
    suspend fun getSiteInfo(): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ Transactions Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/transactions.php")
    suspend fun getTransactions(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/requery.php")
    suspend fun requeryTransaction(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ VTU Services Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/airtime.php")
    suspend fun purchaseAirtime(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/data.php")
    suspend fun purchaseData(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/cable.php")
    suspend fun purchaseCable(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/electric.php")
    suspend fun purchaseElectric(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/exam.php")
    suspend fun purchaseExam(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/sms.php")
    suspend fun sendBulkSms(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ Plans Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/airtime-plans.php")
    suspend fun getAirtimePlans(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/data-plans.php")
    suspend fun getDataPlans(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/cable-plans.php")
    suspend fun getCablePlans(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/electric-plans.php")
    suspend fun getElectricPlans(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/exam-plans.php")
    suspend fun getExamPlans(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/sms-plans.php")
    suspend fun getSmsPricePlans(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ Verification helpers Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/identify-network.php")
    suspend fun identifyNetwork(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/verify-cable.php")
    suspend fun verifyCable(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/verify-electric.php")
    suspend fun verifyElectric(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/verify-betting.php")
    suspend fun verifyBetting(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/betting-plans.php")
    suspend fun getBettingPlatforms(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/betting.php")
    suspend fun purchaseBetting(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/check-limit.php")
    suspend fun checkLimit(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/batch-status.php")
    suspend fun getBatchStatus(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/batch-list.php")
    suspend fun getBatchList(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ Print Hub (Data/Recharge Cards) Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @GET("web/api/databundle-card-plans.php")
    suspend fun getPrintCardPlans(@QueryMap params: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/databundle-card.php")
    suspend fun buyPrintCards(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ SMS Management Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/sms-sender-ids.php")
    suspend fun getSenderIds(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/submit-sender-id.php")
    suspend fun submitSenderId(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ Gift Cards Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/gift-card.php")
    suspend fun giftCardAction(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ Virtual Cards Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/virtual-card.php")
    suspend fun virtualCardAction(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ Crypto Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/crypto.php")
    suspend fun cryptoAction(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/exchange-rate.php")
    suspend fun getExchangeRate(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ Wallet Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/share-fund.php")
    suspend fun shareFund(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/withdrawal.php")
    suspend fun withdrawToBank(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/verify-bank.php")
    suspend fun verifyBank(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/virtual-banks.php")
    suspend fun getVirtualBanks(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/funding-config.php")
    suspend fun getFundingConfig(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/get-charges.php")
    suspend fun getCharges(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/create-checkout.php")
    suspend fun createCheckout(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/platform-banks.php")
    suspend fun getPlatformBanks(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/fund-manual.php")
    suspend fun notifyManualFund(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/verify-funding.php")
    suspend fun verifyFunding(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ Security Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/set-pin.php")
    suspend fun setPin(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/security-quest.php")
    suspend fun securityQuest(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ Contacts Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/contacts.php")
    suspend fun contactsAction(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ App Update Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @GET("web/api/app-update.php")
    suspend fun checkAppUpdate(): Response<Map<String, Any>>

    @POST("web/api/register-device.php")
    suspend fun registerDevice(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ NIN Card Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/nin-card.php")
    suspend fun lookupNIN(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ BVN Verify Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/bvn-verify.php")
    suspend fun verifyBVN(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ KYC Verification Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/api/kyc.php")
    suspend fun kycAction(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("web/api/kyc.php")
    suspend fun kycUpload(@Body body: okhttp3.MultipartBody): Response<Map<String, Any>>

    // Ã¢”â¬Ã¢”â¬ AI Assistant Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    @POST("web/ai-handler.php")
    suspend fun getAiResponse(
        @Query("context") context: String,
        @Body body: @JvmSuppressWildcards Map<String, Any>
    ): Response<Map<String, Any>>

    @POST("web/api/ai-budgeting.php")
    suspend fun getBudgetStats(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    @POST("api/app-backend/ai-handler")
    suspend fun parseAiIntent(
        @Header("Authorization") token: String,
        @Body body: Map<String, Any>
    ): Response<Map<String, Any>>


    @POST("api/app-backend/ai-vision")
    suspend fun parseAiVision(
        @Header("Authorization") token: String,
        @Body body: Map<String, Any>
    ): retrofit2.Response<Map<String, Any>>


    // ---- Referral & VTU Coins ----
    // The code is the username and the share link is the same
    // <host>/web/Register.php?referral=<username> the website copies, so counts and
    // credits stay consistent whichever surface the user came through.
    @POST("web/api/referral.php")
    suspend fun getReferral(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    /** Coin ledger history (earned/redeemed), mirroring the website Points History page. */
    @POST("web/api/points-history.php")
    suspend fun getPointsHistory(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>

    /**
     * VTU Coins -> wallet conversion.
     *
     * One method covers both modes because the endpoint switches on `action`:
     *   no action            -> balance, rate, minimum and conversion history
     *   action=submit+points -> submits a request (pending admin approval)
     */
    @POST("web/api/coin-conversion.php")
    suspend fun coinConversion(@Body body: @JvmSuppressWildcards Map<String, Any>): Response<Map<String, Any>>
}
