package com.mzeevtu.ui.dashboard

import android.content.Intent
import androidx.fragment.app.Fragment
import androidx.navigation.fragment.findNavController
import com.mzeevtu.R
import com.mzeevtu.util.safeNavigate

/** Keep existing native transaction forms and background workers. New flows reuse web policy. */
object MobileServiceNavigator {
    fun launch(fragment: Fragment, slug: String) {
        val destination=when(slug) {
            "airtime"->R.id.nav_airtime; "data"->R.id.nav_data; "cable"->R.id.nav_cable
            "electric"->R.id.nav_electric; "exam"->R.id.nav_exam; "betting"->R.id.nav_betting
            "sms"->R.id.nav_sms; "print"->R.id.nav_print_hub; "nin"->R.id.nav_nin_card; "bvn"->R.id.nav_bvn_verify
            "crypto"->R.id.nav_crypto; "cards"->R.id.nav_vcard; "giftcards"->R.id.nav_giftcard
            "kyc"->R.id.nav_kyc; "pin"->R.id.nav_set_pin; "history"->R.id.nav_transactions
            "fund"->R.id.nav_fund_wallet; "local"->R.id.nav_withdraw; "share"->R.id.nav_share_fund
            "coins"->R.id.nav_coins; "points"->R.id.nav_points_history; else->null
        }
        if(destination!=null)fragment.findNavController().safeNavigate(destination)
        else fragment.startActivity(Intent(fragment.requireContext(),ServicePortalActivity::class.java).putExtra("service",slug))
    }
}
