package com.datagifting.app.ui.dashboard

import android.os.Bundle
import android.view.View
import android.widget.LinearLayout
import androidx.cardview.widget.CardView
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import androidx.navigation.fragment.findNavController
import com.datagifting.app.R
import com.datagifting.app.util.safeNavigate
import com.datagifting.app.viewmodel.MainViewModel

class ServicesFragment : Fragment(R.layout.fragment_services) {

    private val viewModel: MainViewModel by activityViewModels()

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        val nav = findNavController()
        view.findViewById<LinearLayout>(R.id.btn_svc_airtime)?.setOnClickListener { nav.safeNavigate(R.id.nav_airtime) }
        view.findViewById<LinearLayout>(R.id.btn_svc_data)?.setOnClickListener { nav.safeNavigate(R.id.nav_data) }
        view.findViewById<LinearLayout>(R.id.btn_svc_cable)?.setOnClickListener { nav.safeNavigate(R.id.nav_cable) }
        view.findViewById<LinearLayout>(R.id.btn_svc_electric)?.setOnClickListener { nav.safeNavigate(R.id.nav_electric) }
        view.findViewById<LinearLayout>(R.id.btn_svc_exam)?.setOnClickListener { nav.safeNavigate(R.id.nav_exam) }
        view.findViewById<LinearLayout>(R.id.btn_svc_sms)?.setOnClickListener { nav.safeNavigate(R.id.nav_sms) }
        view.findViewById<LinearLayout>(R.id.btn_svc_giftcard)?.setOnClickListener { nav.safeNavigate(R.id.nav_giftcard) }
        view.findViewById<LinearLayout>(R.id.btn_svc_vcard)?.setOnClickListener { nav.safeNavigate(R.id.nav_vcard) }
        view.findViewById<LinearLayout>(R.id.btn_svc_crypto)?.setOnClickListener { nav.safeNavigate(R.id.nav_crypto) }
        view.findViewById<LinearLayout>(R.id.btn_svc_share)?.setOnClickListener { nav.safeNavigate(R.id.nav_share_fund) }
        view.findViewById<LinearLayout>(R.id.btn_svc_withdraw)?.setOnClickListener { nav.safeNavigate(R.id.nav_withdraw) }
        view.findViewById<LinearLayout>(R.id.btn_svc_fund)?.setOnClickListener { nav.safeNavigate(R.id.nav_fund_wallet) }
        view.findViewById<LinearLayout>(R.id.btn_svc_betting)?.setOnClickListener { nav.safeNavigate(R.id.nav_betting) }
        view.findViewById<LinearLayout>(R.id.btn_svc_nin)?.setOnClickListener { nav.safeNavigate(R.id.nav_nin_card) }
        view.findViewById<LinearLayout>(R.id.btn_svc_bvn)?.setOnClickListener { nav.safeNavigate(R.id.nav_bvn_verify) }

        viewModel.enabledServices.observe(viewLifecycleOwner) { applyServiceVisibility(view, it) }
    }

    private fun applyServiceVisibility(view: View, enabled: Set<String>) {
        if (enabled.isEmpty()) return  // server hasn't populated controls yet — show everything
        fun vis(key: String) = if (key in enabled) View.VISIBLE else View.GONE
        view.findViewById<LinearLayout>(R.id.btn_svc_airtime)?.visibility = vis("airtime")
        view.findViewById<LinearLayout>(R.id.btn_svc_data)?.visibility = vis("data")
        view.findViewById<LinearLayout>(R.id.btn_svc_cable)?.visibility = vis("cable")
        view.findViewById<LinearLayout>(R.id.btn_svc_electric)?.visibility = vis("electric")
        view.findViewById<LinearLayout>(R.id.btn_svc_exam)?.visibility = vis("exam")
        view.findViewById<LinearLayout>(R.id.btn_svc_sms)?.visibility = vis("bulk_sms")
        view.findViewById<LinearLayout>(R.id.btn_svc_betting)?.visibility = vis("betting")
        view.findViewById<LinearLayout>(R.id.btn_svc_giftcard)?.visibility = vis("gift_card")
        view.findViewById<LinearLayout>(R.id.btn_svc_vcard)?.visibility = vis("virtual_card")
        view.findViewById<LinearLayout>(R.id.btn_svc_crypto)?.visibility = vis("crypto_hub")
        view.findViewById<LinearLayout>(R.id.btn_svc_nin)?.visibility = vis("nin_card")
        view.findViewById<LinearLayout>(R.id.btn_svc_bvn)?.visibility = vis("bvn_verify")
    }
}

