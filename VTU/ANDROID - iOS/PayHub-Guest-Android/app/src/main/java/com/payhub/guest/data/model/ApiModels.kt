package com.payhub.guest.data.model

import com.google.gson.annotations.SerializedName

data class ApiFailure(
    val status: String? = null,
    val desc: String? = null,
)

data class NetworkDetectResponse(
    val status: String? = null,
    val network: String? = null,
    val data: List<NetworkDetectEntry>? = null,
)

data class NetworkDetectEntry(
    val phone: String,
    val network: String,
)

data class VerifyCustomerResponse(
    val status: String,
    val desc: String? = null,
    @SerializedName("customer_name") val customerName: String? = null,
    @SerializedName("customer_address") val customerAddress: String? = null,
)

data class CheckoutInitResponse(
    val status: String,
    val reference: String? = null,
    val amount: Double? = null,
    @SerializedName("checkout_url") val checkoutUrl: String? = null,
    val desc: String? = null,
)

data class GuestOrderStatusResponse(
    val ref: String? = null,
    val status: String? = null,
    val service: String? = null,
    val amount: String? = null,
    val desc: String? = null,
    @SerializedName("response_desc") val responseDesc: String? = null,
    @SerializedName("meter_number") val meterNumber: String? = null,
    val token: String? = null,
    @SerializedName("token_unit") val tokenUnit: String? = null,
    @SerializedName("customer_id") val customerId: String? = null,
)

/** Local-only receipt snapshot used for the Receipt screen and locally-cached History list. */
data class GuestReceipt(
    val reference: String,
    val service: String,
    val recipient: String,
    val amountPaid: Double,
    val status: String,
    val dateMillis: Long,
    val meterNumber: String? = null,
    val token: String? = null,
    val tokenUnit: String? = null,
)
