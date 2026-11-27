package com.jikabiz.app.ui.services

import android.os.Bundle
import android.text.InputFilter
import android.text.InputType
import android.view.View
import android.widget.ArrayAdapter
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import com.jikabiz.app.R
import com.jikabiz.app.api.RetrofitClient
import com.jikabiz.app.api.TransactionPin
import com.jikabiz.app.databinding.FragmentIntlTransferBinding
import com.jikabiz.app.util.BrandTheme
import com.jikabiz.app.util.LoadingOverlay
import com.jikabiz.app.util.PreferenceManager
import com.jikabiz.app.util.safeNavigate
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

class IntlTransferFragment : Fragment(R.layout.fragment_intl_transfer) {

    private var _binding: FragmentIntlTransferBinding? = null
    private val binding get() = _binding!!

    private var routeSnapshot: String = ""
    private var requestKey: String = ""
    private var kycRequired: Boolean = false
    private var pinRequired: Boolean = true
    private var services: List<Pair<String, String>> = emptyList()
    private var currencies: List<String> = listOf("USD", "GBP", "EUR", "CNY", "CAD", "AUD", "AED")

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentIntlTransferBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnGoKyc.setOnClickListener { findNavController().safeNavigate(R.id.nav_kyc) }
        binding.btnQuote.setOnClickListener { fetchQuote() }
        binding.btnSubmit.setOnClickListener { validateAndConfirm() }

