package com.mzeevtu.ui.services

import android.content.res.ColorStateList
import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.View
import android.widget.ArrayAdapter
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.FragmentElectricBinding
import com.mzeevtu.util.LoadingOverlay
import com.mzeevtu.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

class ElectricFragment : Fragment(R.layout.fragment_electric) {

    private var _binding: FragmentElectricBinding? = null
    private val binding get() = _binding!!

    /** Lowercase provider code sent to API, e.g. "aedc" */
    private var selectedProviderCode = ""
    /** Display label shown to the user, e.g. "AEDC" */
    private var selectedProviderLabel = ""
    private var selectedMeterType = "prepaid"
    private var verifyJob: Job? = null

    /** Tracks the last verified meter number so we can detect changes */
    private var verifiedMeterNumber = ""
    private var verifiedCustomerName = ""

    /** Maps display label Ã¢â ’ provider code, e.g. "AEDC" Ã¢â ’ "aedc" */
    private val providerMap = mutableMapOf<String, String>()

    companion object {
        private const val MIN_AMOUNT = 1000
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentElectricBinding.bind(view)

        setupMeterTypeButtons()
        setupChangeListeners()
        binding.btnVerify.setOnClickListener { verifyMeter() }
        binding.btnBuy.setOnClickListener { confirmPurchase() }
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        loadProviders()
    }

