package com.datagifting.app.ui.services

import android.content.ContentValues
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.provider.MediaStore
import android.text.Editable
import android.text.TextWatcher
import android.view.View
import android.widget.ArrayAdapter
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.databinding.FragmentPrintHubBinding
import com.datagifting.app.util.LoadingOverlay
import com.datagifting.app.util.PreferenceManager
import com.datagifting.app.util.safeNavigate
import com.google.android.material.button.MaterialButton
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch
import java.io.OutputStream

data class PrintPlanItem(
    val planCode: String,
    val serviceType: String,
    val dataType: String,
    val amount: Double,
    val duration: String,
    val network: String
) {
    override fun toString(): String = "$planCode - ₦${"%.2f".format(amount)} ($duration)"
}

class PrintHubFragment : Fragment(R.layout.fragment_print_hub) {

    private var _binding: FragmentPrintHubBinding? = null
    private val binding get() = _binding!!

    private var selectedServiceType = SERVICE_TYPE_DATA
    // For network-based services (data/airtime)
    private var selectedNetwork = "mtn"
    // For provider-based services (cable/electric/exam/betting)
    private var selectedProvider = ""

    // allPlans keyed by "network_or_provider|servicetype|datatype"
    private val allPlans = mutableMapOf<String, MutableList<PrintPlanItem>>()
    // All providers discovered from API for non-network types
    private val providersByType = mutableMapOf<String, MutableList<String>>()

    private val dataTypes = listOf(
        "sme-data" to "SME Data",
        "cg-data" to "Corporate Gifting",
        "dd-data" to "Direct Data",
        "shared-data" to "Shared Data"
    )

    private val networkServices = setOf(SERVICE_TYPE_DATA, SERVICE_TYPE_AIRTIME)

    private val networkMap get() = mapOf(
        "mtn" to binding.btnMtn,
        "glo" to binding.btnGlo,
        "airtel" to binding.btnAirtel,
        "9mobile" to binding.btnNinemobile
    )

    private val networkLogoMap = mapOf(
        "mtn" to R.drawable.net_mtn,
        "glo" to R.drawable.net_glo,
        "airtel" to R.drawable.net_airtel,
        "9mobile" to R.drawable.net_9mobile
    )

    private var selectedPlan: PrintPlanItem? = null

    companion object {
        const val SERVICE_TYPE_DATA = "data"
        const val SERVICE_TYPE_AIRTIME = "airtime"
        const val SERVICE_TYPE_CABLE = "cable"
        const val SERVICE_TYPE_ELECTRIC = "electric"
        const val SERVICE_TYPE_EXAM = "exam"
        const val SERVICE_TYPE_BETTING = "betting"
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentPrintHubBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }

        setupServiceTypeTabs()
        setupNetworkButtons()
        setupDataTypeDropdown()
        setupProviderDropdown()
        setupQuantityWatcher()
        binding.btnBuy.setOnClickListener { confirmPurchase() }

