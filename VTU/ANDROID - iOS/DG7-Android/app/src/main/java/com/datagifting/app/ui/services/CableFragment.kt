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
import com.datagifting.app.databinding.FragmentCableBinding
import com.datagifting.app.util.LoadingOverlay
import com.datagifting.app.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

class CableFragment : Fragment(R.layout.fragment_cable) {

    private var _binding: FragmentCableBinding? = null
    private val binding get() = _binding!!

    private var selectedProvider = "dstv"
    private var selectedPlanCode = ""
    private var selectedPlanName = ""
    private var selectedPlanAmount = ""
    private var verifiedAccountName = ""
    private var verifiedSmartcard = ""
    private val allPlans = mutableMapOf<String, List<Map<String, Any>>>()
    private var verifyJob: Job? = null

    /** Maps provider key → container LinearLayout */
    private val providerButtons get() = mapOf(
        "dstv" to binding.btnDstv,
        "gotv" to binding.btnGotv,
        "startimes" to binding.btnStartimes,
        "showmax" to binding.btnShowmax
    )

    /** Maps provider key → logo ImageView (for greying out unselected providers) */
    private val providerImages get() = mapOf(
        "dstv" to binding.imgDstv,
        "gotv" to binding.imgGotv,
        "startimes" to binding.imgStartimes,
        "showmax" to binding.imgShowmax
    )

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentCableBinding.bind(view)

