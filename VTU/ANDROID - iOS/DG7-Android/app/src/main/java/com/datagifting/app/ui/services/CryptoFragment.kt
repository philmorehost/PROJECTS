package com.datagifting.app.ui.services

import android.content.Intent
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.net.Uri
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
import com.datagifting.app.databinding.FragmentCryptoBinding
import com.datagifting.app.util.LoadingOverlay
import com.datagifting.app.util.PreferenceManager
import com.datagifting.app.util.copyToClipboard
import com.datagifting.app.util.toNaira
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import com.google.android.material.textfield.TextInputEditText
import kotlinx.coroutines.Job
import kotlinx.coroutines.async
import kotlinx.coroutines.launch

class CryptoFragment : Fragment(R.layout.fragment_crypto) {

    private var _binding: FragmentCryptoBinding? = null
    private val binding get() = _binding!!

    // State loaded from API
    private var wallets: List<Map<String, Any>> = emptyList()
    private var swapFeePercent: Double = 0.0
    private var ngnBalance: Double = 0.0

    // Cancellable background job for parallel rate-fetching
    private var rateJob: Job? = null

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentCryptoBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.swipeRefresh.setColorSchemeResources(R.color.primary)
        binding.swipeRefresh.setOnRefreshListener { loadData() }

        binding.btnActionDeposit.setOnClickListener { showDepositDialog() }
        binding.btnActionSend.setOnClickListener { showSendDialog() }
        binding.btnActionSwap.setOnClickListener { showSwapDialog(toNgn = false) }
        binding.btnActionToNgn.setOnClickListener { showSwapDialog(toNgn = true) }
        binding.btnViewAllHistory.setOnClickListener { showAllHistoryDialog() }

