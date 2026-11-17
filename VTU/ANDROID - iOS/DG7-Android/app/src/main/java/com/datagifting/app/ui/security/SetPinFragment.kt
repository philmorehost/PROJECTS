package com.datagifting.app.ui.security

import android.os.Bundle
import android.view.View
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.databinding.FragmentSetPinBinding
import com.datagifting.app.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

class SetPinFragment : Fragment(R.layout.fragment_set_pin) {

    private var _binding: FragmentSetPinBinding? = null
    private val binding get() = _binding!!

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentSetPinBinding.bind(view)
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnSetPin.setOnClickListener { validateAndSet() }
    }

    private fun validateAndSet() {
        val pin = binding.etNewPin.text?.toString()?.trim() ?: ""
        val confirmPin = binding.etConfirmPin.text?.toString()?.trim() ?: ""
        when {
            pin.length != 4 || !pin.all { it.isDigit() } -> snack("PIN must be exactly 4 digits")
            pin != confirmPin -> snack("PINs do not match")
            else -> setPin(pin)
        }
    }

    private fun setPin(pin: String, currentPin: String? = null) {
        binding.progressBar.visibility = View.VISIBLE
        binding.btnSetPin.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val body = mutableMapOf<String, Any>("api_key" to prefs.getApiKey(), "pin" to pin)
                if (!currentPin.isNullOrEmpty()) body["current_pin"] = currentPin
                val resp = api.setPin(body)
                val status = resp.body()?.get("status") as? String ?: "failed"
                val code = resp.body()?.get("code") as? String ?: ""
                val msg = resp.body()?.get("message") as? String ?: resp.body()?.get("desc") as? String ?: ""
                activity?.runOnUiThread {
                    binding.progressBar.visibility = View.GONE
                    binding.btnSetPin.isEnabled = true
                    if (status == "success") {
                        prefs.saveBoolean(com.datagifting.app.util.Constants.KEY_PIN_SET, true)
                        MaterialAlertDialogBuilder(requireContext())
                            .setTitle("✅ PIN Set Successfully")
                            .setMessage("Your transaction PIN has been updated.")
                            .setPositiveButton("OK") { _, _ -> requireActivity().onBackPressedDispatcher.onBackPressed() }.show()
                    } else if (code == "current_pin_required") {
                        // An existing PIN may only be replaced after proving it. Prompt and retry.
                        promptCurrentPin(pin)
                    } else snack(msg)
                }
            } catch (e: Exception) {
                activity?.runOnUiThread { binding.progressBar.visibility = View.GONE; binding.btnSetPin.isEnabled = true; snack(e.message ?: "Error") }
            }
        }
    }

    private fun promptCurrentPin(newPin: String) {
        val input = android.widget.EditText(requireContext()).apply {
            inputType = android.text.InputType.TYPE_CLASS_NUMBER or android.text.InputType.TYPE_NUMBER_VARIATION_PASSWORD
            filters = arrayOf(android.text.InputFilter.LengthFilter(4))
            hint = "Current 4-digit PIN"
        }
        val padding = (20 * resources.displayMetrics.density).toInt()
        val container = android.widget.FrameLayout(requireContext()).apply {
            setPadding(padding, padding / 2, padding, 0); addView(input)
        }
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Current PIN Required")
            .setMessage("Enter your existing transaction PIN to change it.")
            .setView(container)
            .setPositiveButton("Confirm") { _, _ ->
                val current = input.text?.toString()?.trim() ?: ""
                if (current.length != 4) snack("PIN must be 4 digits") else setPin(newPin, current)
            }
            .setNegativeButton("Cancel", null).show()
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()
    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
