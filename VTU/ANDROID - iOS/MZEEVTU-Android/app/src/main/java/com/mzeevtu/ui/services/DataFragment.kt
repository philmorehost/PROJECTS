package com.mzeevtu.ui.services

import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.View
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.FragmentDataBinding
import com.mzeevtu.util.PinPromptHelper
import com.mzeevtu.util.LoadingOverlay
import com.mzeevtu.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.text.NumberFormat
import java.util.Locale

class DataFragment : Fragment(R.layout.fragment_data) {

    private var _binding: FragmentDataBinding? = null
    private val binding get() = _binding!!

    private var selectedNetwork = "mtn"
    private var selectedDataType = "sme-data"
    private var selectedPlan: DataPlanItem? = null

    // allPlans keyed by "network|datatype" (both lowercase), e.g. "mtn|sme-data"
    private val allPlans = mutableMapOf<String, MutableList<DataPlanItem>>()

    private var checkJob: Job? = null
    private var isAutoLocked = false

    // Dropdowns are fed from these; the adapters that rendered radio-style rows are gone.
    private var currentPlans: List<DataPlanItem> = emptyList()
    private var suppressDropdownCallback = false

    private val dataTypes = listOf(
        DataTypeItem("sme-data",    "SME Data",           "Standard SME data bundles"),
        DataTypeItem("cg-data",     "Corporate Gifting",  "Corporate gifting data plans"),
        DataTypeItem("dd-data",     "Direct Data",        "Direct data bundles"),
        DataTypeItem("shared-data", "Shared Data",        "Shared/reseller data plans")
    )

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentDataBinding.bind(view)

