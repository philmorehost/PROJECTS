package com.mzeevtu.ui.services

import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.View
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.FragmentAirtimeBinding
import com.mzeevtu.util.PinPromptHelper
import com.mzeevtu.util.LoadingOverlay
import com.mzeevtu.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class AirtimeFragment : Fragment(R.layout.fragment_airtime) {

    private var _binding: FragmentAirtimeBinding? = null
    private val binding get() = _binding!!
    private var selectedNetwork = "mtn"
    private var checkJob: Job? = null
    // True when network was auto-detected from phone number (locks manual network buttons)
    private var isAutoLocked = false

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentAirtimeBinding.bind(view)

        setupNetworkButtons()
        setupPhoneWatcher()
        setupAmountChips()
        binding.btnBuy.setOnClickListener { confirmPurchase() }
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnOverrideNetwork.setOnClickListener { clearAutoLock() }
    }

    private val networkMap get() = mapOf(
        "mtn" to binding.btnMtn,
        "glo" to binding.btnGlo,
        "airtel" to binding.btnAirtel,
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
            }
        }
        applyNetworkSelection("mtn", fromAuto = false)
    }

    /**
     * Selects [net] as the active network.
     * When [fromAuto] is true the unselected buttons are greyed out (alpha 0.4)
     * to visually indicate the auto-detected provider, matching the web behaviour.
     * When [fromAuto] is false all buttons return to full opacity.
     */
    private fun applyNetworkSelection(net: String, fromAuto: Boolean) {
        selectedNetwork = net
        networkMap.forEach { (key, btn) ->
            btn.isSelected = (key == net)
            btn.alpha = if (fromAuto && key != net) 0.4f else 1.0f
        }
    }

    /** Removes the auto-lock so the user can manually choose a different network. */
    private fun clearAutoLock() {
        isAutoLocked = false
        binding.btnOverrideNetwork.visibility = View.GONE
        networkMap.forEach { (key, btn) ->
            btn.alpha = 1.0f
            btn.isSelected = (key == selectedNetwork)
        }
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
                        checkLimit(phone)
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
            }
        } catch (_: Exception) {}
    }

    private suspend fun checkLimit(phone: String) {
        try {
            val prefs = PreferenceManager(requireContext())
            val api = RetrofitClient.getService()
            val resp = api.checkLimit(mapOf("api_key" to prefs.getApiKey(), "type" to "airtime", "id" to phone))
            if (resp.isSuccessful) {
                val limitReached = resp.body()?.get("limit_reached") as? Boolean ?: false
                val msg = resp.body()?.get("message") as? String ?: ""
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.btnBuy.isEnabled = !limitReached
                    if (limitReached) binding.tvLimitMsg.text = msg
                    binding.tvLimitMsg.visibility = if (limitReached) View.VISIBLE else View.GONE
                }
            }
        } catch (_: Exception) {}
    }

    private fun setupAmountChips() {
        listOf(
            binding.chip50 to "50",
            binding.chip100 to "100",
            binding.chip200 to "200",
            binding.chip500 to "500",
            binding.chip1000 to "1000"
        ).forEach { (chip, amount) -> chip.setOnClickListener { binding.etAmount.setText(amount) } }
    }

    private fun confirmPurchase() {
        val phone = binding.etPhone.text?.toString()?.trim() ?: ""
        val amount = binding.etAmount.text?.toString()?.trim() ?: ""
        if (phone.length != 11) { snack("Enter a valid 11-digit phone number"); return }
        if (amount.isEmpty() || amount.toDoubleOrNull() == null) { snack("Enter a valid amount"); return }

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm Purchase")
            .setMessage("Buy \u20A6$amount ${selectedNetwork.uppercase()} airtime for $phone?")
            .setPositiveButton("Buy Now") { _, _ -> promptPinThenPurchase(phone, amount) }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun promptPinThenPurchase(phone: String, amount: String) {
        val prefs = PreferenceManager(requireContext())
        PinPromptHelper.requirePin(
            requireContext(), prefs,
            onReady = { pin -> doPurchase(phone, amount, pin) },
            onNeedsSetup = { snack("Please set a transaction PIN in Profile > Set PIN first") }
        )
    }

    private fun doPurchase(phone: String, amount: String, pin: String? = null) {
        LoadingOverlay.show(requireContext(), "Processing your purchase...")
        binding.btnBuy.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val requestBody = mutableMapOf<String, Any>(
                    "api_key" to prefs.getApiKey(),
                    "network" to selectedNetwork,
                    "amount" to amount,
                    "phone_number" to phone
                )
                if (!pin.isNullOrEmpty()) requestBody["pin"] = pin
                val resp = api.purchaseAirtime(requestBody)
                val body = resp.body()
                val status = body?.get("status") as? String ?: "failed"
                val msg = body?.get("message") as? String ?: body?.get("desc") as? String ?: "Transaction failed"
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
                    snack("Network error: ${e.message}")
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
            .setMessage(msg.ifBlank { "Airtime purchase $status" })
            .setPositiveButton("OK") { _, _ ->
                if (status.contains("success", ignoreCase = true)) requireActivity().onBackPressedDispatcher.onBackPressed()
            }
            .show()
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
