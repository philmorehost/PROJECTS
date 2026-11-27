package com.mzeevtu.ui.services

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
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.api.TransactionPin
import com.mzeevtu.databinding.FragmentPosRequestBinding
import com.mzeevtu.util.BrandTheme
import com.mzeevtu.util.LoadingOverlay
import com.mzeevtu.util.PreferenceManager
import com.mzeevtu.util.safeNavigate
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

class PosRequestFragment : Fragment(R.layout.fragment_pos_request) {

    private var _binding: FragmentPosRequestBinding? = null
    private val binding get() = _binding!!

    private var routeSnapshot: String = ""
    private var requestKey: String = ""
    private var kycRequired: Boolean = false
    private var pinRequired: Boolean = true
    private var deviceTypes: List<Pair<String, String>> = listOf(
        "android" to "Android POS",
        "classic" to "Classic POS",
        "mini" to "Mini POS"
    )

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentPosRequestBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnGoKyc.setOnClickListener { findNavController().safeNavigate(R.id.nav_kyc) }
        binding.btnSubmit.setOnClickListener { validateAndConfirm() }

        loadInfo()
    }

    private fun loadInfo() {
        LoadingOverlay.show(requireContext(), "Loading POS terminal options...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val resp = RetrofitClient.getService().posRequest(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "info")
                )
                val body = resp.body()
                if (_binding == null) return@launch
                LoadingOverlay.dismiss()
                if (!resp.isSuccessful || body?.get("status") != "success") {
                    val msg = body?.get("message") as? String ?: "Unable to load POS service."
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
                val defaultPhone = body["default_phone"] as? String ?: ""
                if (binding.etContactPhone.text.isNullOrBlank() && defaultPhone.isNotBlank()) {
                    binding.etContactPhone.setText(defaultPhone)
                }

                binding.cardKycNotice.visibility = if (kycRequired) View.VISIBLE else View.GONE
                binding.btnSubmit.isEnabled = routingAvailable && !kycRequired
                binding.tvProviderInfo.text = if (routingAvailable) {
                    "Routed via $providerLabel. Our team will verify your business for delivery."
                } else {
                    "POS terminal requests are temporarily unavailable."
                }

                @Suppress("UNCHECKED_CAST")
                val rawDevices = body["device_types"] as? List<Map<String, Any>> ?: emptyList()
                if (rawDevices.isNotEmpty()) {
                    deviceTypes = rawDevices.mapNotNull {
                        val id = it["id"] as? String ?: return@mapNotNull null
                        val label = it["label"] as? String ?: id
                        id to label
                    }
                }
                binding.spinnerDeviceType.adapter = ArrayAdapter(
                    requireContext(),
                    android.R.layout.simple_spinner_dropdown_item,
                    deviceTypes.map { it.second }
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

    private fun validateAndConfirm() {
        if (kycRequired) {
            snack("Complete KYC verification first")
            return
        }
        val business = binding.etBusinessName.text?.toString()?.trim() ?: ""
        val phone = binding.etContactPhone.text?.toString()?.trim() ?: ""
        val deviceId = deviceTypes.getOrNull(binding.spinnerDeviceType.selectedItemPosition)?.first ?: "android"
        val qty = binding.etQuantity.text?.toString()?.trim()?.toIntOrNull() ?: 0
        val address = binding.etDeliveryAddress.text?.toString()?.trim() ?: ""

        if (business.isEmpty() || address.isEmpty() || qty !in 1..20) {
            snack("Enter business name, delivery address, and quantity (1 - 20)")
            return
        }

        if (pinRequired) {
            promptPin("Confirm POS Request") { pin -> submitPos(business, phone, deviceId, qty, address, pin) }
        } else {
            submitPos(business, phone, deviceId, qty, address, "")
        }
    }

    private fun submitPos(
        business: String, phone: String, deviceId: String,
        qty: Int, address: String, pin: String
    ) {
        LoadingOverlay.show(requireContext(), "Submitting POS request...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val payload = mutableMapOf<String, Any>(
                    "api_key" to prefs.getApiKey(),
                    "action" to "submit",
                    "route_snapshot" to routeSnapshot,
                    "request_key" to requestKey,
                    "business_name" to business,
                    "contact_phone" to phone,
                    "device_type" to deviceId,
                    "quantity" to qty,
                    "delivery_address" to address
                )
                if (pin.isNotEmpty()) payload["pin"] = pin
                val resp = RetrofitClient.getService().posRequest(payload)
                val body = resp.body()
                if (_binding == null) return@launch
                LoadingOverlay.dismiss()
                if (resp.isSuccessful && body?.get("status") == "success") {
                    val msg = body["message"] as? String ?: "POS request received"
                    requestKey = body["request_key"] as? String ?: requestKey
                    @Suppress("UNCHECKED_CAST")
                    val history = body["requests"] as? List<Map<String, Any>> ?: emptyList()
                    renderHistory(history)
                    binding.etBusinessName.setText("")
                    binding.etDeliveryAddress.setText("")
                    binding.etQuantity.setText("1")
                    snack(msg)
                } else {
                    val code = body?.get("code") as? String ?: ""
                    val msg = body?.get("message") as? String ?: "POS request failed"
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
                text = "No POS terminal requests yet."
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
