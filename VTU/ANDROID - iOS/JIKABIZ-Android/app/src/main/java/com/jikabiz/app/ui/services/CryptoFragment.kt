package com.jikabiz.app.ui.services

import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.text.Editable
import android.text.InputFilter
import android.text.InputType
import android.text.TextWatcher
import android.view.View
import android.widget.ArrayAdapter
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import com.google.android.material.button.MaterialButton
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import com.jikabiz.app.R
import com.jikabiz.app.api.RetrofitClient
import com.jikabiz.app.api.TransactionPin
import com.jikabiz.app.databinding.FragmentCryptoBinding
import com.jikabiz.app.ui.dashboard.ServicePortalActivity
import com.jikabiz.app.util.BrandTheme
import com.jikabiz.app.util.Constants
import com.jikabiz.app.util.LoadingOverlay
import com.jikabiz.app.util.PreferenceManager
import com.jikabiz.app.util.copyToClipboard
import com.jikabiz.app.util.safeNavigate
import com.jikabiz.app.util.toNaira
import kotlinx.coroutines.async
import kotlinx.coroutines.launch

class CryptoFragment : Fragment(R.layout.fragment_crypto) {

    private var _binding: FragmentCryptoBinding? = null
    private val binding get() = _binding!!

    private var wallets: List<Map<String, Any>> = emptyList()
    private var desk: Map<String, Any> = emptyMap()
    private var swapFeePercent: Double = 0.0
    private var ngnBalance: Double = 0.0
    private var portfolioNgn: Double = 0.0
    private var activeTab: String = "buy"

    private var buyAssets: List<Map<String, Any>> = emptyList()
    private var sellAssets: List<Map<String, Any>> = emptyList()
    private var swapFromOptions: List<String> = emptyList()
    private var swapToOptions: List<String> = emptyList()
    private var currentAdminAddress: String = ""

    private val buyUnits = listOf("Pay in NGN", "Enter Crypto Quantity")
    private val buyDeliveries = listOf("External Wallet Address", "My CryptoHub Wallet")
    private val sellSources = listOf("Send from External Wallet", "Sell from My CryptoHub Wallet")

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentCryptoBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.swipeRefresh.setColorSchemeColors(BrandTheme.ink(requireContext()))
        binding.swipeRefresh.setOnRefreshListener { loadData(forceRefresh = true) }

        binding.btnTabBuy.setOnClickListener { selectTab("buy") }
        binding.btnTabSell.setOnClickListener { selectTab("sell") }
        binding.btnTabSwap.setOnClickListener { selectTab("swap") }

        binding.btnCompleteKyc.setOnClickListener { findNavController().safeNavigate(R.id.nav_kyc) }
        binding.btnWithdrawLocal.setOnClickListener { findNavController().safeNavigate(R.id.nav_withdraw) }
        binding.btnWithdrawIntl.setOnClickListener {
            startActivity(Intent(requireContext(), ServicePortalActivity::class.java).putExtra("service", "international"))
        }
        binding.btnCopyAdminAddress.setOnClickListener {
            if (currentAdminAddress.isNotBlank()) {
                requireContext().copyToClipboard("Admin Wallet Address", currentAdminAddress)
            } else {
                snack("Admin wallet address is not configured for this coin yet")
            }
        }
        binding.btnViewAllHistory.setOnClickListener { showAllHistoryDialog() }

