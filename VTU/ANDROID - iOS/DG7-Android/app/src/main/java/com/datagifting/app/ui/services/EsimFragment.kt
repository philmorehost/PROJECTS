package com.datagifting.app.ui.services

import android.os.Bundle
import android.text.InputFilter
import android.text.InputType
import android.util.Patterns
import android.view.View
import android.widget.AdapterView
import android.widget.ArrayAdapter
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.api.TransactionPin
import com.datagifting.app.databinding.FragmentEsimBinding
import com.datagifting.app.util.BrandTheme
import com.datagifting.app.util.LoadingOverlay
import com.datagifting.app.util.PreferenceManager
import com.datagifting.app.util.safeNavigate
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

class EsimFragment : Fragment(R.layout.fragment_esim) {

    private var _binding: FragmentEsimBinding? = null
    private val binding get() = _binding!!

    private var requestKey: String = ""
    private var kycRequired: Boolean = false
    private var pinRequired: Boolean = true
    private var plans: List<Map<String, Any>> = emptyList()

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentEsimBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnGoKyc.setOnClickListener { findNavController().safeNavigate(R.id.nav_kyc) }
        binding.btnSubmit.setOnClickListener { validateAndConfirm() }

        loadInfo()
    }

    private fun loadInfo() {
        LoadingOverlay.show(requireContext(), "Loading eSIM plans...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val resp = RetrofitClient.getService().esim(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "info")
                )
                val body = resp.body()
                if (_binding == null) return@launch
                LoadingOverlay.dismiss()
                if (!resp.isSuccessful || body?.get("status") != "success") {
                    val msg = body?.get("message") as? String ?: "Unable to load eSIM plans."
                    binding.tvRailInfo.text = msg
                    binding.btnSubmit.isEnabled = false
                    return@launch
                }

                requestKey = body["request_key"] as? String ?: ""
                kycRequired = body["kyc_required"] as? Boolean ?: false
                pinRequired = body["pin_required"] as? Boolean ?: true
                val railLabel = body["rail_label"] as? String ?: "Aggregator"
                val defaultEmail = body["default_email"] as? String ?: ""
                if (binding.etEmail.text.isNullOrBlank() && defaultEmail.isNotBlank()) {
                    binding.etEmail.setText(defaultEmail)
                }

                binding.cardKycNotice.visibility = if (kycRequired) View.VISIBLE else View.GONE
                binding.btnSubmit.isEnabled = !kycRequired
                binding.tvRailInfo.text = "Travel data in 190+ countries · Rail: $railLabel"

                @Suppress("UNCHECKED_CAST")
                plans = body["plans"] as? List<Map<String, Any>> ?: emptyList()
                val labels = plans.map { p ->
                    val name = p["name"] as? String ?: "Plan"
                    val data = p["data"] as? String ?: ""
                    val days = p["days"] as? String ?: ""
                    val price = p["price_display"] as? String ?: ""
                    "$name · $data / $days · $price"
                }
                binding.spinnerPlan.adapter = ArrayAdapter(
                    requireContext(),
                    android.R.layout.simple_spinner_dropdown_item,
                    labels
                )
                binding.spinnerPlan.onItemSelectedListener = object : AdapterView.OnItemSelectedListener {
                    override fun onItemSelected(parent: AdapterView<*>?, v: View?, pos: Int, id: Long) {
                        val p = plans.getOrNull(pos) ?: return
                        val countries = p["countries"] as? String ?: ""
                        binding.tvPlanDetails.text = if (countries.isNotEmpty()) "Coverage: $countries" else ""
                    }
                    override fun onNothingSelected(parent: AdapterView<*>?) {}
                }

                @Suppress("UNCHECKED_CAST")
                val history = body["requests"] as? List<Map<String, Any>> ?: emptyList()
                renderHistory(history)
            } catch (_: Exception) {
                if (_binding == null) return@launch
                LoadingOverlay.dismiss()
                binding.tvRailInfo.text = "Unable to connect. Please try again."
                binding.btnSubmit.isEnabled = false
            }
        }
    }

    private fun validateAndConfirm() {
        if (kycRequired) {
            snack("Complete KYC verification first")
            return
        }
        val selected = plans.getOrNull(binding.spinnerPlan.selectedItemPosition)
        val email = binding.etEmail.text?.toString()?.trim() ?: ""
        if (selected == null) {
            snack("Select an eSIM plan")
            return
        }
        if (email.isEmpty() || !Patterns.EMAIL_ADDRESS.matcher(email).matches()) {
            snack("Enter a valid email address for eSIM QR delivery")
            return
        }

        if (pinRequired) {
            promptPin("Confirm eSIM Order") { pin -> submitOrder(selected, email, pin) }
        } else {
            submitOrder(selected, email, "")
        }
    }

    private fun submitOrder(plan: Map<String, Any>, email: String, pin: String) {
        LoadingOverlay.show(requireContext(), "Submitting eSIM order...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val planId = plan["plan_id"] as? String ?: ""
                val planName = plan["name"] as? String ?: ""
                val payload = mutableMapOf<String, Any>(
                    "api_key" to prefs.getApiKey(),
                    "action" to "submit",
                    "request_key" to requestKey,
                    "plan_id" to planId,
                    "plan" to planName,
                    "email" to email
                )
                if (pin.isNotEmpty()) payload["pin"] = pin
                val resp = RetrofitClient.getService().esim(payload)
                val body = resp.body()
                if (_binding == null) return@launch
                LoadingOverlay.dismiss()
                if (resp.isSuccessful && body?.get("status") == "success") {
                    val msg = body["message"] as? String ?: "eSIM order received"
                    requestKey = body["request_key"] as? String ?: requestKey
                    @Suppress("UNCHECKED_CAST")
                    val history = body["requests"] as? List<Map<String, Any>> ?: emptyList()
                    renderHistory(history)
                    snack(msg)
                } else {
                    snack(body?.get("message") as? String ?: "eSIM order failed")
                }
            } catch (_: Exception) {
                if (_binding == null) return@launch
                LoadingOverlay.dismiss()
                snack("Network error. Retry safely without changing fields.")
            }
        }
    }

    private fun renderHistory(rows: List<Map<String, Any>>) {
        val box = binding.llHistory
        box.removeAllViews()
        if (rows.isEmpty()) {
            box.addView(TextView(requireContext()).apply {
                text = "No eSIM orders yet."
                textSize = 13f
                tag = "brand:text=on_surface"
            })
            return
        }
        rows.forEach { row ->
            val card = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(16, 12, 16, 12)
                tag = "brand:bg=surface"
            }
            val ref = row["reference"] as? String ?: ""
            val status = row["status"] as? String ?: "pending"
            val summary = row["summary"] as? String ?: ""
            val created = row["created_at"] as? String ?: ""
            card.addView(TextView(requireContext()).apply {
                text = "$ref · ${status.uppercase()}"
                textSize = 13f
                setTypeface(typeface, android.graphics.Typeface.BOLD)
                tag = "brand:text=on_surface"
            })
            card.addView(TextView(requireContext()).apply {
                text = "$summary\n$created"
                textSize = 12f
                tag = "brand:text=on_surface"
            })
            box.addView(card)
        }
    }

    private fun promptPin(title: String, onPin: (String) -> Unit) {
        val input = EditText(requireContext()).apply {
            inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD
            filters = arrayOf(InputFilter.LengthFilter(4))
            hint = "4-digit Transaction PIN"
        }
        val wrap = FrameLayout(requireContext()).apply {
            setPadding(48, 16, 48, 8)
            addView(input)
        }
        val dlg = MaterialAlertDialogBuilder(requireContext())
            .setTitle(title)
            .setMessage("Enter your 4-digit Transaction PIN to confirm.")
            .setView(wrap)
            .setPositiveButton("Confirm") { _, _ ->
                val pin = input.text?.toString()?.trim() ?: ""
                if (pin.length != 4) snack("Enter your 4-digit Transaction PIN")
                else {
                    TransactionPin.current = pin
                    onPin(pin)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
        dlg.window?.decorView?.let { BrandTheme.applyView(it) }
    }

    private fun snack(msg: String) {
        view?.let { Snackbar.make(it, msg, Snackbar.LENGTH_LONG).show() }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
