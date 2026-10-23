package com.datagifting.app.ui.services

import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.View
import android.widget.ArrayAdapter
import android.widget.AutoCompleteTextView
import android.widget.LinearLayout
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.databinding.FragmentVirtualCardBinding
import com.datagifting.app.util.LoadingOverlay
import com.datagifting.app.util.PreferenceManager
import com.datagifting.app.util.copyToClipboard
import com.datagifting.app.util.toNaira
import com.google.android.material.button.MaterialButton
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import com.google.android.material.textfield.TextInputEditText
import kotlinx.coroutines.launch

class VirtualCardFragment : Fragment(R.layout.fragment_virtual_card) {

    private var _binding: FragmentVirtualCardBinding? = null
    private val binding get() = _binding!!

    // State loaded from API
    private var cards: List<Map<String, Any>> = emptyList()
    private var products: List<Map<String, Any>> = emptyList()
    private var usdRate: Double = 0.0
    private var issuanceFeeUsd: Double = 2.0
    private var fundingFeePct: Double = 3.0

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentVirtualCardBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.swipeRefresh.setColorSchemeResources(R.color.primary)
        binding.swipeRefresh.setOnRefreshListener { loadData() }
        binding.btnIssueCard.setOnClickListener { showIssueDialog() }

        loadData()
    }

    // ─── Data loading ─────────────────────────────────────────────────────────

    private fun loadData() {
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val body = RetrofitClient.getService().virtualCardAction(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "list_cards")
                ).body()

                if ((body?.get("status") as? String) == "success") {
                    @Suppress("UNCHECKED_CAST")
                    cards = (body["cards"] as? List<Map<String, Any>>) ?: emptyList()
                    @Suppress("UNCHECKED_CAST")
                    products = (body["available_products"] as? List<Map<String, Any>>) ?: emptyList()
                    usdRate = (body["rate"] as? Double) ?: 0.0
                    issuanceFeeUsd = (body["issuance_fee"] as? Double) ?: 2.0
                    fundingFeePct = (body["funding_fee_pct"] as? Double) ?: 3.0

                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        renderData()
                    }
                } else {
                    val msg = body?.get("message") as? String ?: "Failed to load data"
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        snack(msg)
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    snack("Network error: ${e.message}")
                }
            } finally {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.swipeRefresh.isRefreshing = false
                }
            }
        }
    }

    // ─── Rendering ────────────────────────────────────────────────────────────

    private fun renderData() {
        // Rate & fees
        binding.tvUsdRate.text = if (usdRate > 0) usdRate.toNaira() else "₦—"
        binding.tvIssuanceFee.text = "$${"%.2f".format(issuanceFeeUsd)}"
        binding.tvFundingFee.text = "${fundingFeePct}%"

        // Cards list
        binding.containerCards.removeAllViews()
        if (cards.isEmpty()) {
            binding.tvNoCards.visibility = View.VISIBLE
        } else {
            binding.tvNoCards.visibility = View.GONE
            cards.forEach { card -> binding.containerCards.addView(inflateCardItem(card)) }
        }
    }

    private fun inflateCardItem(card: Map<String, Any>): View {
        val item = layoutInflater.inflate(R.layout.item_virtual_card, binding.containerCards, false)

        val reference = card["reference"] as? String ?: ""
        val maskedPan = card["masked_pan"] as? String ?: ""
        val balanceUsd = (card["balance_usd"] as? Double) ?: 0.0
        val cardName = card["card_name"] as? String ?: ""
        val expiry = card["expiry"] as? String ?: ""
        val status = card["status"] as? String ?: "active"
        val isFrozenAuto = ((card["is_frozen_auto"] as? Double)?.toInt() ?: 0) == 1

        item.findViewById<TextView>(R.id.tv_card_name).text = cardName.ifEmpty { "Virtual Card" }
        item.findViewById<TextView>(R.id.tv_masked_pan).text = maskedPan
        item.findViewById<TextView>(R.id.tv_expiry).text = "Exp: $expiry"
        item.findViewById<TextView>(R.id.tv_balance).text = "$${"%.2f".format(balanceUsd)} USD"

        val tvStatus = item.findViewById<TextView>(R.id.tv_status)
        val (statusLabel, statusColor) = when {
            isFrozenAuto -> "Frozen" to requireContext().getColor(R.color.warning)
            status == "active" -> "Active" to requireContext().getColor(R.color.success)
            status == "terminated" -> "Terminated" to requireContext().getColor(R.color.error)
            else -> status.replaceFirstChar { it.uppercase() } to requireContext().getColor(R.color.text_secondary)
        }
        tvStatus.text = statusLabel
        tvStatus.setTextColor(statusColor)

        val isTerminated = status == "terminated"
        val btnFund = item.findViewById<MaterialButton>(R.id.btn_fund)
        val btnReveal = item.findViewById<MaterialButton>(R.id.btn_reveal)
        val btnWithdraw = item.findViewById<MaterialButton>(R.id.btn_withdraw)

        btnFund.isEnabled = !isTerminated
        btnWithdraw.isEnabled = !isTerminated && balanceUsd > 0

        btnFund.setOnClickListener { showFundDialog(reference) }
        btnReveal.setOnClickListener { showRevealDialog(reference) }
        btnWithdraw.setOnClickListener { confirmWithdraw(reference, balanceUsd) }

        return item
    }

    // ─── Issue Card ───────────────────────────────────────────────────────────

    private fun showIssueDialog() {
        if (products.isEmpty()) {
            snack("No card products available at this time")
            return
        }

        val dv = layoutInflater.inflate(R.layout.dialog_vc_issue, null)
        val spinnerProduct = dv.findViewById<AutoCompleteTextView>(R.id.spinner_product)
        val etCardName = dv.findViewById<TextInputEditText>(R.id.et_card_name)
        val etAmount = dv.findViewById<TextInputEditText>(R.id.et_amount)
        val etPin = dv.findViewById<TextInputEditText>(R.id.et_pin)
        val tvFeePreview = dv.findViewById<TextView>(R.id.tv_fee_preview)

        val productLabels = products.map { "${it["name"] as? String ?: "Card"} (${it["currency"] as? String ?: ""})" }
        spinnerProduct.setAdapter(ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, productLabels))
        spinnerProduct.setText(productLabels.first(), false)

        fun updatePreview() {
            val amountUsd = etAmount.text?.toString()?.toDoubleOrNull() ?: return
            if (amountUsd <= 0) { tvFeePreview.visibility = View.GONE; return }
            val totalUsd = amountUsd + issuanceFeeUsd
            val totalNgn = totalUsd * usdRate
            tvFeePreview.text = "Total: $${"%.2f".format(totalUsd)} USD ≈ ${totalNgn.toNaira()} (incl. $${"%.2f".format(issuanceFeeUsd)} issuance fee)"
            tvFeePreview.visibility = View.VISIBLE
        }

        etAmount.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) { updatePreview() }
        })

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("💳 Issue New Virtual Card")
            .setView(dv)
            .setPositiveButton("Issue Card") { _, _ ->
                val idx = productLabels.indexOf(spinnerProduct.text.toString())
                val productId = if (idx >= 0) products[idx]["id"] as? String ?: "" else ""
                val cardName = etCardName.text?.toString()?.trim() ?: ""
                val amountUsd = etAmount.text?.toString()?.toDoubleOrNull() ?: 0.0
                val pin = etPin.text?.toString() ?: ""

                when {
                    cardName.isEmpty() -> snack("Please enter a name for the card")
                    amountUsd < 5 -> snack("Minimum initial load is $5")
                    pin.length != 4 -> snack("Enter your 4-digit Transaction PIN")
                    else -> issueCard(productId, cardName, amountUsd, pin)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun issueCard(productId: String, cardName: String, amountUsd: Double, pin: String) {
        LoadingOverlay.show(requireContext(), "Issuing your card...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val body = RetrofitClient.getService().virtualCardAction(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "action" to "issue",
                        "product_id" to productId,
                        "card_name" to cardName,
                        "amount_usd" to amountUsd,
                        "pin" to pin
                    )
                ).body()
                val status = body?.get("status") as? String ?: "error"
                val msg = body?.get("message") as? String ?: "Failed to issue card"

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    if (status == "success") {
                        showSuccessDialog("Card Issued ✅", msg)
                        loadData()
                    } else {
                        snack(msg)
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    snack("Network error: ${e.message}")
                }
            }
        }
    }

    // ─── Fund Card ────────────────────────────────────────────────────────────

    private fun showFundDialog(reference: String) {
        val dv = layoutInflater.inflate(R.layout.dialog_vc_fund, null)
        val etAmount = dv.findViewById<TextInputEditText>(R.id.et_amount)
        val etPin = dv.findViewById<TextInputEditText>(R.id.et_pin)
        val tvFeePreview = dv.findViewById<TextView>(R.id.tv_fee_preview)

        fun updatePreview() {
            val amountUsd = etAmount.text?.toString()?.toDoubleOrNull() ?: return
            if (amountUsd <= 0) { tvFeePreview.visibility = View.GONE; return }
            val totalUsdWithFee = amountUsd * (1 + fundingFeePct / 100)
            val totalNgn = totalUsdWithFee * usdRate
            tvFeePreview.text = "Total: $${"%.2f".format(totalUsdWithFee)} USD ≈ ${totalNgn.toNaira()} (incl. ${fundingFeePct}% fee)"
            tvFeePreview.visibility = View.VISIBLE
        }

        etAmount.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) { updatePreview() }
        })

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("💰 Fund Virtual Card")
            .setView(dv)
            .setPositiveButton("Fund") { _, _ ->
                val amountUsd = etAmount.text?.toString()?.toDoubleOrNull() ?: 0.0
                val pin = etPin.text?.toString() ?: ""

                when {
                    amountUsd <= 0 -> snack("Enter a valid amount")
                    pin.length != 4 -> snack("Enter your 4-digit Transaction PIN")
                    else -> fundCard(reference, amountUsd, pin)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun fundCard(reference: String, amountUsd: Double, pin: String) {
        LoadingOverlay.show(requireContext(), "Funding your card...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val body = RetrofitClient.getService().virtualCardAction(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "action" to "fund",
                        "card_ref" to reference,
                        "amount_usd" to amountUsd,
                        "pin" to pin
                    )
                ).body()
                val status = body?.get("status") as? String ?: "error"
                val msg = body?.get("message") as? String ?: "Funding failed"

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    if (status == "success") {
                        showSuccessDialog("Card Funded ✅", msg)
                        loadData()
                    } else {
                        snack(msg)
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    snack("Network error: ${e.message}")
                }
            }
        }
    }

    // ─── Reveal Card Details ──────────────────────────────────────────────────

    private fun showRevealDialog(reference: String) {
        val dv = layoutInflater.inflate(R.layout.dialog_vc_reveal, null)
        val etPin = dv.findViewById<TextInputEditText>(R.id.et_pin)

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("🔍 Reveal Card Details")
            .setView(dv)
            .setPositiveButton("Reveal") { _, _ ->
                val pin = etPin.text?.toString() ?: ""
                if (pin.length != 4) {
                    snack("Enter your 4-digit Transaction PIN")
                } else {
                    revealCard(reference, pin)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun revealCard(reference: String, pin: String) {
        LoadingOverlay.show(requireContext(), "Revealing card details...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val body = RetrofitClient.getService().virtualCardAction(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "action" to "reveal",
                        "card_ref" to reference,
                        "pin" to pin
                    )
                ).body()
                val status = body?.get("status") as? String ?: "error"

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    if (status == "success") {
                        @Suppress("UNCHECKED_CAST")
                        val data = body?.get("data") as? Map<String, Any> ?: emptyMap()
                        showCardDetailsDialog(data)
                    } else {
                        snack(body?.get("message") as? String ?: "Could not reveal card details")
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    snack("Network error: ${e.message}")
                }
            }
        }
    }

    private fun showCardDetailsDialog(data: Map<String, Any>) {
        val maskedPan = data["masked_pan"] as? String ?: "—"
        val cvv = data["cvv"] as? String ?: "—"
        val expMonth = data["expiry_month"] as? String ?: "—"
        val expYear = data["expiry_year"] as? String ?: "—"

        val details = LinearLayout(requireContext()).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(60, 40, 60, 20)
        }

        fun addRow(label: String, value: String) {
            val row = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.VERTICAL
                val lp = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)
                lp.bottomMargin = (12 * resources.displayMetrics.density).toInt()
                layoutParams = lp
            }
            val tvLabel = TextView(requireContext()).apply {
                text = label
                textSize = 11f
                setTextColor(requireContext().getColor(R.color.text_secondary))
                isAllCaps = true
            }
            val tvValue = TextView(requireContext()).apply {
                text = value
                textSize = 16f
                setTextColor(requireContext().getColor(R.color.text_primary))
                typeface = android.graphics.Typeface.MONOSPACE
                setTextIsSelectable(true)
            }
            row.addView(tvLabel)
            row.addView(tvValue)
            details.addView(row)
        }

        addRow("Card Number", maskedPan)
        addRow("CVV", cvv)
        addRow("Expiry", "$expMonth/$expYear")

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Card Details")
            .setView(details)
            .setPositiveButton("Copy PAN") { _, _ ->
                requireContext().copyToClipboard("Card Number", maskedPan)
            }
            .setNegativeButton("Close", null)
            .show()
    }

    // ─── Withdraw / Liquidate Card ────────────────────────────────────────────

    private fun confirmWithdraw(reference: String, balanceUsd: Double) {
        val approxNgn = balanceUsd * usdRate
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Withdraw Card Funds")
            .setMessage(
                "This will return $${"%.2f".format(balanceUsd)} USD (≈ ${approxNgn.toNaira()}) to your NGN wallet and terminate the card.\n\nAre you sure?"
            )
            .setPositiveButton("Withdraw & Terminate") { _, _ -> withdrawCard(reference) }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun withdrawCard(reference: String) {
        LoadingOverlay.show(requireContext(), "Processing withdrawal...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val body = RetrofitClient.getService().virtualCardAction(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "action" to "withdraw",
                        "card_ref" to reference
                    )
                ).body()
                val status = body?.get("status") as? String ?: "error"
                val msg = body?.get("message") as? String ?: "Withdrawal failed"

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    if (status == "success") {
                        showSuccessDialog("Withdrawal Successful ✅", msg)
                        loadData()
                    } else {
                        snack(msg)
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    snack("Network error: ${e.message}")
                }
            }
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private fun showSuccessDialog(title: String, message: String) {
        MaterialAlertDialogBuilder(requireContext())
            .setTitle(title)
            .setMessage(message)
            .setPositiveButton("Done", null)
            .show()
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}

