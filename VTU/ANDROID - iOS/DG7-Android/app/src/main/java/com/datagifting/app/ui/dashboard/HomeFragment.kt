package com.datagifting.app.ui.dashboard

import android.os.Bundle
import android.view.View
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import androidx.navigation.fragment.findNavController
import com.datagifting.app.R
import com.datagifting.app.data.model.ApiResult
import com.datagifting.app.data.model.Transaction
import com.datagifting.app.databinding.FragmentHomeBinding
import com.datagifting.app.ui.transactions.ReceiptHelper
import com.datagifting.app.util.PreferenceManager
import com.datagifting.app.util.safeNavigate
import com.datagifting.app.util.toNaira
import com.datagifting.app.viewmodel.MainViewModel

class HomeFragment : Fragment(R.layout.fragment_home) {

    private var _binding: FragmentHomeBinding? = null
    private val binding get() = _binding!!
    private val viewModel: MainViewModel by activityViewModels()
    private lateinit var prefs: PreferenceManager

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentHomeBinding.bind(view)
        prefs = PreferenceManager(requireContext())

        setupUI()
        setupServiceButtons()
        observeData()
        viewModel.loadProfile()
        viewModel.loadServices()
        viewModel.loadTransactions(limit = 5)

        binding.swipeRefreshHome.setOnRefreshListener {
            viewModel.loadProfile()
            viewModel.loadServices()
            viewModel.loadTransactions(limit = 5)
        }
        binding.swipeRefreshHome.setColorSchemeResources(R.color.primary)
    }

    private fun setupUI() {
        val firstname = prefs.getString(com.datagifting.app.util.Constants.KEY_FIRSTNAME)
        binding.tvGreeting.text = "Hello, $firstname 👋"
        val balance = prefs.getDouble(com.datagifting.app.util.Constants.KEY_BALANCE)
        binding.tvBalance.text = balance.toNaira()

        binding.btnAddMoney.setOnClickListener { findNavController().safeNavigate(R.id.action_home_to_fund) }
        binding.btnWithdraw.setOnClickListener { findNavController().safeNavigate(R.id.action_home_to_withdraw) }
        binding.btnShareBalance.setOnClickListener { findNavController().safeNavigate(R.id.action_home_to_share) }
        binding.btnSeeAll.setOnClickListener { findNavController().safeNavigate(R.id.action_home_to_transactions) }

        var balanceVisible = true
        binding.btnToggleBalance.setOnClickListener {
            balanceVisible = !balanceVisible
            binding.tvBalance.text = if (balanceVisible)
                prefs.getDouble(com.datagifting.app.util.Constants.KEY_BALANCE).toNaira()
            else "₦ ••••••"
        }
    }

    private fun setupServiceButtons() {
        val nav = findNavController()
        binding.btnAirtime.setOnClickListener { nav.safeNavigate(R.id.action_home_to_airtime) }
        binding.btnData.setOnClickListener { nav.safeNavigate(R.id.action_home_to_data) }
        binding.btnElectric.setOnClickListener { nav.safeNavigate(R.id.action_home_to_electric) }
        binding.btnCable.setOnClickListener { nav.safeNavigate(R.id.action_home_to_cable) }
        binding.btnExam.setOnClickListener { nav.safeNavigate(R.id.action_home_to_exam) }
        binding.btnSms.setOnClickListener { nav.safeNavigate(R.id.action_home_to_sms) }
        binding.btnGiftcard.setOnClickListener { nav.safeNavigate(R.id.action_home_to_giftcard) }
        binding.btnVcard.setOnClickListener { nav.safeNavigate(R.id.action_home_to_vcard) }
        binding.btnCrypto.setOnClickListener { nav.safeNavigate(R.id.action_home_to_crypto) }
        binding.btnBetting.setOnClickListener { nav.safeNavigate(R.id.action_home_to_betting) }
        binding.btnPrint.setOnClickListener { nav.safeNavigate(R.id.action_home_to_print) }
        binding.btnNin.setOnClickListener { nav.safeNavigate(R.id.action_home_to_nin) }
        binding.btnBvn.setOnClickListener { nav.safeNavigate(R.id.action_home_to_bvn) }
        binding.btnWithdrawShortcut.setOnClickListener { nav.safeNavigate(R.id.action_home_to_withdraw) }
        binding.btnHistory.setOnClickListener { nav.safeNavigate(R.id.action_home_to_transactions) }
    }

    private fun applyServiceVisibility(enabled: Set<String>) {
        if (enabled.isEmpty()) return  // server hasn't populated controls yet — show everything
        fun vis(key: String) = if (key in enabled) View.VISIBLE else View.GONE
        binding.btnAirtime.visibility = vis("airtime")
        binding.btnData.visibility = vis("data")
        binding.btnElectric.visibility = vis("electric")
        binding.btnCable.visibility = vis("cable")
        binding.btnExam.visibility = vis("exam")
        binding.btnSms.visibility = vis("bulk_sms")
        binding.btnGiftcard.visibility = vis("gift_card")
        binding.btnVcard.visibility = vis("virtual_card")
        binding.btnCrypto.visibility = vis("crypto_hub")
        binding.btnBetting.visibility = vis("betting")
        binding.btnPrint.visibility = vis("data_card")
        binding.btnNin.visibility = vis("nin_card")
        binding.btnBvn.visibility = vis("bvn_verify")
    }

    private fun observeData() {
        viewModel.enabledServices.observe(viewLifecycleOwner) { applyServiceVisibility(it) }

        viewModel.profile.observe(viewLifecycleOwner) { result ->
            if (result is ApiResult.Success) {
                val user = result.data
                binding.tvGreeting.text = "Hello, ${user.firstname} 👋"
                binding.tvBalance.text = user.balance.toNaira()
                binding.tvUsername.text = "@${user.username}"
                prefs.saveDouble(com.datagifting.app.util.Constants.KEY_BALANCE, user.balance)
                binding.swipeRefreshHome.isRefreshing = false
            } else if (result is ApiResult.Error) {
                binding.swipeRefreshHome.isRefreshing = false
            }
        }
        viewModel.transactions.observe(viewLifecycleOwner) { result ->
            if (result is ApiResult.Success) {
                val recent = result.data.take(5)
                binding.tvNoTransactions.visibility = if (recent.isEmpty()) View.VISIBLE else View.GONE
                binding.containerRecentTx.removeAllViews()
                recent.forEach { tx ->
                    val row = layoutInflater.inflate(R.layout.item_transaction_row, binding.containerRecentTx, false)
                    row.findViewById<TextView>(R.id.tv_tx_desc).text = tx.description
                    row.findViewById<TextView>(R.id.tv_tx_amount).text = tx.amount.toNaira()
                    row.findViewById<TextView>(R.id.tv_tx_date).text = tx.date
                    val amountView = row.findViewById<TextView>(R.id.tv_tx_amount)
                    val isDebit = tx.description.contains("debit", ignoreCase = true) ||
                            tx.type.contains("airtime", ignoreCase = true) ||
                            tx.type.contains("data", ignoreCase = true)
                    amountView.setTextColor(
                        requireContext().getColor(if (isDebit) R.color.debit else R.color.credit)
                    )
                    // Color the status dot
                    row.findViewById<View>(R.id.view_status_dot).setBackgroundColor(
                        when (tx.status) { 1 -> android.graphics.Color.parseColor("#2E7D32"); 2 -> android.graphics.Color.parseColor("#F9A825"); else -> android.graphics.Color.parseColor("#C62828") }
                    )
                    binding.containerRecentTx.addView(row)
                    row.setOnClickListener { ReceiptHelper.showReceiptDialog(requireContext(), layoutInflater, tx) }
                }
            }
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
