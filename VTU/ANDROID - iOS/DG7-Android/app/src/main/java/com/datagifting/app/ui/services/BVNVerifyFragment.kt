package com.datagifting.app.ui.services

import android.os.Bundle
import android.view.View
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.databinding.FragmentBvnVerifyBinding
import com.datagifting.app.util.LoadingOverlay
import com.datagifting.app.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

class BVNVerifyFragment : Fragment(R.layout.fragment_bvn_verify) {

    private var _binding: FragmentBvnVerifyBinding? = null
    private val binding get() = _binding!!

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentBvnVerifyBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnVerify.setOnClickListener { confirmAndVerify() }
    }

    private fun confirmAndVerify() {
        val bvn = binding.etBvn.text?.toString()?.trim() ?: ""
        if (bvn.length != 11 || !bvn.all { it.isDigit() }) {
            snack("Please enter a valid 11-digit BVN")
            return
        }
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm BVN Verification")
            .setMessage("A fee will be charged to verify BVN:\n${bvn.take(3)}****${bvn.takeLast(2)}\n\nProceed?")
            .setPositiveButton("Proceed") { _, _ -> doVerify(bvn) }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun doVerify(bvn: String) {
        LoadingOverlay.show(requireContext(), "Verifying your details...")
        binding.btnVerify.isEnabled = false
        binding.cardResult.visibility = View.GONE

        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.verifyBVN(mapOf("api_key" to prefs.getApiKey(), "bvn" to bvn))
                val body = resp.body()
                val status = body?.get("status") as? String ?: "failed"
                val desc = body?.get("desc") as? String ?: "Unknown error"

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnVerify.isEnabled = true
                    if (status == "success") {
                        displayResult(body ?: emptyMap())
                    } else {
                        snack(desc)
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnVerify.isEnabled = true
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    private fun displayResult(data: Map<String, Any>) {
        binding.cardResult.visibility = View.VISIBLE

        binding.tvFullName.text = listOf(
            data["firstname"] as? String ?: "",
            data["middlename"] as? String ?: "",
            data["lastname"] as? String ?: ""
        ).filter { it.isNotBlank() }.joinToString(" ")
        binding.tvDob.text = "Date of Birth: ${data["date_of_birth"] as? String ?: "—"}"
        binding.tvGender.text = "Gender: ${data["gender"] as? String ?: "—"}"
        binding.tvPhone.text = "Phone: ${data["phone"] as? String ?: "—"}"
        binding.tvBank.text = "Bank of Enrolment: ${data["bank_of_enrolment"] as? String ?: "—"}"
        binding.tvLevel.text = "Account Level: ${data["level_of_account"] as? String ?: "—"}"
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
