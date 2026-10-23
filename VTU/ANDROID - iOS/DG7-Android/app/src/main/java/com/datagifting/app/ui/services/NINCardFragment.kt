package com.datagifting.app.ui.services

import android.graphics.BitmapFactory
import android.os.Bundle
import android.util.Base64
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.databinding.FragmentNinCardBinding
import com.datagifting.app.util.LoadingOverlay
import com.datagifting.app.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

class NINCardFragment : Fragment(R.layout.fragment_nin_card) {

    private var _binding: FragmentNinCardBinding? = null
    private val binding get() = _binding!!

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentNinCardBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnLookup.setOnClickListener { confirmAndLookup() }
    }

    private fun confirmAndLookup() {
        val nin = binding.etNin.text?.toString()?.trim() ?: ""
        if (nin.length != 11 || !nin.all { it.isDigit() }) {
            snack("Please enter a valid 11-digit NIN")
            return
        }
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm NIN Lookup")
            .setMessage("A fee will be charged to retrieve NIN slip for:\n$nin\n\nProceed?")
            .setPositiveButton("Proceed") { _, _ -> doLookup(nin) }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun doLookup(nin: String) {
        LoadingOverlay.show(requireContext(), "Fetching your NIN details...")
        binding.btnLookup.isEnabled = false
        binding.cardResult.visibility = View.GONE

        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.lookupNIN(mapOf("api_key" to prefs.getApiKey(), "nin" to nin))
                val body = resp.body()
                val status = body?.get("status") as? String ?: "failed"
                val desc = body?.get("desc") as? String ?: "Unknown error"

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnLookup.isEnabled = true
                    if (status == "success") {
                        @Suppress("UNCHECKED_CAST")
                        val data = body?.get("data") as? Map<String, Any> ?: emptyMap()
                        displayResult(nin, data)
                    } else {
                        snack(desc)
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnLookup.isEnabled = true
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    private fun displayResult(nin: String, data: Map<String, Any>) {
        binding.cardResult.visibility = View.VISIBLE

        binding.tvNin.text = nin
        binding.tvFullName.text = listOf(
            data["firstname"] as? String ?: "",
            data["middlename"] as? String ?: "",
            data["lastname"] as? String ?: ""
        ).filter { it.isNotBlank() }.joinToString(" ")
        binding.tvDob.text = data["birthdate"] as? String ?: "—"
        binding.tvGender.text = data["gender"] as? String ?: "—"
        binding.tvPhone.text = data["phone"] as? String ?: "—"
        binding.tvAddress.text = data["address"] as? String ?: "—"
        binding.tvState.text = data["residence_state"] as? String ?: "—"
        binding.tvOrigin.text = data["state_of_origin"] as? String ?: "—"

        val photoB64 = data["photo"] as? String ?: ""
        if (photoB64.isNotEmpty()) {
            try {
                val cleanB64 = photoB64.substringAfter(",")
                val decoded = Base64.decode(cleanB64, Base64.DEFAULT)
                val bmp = BitmapFactory.decodeByteArray(decoded, 0, decoded.size)
                if (bmp != null) binding.ivPhoto.setImageBitmap(bmp)
            } catch (_: Exception) {}
        }
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
