package com.payhub.guest.data.model

import com.google.gson.annotations.SerializedName

// Field names match web/guest-api/catalog.php's exact JSON keys (uppercase — inherited from
// the authenticated API's existing *-plans.php contract, kept identical for guest mode).

data class AirtimeCatalogResponse(
    @SerializedName("AIRTIME_VTU") val airtimeVtu: Map<String, AirtimeNetworkInfo>? = null,
    val status: String? = null,
    val desc: String? = null,
)

data class AirtimeNetworkInfo(
    @SerializedName("PRODUCT_CODE") val productCode: String,
    @SerializedName("DISCOUNT_PERCENT") val discountPercent: String,
)

data class DataCatalogResponse(
    @SerializedName("MOBILE_NETWORK") val mobileNetwork: Map<String, List<DataPlan>>? = null,
    val status: String? = null,
    val desc: String? = null,
)

data class DataPlan(
    @SerializedName("ID") val id: String,
    @SerializedName("PRODUCT_CODE") val productCode: String,
    @SerializedName("PRODUCT_NAME") val productName: String,
    @SerializedName("DATA_TYPE") val dataType: String,
    @SerializedName("DATA_TYPE_CODE") val dataTypeCode: String,
    @SerializedName("AMOUNT") val amount: String,
    @SerializedName("DURATION") val duration: String,
)

data class CableCatalogResponse(
    @SerializedName("CABLE_SUBSCRIPTION") val cableSubscription: Map<String, List<CablePlan>>? = null,
    val status: String? = null,
    val desc: String? = null,
)

data class CablePlan(
    @SerializedName("ID") val id: String,
    @SerializedName("PACKAGE") val packageName: String,
    @SerializedName("AMOUNT") val amount: String,
)

data class ElectricCatalogResponse(
    @SerializedName("ELECTRIC_PAYMENT") val electricPayment: Map<String, ElectricProviderInfo>? = null,
    val status: String? = null,
    val desc: String? = null,
)

data class ElectricProviderInfo(
    @SerializedName("PROVIDER_CODE") val providerCode: String,
    @SerializedName("DISCOUNT_PERCENT") val discountPercent: String,
)

data class ExamCatalogResponse(
    @SerializedName("EXAM_PIN") val examPin: Map<String, List<ExamPlan>>? = null,
    val status: String? = null,
    val desc: String? = null,
)

data class ExamPlan(
    @SerializedName("ID") val id: String,
    @SerializedName("EXAM_TYPE") val examType: String,
    @SerializedName("AMOUNT") val amount: String,
)

data class BettingCatalogResponse(
    @SerializedName("BETTING_PROVIDERS") val bettingProviders: List<BettingProvider>? = null,
    val status: String? = null,
    val desc: String? = null,
)

data class BettingProvider(
    @SerializedName("provider_code") val providerCode: String,
    @SerializedName("provider_name") val providerName: String,
)