        setupProviderButtons()
        setupSmartcardWatcher()
        binding.spinnerPlan.threshold = 0
        binding.spinnerPlan.setOnClickListener { binding.spinnerPlan.showDropDown() }
        binding.btnVerifySmartcard.setOnClickListener { verifySmartcard() }
        binding.btnPay.setOnClickListener { confirmPayment() }
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        loadPlans()
    }

    private fun setupProviderButtons() {
        providerButtons.forEach { (provider, layout) ->
            layout.setOnClickListener {
                applyProviderSelection(provider)
                clearVerification()
                updatePlanDropdown()
                // Automatically open the plan dropdown so users see available packages immediately.
                // Capture the adapter set by updatePlanDropdown() and only show if it's still
                // the active adapter when the post runs (guards against rapid provider switches).
                val snapshotAdapter = binding.spinnerPlan.adapter
                binding.spinnerPlan.post {
                    if (binding.spinnerPlan.adapter === snapshotAdapter &&
                        (snapshotAdapter?.count ?: 0) > 0) {
                        binding.spinnerPlan.showDropDown()
                    }
                }
            }
        }
        applyProviderSelection("dstv")
    }

    /**
     * Highlights [provider] button and greys out all others (alpha 0.4 on their logo images).
     */
    private fun applyProviderSelection(provider: String) {
        selectedProvider = provider
        providerButtons.forEach { (key, layout) ->
            layout.isSelected = (key == provider)
        }
        providerImages.forEach { (key, img) ->
            img.alpha = if (key == provider) 1.0f else 0.4f
        }
    }

    private fun loadPlans() {
        binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.getCablePlans(mapOf("api_key" to prefs.getApiKey()))
                if (resp.isSuccessful) {
                    val body = resp.body()
                    @Suppress("UNCHECKED_CAST")
                    val cableSub = body?.get("CABLE_SUBSCRIPTION") as? Map<String, Any>
                    allPlans.clear()
                    cableSub?.forEach { (k, v) ->
                        @Suppress("UNCHECKED_CAST")
                        val planList = v as? List<Map<String, Any>>
                        if (planList != null) {
                            allPlans[k.lowercase()] = planList
                        }
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    snack("Failed to load plans: ${e.message}")
                }
            }
            activity?.runOnUiThread {
                if (_binding == null) return@runOnUiThread
                binding.progressBar.visibility = View.GONE
                updatePlanDropdown()
            }
        }
    }

    private fun formatPackageName(code: String): String =
        code.replace("-", " ").replace("_", " ")
            .split(" ").joinToString(" ") { word -> word.replaceFirstChar { char -> char.uppercase() } }

    private fun formatAmount(raw: String): String {
        val numeric = raw.toDoubleOrNull() ?: return raw
        val isWholeNumber = numeric == kotlin.math.floor(numeric)
        return if (isWholeNumber) numeric.toLong().toString() else raw
    }

    private fun updatePlanDropdown() {
        val plans = allPlans[selectedProvider] ?: emptyList()
        val names = plans.map { plan ->
            val pkgCode = plan["PACKAGE"] as? String ?: plan["package"] as? String ?: ""
            val displayName = formatPackageName(pkgCode)
            val amount = formatAmount(plan["AMOUNT"]?.toString() ?: "")
            "$displayName - ₦$amount"
        }
        val adapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, names)
        binding.spinnerPlan.setAdapter(adapter)
        binding.spinnerPlan.threshold = 0
        binding.spinnerPlan.setText("", false)
        selectedPlanCode = ""; selectedPlanName = ""; selectedPlanAmount = ""
        binding.spinnerPlan.setOnItemClickListener { _, _, position, _ ->
            plans.getOrNull(position)?.let { plan ->
                selectedPlanCode = plan["PACKAGE"] as? String ?: ""
                selectedPlanName = formatPackageName(selectedPlanCode)
                selectedPlanAmount = formatAmount(plan["AMOUNT"]?.toString() ?: "")
            }
        }
        val hint = if (plans.isEmpty()) "No plans for ${selectedProvider.uppercase()} — contact support" else "Select Package"
        binding.tilPlan.hint = hint
    }

    private fun setupSmartcardWatcher() {
        binding.etSmartcard.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                clearVerification()
            }
        })
    }

    private fun clearVerification() {
        verifyJob?.cancel()
        verifiedAccountName = ""
        verifiedSmartcard = ""
        binding.layoutVerifiedInfo.visibility = View.GONE
    }

    private fun verifySmartcard() {
        val number = binding.etSmartcard.text?.toString()?.trim() ?: ""
        if (selectedProvider.isEmpty()) { snack("Select a Cable TV provider first"); return }
        if (selectedPlanCode.isEmpty()) { snack("Select a subscription package first"); return }
        if (number.isEmpty()) { snack("Enter SmartCard / IUC number"); return }

        verifyJob?.cancel()
        binding.progressBar.visibility = View.VISIBLE
        binding.btnVerifySmartcard.isEnabled = false
        verifyJob = lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.verifyCable(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "type" to selectedProvider,
                        "iuc_number" to number,
                        "package" to selectedPlanCode
                    )
                )
                val body = resp.body()
                val status = body?.get("status") as? String ?: ""
                val name = if (status.equals("success", ignoreCase = true)) {
                    body?.get("desc") as? String
                        ?: body?.get("Customer_Name") as? String
                        ?: body?.get("name") as? String
                        ?: body?.get("customer_name") as? String ?: ""
                } else {
                    body?.get("Customer_Name") as? String
                        ?: body?.get("name") as? String
                        ?: body?.get("customer_name") as? String ?: ""
                }
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    binding.btnVerifySmartcard.isEnabled = true
                    if (name.isNotEmpty() && status.equals("success", ignoreCase = true)) {
                        verifiedAccountName = name
                        verifiedSmartcard = number
                        binding.tvVerifiedName.text = "✓ $name"
                        binding.tvVerifiedSmartcard.text = "IUC / SmartCard: $number"
                        binding.layoutVerifiedInfo.visibility = View.VISIBLE
                    } else {
                        val msg = body?.get("desc") as? String
                            ?: body?.get("message") as? String
                            ?: if (status.contains("failed", true)) "SmartCard verification failed"
                               else "Could not verify SmartCard number"
                        snack(msg)
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    binding.btnVerifySmartcard.isEnabled = true
                    snack(e.message ?: "Verification error")
                }
            }
        }
    }

    private fun confirmPayment() {
        val smartcard = binding.etSmartcard.text?.toString()?.trim() ?: ""
        if (selectedPlanCode.isEmpty()) { snack("Select a subscription package first"); return }
        if (smartcard.isEmpty()) { snack("Enter SmartCard / IUC number"); return }
        if (verifiedAccountName.isEmpty()) {
            snack("Please verify your SmartCard / IUC number first"); return
        }
        if (verifiedSmartcard != smartcard) {
            snack("SmartCard number changed — please verify again"); return
        }
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm Payment")
            .setMessage(
                "Provider: ${selectedProvider.uppercase()}\n" +
                "IUC / SmartCard: $smartcard\n" +
                "Account: $verifiedAccountName\n" +
                "Package: $selectedPlanName\n" +
                "Amount: ₦$selectedPlanAmount"
            )
            .setPositiveButton("Pay Now") { _, _ -> doPurchase(smartcard) }
            .setNegativeButton("Cancel", null).show()
    }

    private fun doPurchase(smartcard: String) {
        LoadingOverlay.show(requireContext(), "Processing your purchase...")
        binding.btnPay.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.purchaseCable(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "type" to selectedProvider,
                        "iuc_number" to smartcard,
                        "package" to selectedPlanCode
                    )
                )
                val status = resp.body()?.get("status") as? String ?: "failed"
                val msg = resp.body()?.get("message") as? String
                    ?: resp.body()?.get("desc") as? String ?: ""
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnPay.isEnabled = true
                    if (status.contains("success", true)) showSuccess(msg) else snack(msg.ifEmpty { "Payment failed" })
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

    private fun showSuccess(msg: String) {
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("✅ Subscription Successful")
            .setMessage(msg)
            .setPositiveButton("Done") { _, _ -> requireActivity().onBackPressedDispatcher.onBackPressed() }
            .show()
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() {
        super.onDestroyView()
        verifyJob?.cancel()
        _binding = null
    }
}

