package com.datagifting.app.ui.wallet

import android.os.Bundle
import android.view.View
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.databinding.FragmentShareFundBinding
import com.datagifting.app.util.LoadingOverlay
import com.datagifting.app.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

class ShareFundFragment : Fragment(R.layout.fragment_share_fund) {

    private var _binding: FragmentShareFundBinding? = null
    private val binding get() = _binding!!

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentShareFundBinding.bind(view)
        binding.btnSend.setOnClickListener { showPinDialog() }
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
    }

    private fun showPinDialog() {
        val recipient = binding.etRecipient.text?.toString()?.trim() ?: ""
        val amount = binding.etAmount.text?.toString()?.trim() ?: ""
        if (recipient.isEmpty()) { snack("Enter recipient username"); return }
        if (amount.isEmpty() || (amount.toDoubleOrNull() ?: 0.0) <= 0) { snack("Enter valid amount"); return }

        val pinView = layoutInflater.inflate(R.layout.dialog_pin_input, null)
        val etPin = pinView.findViewById<com.google.android.material.textfield.TextInputEditText>(R.id.et_pin)
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm Fund Transfer")
            .setMessage("Send ₦$amount to @$recipient")
            .setView(pinView)
            .setPositiveButton("Send") { _, _ ->
                val pin = etPin?.text?.toString()?.trim() ?: ""
                if (pin.length != 4) snack("PIN must be 4 digits") else doSend(recipient, amount, pin)
            }
            .setNegativeButton("Cancel", null).show()
    }

    private fun doSend(recipient: String, amount: String, pin: String) {
        LoadingOverlay.show(requireContext(), "Sending funds...")
        binding.btnSend.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.shareFund(mapOf("api_key" to prefs.getApiKey(),
                    "user" to recipient, "amount" to amount, "pin" to pin))
                val status = resp.body()?.get("status") as? String ?: "failed"
                val msg = resp.body()?.get("message") as? String ?: resp.body()?.get("desc") as? String ?: ""
                activity?.runOnUiThread {
                    LoadingOverlay.dismiss(); binding.btnSend.isEnabled = true
                    if (status.contains("success", true)) {
                        MaterialAlertDialogBuilder(requireContext()).setTitle("✅ Fund Sent").setMessage(msg)
                            .setPositiveButton("Done") { _, _ -> requireActivity().onBackPressedDispatcher.onBackPressed() }.show()
                    } else snack(msg)
                }
            } catch (e: Exception) {
                activity?.runOnUiThread { LoadingOverlay.dismiss(); binding.btnSend.isEnabled = true; snack(e.message ?: "Error") }
            }
        }
    }
    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()
    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