    private fun loadProviders() {
        binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.getElectricPlans(mapOf("api_key" to prefs.getApiKey()))
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val electric = resp.body()?.get("ELECTRIC_PAYMENT") as? Map<String, Any>
                    if (!electric.isNullOrEmpty()) {
                        providerMap.clear()
                        for ((label, value) in electric) {
                            @Suppress("UNCHECKED_CAST")
                            val details = value as? Map<String, Any>
                            val code = (details?.get("PROVIDER_CODE") as? String)?.lowercase()
                                ?: label.lowercase()
                            providerMap[label.uppercase()] = code
                        }
                        val names = providerMap.keys.sorted()
                        activity?.runOnUiThread {
                            if (_binding == null) return@runOnUiThread
                            binding.progressBar.visibility = View.GONE
                            setupProviderDropdown(names)
                        }
                        return@launch
                    }
                }
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    snack("Failed to load providers")
                }
            }
        }
    }

    private fun setupProviderDropdown(labels: List<String>) {
        val adapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, labels)
        binding.spinnerProvider.setAdapter(adapter)
        binding.spinnerProvider.threshold = 0
        binding.spinnerProvider.setOnItemClickListener { _, _, pos, _ ->
            val label = labels[pos]
            selectedProviderCode = providerMap[label] ?: label.lowercase()
            selectedProviderLabel = label
            clearVerification()
        }
    }

    private fun setupMeterTypeButtons() {
        updateMeterTypeUI()
        binding.btnPrepaid.setOnClickListener {
            if (selectedMeterType != "prepaid") {
                selectedMeterType = "prepaid"
                updateMeterTypeUI()
                clearVerification()
            }
        }
        binding.btnPostpaid.setOnClickListener {
            if (selectedMeterType != "postpaid") {
                selectedMeterType = "postpaid"
                updateMeterTypeUI()
                clearVerification()
            }
        }
    }

    private fun updateMeterTypeUI() {
        val primaryColor = ContextCompat.getColor(requireContext(), R.color.primary)
        val inactiveColor = ContextCompat.getColor(requireContext(), R.color.surface_variant)
        val inactiveTextColor = ContextCompat.getColor(requireContext(), R.color.text_secondary)
        val whiteColor = ContextCompat.getColor(requireContext(), R.color.white)
        if (selectedMeterType == "prepaid") {
            binding.btnPrepaid.backgroundTintList = ColorStateList.valueOf(primaryColor)
            binding.btnPrepaid.setTextColor(whiteColor)
            binding.btnPostpaid.backgroundTintList = ColorStateList.valueOf(inactiveColor)
            binding.btnPostpaid.setTextColor(inactiveTextColor)
        } else {
            binding.btnPostpaid.backgroundTintList = ColorStateList.valueOf(primaryColor)
            binding.btnPostpaid.setTextColor(whiteColor)
            binding.btnPrepaid.backgroundTintList = ColorStateList.valueOf(inactiveColor)
            binding.btnPrepaid.setTextColor(inactiveTextColor)
        }
    }

    private fun setupChangeListeners() {
        binding.etMeterNumber.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                clearVerification()
            }
        })
    }

    private fun clearVerification() {
        verifiedCustomerName = ""
        verifiedMeterNumber = ""
        binding.layoutVerifiedInfo.visibility = View.GONE
    }

    private fun verifyMeter() {
        val meter = binding.etMeterNumber.text?.toString()?.trim() ?: ""
        if (selectedProviderCode.isEmpty()) { snack("Select electricity provider"); return }
        if (meter.isEmpty()) { snack("Enter meter number"); return }

        verifyJob?.cancel()
        binding.progressBar.visibility = View.VISIBLE
        binding.btnVerify.isEnabled = false
        verifyJob = lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.verifyElectric(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "provider" to selectedProviderCode,
                        "meter_number" to meter,
                        "type" to selectedMeterType
                    )
                )
                val name = resp.body()?.get("Customer_Name") as? String
                    ?: resp.body()?.get("name") as? String
                    ?: resp.body()?.get("customer_name") as? String ?: ""
                val status = resp.body()?.get("status") as? String ?: ""
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    binding.btnVerify.isEnabled = true
                    if (name.isNotEmpty()) {
                        verifiedCustomerName = name
                        verifiedMeterNumber = meter
                        binding.tvVerifiedName.text = "\u2713 $name"
                        binding.tvVerifiedMeter.text = "Meter No: $meter"
                        binding.layoutVerifiedInfo.visibility = View.VISIBLE
                    } else {
                        val msg = resp.body()?.get("desc") as? String
                            ?: resp.body()?.get("message") as? String
                            ?: if (status.contains("failed", true)) "Meter verification failed" else "Could not verify meter"
                        snack(msg)
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    binding.btnVerify.isEnabled = true
                    snack(e.message ?: "Verification error")
                }
            }
        }
    }

    private fun confirmPurchase() {
        val meter = binding.etMeterNumber.text?.toString()?.trim() ?: ""
        val amountStr = binding.etAmount.text?.toString()?.trim() ?: ""
        val amount = amountStr.toDoubleOrNull() ?: 0.0

        if (selectedProviderCode.isEmpty()) { snack("Select electricity provider"); return }
        if (meter.isEmpty()) { snack("Enter meter number"); return }
        if (verifiedCustomerName.isEmpty() || verifiedMeterNumber != meter) {
            snack("Please verify the meter number first"); return
        }
        if (amountStr.isEmpty()) { snack("Enter amount"); return }
        if (amount < MIN_AMOUNT) { snack("Minimum amount is \u20A6$MIN_AMOUNT"); return }

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm Payment")
            .setMessage(
                "Provider: $selectedProviderLabel\n" +
                "Meter: $meter\n" +
                "Account: $verifiedCustomerName\n" +
                "Type: ${selectedMeterType.replaceFirstChar { it.uppercase() }}\n" +
                "Amount: \u20A6$amountStr"
            )
            .setPositiveButton("Pay Now") { _, _ -> doPurchase(meter, amountStr) }
            .setNegativeButton("Cancel", null).show()
    }

    private fun doPurchase(meter: String, amount: String) {
        LoadingOverlay.show(requireContext(), "Processing your purchase...")
        binding.btnBuy.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.purchaseElectric(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "provider" to selectedProviderCode,
                        "meter_number" to meter,
                        "type" to selectedMeterType,
                        "amount" to amount
                    )
                )
                val status = resp.body()?.get("status") as? String ?: "failed"
                val msg = resp.body()?.get("message") as? String
                    ?: resp.body()?.get("desc") as? String ?: ""
                val token = resp.body()?.get("token") as? String ?: ""
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnBuy.isEnabled = true
                    if (status.contains("success", true)) {
                        val resultMsg = if (token.isNotEmpty()) "$msg\n\nToken: $token" else msg
                        MaterialAlertDialogBuilder(requireContext())
                            .setTitle("\u2705 Electricity Token")
                            .setMessage(resultMsg)
                            .setPositiveButton("Done") { _, _ ->
                                requireActivity().onBackPressedDispatcher.onBackPressed()
                            }.show()
                    } else {
                        snack(msg.ifEmpty { "Payment failed" })
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnBuy.isEnabled = true
                    snack(e.message ?: "Error")
                }
            }
        }
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() {
        super.onDestroyView()
        verifyJob?.cancel()
        _binding = null
    }
}