        loadData()
    }

    // ─── Data loading ─────────────────────────────────────────────────────────

    private fun loadData() {
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            val w = async { loadWallets() }
            val h = async { loadHistory() }
            w.await()
            h.await()
            if (_binding != null) binding.swipeRefresh.isRefreshing = false
        }
    }

    private suspend fun loadWallets() {
        try {
            val prefs = PreferenceManager(requireContext())
            val resp = RetrofitClient.getService().cryptoAction(
                mapOf("api_key" to prefs.getApiKey(), "action" to "get_wallets")
            )
            val body = resp.body() ?: return
            if ((body["status"] as? String) != "success") return

            @Suppress("UNCHECKED_CAST")
            wallets = (body["wallets"] as? List<Map<String, Any>>) ?: emptyList()
            swapFeePercent = (body["swap_fee_percent"] as? Double) ?: 0.0
            ngnBalance = (body["ngn_balance"] as? Double) ?: 0.0

            activity?.runOnUiThread {
                if (_binding == null) return@runOnUiThread
                binding.tvNgnBalance.text = ngnBalance.toNaira()
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
                renderHistory(history.take(5))
                binding.tvNoHistory.visibility = if (history.isEmpty()) View.VISIBLE else View.GONE
            }
        } catch (_: Exception) {}
    }

    // ─── Rendering ────────────────────────────────────────────────────────────

    private fun renderWallets() {
        val container = binding.containerWallets
        container.removeAllViews()

        if (wallets.isEmpty()) {
            binding.tvNoWallets.visibility = View.VISIBLE
            return
        }
        binding.tvNoWallets.visibility = View.GONE

        // 2-column grid via paired horizontal rows
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

        // Asynchronously fetch NGN rates for each wallet
        fetchRates()
    }

    private fun inflateWalletCard(wallet: Map<String, Any>): View {
        val currency = wallet["currency"] as? String ?: ""
        val label = wallet["label"] as? String ?: currency
        val balance = (wallet["balance"] as? Double) ?: 0.0

        val card = layoutInflater.inflate(R.layout.item_crypto_wallet_card, null, false)

        // Coin icon circle
        val tvIcon = card.findViewById<TextView>(R.id.tv_coin_icon)
        tvIcon.text = cryptoSymbol(currency)
        tvIcon.background = circleDrawable(cryptoColor(currency))

        card.findViewById<TextView>(R.id.tv_label).text = label
        card.findViewById<TextView>(R.id.tv_ticker).text = currency
        card.findViewById<TextView>(R.id.tv_balance).text = formatCryptoAmount(balance, currency)

        val tvRate = card.findViewById<TextView>(R.id.tv_rate)
        tvRate.text = "Loading rate…"
        tvRate.tag = currency   // used by updateWalletCardRate()

        card.findViewById<TextView>(R.id.tv_total_ngn).visibility = View.GONE

        return card
    }

    private fun fetchRates() {
        rateJob?.cancel()
        rateJob = lifecycleScope.launch {
            val prefs = PreferenceManager(requireContext())
            for (wallet in wallets) {
                val currency = wallet["currency"] as? String ?: continue
                try {
                    val resp = RetrofitClient.getService().cryptoAction(
                        mapOf("api_key" to prefs.getApiKey(), "action" to "get_rate", "from" to currency, "to" to "NGN")
                    )
                    val rate = (resp.body()?.get("rate") as? Double) ?: 0.0
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        updateWalletCardRate(wallet, rate)
                    }
                } catch (_: Exception) {
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        findRateTvForCurrency(currency)?.text = "Rate unavailable"
                    }
                }
            }
        }
    }

    private fun updateWalletCardRate(wallet: Map<String, Any>, rate: Double) {
        val currency = wallet["currency"] as? String ?: return
        val balance = (wallet["balance"] as? Double) ?: 0.0
        val tvRate = findRateTvForCurrency(currency) ?: return

        if (rate > 0) {
            tvRate.text = "≈ ${rate.toNaira()} / 1 $currency"
            // Show total NGN value if balance > 0
            val totalNgnView = tvRate.parent as? LinearLayout ?: return
            val tvTotal = totalNgnView.findViewById<TextView>(R.id.tv_total_ngn)
            if (balance > 0 && tvTotal != null) {
                tvTotal.text = "Total: ${(balance * rate).toNaira()}"
                tvTotal.visibility = View.VISIBLE
            }
        } else {
            tvRate.text = "Rate unavailable"
        }
    }

    /** Walk the wallet container to find the rate TextView tagged with [currency]. */
    private fun findRateTvForCurrency(currency: String): TextView? {
        val container = _binding?.containerWallets ?: return null
        for (i in 0 until container.childCount) {
            val row = container.getChildAt(i) as? LinearLayout ?: continue
            for (j in 0 until row.childCount) {
                val tv = row.getChildAt(j)?.findViewById<TextView>(R.id.tv_rate)
                if (tv?.tag == currency) return tv
            }
        }
        return null
    }

    private fun renderHistory(history: List<Map<String, Any>>) {
        val container = binding.containerHistory
        container.removeAllViews()

        history.forEachIndexed { index, item ->
            val row = layoutInflater.inflate(R.layout.item_crypto_history_row, container, false)
            val type = (item["type"] as? String ?: "").replace("_", " ").uppercase()
            val currency = item["currency"] as? String ?: ""
            val amount = (item["amount"] as? Double) ?: 0.0
            val status = (item["status"] as? Double)?.toInt() ?: 0
            val date = item["date"] as? String ?: ""

            row.findViewById<TextView>(R.id.tv_type).text = type
            row.findViewById<TextView>(R.id.tv_date).text = date
            row.findViewById<TextView>(R.id.tv_amount).text = formatCryptoAmount(amount, currency)

            val (statusText, statusColor) = when (status) {
                1 -> "Success" to requireContext().getColor(R.color.success)
                2 -> "Pending" to requireContext().getColor(R.color.warning)
                else -> "Expired" to requireContext().getColor(R.color.error)
            }
            row.findViewById<TextView>(R.id.tv_status).apply {
                text = statusText
                setTextColor(statusColor)
            }
            row.findViewById<View>(R.id.view_status_dot).apply {
                background = circleDrawable(statusColor)
            }

            container.addView(row)

            if (index < history.size - 1) {
                val divider = View(requireContext()).apply {
                    layoutParams = LinearLayout.LayoutParams(
                        LinearLayout.LayoutParams.MATCH_PARENT, 1
                    ).also { it.marginStart = dpToPx(14); it.marginEnd = dpToPx(14) }
                    setBackgroundColor(requireContext().getColor(R.color.divider))
                }
                container.addView(divider)
            }
        }
    }

    // ─── Deposit / Invoice ────────────────────────────────────────────────────

    private fun showDepositDialog() {
        val dialogView = layoutInflater.inflate(R.layout.dialog_crypto_deposit, null)
        val spinner = dialogView.findViewById<AutoCompleteTextView>(R.id.spinner_currency)
        val etAmount = dialogView.findViewById<TextInputEditText>(R.id.et_amount)

        val labels = wallets.map { "${it["label"] as? String ?: it["currency"] as? String ?: ""} (${it["currency"] as? String ?: ""})" }
        if (labels.isEmpty()) { snack("No active crypto wallets available"); return }

        spinner.setAdapter(ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, labels))
        spinner.setText(labels.first(), false)

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("💰 Deposit Crypto")
            .setView(dialogView)
            .setPositiveButton("Create Invoice") { _, _ ->
                val idx = labels.indexOf(spinner.text.toString())
                val currency = if (idx >= 0) wallets[idx]["currency"] as? String ?: "" else ""
                val amount = etAmount.text?.toString()?.toDoubleOrNull() ?: 0.0
                if (currency.isEmpty()) { snack("Please select a currency"); return@setPositiveButton }
                createInvoice(currency, amount)
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun createInvoice(currency: String, amount: Double) {
        binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val params = mutableMapOf<String, Any>(
                    "api_key" to prefs.getApiKey(),
                    "action" to "create_invoice",
                    "currency" to currency
                )
                if (amount > 0) params["amount"] = amount

                val body = RetrofitClient.getService().cryptoAction(params).body()
                val status = body?.get("status") as? String ?: "error"

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    if (status == "success") {
                        @Suppress("UNCHECKED_CAST")
                        val data = body?.get("data") as? Map<String, Any> ?: emptyMap()
                        val localRef = body?.get("local_ref") as? String ?: ""
                        val invoiceUrl = data["invoice_url"] as? String ?: ""
                        val walletAddress = data["wallet_hash"] as? String ?: ""
                        showInvoiceDialog(localRef, invoiceUrl, walletAddress, currency)
                    } else {
                        snack(body?.get("message") as? String ?: "Failed to create invoice")
                    }
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

    private fun showInvoiceDialog(reference: String, invoiceUrl: String, walletAddress: String, currency: String) {
        val dv = layoutInflater.inflate(R.layout.dialog_crypto_invoice, null)
        dv.findViewById<TextView>(R.id.tv_reference).text = reference
        dv.findViewById<TextView>(R.id.tv_invoice_url).text = invoiceUrl.ifEmpty { "(No URL returned — contact support)" }

        if (walletAddress.isNotEmpty()) {
            dv.findViewById<TextView>(R.id.label_wallet).visibility = View.VISIBLE
            dv.findViewById<TextView>(R.id.tv_wallet_address).apply {
                text = walletAddress
                visibility = View.VISIBLE
            }
        }

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("$currency Invoice")
            .setView(dv)
            .setPositiveButton("Done", null)
            .show()

        dv.findViewById<android.widget.Button>(R.id.btn_copy_url).setOnClickListener {
            requireContext().copyToClipboard("Invoice URL", invoiceUrl)
        }
        dv.findViewById<android.widget.Button>(R.id.btn_open_url).setOnClickListener {
            if (invoiceUrl.isNotEmpty()) {
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(invoiceUrl)))
            } else {
                snack("No invoice URL available")
            }
        }
    }

    // ─── Send / Withdraw ──────────────────────────────────────────────────────

    private fun showSendDialog() {
        val dv = layoutInflater.inflate(R.layout.dialog_crypto_send, null)
        val spinner = dv.findViewById<AutoCompleteTextView>(R.id.spinner_currency)
        val etAddress = dv.findViewById<TextInputEditText>(R.id.et_address)
        val etAmount = dv.findViewById<TextInputEditText>(R.id.et_amount)
        val etPin = dv.findViewById<TextInputEditText>(R.id.et_pin)

        val walletsWithBalance = wallets.filter { (it["balance"] as? Double ?: 0.0) > 0 }
        val labels = walletsWithBalance.map { w ->
            val c = w["currency"] as? String ?: ""
            val l = w["label"] as? String ?: c
            val b = formatCryptoAmount((w["balance"] as? Double) ?: 0.0, c)
            "$l ($c)  [$b]"
        }
        if (labels.isEmpty()) { snack("You have no crypto balance to send"); return }

        spinner.setAdapter(ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, labels))
        spinner.setText(labels.first(), false)

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("📤 Send Crypto")
            .setView(dv)
            .setPositiveButton("Send") { _, _ ->
                val idx = labels.indexOf(spinner.text.toString())
                val currency = if (idx >= 0) walletsWithBalance[idx]["currency"] as? String ?: "" else ""
                val address = etAddress.text?.toString()?.trim() ?: ""
                val amount = etAmount.text?.toString()?.toDoubleOrNull() ?: 0.0
                val pin = etPin.text?.toString() ?: ""

                when {
                    currency.isEmpty() -> snack("Please select a currency")
                    address.isEmpty() -> snack("Enter recipient wallet address")
                    amount <= 0 -> snack("Enter a valid amount")
                    pin.length != 4 -> snack("Enter your 4-digit Transaction PIN")
                    else -> sendCrypto(currency, amount, address, pin)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun sendCrypto(currency: String, amount: Double, address: String, pin: String) {
        binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val body = RetrofitClient.getService().cryptoAction(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "action" to "withdraw",
                        "currency" to currency,
                        "amount" to amount,
                        "address" to address,
                        "pin" to pin
                    )
                ).body()
                val status = body?.get("status") as? String ?: "error"
                val msg = body?.get("message") as? String ?: "Transaction failed"

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    if (status == "success") {
                        showSuccessDialog("Send Successful ✅", msg)
                        loadData()
                    } else snack(msg)
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

    // ─── Swap ─────────────────────────────────────────────────────────────────

    private fun showSwapDialog(toNgn: Boolean) {
        val dv = layoutInflater.inflate(R.layout.dialog_crypto_swap, null)
        val spinnerFrom = dv.findViewById<AutoCompleteTextView>(R.id.spinner_from)
        val spinnerTo = dv.findViewById<AutoCompleteTextView>(R.id.spinner_to)
        val etAmount = dv.findViewById<TextInputEditText>(R.id.et_amount)
        val etPin = dv.findViewById<TextInputEditText>(R.id.et_pin)
        val layoutPreview = dv.findViewById<LinearLayout>(R.id.layout_receive_preview)
        val tvReceive = dv.findViewById<TextView>(R.id.tv_receive_amount)
        val tvFee = dv.findViewById<TextView>(R.id.tv_swap_fee)

        val walletsWithBalance = wallets.filter { (it["balance"] as? Double ?: 0.0) > 0 }
        val fromLabels = walletsWithBalance.map { w ->
            "${w["label"] as? String ?: w["currency"] as? String ?: ""} (${w["currency"] as? String ?: ""})"
        }
        // "To" includes NGN + all active crypto currencies
        val toOptions = mutableListOf("NGN – Naira Wallet")
        wallets.forEach { w ->
            val c = w["currency"] as? String ?: ""
            val l = w["label"] as? String ?: c
            if (c.isNotEmpty()) toOptions.add("$l ($c)")
        }

        if (fromLabels.isEmpty()) { snack("You have no crypto balance to swap"); return }

        spinnerFrom.setAdapter(ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, fromLabels))
        spinnerFrom.setText(fromLabels.first(), false)

        spinnerTo.setAdapter(ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, toOptions))
        spinnerTo.setText(if (toNgn) toOptions.first() else (toOptions.getOrNull(1) ?: toOptions.first()), false)

        var previewJob: Job? = null

        fun refreshPreview() {
            val fromIdx = fromLabels.indexOf(spinnerFrom.text.toString())
            val toIdx = toOptions.indexOf(spinnerTo.text.toString())
            val fromCurrency = if (fromIdx >= 0) walletsWithBalance[fromIdx]["currency"] as? String ?: "" else ""
            val toRaw = if (toIdx >= 0) toOptions[toIdx] else ""
            val toCurrency = if (toRaw.startsWith("NGN")) "NGN" else toRaw.substringAfterLast("(").trimEnd(')')
            val amount = etAmount.text?.toString()?.toDoubleOrNull() ?: 0.0

            if (fromCurrency.isEmpty() || toCurrency.isEmpty() || amount <= 0) {
                layoutPreview.visibility = View.GONE
                return
            }

            previewJob?.cancel()
            previewJob = lifecycleScope.launch {
                try {
                    val prefs = PreferenceManager(requireContext())
                    val body = RetrofitClient.getService().cryptoAction(
                        mapOf("api_key" to prefs.getApiKey(), "action" to "get_rate", "from" to fromCurrency, "to" to toCurrency)
                    ).body()
                    val rate = (body?.get("rate") as? Double) ?: 0.0
                    val gross = amount * rate
                    val fee = if (toCurrency == "NGN" && swapFeePercent > 0) (swapFeePercent / 100.0) * gross else 0.0
                    val net = gross - fee

                    activity?.runOnUiThread {
                        layoutPreview.visibility = View.VISIBLE
                        tvReceive.text = if (toCurrency == "NGN") net.toNaira()
                        else "${formatCryptoAmount(net, toCurrency)}"
                        if (fee > 0) {
                            tvFee.visibility = View.VISIBLE
                            tvFee.text = "Swap fee: ${fee.toNaira()} ($swapFeePercent%)"
                        } else {
                            tvFee.visibility = View.GONE
                        }
                    }
                } catch (_: Exception) {
                    activity?.runOnUiThread { layoutPreview.visibility = View.GONE }
                }
            }
        }

        val amountWatcher = object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) { refreshPreview() }
        }
        etAmount.addTextChangedListener(amountWatcher)
        spinnerFrom.setOnItemClickListener { _, _, _, _ -> refreshPreview() }
        spinnerTo.setOnItemClickListener { _, _, _, _ -> refreshPreview() }

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("🔄 Swap Crypto")
            .setView(dv)
            .setPositiveButton("Swap") { _, _ ->
                val fromIdx = fromLabels.indexOf(spinnerFrom.text.toString())
                val toIdx = toOptions.indexOf(spinnerTo.text.toString())
                val fromCurrency = if (fromIdx >= 0) walletsWithBalance[fromIdx]["currency"] as? String ?: "" else ""
                val toRaw = if (toIdx >= 0) toOptions[toIdx] else ""
                val toCurrency = if (toRaw.startsWith("NGN")) "NGN" else toRaw.substringAfterLast("(").trimEnd(')')
                val amount = etAmount.text?.toString()?.toDoubleOrNull() ?: 0.0
                val pin = etPin.text?.toString() ?: ""

                when {
                    fromCurrency.isEmpty() || toCurrency.isEmpty() -> snack("Please select currencies")
                    fromCurrency == toCurrency -> snack("Cannot swap to the same currency")
                    amount <= 0 -> snack("Enter a valid amount")
                    pin.length != 4 -> snack("Enter your 4-digit Transaction PIN")
                    else -> doSwap(fromCurrency, toCurrency, amount, pin)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun doSwap(from: String, to: String, amount: Double, pin: String) {
        LoadingOverlay.show(requireContext(), "Processing swap...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val body = RetrofitClient.getService().cryptoAction(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "action" to "swap",
                        "from" to from,
                        "to" to to,
                        "amount" to amount,
                        "pin" to pin
                    )
                ).body()
                val status = body?.get("status") as? String ?: "error"
                val msg = body?.get("message") as? String ?: "Swap failed"

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    if (status == "success") {
                        showSuccessDialog("Swap Successful 🎉", msg)
                        loadData()
                    } else snack(msg)
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

    // ─── Full history dialog ──────────────────────────────────────────────────

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
                    if (history.isEmpty()) { snack("No crypto transactions yet"); return@runOnUiThread }

                    val items = history.map { item ->
                        val type = (item["type"] as? String ?: "").replace("_", " ").uppercase()
                        val currency = item["currency"] as? String ?: ""
                        val amount = (item["amount"] as? Double) ?: 0.0
                        val status = (item["status"] as? Double)?.toInt() ?: 0
                        val date = item["date"] as? String ?: ""
                        val icon = when (status) { 1 -> "✅"; 2 -> "⏳"; else -> "❌" }
                        "$icon $type\n${formatCryptoAmount(amount, currency)}  •  $date"
                    }.toTypedArray()

                    MaterialAlertDialogBuilder(requireContext())
                        .setTitle("Crypto Transaction History")
                        .setItems(items, null)
                        .setPositiveButton("Close", null)
                        .show()
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

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private fun showSuccessDialog(title: String, message: String) {
        MaterialAlertDialogBuilder(requireContext())
            .setTitle(title)
            .setMessage(message)
            .setPositiveButton("Done", null)
            .show()
    }

    /** Format crypto amount, trimming trailing zeros. */
    private fun formatCryptoAmount(amount: Double, currency: String): String {
        val formatted = "%.8f".format(amount).trimEnd('0').trimEnd('.')
        return "$formatted $currency"
    }

    private fun cryptoSymbol(currency: String): String = when (currency) {
        "BTC" -> "₿"; "ETH" -> "Ξ"; "LTC" -> "Ł"
        "TRX" -> "TRX"; "USDT_TRX" -> "₮"; "USDC" -> "$"
        "BCH" -> "BCH"; "DOGE" -> "Ð"
        else -> currency.take(3)
    }

    private fun cryptoColor(currency: String): Int = Color.parseColor(
        when (currency) {
            "BTC" -> "#F7931A"
            "ETH" -> "#627EEA"
            "LTC" -> "#A0A0A0"
            "TRX" -> "#EF0027"
            "USDT_TRX" -> "#26A17B"
            "USDC" -> "#2775CA"
            "BCH" -> "#8DC351"
            "DOGE" -> "#C2A633"
            else -> "#0d6efd"
        }
    )

    private fun circleDrawable(color: Int): GradientDrawable =
        GradientDrawable().apply { shape = GradientDrawable.OVAL; setColor(color) }

    private fun dpToPx(dp: Int): Int = (dp * resources.displayMetrics.density).toInt()

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() {
        super.onDestroyView()
        rateJob?.cancel()
        _binding = null
    }
}