        wireInputWatchers()
        selectTab("buy")
        loadData(forceRefresh = false)
    }

    private fun wireInputWatchers() {
        val watcher = object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                when (activeTab) {
                    "buy" -> updateBuyPreview()
                    "sell" -> updateSellPreview()
                    "swap" -> updateSwapPreview()
                }
            }
        }
        binding.etBuyAmount.addTextChangedListener(watcher)
        binding.etSellAmount.addTextChangedListener(watcher)
        binding.etSwapAmount.addTextChangedListener(watcher)

        binding.spinnerBuyAsset.setOnItemClickListener { _, _, _, _ -> updateBuyPreview() }
        binding.spinnerBuyUnit.setOnItemClickListener { _, _, _, _ -> updateBuyPreview() }
        binding.spinnerBuyDelivery.setOnItemClickListener { _, _, _, _ -> updateBuyPreview() }

        binding.spinnerSellAsset.setOnItemClickListener { _, _, _, _ -> updateSellPreview() }
        binding.spinnerSellSource.setOnItemClickListener { _, _, _, _ -> updateSellPreview() }

        binding.spinnerSwapFrom.setOnItemClickListener { _, _, _, _ -> updateSwapPreview() }
        binding.spinnerSwapTo.setOnItemClickListener { _, _, _, _ -> updateSwapPreview() }

        binding.btnSubmitBuy.setOnClickListener { submitBuyOrder() }
        binding.btnSubmitSell.setOnClickListener { submitSellOrder() }
        binding.btnSubmitSwap.setOnClickListener { submitSwapOrder() }
    }

    private fun selectTab(tab: String) {
        activeTab = tab
        binding.panelBuy.visibility = if (tab == "buy") View.VISIBLE else View.GONE
        binding.panelSell.visibility = if (tab == "sell") View.VISIBLE else View.GONE
        binding.panelSwap.visibility = if (tab == "swap") View.VISIBLE else View.GONE

        styleTabButton(binding.btnTabBuy, tab == "buy")
        styleTabButton(binding.btnTabSell, tab == "sell")
        styleTabButton(binding.btnTabSwap, tab == "swap")
    }

    private fun styleTabButton(btn: MaterialButton, selected: Boolean) {
        val ctx = requireContext()
        val onPrimary = BrandTheme.onPrimary(ctx)
        if (selected) {
            btn.backgroundTintList = ColorStateList.valueOf(onPrimary)
            btn.setTextColor(BrandTheme.primary(ctx))
            btn.strokeColor = ColorStateList.valueOf(onPrimary)
        } else {
            btn.backgroundTintList = ColorStateList.valueOf(Color.TRANSPARENT)
            btn.setTextColor(onPrimary)
            btn.strokeColor = ColorStateList.valueOf(Color.argb(140, Color.red(onPrimary), Color.green(onPrimary), Color.blue(onPrimary)))
        }
    }

    // ─── Data Loading ──────────────────────────────────────────────────────────

    private fun loadData(forceRefresh: Boolean) {
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            val d = async { loadDesk(forceRefresh) }
            val h = async { loadHistory() }
            d.await()
            h.await()
            if (_binding != null) binding.swipeRefresh.isRefreshing = false
        }
    }

    private suspend fun loadDesk(forceRefresh: Boolean) {
        try {
            val prefs = PreferenceManager(requireContext())
            val params = mutableMapOf<String, Any>(
                "api_key" to prefs.getApiKey(),
                "action" to "a2p_desk"
            )
            if (forceRefresh) params["refresh"] = 1

            val resp = RetrofitClient.getService().cryptoAction(params)
            val body = resp.body() ?: return
            if ((body["status"] as? String) != "success") {
                val msg = body["message"] as? String
                if (!msg.isNullOrBlank()) activity?.runOnUiThread { if (_binding != null) snack(msg) }
                return
            }

            @Suppress("UNCHECKED_CAST")
            wallets = (body["wallets"] as? List<Map<String, Any>>) ?: emptyList()
            @Suppress("UNCHECKED_CAST")
            desk = (body["desk"] as? Map<String, Any>) ?: emptyMap()
            swapFeePercent = (body["swap_fee_percent"] as? Number)?.toDouble() ?: 0.0
            ngnBalance = (body["ngn_balance"] as? Number)?.toDouble() ?: 0.0
            portfolioNgn = (body["portfolio_ngn"] as? Number)?.toDouble() ?: 0.0

            activity?.runOnUiThread {
                if (_binding == null) return@runOnUiThread
                binding.tvNgnBalance.text = ngnBalance.toNaira()
                binding.tvPortfolioNgn.text = "Crypto Portfolio: ${portfolioNgn.toNaira()}"

                val rateMode = (desk["rate_mode"] as? String) ?: "live"
                binding.tvDeskRateChip.text = if (rateMode == "live") "Live Desk Rates • Buy / Sell / Swap" else "Fixed Desk Rates • Buy / Sell / Swap"

                val kycReq = (desk["kyc_required"] as? Boolean) ?: false
                val kycOk = (desk["kyc_verified"] as? Boolean) ?: true
                binding.cardKycNotice.visibility = if (kycReq && !kycOk) View.VISIBLE else View.GONE

                populateDropdowns()
                renderWallets()
            }
        } catch (_: Exception) {}
    }

    private suspend fun loadHistory() {
        try {
            val prefs = PreferenceManager(requireContext())
            val resp = RetrofitClient.getService().cryptoAction(
                mapOf("api_key" to prefs.getApiKey(), "action" to "history")
            )
            val body = resp.body() ?: return
            if ((body["status"] as? String) != "success") return

            @Suppress("UNCHECKED_CAST")
            val history = (body["history"] as? List<Map<String, Any>>) ?: emptyList()

            activity?.runOnUiThread {
                if (_binding == null) return@runOnUiThread
                renderHistory(history.take(6))
                binding.tvNoHistory.visibility = if (history.isEmpty()) View.VISIBLE else View.GONE
            }
        } catch (_: Exception) {}
    }

    private fun populateDropdowns() {
        val ctx = requireContext()

        buyAssets = wallets.filter { (it["buy_enabled"] as? Boolean) == true }
        val buyLabels = buyAssets.map { assetLabel(it) }
        binding.spinnerBuyAsset.setAdapter(ArrayAdapter(ctx, android.R.layout.simple_dropdown_item_1line, buyLabels))
        if (buyLabels.isNotEmpty() && binding.spinnerBuyAsset.text.isNullOrBlank()) {
            binding.spinnerBuyAsset.setText(buyLabels.first(), false)
        }
        binding.spinnerBuyUnit.setAdapter(ArrayAdapter(ctx, android.R.layout.simple_dropdown_item_1line, buyUnits))
        if (binding.spinnerBuyUnit.text.isNullOrBlank()) binding.spinnerBuyUnit.setText(buyUnits.first(), false)
        binding.spinnerBuyDelivery.setAdapter(ArrayAdapter(ctx, android.R.layout.simple_dropdown_item_1line, buyDeliveries))
        if (binding.spinnerBuyDelivery.text.isNullOrBlank()) binding.spinnerBuyDelivery.setText(buyDeliveries.first(), false)

        sellAssets = wallets.filter { (it["sell_enabled"] as? Boolean) == true }
        val sellLabels = sellAssets.map { assetLabel(it) }
        binding.spinnerSellAsset.setAdapter(ArrayAdapter(ctx, android.R.layout.simple_dropdown_item_1line, sellLabels))
        if (sellLabels.isNotEmpty() && binding.spinnerSellAsset.text.isNullOrBlank()) {
            binding.spinnerSellAsset.setText(sellLabels.first(), false)
        }
        binding.spinnerSellSource.setAdapter(ArrayAdapter(ctx, android.R.layout.simple_dropdown_item_1line, sellSources))
        if (binding.spinnerSellSource.text.isNullOrBlank()) binding.spinnerSellSource.setText(sellSources.first(), false)

        val swapFromList = mutableListOf("NGN – Naira Wallet")
        val swapToList = mutableListOf("NGN – Naira Wallet")
        wallets.forEach { w ->
            val code = w["currency"] as? String ?: return@forEach
            val label = w["label"] as? String ?: code
            val bal = (w["balance"] as? Number)?.toDouble() ?: 0.0
            val swapOk = (w["swap_enabled"] as? Boolean) == true
            if (swapOk || bal > 0) swapFromList.add("$label ($code)")
            if (swapOk) swapToList.add("$label ($code)")
        }
        swapFromOptions = swapFromList
        swapToOptions = swapToList
        binding.spinnerSwapFrom.setAdapter(ArrayAdapter(ctx, android.R.layout.simple_dropdown_item_1line, swapFromList))
        binding.spinnerSwapTo.setAdapter(ArrayAdapter(ctx, android.R.layout.simple_dropdown_item_1line, swapToList))
        if (binding.spinnerSwapFrom.text.isNullOrBlank() && swapFromList.size > 1) {
            binding.spinnerSwapFrom.setText(swapFromList[1], false)
        }
        if (binding.spinnerSwapTo.text.isNullOrBlank()) {
            binding.spinnerSwapTo.setText(swapToList.first(), false)
        }

        updateBuyPreview()
        updateSellPreview()
        updateSwapPreview()
    }

    // ─── Previews ──────────────────────────────────────────────────────────────

    private fun selectedBuyAsset(): Map<String, Any>? {
        val txt = binding.spinnerBuyAsset.text.toString()
        return buyAssets.firstOrNull { assetLabel(it) == txt } ?: buyAssets.firstOrNull()
    }

    private fun selectedSellAsset(): Map<String, Any>? {
        val txt = binding.spinnerSellAsset.text.toString()
        return sellAssets.firstOrNull { assetLabel(it) == txt } ?: sellAssets.firstOrNull()
    }

    private fun updateBuyPreview() {
        val asset = selectedBuyAsset()
        val external = binding.spinnerBuyDelivery.text.toString().startsWith("External")
        binding.tilBuyAddress.visibility = if (external) View.VISIBLE else View.GONE
        binding.tvBuyAddressHint.visibility = if (external) View.VISIBLE else View.GONE

        if (asset == null) {
            binding.tvBuyRateInfo.text = "No cryptocurrency is currently enabled for Buy orders."
            binding.layoutBuyPreview.visibility = View.GONE
            return
        }

        val symbol = (asset["symbol"] as? String) ?: (asset["currency"] as? String) ?: ""
        val rate = (asset["buy_rate_ngn"] as? Number)?.toDouble() ?: 0.0
        val hint = (asset["address_hint"] as? String) ?: ""
        binding.tvBuyRateInfo.text = "Buy Rate: ${rate.toNaira()} / $symbol"
        binding.tvBuyAddressHint.text = hint

        val amt = binding.etBuyAmount.text?.toString()?.toDoubleOrNull() ?: 0.0
        if (amt <= 0 || rate <= 0) {
            binding.layoutBuyPreview.visibility = View.GONE
            return
        }
        val isCryptoUnit = binding.spinnerBuyUnit.text.toString().contains("Crypto")
        val ngnTotal = if (isCryptoUnit) amt * rate else amt
        val cryptoOut = if (isCryptoUnit) amt else amt / rate

        binding.layoutBuyPreview.visibility = View.VISIBLE
        binding.tvBuyPreview.text = "Pay ${ngnTotal.toNaira()} → Receive ${formatCryptoAmount(cryptoOut, symbol)}"
    }

    private fun updateSellPreview() {
        val asset = selectedSellAsset()
        val external = binding.spinnerSellSource.text.toString().contains("External")
        binding.cardAdminDeposit.visibility = if (external) View.VISIBLE else View.GONE
        binding.tilSellTxHash.visibility = if (external) View.VISIBLE else View.GONE
        binding.tilSellNote.visibility = if (external) View.VISIBLE else View.GONE

        if (asset == null) {
            binding.tvSellRateInfo.text = "No cryptocurrency is currently enabled for Sell orders."
            binding.layoutSellPreview.visibility = View.GONE
            currentAdminAddress = ""
            return
        }

        val symbol = (asset["symbol"] as? String) ?: (asset["currency"] as? String) ?: ""
        val rate = (asset["sell_rate_ngn"] as? Number)?.toDouble() ?: 0.0
        val adminAddr = (asset["deposit_address"] as? String)?.trim() ?: ""
        val netNote = (asset["network_note"] as? String)?.trim() ?: ""
        val bal = (asset["balance"] as? Number)?.toDouble() ?: 0.0

        currentAdminAddress = adminAddr
        binding.tvSellRateInfo.text = if (external) {
            "Sell Rate: ${rate.toNaira()} / $symbol"
        } else {
            "Sell Rate: ${rate.toNaira()} / $symbol • Available Wallet: ${formatCryptoAmount(bal, symbol)}"
        }

        binding.tvAdminDepositAddress.text = if (adminAddr.isNotBlank()) adminAddr else "Admin receiving address not configured for this asset yet."
        if (netNote.isNotBlank()) {
            binding.tvAdminNetworkNote.text = netNote
            binding.tvAdminNetworkNote.visibility = View.VISIBLE
        } else {
            binding.tvAdminNetworkNote.visibility = View.GONE
        }

        val amt = binding.etSellAmount.text?.toString()?.toDoubleOrNull() ?: 0.0
        if (amt <= 0 || rate <= 0) {
            binding.layoutSellPreview.visibility = View.GONE
            return
        }
        val payout = amt * rate
        binding.layoutSellPreview.visibility = View.VISIBLE
        binding.tvSellPreview.text = "Expected Payout: ${payout.toNaira()} to your NGN Wallet"
    }

    private fun updateSwapPreview() {
        val fromCode = extractSwapCode(binding.spinnerSwapFrom.text.toString())
        val toCode = extractSwapCode(binding.spinnerSwapTo.text.toString())
        val amt = binding.etSwapAmount.text?.toString()?.toDoubleOrNull() ?: 0.0

        if (fromCode.isEmpty() || toCode.isEmpty() || fromCode == toCode || amt <= 0) {
            binding.layoutSwapPreview.visibility = View.GONE
            return
        }

        val fromNgn = if (fromCode == "NGN") 1.0 else baseNgnFor(fromCode)
        val toNgn = if (toCode == "NGN") 1.0 else baseNgnFor(toCode)
        if (fromNgn <= 0 || toNgn <= 0) {
            binding.layoutSwapPreview.visibility = View.GONE
            return
        }

        val grossNgn = amt * fromNgn
        val feeNgn = grossNgn * (swapFeePercent / 100.0)
        val netNgn = (grossNgn - feeNgn).coerceAtLeast(0.0)
        val outAmt = netNgn / toNgn

        binding.layoutSwapPreview.visibility = View.VISIBLE
        binding.tvSwapPreview.text = if (toCode == "NGN") {
            "Receive ${netNgn.toNaira()} (NGN Wallet)"
        } else {
            "Receive ${formatCryptoAmount(outAmt, toCode)}"
        }
        binding.tvSwapFee.text = "Swap Fee ($swapFeePercent%): ${feeNgn.toNaira()}"
    }

    // ─── Submissions ───────────────────────────────────────────────────────────

    private fun submitBuyOrder() {
        val asset = selectedBuyAsset() ?: run { snack("No asset available to buy"); return }
        val code = asset["currency"] as? String ?: ""
        val amt = binding.etBuyAmount.text?.toString()?.toDoubleOrNull() ?: 0.0
        val unit = if (binding.spinnerBuyUnit.text.toString().contains("Crypto")) "CRYPTO" else "NGN"
        val delivery = if (binding.spinnerBuyDelivery.text.toString().startsWith("External")) "external" else "internal"
        val destAddr = binding.etBuyAddress.text?.toString()?.trim() ?: ""

        if (amt <= 0) { snack("Enter a valid positive amount"); return }
        if (delivery == "external" && destAddr.length < 10) {
            snack("Enter a valid receiving wallet address")
            return
        }

        promptPinAndExecute("Authorize Crypto Buy") { pin ->
            postOrder(
                title = "Buy Order Submitted",
                loadingText = "Placing A2P Buy order...",
                payload = mapOf(
                    "action" to "a2p_buy",
                    "asset" to code,
                    "amount" to amt,
                    "amount_unit" to unit,
                    "delivery_mode" to delivery,
                    "destination_address" to destAddr,
                    "pin" to pin
                )
            )
        }
    }

    private fun submitSellOrder() {
        val asset = selectedSellAsset() ?: run { snack("No asset available to sell"); return }
        val code = asset["currency"] as? String ?: ""
        val amt = binding.etSellAmount.text?.toString()?.toDoubleOrNull() ?: 0.0
        val source = if (binding.spinnerSellSource.text.toString().contains("External")) "external" else "internal"
        val txHash = binding.etSellTxHash.text?.toString()?.trim() ?: ""
        val note = binding.etSellNote.text?.toString()?.trim() ?: ""

        if (amt <= 0) { snack("Enter a valid crypto quantity to sell"); return }
        if (source == "external" && currentAdminAddress.isBlank()) {
            snack("Admin deposit address is not configured for this asset yet")
            return
        }
        if (source == "external" && txHash.length < 8) {
            snack("Enter the Blockchain TX Hash (minimum 8 characters) after sending to admin")
            return
        }

        promptPinAndExecute("Authorize Crypto Sell") { pin ->
            postOrder(
                title = "Sell Order Submitted",
                loadingText = "Submitting P2A Sell order...",
                payload = mapOf(
                    "action" to "p2a_sell",
                    "asset" to code,
                    "amount" to amt,
                    "sell_source" to source,
                    "tx_hash" to txHash,
                    "sender_note" to note,
                    "pin" to pin
                )
            )
        }
    }

    private fun submitSwapOrder() {
        val fromCode = extractSwapCode(binding.spinnerSwapFrom.text.toString())
        val toCode = extractSwapCode(binding.spinnerSwapTo.text.toString())
        val amt = binding.etSwapAmount.text?.toString()?.toDoubleOrNull() ?: 0.0

        if (fromCode.isEmpty() || toCode.isEmpty() || fromCode == toCode) {
            snack("Select two different wallets to swap")
            return
        }
        if (amt <= 0) {
            snack("Enter a valid swap amount")
            return
        }

        promptPinAndExecute("Authorize Instant Swap") { pin ->
            postOrder(
                title = "Swap Completed",
                loadingText = "Executing instant swap...",
                payload = mapOf(
                    "action" to "a2p_swap",
                    "swap_from" to fromCode,
                    "swap_to" to toCode,
                    "amount" to amt,
                    "pin" to pin
                )
            )
        }
    }

    private fun promptPinAndExecute(title: String, onPin: (String) -> Unit) {
        val prefs = PreferenceManager(requireContext())
        if (!prefs.getBoolean(Constants.KEY_PIN_SET, false)) {
            val dlg = MaterialAlertDialogBuilder(requireContext())
                .setTitle("Transaction PIN Required")
                .setMessage("Please set your 4-digit security PIN before placing CryptoHub orders.")
                .setPositiveButton("Set PIN") { _, _ -> findNavController().safeNavigate(R.id.nav_set_pin) }
                .setNegativeButton("Cancel", null)
                .show()
            dlg.window?.decorView?.let { BrandTheme.applyView(it) }
            return
        }

        val input = EditText(requireContext()).apply {
            inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD
            filters = arrayOf(InputFilter.LengthFilter(4))
            hint = "4-digit PIN"
        }
        val pad = dpToPx(20)
        val wrap = FrameLayout(requireContext()).apply {
            setPadding(pad, pad / 2, pad, 0)
            addView(input)
        }

        val dlg = MaterialAlertDialogBuilder(requireContext())
            .setTitle(title)
            .setMessage("Enter your 4-digit Transaction PIN to continue.")
            .setView(wrap)
            .setPositiveButton("Confirm") { _, _ ->
                val pin = input.text?.toString()?.trim() ?: ""
                if (pin.length != 4) {
                    snack("Enter your 4-digit Transaction PIN")
                } else {
                    TransactionPin.current = pin
                    onPin(pin)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
        dlg.window?.decorView?.let { BrandTheme.applyView(it) }
    }

    private fun postOrder(title: String, loadingText: String, payload: Map<String, Any>) {
        LoadingOverlay.show(requireContext(), loadingText)
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val req = payload.toMutableMap()
                req["api_key"] = prefs.getApiKey()
                val body = RetrofitClient.getService().cryptoAction(req).body()
                val status = body?.get("status") as? String ?: "error"
                val code = body?.get("code") as? String ?: ""
                val msg = body?.get("message") as? String ?: "Request failed"

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    if (status == "success") {
                        binding.etBuyAmount.setText("")
                        binding.etSellAmount.setText("")
                        binding.etSellTxHash.setText("")
                        binding.etSellNote.setText("")
                        binding.etSwapAmount.setText("")
                        showSuccessDialog(title, msg)
                        loadData(forceRefresh = false)
                    } else if (code == "KYC_REQUIRED") {
                        val dlg = MaterialAlertDialogBuilder(requireContext())
                            .setTitle("KYC Verification Required")
                            .setMessage(msg)
                            .setPositiveButton("Verify KYC") { _, _ -> findNavController().safeNavigate(R.id.nav_kyc) }
                            .setNegativeButton("Close", null)
                            .show()
                        dlg.window?.decorView?.let { BrandTheme.applyView(it) }
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

    // ─── Rendering Wallets & History ───────────────────────────────────────────

    private fun renderWallets() {
        val container = binding.containerWallets
        container.removeAllViews()

        if (wallets.isEmpty()) {
            binding.tvNoWallets.visibility = View.VISIBLE
            return
        }
        binding.tvNoWallets.visibility = View.GONE

        val pairs = wallets.chunked(2)
        for (pair in pairs) {
            val row = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.HORIZONTAL
                layoutParams = LinearLayout.LayoutParams(
                    LinearLayout.LayoutParams.MATCH_PARENT,
                    LinearLayout.LayoutParams.WRAP_CONTENT
                ).also { it.bottomMargin = dpToPx(8) }
            }
            for (i in 0..1) {
                val params = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
                if (i == 0) params.marginEnd = dpToPx(4) else params.marginStart = dpToPx(4)

                if (i < pair.size) {
                    val card = inflateWalletCard(pair[i])
                    card.layoutParams = params
                    row.addView(card)
                } else {
                    val spacer = View(requireContext())
                    spacer.layoutParams = params
                    row.addView(spacer)
                }
            }
            container.addView(row)
        }
    }

    private fun inflateWalletCard(wallet: Map<String, Any>): View {
        val currency = wallet["currency"] as? String ?: ""
        val symbol = wallet["symbol"] as? String ?: currency
        val label = wallet["label"] as? String ?: currency
        val balance = (wallet["balance"] as? Number)?.toDouble() ?: 0.0
        val baseRate = (wallet["base_ngn"] as? Number)?.toDouble() ?: 0.0
        val valNgn = (wallet["val_ngn"] as? Number)?.toDouble() ?: (balance * baseRate)

        val card = layoutInflater.inflate(R.layout.item_crypto_wallet_card, null, false)
        val tvIcon = card.findViewById<TextView>(R.id.tv_coin_icon)
        tvIcon.text = cryptoSymbol(currency)
        tvIcon.background = circleDrawable(cryptoColor(currency))

        card.findViewById<TextView>(R.id.tv_label).text = label
        card.findViewById<TextView>(R.id.tv_ticker).text = currency
        card.findViewById<TextView>(R.id.tv_balance).text = formatCryptoAmount(balance, symbol)

        val tvRate = card.findViewById<TextView>(R.id.tv_rate)
        tvRate.text = if (baseRate > 0) "1 $symbol ≈ ${baseRate.toNaira()}" else "Rate unavailable"

        val tvTotal = card.findViewById<TextView>(R.id.tv_total_ngn)
        if (valNgn > 0) {
            tvTotal.text = "Value: ${valNgn.toNaira()}"
            tvTotal.visibility = View.VISIBLE
        } else {
            tvTotal.visibility = View.GONE
        }

        card.setOnClickListener {
            val lbl = assetLabel(wallet)
            when (activeTab) {
                "buy" -> if ((wallet["buy_enabled"] as? Boolean) == true) {
                    binding.spinnerBuyAsset.setText(lbl, false)
                    updateBuyPreview()
                }
                "sell" -> if ((wallet["sell_enabled"] as? Boolean) == true) {
                    binding.spinnerSellAsset.setText(lbl, false)
                    updateSellPreview()
                }
                "swap" -> {
                    val opt = "$label ($currency)"
                    if (swapFromOptions.contains(opt)) {
                        binding.spinnerSwapFrom.setText(opt, false)
                        updateSwapPreview()
                    }
                }
            }
        }
        return card
    }

    private fun renderHistory(history: List<Map<String, Any>>) {
        val container = binding.containerHistory
        container.removeAllViews()

        history.forEachIndexed { index, item ->
            val row = layoutInflater.inflate(R.layout.item_crypto_history_row, container, false)
            val rawType = (item["type"] as? String ?: "")
            val typeLabel = when (rawType.lowercase()) {
                "a2p_buy" -> "BUY CRYPTO (A2P)"
                "p2a_sell" -> "SELL CRYPTO (P2A)"
                "a2p_swap" -> "INSTANT SWAP"
                else -> rawType.replace("_", " ").uppercase()
            }
            val currency = item["currency"] as? String ?: ""
            val amount = (item["amount"] as? Number)?.toDouble() ?: 0.0
            val ngnAmt = (item["ngn_amount"] as? Number)?.toDouble() ?: 0.0
            val status = (item["status"] as? Number)?.toInt() ?: 0
            val date = item["date"] as? String ?: ""
            val rejectReason = (item["reject_reason"] as? String)?.trim() ?: ""

            row.findViewById<TextView>(R.id.tv_type).text = typeLabel
            row.findViewById<TextView>(R.id.tv_date).text = buildString {
                append(date)
                if (ngnAmt > 0) append(" • ${ngnAmt.toNaira()}")
                if (rejectReason.isNotEmpty() && status == 3) append("\nNote: $rejectReason")
            }
            row.findViewById<TextView>(R.id.tv_amount).text = formatCryptoAmount(amount, currency)

            val (statusText, statusColor) = when (status) {
                1 -> "Completed" to requireContext().getColor(R.color.success)
                2 -> "Pending Review" to requireContext().getColor(R.color.warning)
                else -> "Rejected" to requireContext().getColor(R.color.error)
            }
            row.findViewById<TextView>(R.id.tv_status).apply {
                text = statusText
                setTextColor(statusColor)
            }
            row.findViewById<View>(R.id.view_status_dot).background = circleDrawable(statusColor)

            container.addView(row)
            if (index < history.lastIndex) {
                val divider = View(requireContext()).apply {
                    layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 1)
                    setBackgroundColor(requireContext().getColor(R.color.divider))
                }
                container.addView(divider)
            }
        }
    }

    private fun showAllHistoryDialog() {
        binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val body = RetrofitClient.getService().cryptoAction(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "history")
                ).body()

                @Suppress("UNCHECKED_CAST")
                val history = (body?.get("history") as? List<Map<String, Any>>) ?: emptyList()

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    if (history.isEmpty()) { snack("No crypto orders yet"); return@runOnUiThread }

                    val items = history.map { item ->
                        val rawType = (item["type"] as? String ?: "")
                        val type = when (rawType.lowercase()) {
                            "a2p_buy" -> "BUY CRYPTO (A2P)"
                            "p2a_sell" -> "SELL CRYPTO (P2A)"
                            "a2p_swap" -> "INSTANT SWAP"
                            else -> rawType.replace("_", " ").uppercase()
                        }
                        val currency = item["currency"] as? String ?: ""
                        val amount = (item["amount"] as? Number)?.toDouble() ?: 0.0
                        val ngnAmt = (item["ngn_amount"] as? Number)?.toDouble() ?: 0.0
                        val status = (item["status"] as? Number)?.toInt() ?: 0
                        val date = item["date"] as? String ?: ""
                        val icon = when (status) { 1 -> "✓"; 2 -> "⟳"; else -> "✕" }
                        val ngnPart = if (ngnAmt > 0) " (${ngnAmt.toNaira()})" else ""
                        "$icon $type\n${formatCryptoAmount(amount, currency)}$ngnPart • $date"
                    }.toTypedArray()

                    val dlg = MaterialAlertDialogBuilder(requireContext())
                        .setTitle("Crypto Order History")
                        .setItems(items, null)
                        .setPositiveButton("Close", null)
                        .show()
                    dlg.window?.decorView?.let { BrandTheme.applyView(it) }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    snack("Network error: ${e.message}")
                }
            }
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private fun assetLabel(w: Map<String, Any>): String {
        val label = w["label"] as? String ?: w["currency"] as? String ?: ""
        val code = w["currency"] as? String ?: ""
        return "$label [$code]"
    }

    private fun extractSwapCode(option: String): String {
        if (option.startsWith("NGN")) return "NGN"
        return option.substringAfterLast("(", "").trimEnd(')').trim()
    }

    private fun baseNgnFor(code: String): Double {
        val w = wallets.firstOrNull { (it["currency"] as? String) == code } ?: return 0.0
        return (w["base_ngn"] as? Number)?.toDouble() ?: 0.0
    }

    private fun showSuccessDialog(title: String, message: String) {
        val dlg = MaterialAlertDialogBuilder(requireContext())
            .setTitle(title)
            .setMessage(message)
            .setPositiveButton("Done", null)
            .show()
        dlg.window?.decorView?.let { BrandTheme.applyView(it) }
    }

    private fun formatCryptoAmount(amount: Double, currency: String): String {
        val formatted = "%.8f".format(amount).trimEnd('0').trimEnd('.')
        return "$formatted $currency"
    }

    private fun cryptoSymbol(currency: String): String = when (currency) {
        "BTC" -> "₿"
        "ETH" -> "Ξ"
        "LTC" -> "Ł"
        "TRX" -> "TRX"
        "USDT_TRC20", "USDT_BEP20", "USDT_TRX" -> "₮"
        "USDC" -> "$"
        "BCH" -> "BCH"
        "DOGE" -> "Đ"
        else -> currency.take(3)
    }

    private fun cryptoColor(currency: String): Int = Color.parseColor(
        when (currency) {
            "BTC" -> "#F7931A"
            "ETH" -> "#627EEA"
            "LTC" -> "#A0A0A0"
            "TRX" -> "#EF0027"
            "USDT_TRC20", "USDT_BEP20", "USDT_TRX" -> "#26A17B"
            "USDC" -> "#2775CA"
            "BCH" -> "#8DC351"
            "DOGE" -> "#C2A633"
            else -> "#0F766E"
        }
    )

    private fun circleDrawable(color: Int): GradientDrawable =
        GradientDrawable().apply { shape = GradientDrawable.OVAL; setColor(color) }

    private fun dpToPx(dp: Int): Int = (dp * resources.displayMetrics.density).toInt()

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
