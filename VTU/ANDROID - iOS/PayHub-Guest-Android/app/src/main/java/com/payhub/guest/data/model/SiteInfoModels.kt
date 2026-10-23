package com.payhub.guest.data.model

import com.google.gson.annotations.SerializedName

data class SiteInfoResponse(
    val status: String? = null,
    val data: SiteInfoData? = null,
)

data class SiteInfoData(
    @SerializedName("site_title") val siteTitle: String? = null,
    @SerializedName("logo_url") val logoUrl: String? = null,
    @SerializedName("primary_color") val primaryColor: String? = null,
    @SerializedName("secondary_color") val secondaryColor: String? = null,
    // A service key absent from this map must be treated as enabled — it means the admin
    // never touched Service Control Centre for it, not that it's disabled. Only an explicit
    // 0 hides a service.
    val services: Map<String, Int>? = null,
    @SerializedName("currency_symbol") val currencySymbol: String? = null,
    val support: GuestSupportInfo? = null,
)

data class GuestSupportInfo(
    val email: String? = null,
    val phone: String? = null,
    val address: String? = null,
)
