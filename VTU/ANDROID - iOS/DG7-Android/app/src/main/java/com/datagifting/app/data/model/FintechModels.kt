package com.datagifting.app.data.model

import com.google.gson.annotations.SerializedName

// ── Auth ──────────────────────────────────────────────────────────────────────

data class User(
    val username: String = "",
    val firstname: String = "",
    val lastname: String = "",
    val email: String = "",
    val phone: String = "",
    val balance: Double = 0.0,
    @SerializedName("api_key") val apiKey: String = "",
    @SerializedName("account_level") val accountLevel: Int = 1,
    @SerializedName("level_name") val levelName: String = "",
    @SerializedName("kyc_status") val kycStatus: Int = 0,
    @SerializedName("kyc_verified") val kycVerified: String = "No",
    @SerializedName("security_pin_set") val securityPinSet: Boolean = false
)

// ── Transactions ──────────────────────────────────────────────────────────────

data class Transaction(
    val reference: String = "",
    val type: String = "",
    val amount: Double = 0.0,
    @SerializedName("discounted_amount") val discountedAmount: Double = 0.0,
    @SerializedName("balance_before") val balanceBefore: Double = 0.0,
    @SerializedName("balance_after") val balanceAfter: Double = 0.0,
    val description: String = "",
    val status: Int = 0,
    @SerializedName("status_name") val statusName: String = "",
    val mode: String = "",
    val date: String = ""
)

// ── Services ──────────────────────────────────────────────────────────────────

data class ServiceItem(
    val id: String,
    val title: String,
    val iconRes: Int,
    val bgColor: Int,
    val iconColor: Int,
    val route: String
)

data class DataPlan(
    @SerializedName("product_name") val productName: String = "",
    val price: String = "",
    val code: String = "",
    val validity: String = "",
    @SerializedName("display_name") val displayName: String = ""
)

data class CablePlan(
    @SerializedName("ID") val id: String = "",
    @SerializedName("PACKAGE") val packageName: String = "",
    @SerializedName("AMOUNT") val amount: String = ""
)

data class ExamPlan(
    @SerializedName("ID") val id: String = "",
    @SerializedName("EXAM_TYPE") val examType: String = "",
    @SerializedName("AMOUNT") val amount: String = ""
)

data class SenderIdItem(
    @SerializedName("sender_id") val senderId: String = "",
    val status: String = ""
)

// ── Gift Cards ────────────────────────────────────────────────────────────────

data class GiftCardProduct(
    @SerializedName("product_id") val productId: Int = 0,
    val name: String = "",
    val logo: String = "",
    @SerializedName("min_value") val minValue: Double = 0.0,
    @SerializedName("max_value") val maxValue: Double = 0.0,
    val currency: String = "USD",
    val markup: Double = 0.0,
    val category: String = ""
)

// ── Virtual Cards ─────────────────────────────────────────────────────────────

data class VirtualCard(
    val reference: String = "",
    @SerializedName("masked_pan") val maskedPan: String = "",
    @SerializedName("balance_usd") val balanceUsd: Double = 0.0,
    @SerializedName("card_name") val cardName: String = "",
    val expiry: String = "",
    val status: String = "",
    @SerializedName("created_at") val createdAt: String = ""
)

// ── Crypto ────────────────────────────────────────────────────────────────────

data class CryptoWallet(
    val currency: String = "",
    val label: String = "",
    val balance: Double = 0.0,
    val address: String = "",
    @SerializedName("is_active") val isActive: Boolean = true
)

// ── Bank ─────────────────────────────────────────────────────────────────────

data class Bank(
    @SerializedName("bankCode") val bankCode: String = "",
    @SerializedName("bankName") val bankName: String = ""
)

// ── Site Info ─────────────────────────────────────────────────────────────────

data class SiteInfo(
    @SerializedName("site_title") val siteTitle: String = "",
    @SerializedName("logo_url") val logoUrl: String = "",
    @SerializedName("primary_color") val primaryColor: String = "#0d6efd",
    @SerializedName("secondary_color") val secondaryColor: String = "#f6f9fc",
    val services: Map<String, Int> = emptyMap(),
    @SerializedName("currency_symbol") val currencySymbol: String = "₦",
    val support: SupportInfo = SupportInfo()
)

data class SupportInfo(
    val email: String = "",
    val address: String = "",
    val whatsapp: String = ""
)

// ── Result wrapper ────────────────────────────────────────────────────────────

sealed class ApiResult<out T> {
    data class Success<T>(val data: T) : ApiResult<T>()
    data class Error(val message: String) : ApiResult<Nothing>()
    object Loading : ApiResult<Nothing>()
}