        loadInfo()
    }

    private fun loadInfo() {
        LoadingOverlay.show(requireContext(), "Loading transfer corridors...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val resp = RetrofitClient.getService().intlTransfer(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "info")
                )
                val body = resp.body()
                if (_binding == null) return@launch
                LoadingOverlay.dismiss()
                if (!resp.isSuccessful || body?.get("status") != "success") {
                    val msg = body?.get("message") as? String ?: "Unable to load transfer service."
                    binding.tvProviderInfo.text = msg
                    binding.btnSubmit.isEnabled = false
                    return@launch
                }

                routeSnapshot = body["route_snapshot"] as? String ?: ""
                requestKey = body["request_key"] as? String ?: ""
                kycRequired = body["kyc_required"] as? Boolean ?: false
                pinRequired = body["pin_required"] as? Boolean ?: true
                val providerLabel = body["provider_label"] as? String ?: ""
                val routingAvailable = body["routing_available"] as? Boolean ?: false

                binding.cardKycNotice.visibility = if (kycRequired) View.VISIBLE else View.GONE
                binding.btnSubmit.isEnabled = routingAvailable && !kycRequired
                binding.tvProviderInfo.text = if (routingAvailable) {
                    "Routed via $providerLabel. Requests are verified before settlement."
                } else {
                    "International transfer routing is temporarily unavailable."
                }

                @Suppress("UNCHECKED_CAST")
                val rawServices = body["services"] as? List<Map<String, Any>> ?: emptyList()
                services = rawServices.mapNotNull {
                    val slug = it["slug"] as? String ?: return@mapNotNull null
                    val label = it["label"] as? String ?: slug
                    slug to label
                }
                binding.spinnerService.adapter = ArrayAdapter(
                    requireContext(),
                    android.R.layout.simple_spinner_dropdown_item,
                    services.map { it.second }
                )

                @Suppress("UNCHECKED_CAST")
                val rawCur = body["currencies"] as? List<String>
                if (!rawCur.isNullOrEmpty()) currencies = rawCur
                binding.spinnerCurrency.adapter = ArrayAdapter(
                    requireContext(),
                    android.R.layout.simple_spinner_dropdown_item,
                    currencies
                )

                @Suppress("UNCHECKED_CAST")
                val history = body["requests"] as? List<Map<String, Any>> ?: emptyList()
                renderHistory(history)
            } catch (_: Exception) {
                if (_binding == null) return@launch
                LoadingOverlay.dismiss()
                binding.tvProviderInfo.text = "Unable to connect. Please try again."
                binding.btnSubmit.isEnabled = false
            }
        }
    }

    private fun fetchQuote() {
        val amt = binding.etAmountUsd.text?.toString()?.trim()?.toDoubleOrNull() ?: 0.0
        if (amt <= 0) {
            snack("Enter a USD amount first")
            return
        }
        val cur = currencies.getOrNull(binding.spinnerCurrency.selectedItemPosition) ?: "USD"
        binding.tvQuoteResult.text = "Checking quote..."
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val resp = RetrofitClient.getService().intlTransfer(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "quote", "amount_usd" to amt, "currency" to cur)
                )
                val body = resp.body()
                if (_binding == null) return@launch
                binding.tvQuoteResult.text = body?.get("quote_note") as? String
                    ?: (body?.get("message") as? String ?: "Quote unavailable")
            } catch (_: Exception) {
                if (_binding != null) binding.tvQuoteResult.text = "Quote unavailable"
            }
        }
    }

    private fun validateAndConfirm() {
        if (kycRequired) {
            snack("Complete KYC verification first")
            return
        }
        val svcIdx = binding.spinnerService.selectedItemPosition
        val svcSlug = services.getOrNull(svcIdx)?.first ?: ""
        val cur = currencies.getOrNull(binding.spinnerCurrency.selectedItemPosition) ?: "USD"
        val amt = binding.etAmountUsd.text?.toString()?.trim()?.toDoubleOrNull() ?: 0.0
        val name = binding.etRecipientName.text?.toString()?.trim() ?: ""
        val country = binding.etRecipientCountry.text?.toString()?.trim() ?: ""
        val account = binding.etRecipientAccount.text?.toString()?.trim() ?: ""

        if (svcSlug.isEmpty() || amt < 1.0 || name.isEmpty() || country.isEmpty() || account.isEmpty()) {
            snack("Fill in corridor, amount ($1+), recipient name, country, and account ID")
            return
        }

        if (pinRequired) {
            promptPin("Confirm Transfer Request") { pin -> submitTransfer(svcSlug, cur, amt, name, country, account, pin) }
        } else {
            submitTransfer(svcSlug, cur, amt, name, country, account, "")
        }
    }

    private fun submitTransfer(
        svcSlug: String, cur: String, amt: Double,
        name: String, country: String, account: String, pin: String
    ) {
        LoadingOverlay.show(requireContext(), "Submitting transfer request...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val payload = mutableMapOf<String, Any>(
                    "api_key" to prefs.getApiKey(),
                    "action" to "submit",
                    "route_snapshot" to routeSnapshot,
                    "request_key" to requestKey,
                    "service" to svcSlug,
                    "currency" to cur,
                    "amount_usd" to amt,
                    "recipient_name" to name,
                    "recipient_country" to country,
                    "recipient_account" to account
                )
                if (pin.isNotEmpty()) payload["pin"] = pin
                val resp = RetrofitClient.getService().intlTransfer(payload)
                val body = resp.body()
                if (_binding == null) return@launch
                LoadingOverlay.dismiss()
                if (resp.isSuccessful && body?.get("status") == "success") {
                    val msg = body["message"] as? String ?: "Transfer request submitted"
                    requestKey = body["request_key"] as? String ?: requestKey
                    @Suppress("UNCHECKED_CAST")
                    val history = body["requests"] as? List<Map<String, Any>> ?: emptyList()
                    renderHistory(history)
                    binding.etAmountUsd.setText("")
                    binding.etRecipientName.setText("")
                    binding.etRecipientCountry.setText("")
                    binding.etRecipientAccount.setText("")
                    snack(msg)
                } else {
                    val code = body?.get("code") as? String ?: ""
                    val msg = body?.get("message") as? String ?: "Transfer request failed"
                    snack(msg)
                    if (code == "ROUTE_CHANGED") loadInfo()
                }
            } catch (_: Exception) {
                if (_binding == null) return@launch
                LoadingOverlay.dismiss()
                snack("Network error. Retry safely without changing fields.")
            }
        }
    }

    private fun renderHistory(rows: List<Map<String, Any>>) {
        val box = binding.llHistory
        box.removeAllViews()
        if (rows.isEmpty()) {
            box.addView(TextView(requireContext()).apply {
                text = "No international transfer requests yet."
                textSize = 13f
                tag = "brand:text=on_surface"
            })
            return
        }
        rows.forEach { row ->
            val card = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(16, 12, 16, 12)
                tag = "brand:bg=surface"
            }
            val ref = row["reference"] as? String ?: ""
            val status = row["status"] as? String ?: "pending"
            val summary = row["summary"] as? String ?: ""
            val created = row["created_at"] as? String ?: ""
            card.addView(TextView(requireContext()).apply {
                text = "$ref · ${status.uppercase()}"
                textSize = 13f
                setTypeface(typeface, android.graphics.Typeface.BOLD)
                tag = "brand:text=on_surface"
            })
            card.addView(TextView(requireContext()).apply {
                text = "$summary\n$created"
                textSize = 12f
                tag = "brand:text=on_surface"
            })
            box.addView(card)
        }
    }

    private fun promptPin(title: String, onPin: (String) -> Unit) {
        val input = EditText(requireContext()).apply {
            inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD
            filters = arrayOf(InputFilter.LengthFilter(4))
            hint = "4-digit Transaction PIN"
        }
        val wrap = FrameLayout(requireContext()).apply {
            setPadding(48, 16, 48, 8)
            addView(input)
        }
        val dlg = MaterialAlertDialogBuilder(requireContext())
            .setTitle(title)
            .setMessage("Enter your 4-digit Transaction PIN to confirm.")
            .setView(wrap)
            .setPositiveButton("Confirm") { _, _ ->
                val pin = input.text?.toString()?.trim() ?: ""
                if (pin.length != 4) snack("Enter your 4-digit Transaction PIN")
                else {
                    TransactionPin.current = pin
                    onPin(pin)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
        dlg.window?.decorView?.let { BrandTheme.applyView(it) }
    }

    private fun snack(msg: String) {
        view?.let { Snackbar.make(it, msg, Snackbar.LENGTH_LONG).show() }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
