package com.mzeevtu.ui.security

import android.os.Bundle
import android.view.View
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.FragmentSetPinBinding
import com.mzeevtu.util.PreferenceManager
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

    private fun setPin(pin: String) {
        binding.progressBar.visibility = View.VISIBLE
        binding.btnSetPin.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.setPin(mapOf("api_key" to prefs.getApiKey(), "pin" to pin))
                val status = resp.body()?.get("status") as? String ?: "failed"
                val msg = resp.body()?.get("message") as? String ?: resp.body()?.get("desc") as? String ?: ""
                activity?.runOnUiThread {
                    binding.progressBar.visibility = View.GONE
                    binding.btnSetPin.isEnabled = true
                    if (status == "success") {
                        prefs.saveBoolean(com.mzeevtu.util.Constants.KEY_PIN_SET, true)
                        MaterialAlertDialogBuilder(requireContext())
                            .setTitle("\u2705 PIN Set Successfully")
                            .setMessage("Your transaction PIN has been updated.")
                            .setPositiveButton("OK") { _, _ -> requireActivity().onBackPressedDispatcher.onBackPressed() }.show()
                    } else snack(msg)
                }
            } catch (e: Exception) {
                activity?.runOnUiThread { binding.progressBar.visibility = View.GONE; binding.btnSetPin.isEnabled = true; snack(e.message ?: "Error") }
            }
        }
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()
    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
