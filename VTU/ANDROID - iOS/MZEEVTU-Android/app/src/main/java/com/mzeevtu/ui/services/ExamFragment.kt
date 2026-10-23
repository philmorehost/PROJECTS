package com.mzeevtu.ui.services

import android.os.Bundle
import android.view.View
import android.widget.ArrayAdapter
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.FragmentExamBinding
import com.mzeevtu.util.LoadingOverlay
import com.mzeevtu.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

class ExamFragment : Fragment(R.layout.fragment_exam) {

    private var _binding: FragmentExamBinding? = null
    private val binding get() = _binding!!
    private var selectedExam = "waec"
    private var selectedProductCode = ""
    private var selectedProductPrice = "0"

    // All exam products keyed by uppercase exam name
    private val allProducts = mutableMapOf<String, List<Map<String, Any>>>()

    private val examMap get() = mapOf(
        "waec" to binding.btnWaec,
        "neco" to binding.btnNeco,
        "nabteb" to binding.btnNabteb,
        "jamb" to binding.btnJamb
    )

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentExamBinding.bind(view)

        setupExamButtons()
        binding.btnBuy.setOnClickListener { confirmPurchase() }
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        loadExamPlans()
    }

    private fun setupExamButtons() {
        examMap.forEach { (exam, btn) ->
            btn.setOnClickListener {
                applyExamSelection(exam)
                updateProductDropdown()
            }
        }
        applyExamSelection("waec")
    }

    /**
     * Selects [exam] as the active exam body.
     * The selected icon stays at full opacity; all others are greyed out (alpha 0.4)
     * to visually indicate the active selection.
     */
    private fun applyExamSelection(exam: String) {
        selectedExam = exam
        examMap.forEach { (key, btn) ->
            btn.isSelected = (key == exam)
            btn.alpha = if (key == exam) 1.0f else 0.4f
        }
    }

    private fun loadExamPlans() {
        binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.getExamPlans(mapOf("api_key" to prefs.getApiKey()))
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val examPin = resp.body()?.get("EXAM_PIN") as? Map<String, List<Map<String, Any>>>
                    allProducts.clear()
                    examPin?.forEach { (k, v) -> allProducts[k.uppercase()] = v }
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        binding.progressBar.visibility = View.GONE
                        updateProductDropdown()
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
                    snack("Failed to load exam products. Please check your connection.")
                }
            }
        }
    }

    private fun updateProductDropdown() {
        val products = allProducts[selectedExam.uppercase()] ?: emptyList()
        val names = products.map { p ->
            val type = p["EXAM_TYPE"] as? String ?: p["PRODUCT_CODE"] as? String ?: "PIN"
            val amount = p["AMOUNT"]?.toString() ?: "0"
            "$type \u2013 \u20A6$amount"
        }
        val adapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, names)
        binding.spinnerProduct.setAdapter(adapter)
        binding.spinnerProduct.threshold = 0
        binding.spinnerProduct.setText("", false)
        selectedProductCode = ""
        selectedProductPrice = "0"

        if (products.isNotEmpty()) {
            binding.tvPriceInfo.text = "Select a PIN type above for ${selectedExam.uppercase()}"
        } else {
            binding.tvPriceInfo.text = "No products available for ${selectedExam.uppercase()}"
        }

        binding.spinnerProduct.setOnItemClickListener { _, _, pos, _ ->
            products.getOrNull(pos)?.let { p ->
                selectedProductCode = p["EXAM_TYPE"] as? String ?: ""
                selectedProductPrice = p["AMOUNT"]?.toString() ?: "0"
                binding.tvPriceInfo.text = "Price: \u20A6$selectedProductPrice per PIN"
            }
        }
    }

    private fun confirmPurchase() {
        if (selectedProductCode.isEmpty()) { snack("Select a PIN type first"); return }
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm Purchase")
            .setMessage("Exam: ${selectedExam.uppercase()}\nPIN Type: $selectedProductCode\nAmount: \u20A6$selectedProductPrice")
            .setPositiveButton("Buy Now") { _, _ -> doPurchase() }
            .setNegativeButton("Cancel", null).show()
    }

    private fun doPurchase() {
        LoadingOverlay.show(requireContext(), "Processing your purchase...")
        binding.btnBuy.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                // Backend web/func/exam.php expects:
                //   "type"     Ã¢â ’ exam body (waec/neco/nabteb/jamb)
                //   "quantity" Ã¢â ’ product/pin type code (val_1 in price table, e.g. "result_checker")
                val resp = api.purchaseExam(mapOf(
                    "api_key" to prefs.getApiKey(),
                    "type" to selectedExam,
                    "quantity" to selectedProductCode
                ))
                val body = resp.body()
                val status = body?.get("status") as? String ?: "failed"
                val desc = body?.get("desc") as? String ?: ""
                val responseDesc = body?.get("response_desc") as? String ?: ""
                val ref = body?.get("ref") as? String ?: ""
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnBuy.isEnabled = true
                    when {
                        status.contains("success", ignoreCase = true) -> showResult(ref, responseDesc.ifEmpty { desc })
                        status.contains("pending", ignoreCase = true) -> showResult(ref, "Transaction pending.\n${responseDesc.ifEmpty { desc }}")
                        else -> snack(desc.ifEmpty { "Transaction failed" })
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

    private fun showResult(ref: String, details: String) {
        val refLine = if (ref.isNotEmpty()) "\nRef: $ref" else ""
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("\u2705 ${selectedExam.uppercase()} PIN Purchased")
            .setMessage("$details$refLine")
            .setPositiveButton("Done") { _, _ -> requireActivity().onBackPressedDispatcher.onBackPressed() }
            .show()
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
