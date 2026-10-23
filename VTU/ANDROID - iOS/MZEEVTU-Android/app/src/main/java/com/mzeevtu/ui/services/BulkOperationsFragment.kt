package com.mzeevtu.ui.services

import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Button
import android.widget.EditText
import android.widget.ProgressBar
import android.widget.RadioButton
import android.widget.RadioGroup
import android.widget.TextView
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import androidx.appcompat.widget.Toolbar
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.util.LoadingOverlay
import com.mzeevtu.util.PreferenceManager
import com.mzeevtu.util.safeNavigate
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class BulkOperationsFragment : Fragment() {

    private lateinit var rgServiceType: RadioGroup
    private lateinit var rbAirtime: RadioButton
    private lateinit var rbData: RadioButton
    private lateinit var rbSms: RadioButton
    
    private lateinit var etRecipients: EditText
    private lateinit var tvValidationSummary: TextView
    private lateinit var tvValidationDetails: TextView
    
    private lateinit var tvParamLabel: TextView
    private lateinit var etParamValue: EditText
    
    private lateinit var btnSubmitBatch: Button
    private lateinit var progressBar: ProgressBar

    private val api = RetrofitClient.getService()
    private lateinit var prefs: PreferenceManager

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View? {
        val root = inflater.inflate(R.layout.fragment_bulk_operations, container, false)
        prefs = PreferenceManager(requireContext())

        val toolbar = root.findViewById<Toolbar>(R.id.toolbar)
        toolbar.setNavigationIcon(R.drawable.ic_home)
        toolbar.setNavigationOnClickListener {
            requireActivity().onBackPressedDispatcher.onBackPressed()
        }

        root.findViewById<Button>(R.id.btnViewHistory).setOnClickListener {
            findNavController().safeNavigate(R.id.nav_batch_transactions)
        }

        rgServiceType = root.findViewById(R.id.rgServiceType)
        rbAirtime = root.findViewById(R.id.rbAirtime)
        rbData = root.findViewById(R.id.rbData)
        rbSms = root.findViewById(R.id.rbSms)
        
        etRecipients = root.findViewById(R.id.etRecipients)
        tvValidationSummary = root.findViewById(R.id.tvValidationSummary)
        tvValidationDetails = root.findViewById(R.id.tvValidationDetails)
        
        tvParamLabel = root.findViewById(R.id.tvParamLabel)
        etParamValue = root.findViewById(R.id.etParamValue)
        
        btnSubmitBatch = root.findViewById(R.id.btnSubmitBatch)
        progressBar = root.findViewById(R.id.progressBar)

        setupListeners()

        return root
    }

    private fun setupListeners() {
        rgServiceType.setOnCheckedChangeListener { _, checkedId ->
            when (checkedId) {
                R.id.rbAirtime -> {
                    tvParamLabel.text = "AMOUNT PER NUMBER (₦)"
                    etParamValue.hint = "Enter amount (e.g. 100)"
                    etParamValue.setText("")
                }
                R.id.rbData -> {
                    tvParamLabel.text = "PLAN VALUE (₦)"
                    etParamValue.hint = "Enter data plan price (e.g. 300)"
                    etParamValue.setText("")
                }
                R.id.rbSms -> {
                    tvParamLabel.text = "SMS MESSAGE TEXT"
                    etParamValue.hint = "Enter batch message to send..."
                    etParamValue.setText("")
                }
            }
        }

        etRecipients.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                validateNumbers(s?.toString().orEmpty())
            }
        })

        btnSubmitBatch.setOnClickListener {
            val sType = when (rgServiceType.checkedRadioButtonId) {
                R.id.rbAirtime -> "airtime"
                R.id.rbData -> "data"
                else -> "sms"
            }
            executeBatch(sType)
        }
    }

    private fun validateNumbers(text: String) {
        val rawList = text.split(",").map { it.trim() }.filter { it.isNotEmpty() }
        var mtn = 0
        var airtel = 0
        var glo = 0
        var mobile9 = 0
        var invalid = 0

        for (num in rawList) {
            val clean = num.replace(" ", "")
            if (clean.length >= 10) {
                val prefix = if (clean.startsWith("+234")) {
                    "0" + clean.substring(4)
                } else if (clean.startsWith("234")) {
                    "0" + clean.substring(3)
                } else {
                    clean
                }.take(4)

                when {
                    prefix in listOf("0803", "0806", "0810", "0813", "0814", "0816", "0903", "0906", "0913") -> mtn++
                    prefix in listOf("0802", "0808", "0812", "0701", "0708", "0902", "0907", "0901", "0912") -> airtel++
                    prefix in listOf("0805", "0807", "0811", "0815", "0705", "0905", "0915") -> glo++
                    prefix in listOf("0809", "0817", "0818", "0908", "0909") -> mobile9++
                    else -> invalid++
                }
            } else {
                invalid++
            }
        }

        val total = mtn + airtel + glo + mobile9
        tvValidationSummary.text = "Live Validation: $total numbers verified ($invalid invalid)"
        tvValidationDetails.text = "MTN: $mtn | Airtel: $airtel | Glo: $glo | 9mobile: $mobile9"
    }

    private fun executeBatch(serviceType: String) {
        val rawNumbers = etRecipients.text.toString().trim()
        val param = etParamValue.text.toString().trim()

        if (rawNumbers.isEmpty()) {
            Toast.makeText(requireContext(), "Please input recipient numbers", Toast.LENGTH_SHORT).show()
            return
        }
        if (param.isEmpty()) {
            Toast.makeText(requireContext(), "Please fill in parameter details", Toast.LENGTH_SHORT).show()
            return
        }

        val list = rawNumbers.split(",").map { it.trim() }.filter { it.isNotEmpty() }
        
        btnSubmitBatch.isEnabled = false
        LoadingOverlay.show(requireContext(), "Processing batch...")

        lifecycleScope.launch {
            try {
                Toast.makeText(requireContext(), "Initiating batch job for ${list.size} numbers...", Toast.LENGTH_LONG).show()
                var successCount = 0
                
                // Process batch request sequences
                for ((idx, num) in list.withIndex()) {
                    btnSubmitBatch.text = "PROCESSING ${idx + 1}/${list.size}..."
                    
                    val body = mutableMapOf(
                        "api_key" to prefs.getApiKey(),
                        "phone_number" to num,
                        "phone" to num
                    )

                    if (serviceType == "sms") {
                        body["message"] = param
                    } else {
                        body["amount"] = param
                    }

                    try {
                        val resp = when (serviceType) {
                            "airtime" -> api.purchaseAirtime(body)
                            "data" -> api.purchaseData(body)
                            else -> api.sendBulkSms(body)
                        }
                        if (resp.isSuccessful && resp.body()?.get("status") == "success") {
                            successCount++
                        }
                    } catch (_: Exception) {}
                    
                    delay(300) // Small safety interval to prevent flooding
                }

                Toast.makeText(requireContext(), "🎉 Batch completed! Successfully processed $successCount/${list.size} requests.", Toast.LENGTH_LONG).show()
                etRecipients.setText("")
                etParamValue.setText("")
            } catch (e: Exception) {
                Toast.makeText(requireContext(), "Batch execution error: ${e.message}", Toast.LENGTH_SHORT).show()
            } finally {
                btnSubmitBatch.isEnabled = true
                btnSubmitBatch.text = "PROCESS BATCH"
                LoadingOverlay.dismiss()
            }
        }
    }
}