        loadPlans()
    }

    private fun setupServiceTypeTabs() {
        applyServiceTypeSelection(SERVICE_TYPE_DATA)
        binding.btnTypeData.setOnClickListener { applyServiceTypeSelection(SERVICE_TYPE_DATA) }
        binding.btnTypeAirtime.setOnClickListener { applyServiceTypeSelection(SERVICE_TYPE_AIRTIME) }
        binding.btnTypeCable.setOnClickListener { applyServiceTypeSelection(SERVICE_TYPE_CABLE) }
        binding.btnTypeElectric.setOnClickListener { applyServiceTypeSelection(SERVICE_TYPE_ELECTRIC) }
        binding.btnTypeExam.setOnClickListener { applyServiceTypeSelection(SERVICE_TYPE_EXAM) }
        binding.btnTypeBetting.setOnClickListener { applyServiceTypeSelection(SERVICE_TYPE_BETTING) }
        binding.btnTypeNin.setOnClickListener {
            findNavController().safeNavigate(R.id.action_print_to_nin)
        }
        binding.btnTypeBvn.setOnClickListener {
            findNavController().safeNavigate(R.id.action_print_to_bvn)
        }
    }

    private fun allTypeBtns() = listOf(
        binding.btnTypeData, binding.btnTypeAirtime, binding.btnTypeCable,
        binding.btnTypeElectric, binding.btnTypeExam, binding.btnTypeBetting,
        binding.btnTypeNin, binding.btnTypeBvn
    )

    private fun applyServiceTypeSelection(type: String) {
        selectedServiceType = type
        val isNetwork = type in networkServices

        // Update tab button styles
        allTypeBtns().forEach { btn ->
            btn.setBackgroundColor(requireContext().getColor(android.R.color.transparent))
            btn.setTextColor(requireContext().getColor(R.color.primary))
        }
        val activeBtn = when (type) {
            SERVICE_TYPE_DATA -> binding.btnTypeData
            SERVICE_TYPE_AIRTIME -> binding.btnTypeAirtime
            SERVICE_TYPE_CABLE -> binding.btnTypeCable
            SERVICE_TYPE_ELECTRIC -> binding.btnTypeElectric
            SERVICE_TYPE_EXAM -> binding.btnTypeExam
            SERVICE_TYPE_BETTING -> binding.btnTypeBetting
            else -> binding.btnTypeData
        }
        activeBtn.setBackgroundColor(requireContext().getColor(R.color.primary))
        activeBtn.setTextColor(requireContext().getColor(R.color.text_on_primary))

        // Show/hide sections
        binding.sectionNetwork.visibility = if (isNetwork) View.VISIBLE else View.GONE
        binding.tilDataType.visibility = if (type == SERVICE_TYPE_DATA) View.VISIBLE else View.GONE
        binding.tilProvider.visibility = if (!isNetwork) View.VISIBLE else View.GONE

        // Update provider dropdown for selected type
        if (!isNetwork) {
            val providers = providersByType[type] ?: emptyList()
            val providerAdapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, providers)
            binding.spinnerProvider.setAdapter(providerAdapter)
            binding.spinnerProvider.threshold = 0
            if (providers.isNotEmpty()) {
                selectedProvider = providers[0]
                binding.spinnerProvider.setText(providers[0], false)
            } else {
                selectedProvider = ""
                binding.spinnerProvider.setText("", false)
            }
        }

        selectedPlan = null
        updatePlanDropdown()
        updateTotalPrice()
    }

    private fun setupNetworkButtons() {
        networkMap.forEach { (net, btn) ->
            btn.setOnClickListener { applyNetworkSelection(net) }
        }
        applyNetworkSelection("mtn")
    }

    private fun applyNetworkSelection(net: String) {
        selectedNetwork = net
        networkMap.forEach { (key, btn) ->
            btn.isSelected = (key == net)
            btn.alpha = 1.0f
        }
        selectedPlan = null
        updatePlanDropdown()
        updateTotalPrice()
    }

    private fun setupDataTypeDropdown() {
        val labels = dataTypes.map { it.second }
        val adapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, labels)
        binding.spinnerDataType.setAdapter(adapter)
        binding.spinnerDataType.threshold = 0
        binding.spinnerDataType.setText(labels[0], false)

        binding.spinnerDataType.setOnItemClickListener { _, _, _, _ ->
            selectedPlan = null
            updatePlanDropdown()
            updateTotalPrice()
        }
    }

    private fun setupProviderDropdown() {
        binding.spinnerProvider.threshold = 0
        binding.spinnerProvider.setOnItemClickListener { _, _, position, _ ->
            val providers = providersByType[selectedServiceType] ?: emptyList()
            selectedProvider = providers.getOrNull(position) ?: ""
            selectedPlan = null
            updatePlanDropdown()
            updateTotalPrice()
        }
    }

    private fun getCurrentDataType(): String {
        if (selectedServiceType != SERVICE_TYPE_DATA) return ""
        val label = binding.spinnerDataType.text?.toString() ?: dataTypes[0].second
        return dataTypes.firstOrNull { it.second == label }?.first ?: dataTypes[0].first
    }

    private fun getCurrentProviderOrNetwork(): String {
        return if (selectedServiceType in networkServices) selectedNetwork else selectedProvider
    }

    private fun updatePlanDropdown() {
        val provOrNet = getCurrentProviderOrNetwork()
        val key = "$provOrNet|$selectedServiceType|${getCurrentDataType()}"
        val plans = allPlans[key] ?: emptyList()
        val adapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, plans)
        binding.spinnerPlan.setAdapter(adapter)
        binding.spinnerPlan.threshold = 0
        binding.spinnerPlan.setText("", false)
        selectedPlan = null

        binding.spinnerPlan.setOnItemClickListener { _, _, position, _ ->
            selectedPlan = plans.getOrNull(position)
            updateTotalPrice()
        }
    }

    private fun setupQuantityWatcher() {
        binding.etQuantity.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) { updateTotalPrice() }
        })
    }

    private fun getQuantity(): Int = binding.etQuantity.text?.toString()?.toIntOrNull() ?: 0

    private fun updateTotalPrice() {
        val qty = getQuantity()
        val plan = selectedPlan
        val total = if (plan != null && qty > 0) plan.amount * qty else 0.0
        binding.tvTotalPrice.text = "Total: ₦${"%.2f".format(total)}"
    }

    private fun loadPlans() {
        binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.getPrintCardPlans(mapOf("api_key" to prefs.getApiKey()))
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val networkData = resp.body()?.get("MOBILE_NETWORK") as? Map<String, List<Map<String, Any>>>
                    allPlans.clear()
                    providersByType.clear()
                    networkData?.forEach networkLoop@{ (networkKey, plans) ->
                        val netLower = networkKey.lowercase()
                        plans.forEach { plan ->
                            val stype = (plan["SERVICE_TYPE"] as? String)?.lowercase() ?: SERVICE_TYPE_DATA
                            val dtype = (plan["DATA_TYPE"] as? String)?.lowercase() ?: ""
                            val pcode = plan["PLAN_CODE"] as? String ?: return@forEach
                            val amount = plan["AMOUNT"]?.toString()?.toDoubleOrNull() ?: return@forEach
                            val duration = plan["DURATION"] as? String ?: ""
                            val mapKey = "$netLower|$stype|$dtype"
                            allPlans.getOrPut(mapKey) { mutableListOf() }.add(
                                PrintPlanItem(pcode, stype, dtype, amount, duration, netLower)
                            )
                            // Track providers for non-network types
                            if (stype !in networkServices) {
                                val list = providersByType.getOrPut(stype) { mutableListOf() }
                                if (!list.contains(netLower)) list.add(netLower)
                            }
                        }
                    }
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        binding.progressBar.visibility = View.GONE
                        // Refresh provider lists for current type
                        if (selectedServiceType !in networkServices) {
                            val providers = providersByType[selectedServiceType] ?: emptyList()
                            val adapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, providers)
                            binding.spinnerProvider.setAdapter(adapter)
                            if (providers.isNotEmpty() && selectedProvider.isEmpty()) {
                                selectedProvider = providers[0]
                                binding.spinnerProvider.setText(providers[0], false)
                            }
                        }
                        updatePlanDropdown()
                    }
                } else {
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        binding.progressBar.visibility = View.GONE
                        snack("Failed to load plans")
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    private fun confirmPurchase() {
        val plan = selectedPlan ?: return snack("Select a plan")
        val qty = getQuantity()
        if (qty < 1 || qty > 40) return snack("Quantity must be between 1 and 40")
        val total = plan.amount * qty

        val serviceLabel = serviceTypeLabel(plan.serviceType)
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm Purchase")
            .setMessage(
                "Provider: ${getCurrentProviderOrNetwork().uppercase()}\n" +
                "Service: $serviceLabel\n" +
                "Plan: ${plan.planCode}\n" +
                "Quantity: $qty card(s)\n" +
                "Total: ₦${"%.2f".format(total)}"
            )
            .setPositiveButton("Buy") { _, _ -> doPurchase(plan, qty) }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun serviceTypeLabel(type: String) = when (type) {
        SERVICE_TYPE_AIRTIME -> "Recharge Card"
        SERVICE_TYPE_CABLE -> "Cable Card"
        SERVICE_TYPE_ELECTRIC -> "Electric Token"
        SERVICE_TYPE_EXAM -> "Exam PIN"
        SERVICE_TYPE_BETTING -> "Betting Card"
        else -> "Data Card"
    }

    private fun doPurchase(plan: PrintPlanItem, quantity: Int) {
        LoadingOverlay.show(requireContext(), "Processing your purchase...")
        binding.btnBuy.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val provOrNet = getCurrentProviderOrNetwork()
                val params = mutableMapOf<String, Any>(
                    "api_key" to prefs.getApiKey(),
                    "network" to provOrNet,
                    "service_type" to plan.serviceType,
                    "plan_code" to plan.planCode,
                    "quantity" to quantity.toString()
                )
                if (plan.serviceType == SERVICE_TYPE_DATA) params["data_type"] = plan.dataType
                val resp = api.buyPrintCards(params)
                val body = resp.body()
                val status = body?.get("status") as? String ?: "failed"
                val desc = body?.get("desc") as? String ?: ""
                @Suppress("UNCHECKED_CAST")
                val cards = body?.get("cards") as? List<Map<String, Any>>
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnBuy.isEnabled = true
                    if (status == "success" && cards != null) {
                        displayGeneratedCards(plan, cards)
                    } else {
                        snack(if (desc.isNotEmpty()) desc else "Purchase failed")
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnBuy.isEnabled = true
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    private fun displayGeneratedCards(plan: PrintPlanItem, cards: List<Map<String, Any>>) {
        binding.tvCardsHeader.visibility = View.VISIBLE
        binding.containerCards.removeAllViews()
        val serviceLabel = serviceTypeLabel(plan.serviceType)
        val provOrNet = getCurrentProviderOrNetwork()
        cards.forEach { card ->
            val epin = card["epin"] as? String ?: "—"
            val sn = card["sn"] as? String ?: "—"
            val cardView = layoutInflater.inflate(R.layout.item_generated_card, binding.containerCards, false)

            val ivLogo = cardView.findViewById<ImageView>(R.id.iv_network_logo)
            val tvNetwork = cardView.findViewById<TextView>(R.id.tv_card_network)
            val tvPlan = cardView.findViewById<TextView>(R.id.tv_card_plan)
            val tvPrice = cardView.findViewById<TextView>(R.id.tv_card_price)
            val tvSn = cardView.findViewById<TextView>(R.id.tv_card_sn)
            val tvPin = cardView.findViewById<TextView>(R.id.tv_card_pin)
            val tvValidity = cardView.findViewById<TextView>(R.id.tv_card_validity)
            val btnSave = cardView.findViewById<MaterialButton>(R.id.btn_save_card)

            networkLogoMap[provOrNet]?.let { ivLogo.setImageResource(it) }
            tvNetwork.text = "${provOrNet.uppercase()} $serviceLabel"
            tvPlan.text = plan.planCode
            tvPrice.text = "₦${"%.2f".format(plan.amount)}"
            tvSn.text = sn
            tvPin.text = epin
            tvValidity.text = if (plan.duration.isNotEmpty()) "Valid: ${plan.duration}" else ""

            btnSave.setOnClickListener { saveCardAsImage(cardView, "$provOrNet-$epin") }

            binding.containerCards.addView(cardView)
        }
        binding.root.post {
            (binding.root as? android.widget.ScrollView)?.smoothScrollTo(0, binding.tvCardsHeader.top)
        }
        snack("${cards.size} card(s) generated successfully!")
    }

    private fun saveCardAsImage(cardView: View, filename: String) {
        try {
            cardView.measure(
                View.MeasureSpec.makeMeasureSpec(cardView.width, View.MeasureSpec.EXACTLY),
                View.MeasureSpec.makeMeasureSpec(0, View.MeasureSpec.UNSPECIFIED)
            )
            cardView.layout(0, 0, cardView.measuredWidth, cardView.measuredHeight)
            val bmp = Bitmap.createBitmap(cardView.measuredWidth, cardView.measuredHeight, Bitmap.Config.ARGB_8888)
            val canvas = Canvas(bmp)
            canvas.drawColor(Color.WHITE)
            cardView.draw(canvas)

            val displayName = "card_${filename.replace("-", "_")}_${System.currentTimeMillis()}.png"
            val mimeType = "image/png"

            val outputStream: OutputStream?
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                val values = ContentValues().apply {
                    put(MediaStore.Images.Media.DISPLAY_NAME, displayName)
                    put(MediaStore.Images.Media.MIME_TYPE, mimeType)
                    put(MediaStore.Images.Media.RELATIVE_PATH, Environment.DIRECTORY_PICTURES + "/PrintHub")
                }
                val uri = requireContext().contentResolver.insert(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, values)
                outputStream = uri?.let { requireContext().contentResolver.openOutputStream(it) }
            } else {
                @Suppress("DEPRECATION")
                val dir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_PICTURES + "/PrintHub")
                dir.mkdirs()
                val file = java.io.File(dir, displayName)
                outputStream = java.io.FileOutputStream(file)
            }

            outputStream?.use { bmp.compress(Bitmap.CompressFormat.PNG, 100, it) }
            snack("Card saved to Pictures/PrintHub")
        } catch (e: Exception) {
            snack("Could not save image: ${e.message}")
        }
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}