        setupNetworkButtons()
        setupDataTypeDropdown()
        setupPlanDropdown()
        setupPhoneWatcher()
        binding.btnBuy.setOnClickListener { confirmPurchase() }
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnOverrideNetwork.setOnClickListener { clearAutoLock() }
        loadPlans()
    }

    private val networkMap get() = mapOf(
        "mtn"     to binding.btnMtn,
        "glo"     to binding.btnGlo,
        "airtel"  to binding.btnAirtel,
        "9mobile" to binding.btnNinemobile
    )

    private fun setupNetworkButtons() {
        networkMap.forEach { (net, btn) ->
            btn.setOnClickListener {
                if (isAutoLocked) {
                    snack("Tap 'Override Network' to manually change the network")
                    return@setOnClickListener
                }
                applyNetworkSelection(net, fromAuto = false)
                updatePlanList()
            }
        }
        applyNetworkSelection("mtn", fromAuto = false)
    }

    private fun applyNetworkSelection(net: String, fromAuto: Boolean) {
        selectedNetwork = net
        networkMap.forEach { (key, btn) ->
            btn.isSelected = (key == net)
            btn.alpha = if (fromAuto && key != net) 0.4f else 1.0f
        }
    }

    private fun clearAutoLock() {
        isAutoLocked = false
        binding.btnOverrideNetwork.visibility = View.GONE
        networkMap.forEach { (key, btn) ->
            btn.alpha = 1.0f
            btn.isSelected = (key == selectedNetwork)
        }
    }

    /** Data Type drop-down (replaces the old list of selectable rows). */
    private fun setupDataTypeDropdown() {
        val labels = dataTypes.map { it.name }
        binding.spinnerDataType.setSimpleItems(labels.toTypedArray())

        // Default selection: sme-data (index 0)
        selectedDataType = dataTypes[0].code
        binding.spinnerDataType.setText(dataTypes[0].name, false)

        binding.spinnerDataType.setOnItemClickListener { _, _, position, _ ->
            dataTypes.getOrNull(position)?.let {
                selectedDataType = it.code
                updatePlanList()
            }
        }
    }

    /** Plan drop-down, rebuilt whenever the network or data type changes. */
    private fun setupPlanDropdown() {
        binding.spinnerPlan.setOnItemClickListener { _, _, position, _ ->
            if (suppressDropdownCallback) return@setOnItemClickListener
            selectedPlan = currentPlans.getOrNull(position)
        }
    }

    private fun loadPlans() {
        binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.getDataPlans(mapOf("api_key" to prefs.getApiKey()))
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val networkData = resp.body()?.get("MOBILE_NETWORK") as? Map<String, List<Map<String, Any>>>
                    allPlans.clear()
                    networkData?.forEach { (networkKey, plans) ->
                        val networkLower = networkKey.lowercase()
                        plans.forEach planLoop@{ plan ->
                            val typeCode = (plan["DATA_TYPE_CODE"] as? String)?.lowercase() ?: return@planLoop
                            val key = "$networkLower|$typeCode"
                            val list = allPlans.getOrPut(key) { mutableListOf() }
                            list.add(DataPlanItem(
                                id          = plan["ID"]?.toString() ?: "",
                                code        = plan["PRODUCT_CODE"] as? String ?: "",
                                name        = plan["PRODUCT_NAME"] as? String ?: plan["PRODUCT_CODE"] as? String ?: "Plan",
                                amount      = plan["AMOUNT"]?.toString() ?: "0",
                                duration    = plan["DURATION"] as? String ?: "",
                                dataTypeCode = typeCode
                            ))
                        }
                    }
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        binding.progressBar.visibility = View.GONE
                        updatePlanList()
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
                    snack("Failed to load data plans. Please check your connection.")
                }
            }
        }
    }

    private fun updatePlanList() {
        val key = "${selectedNetwork.lowercase()}|${selectedDataType.lowercase()}"
        val plans = allPlans[key] ?: emptyList()
        currentPlans = plans
        selectedPlan = null

        // One label per plan: name, price and validity - the same information the rows showed.
        val labels = plans.map { plan ->
            val validity = if (plan.duration.isNotEmpty()) " · ${plan.duration}" else ""
            "${plan.name} — ₦${formatAmount(plan.amount)}$validity"
        }

        suppressDropdownCallback = true
        binding.spinnerPlan.setSimpleItems(labels.toTypedArray())
        binding.spinnerPlan.setText("", false)
        suppressDropdownCallback = false
        binding.spinnerPlan.isEnabled = plans.isNotEmpty()

        binding.tvNoPlans.visibility = if (plans.isEmpty()) View.VISIBLE else View.GONE
        binding.spinnerPlan.visibility = if (plans.isEmpty()) View.GONE else View.VISIBLE
    }

    private fun formatAmount(raw: String): String = try {
        NumberFormat.getNumberInstance(Locale.getDefault()).format(raw.toDouble())
    } catch (_: Exception) {
        raw
    }

    private fun setupPhoneWatcher() {
        binding.etPhone.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                val phone = s?.toString()?.trim() ?: ""
                if (phone.length == 11) {
                    checkJob?.cancel()
                    checkJob = lifecycleScope.launch {
                        delay(400)
                        autoDetectNetwork(phone)
                    }
                }
            }
        })
    }

    private suspend fun autoDetectNetwork(phone: String) {
        try {
            val api = RetrofitClient.getService()
            val resp = api.identifyNetwork(mapOf("phone" to phone))
            if (!resp.isSuccessful) return
            val net = (resp.body()?.get("network") as? String)?.lowercase() ?: return
            activity?.runOnUiThread {
                if (_binding == null) return@runOnUiThread
                isAutoLocked = true
                applyNetworkSelection(net, fromAuto = true)
                binding.btnOverrideNetwork.visibility = View.VISIBLE
                updatePlanList()
            }
        } catch (_: Exception) {}
    }

    private fun confirmPurchase() {
        val phone = binding.etPhone.text?.toString()?.trim() ?: ""
        if (phone.length != 11) { snack("Enter valid 11-digit phone number"); return }
        val plan = selectedPlan
        if (plan == null) { snack("Select a data plan"); return }
        val durationLine = if (plan.duration.isNotEmpty()) "\nValidity: ${plan.duration}" else ""
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm Purchase")
            .setMessage("Phone: $phone\nNetwork: ${selectedNetwork.uppercase()}\nType: ${selectedDataType.replace("-", " ").uppercase()}\nPlan: ${plan.name}\nAmount: \u20A6${plan.amount}$durationLine")
            .setPositiveButton("Buy Now") { _, _ -> promptPinThenPurchase(phone, plan) }
            .setNegativeButton("Cancel", null).show()
    }

    private fun promptPinThenPurchase(phone: String, plan: DataPlanItem) {
        val prefs = PreferenceManager(requireContext())
        PinPromptHelper.requirePin(
            requireContext(), prefs,
            onReady = { pin -> doPurchase(phone, plan, pin) },
            onNeedsSetup = { snack("Please set a transaction PIN in Profile > Set PIN first") }
        )
    }

    private fun doPurchase(phone: String, plan: DataPlanItem, pin: String? = null) {
        LoadingOverlay.show(requireContext(), "Processing your purchase...")
        binding.btnBuy.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val requestBody = mutableMapOf<String, Any>(
                    "api_key"   to prefs.getApiKey(),
                    "network"   to selectedNetwork,
                    "data_type" to selectedDataType,
                    "plan_code" to plan.code,
                    "phone_no"  to phone
                )
                if (!pin.isNullOrEmpty()) requestBody["pin"] = pin
                val resp = api.purchaseData(requestBody)
                val status = resp.body()?.get("status") as? String ?: "failed"
                val msg = resp.body()?.get("message") as? String
                    ?: resp.body()?.get("desc") as? String ?: ""
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnBuy.isEnabled = true
                    showResult(status, msg)
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

    /** Always shows the transaction's actual status (success/pending/failed) via a dialog,
     *  rather than only success getting a clear dialog and everything else a small snackbar. */
    private fun showResult(status: String, msg: String) {
        val title = when {
            status.contains("success", ignoreCase = true) -> "✅ Successful"
            status.contains("pending", ignoreCase = true) -> "⏳ Pending"
            else -> "❌ Failed"
        }
        MaterialAlertDialogBuilder(requireContext())
            .setTitle(title)
            .setMessage(msg.ifBlank { "Data purchase $status" })
            .setPositiveButton("OK") { _, _ ->
                if (status.contains("success", ignoreCase = true)) requireActivity().onBackPressedDispatcher.onBackPressed()
            }
            .show()
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
