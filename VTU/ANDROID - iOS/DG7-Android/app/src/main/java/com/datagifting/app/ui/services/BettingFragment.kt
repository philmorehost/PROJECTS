package com.datagifting.app.ui.services

import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.View
import android.widget.ArrayAdapter
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.databinding.FragmentBettingBinding
import com.datagifting.app.util.LoadingOverlay
import com.datagifting.app.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class BettingFragment : Fragment(R.layout.fragment_betting) {

    private var _binding: FragmentBettingBinding? = null
    private val binding get() = _binding!!
    private var selectedProviderCode = ""
    private var verifiedCustomerName = ""
    private var verifyJob: Job? = null

    private val providers = mutableListOf<Map<String, String>>()

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentBettingBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnVerify.setOnClickListener { verifyAccount() }
        binding.btnPay.setOnClickListener { confirmPayment() }

        setupCustomerIdWatcher()
        loadProviders()
    }

    private fun loadProviders() {
        binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.getBettingPlatforms(mapOf("api_key" to prefs.getApiKey()))
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val list = resp.body()?.get("BETTING_PROVIDERS") as? List<Map<String, String>>
                    if (!list.isNullOrEmpty()) {
                        providers.clear()
                        providers.addAll(list)
                        val names = providers.map { it["provider_name"] ?: it["provider_code"] ?: "" }
                        activity?.runOnUiThread {
                            if (_binding == null) return@runOnUiThread
                            binding.progressBar.visibility = View.GONE
                            val adapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, names)
                            binding.spinnerProvider.setAdapter(adapter)
                            binding.spinnerProvider.threshold = 0
                            binding.spinnerProvider.setOnItemClickListener { _, _, pos, _ ->
                                providers.getOrNull(pos)?.let { p ->
                                    selectedProviderCode = p["provider_code"] ?: ""
                                    clearVerification()
                                }
                            }
                        }
                    } else {
                        activity?.runOnUiThread {
                            if (_binding == null) return@runOnUiThread
                            binding.progressBar.visibility = View.GONE
                            snack("No betting platforms available")
                        }
                    }
                } else {
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        binding.progressBar.visibility = View.GONE
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    snack(e.message ?: "Failed to load providers")
                }
            }
        }
    }

    private fun setupCustomerIdWatcher() {
        binding.etCustomerId.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) { clearVerification() }
        })
    }

    private fun clearVerification() {
        verifiedCustomerName = ""
        binding.tvVerifiedName.visibility = View.GONE
    }

    private fun verifyAccount() {
        val customerId = binding.etCustomerId.text?.toString()?.trim() ?: ""
        if (selectedProviderCode.isEmpty()) { snack("Select a betting platform"); return }
        if (customerId.isEmpty()) { snack("Enter customer ID"); return }

        binding.progressBar.visibility = View.VISIBLE
        binding.btnVerify.isEnabled = false
        verifyJob?.cancel()
        verifyJob = lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.verifyBetting(mapOf(
                    "api_key" to prefs.getApiKey(),
                    "provider" to selectedProviderCode,
                    "customer_id" to customerId
                ))
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
                        binding.tvVerifiedName.text = "✓ $name"
                        binding.tvVerifiedName.visibility = View.VISIBLE
                    } else if (status.contains("failed", true) || status.contains("error", true)) {
                        val msg = resp.body()?.get("desc") as? String ?: resp.body()?.get("message") as? String ?: "Verification failed"
                        snack(msg)
                    } else {
                        snack("Could not verify account. Check the ID and try again.")
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

    private fun confirmPayment() {
        val customerId = binding.etCustomerId.text?.toString()?.trim() ?: ""
        val amount = binding.etAmount.text?.toString()?.trim() ?: ""
        if (selectedProviderCode.isEmpty()) { snack("Select a betting platform"); return }
        if (customerId.isEmpty()) { snack("Enter customer ID"); return }
        if (verifiedCustomerName.isEmpty()) { snack("Please verify the account first"); return }
        if (amount.isEmpty() || (amount.toDoubleOrNull() ?: 0.0) <= 0) { snack("Enter a valid amount"); return }

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm Betting Deposit")
            .setMessage("Platform: ${selectedProviderCode.uppercase()}\nCustomer: $verifiedCustomerName\nID: $customerId\nAmount: ₦$amount")
            .setPositiveButton("Pay Now") { _, _ -> doPurchase(customerId, amount) }
            .setNegativeButton("Cancel", null).show()
    }

    private fun doPurchase(customerId: String, amount: String) {
        LoadingOverlay.show(requireContext(), "Processing your purchase...")
        binding.btnPay.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.purchaseBetting(mapOf(
                    "api_key" to prefs.getApiKey(),
                    "provider" to selectedProviderCode,
                    "customer_id" to customerId,
                    "amount" to amount
                ))
                val status = resp.body()?.get("status") as? String ?: "failed"
                val msg = resp.body()?.get("message") as? String ?: resp.body()?.get("desc") as? String ?: ""
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnPay.isEnabled = true
                    if (status.contains("success", true)) {
                        MaterialAlertDialogBuilder(requireContext())
                            .setTitle("✅ Deposit Successful")
                            .setMessage(msg)
                            .setPositiveButton("Done") { _, _ -> requireActivity().onBackPressedDispatcher.onBackPressed() }
                            .show()
                    } else snack(msg)
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnPay.isEnabled = true
                    snack(e.message ?: "Error")
                }
            }
        }
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()
    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
