package com.mzeevtu.ui.services

import android.os.Bundle
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.FragmentGiftcardBinding
import com.mzeevtu.util.LoadingOverlay
import com.mzeevtu.util.PreferenceManager
import com.google.android.material.card.MaterialCardView
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

class GiftCardFragment : Fragment(R.layout.fragment_giftcard) {

    private var _binding: FragmentGiftcardBinding? = null
    private val binding get() = _binding!!

    /** All products returned by API, keyed by category */
    private val allProducts = mutableListOf<Map<String, Any>>()
    private val categories = mutableListOf<String>()

    /** Currently selected product */
    private var selectedProductId = 0
    private var selectedProductName = ""
    private var selectedProductCurrency = "USD"
    private var selectedProductType = ""
    private var selectedAmount = 0.0

    /** TextWatcher attached to et_amount for RANGE products; tracked so we can remove it before re-attaching */
    private var amountWatcher: android.text.TextWatcher? = null

    companion object {
        private const val CATEGORY_ALL = "All Categories"
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentGiftcardBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnBuy.setOnClickListener { confirmPurchase() }

        setupTabs()
        setupSearch()
        loadProducts()
    }

    private fun setupSearch() {
        binding.etSearch.addTextChangedListener(object : android.text.TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: android.text.Editable?) {
                val category = binding.spinnerCategory.text.toString()
                updateProductDropdown(category, s?.toString() ?: "")
            }
        })
    }

    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    // Tab management
    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬

    private fun setupTabs() {
        binding.tabBuy.setOnClickListener { showTab(0) }
        binding.tabMyCards.setOnClickListener { showTab(1) }
        binding.tabP2p.setOnClickListener { showTab(2) }
        showTab(0)
    }

    private fun showTab(index: Int) {
        binding.tabBuy.isSelected = index == 0
        binding.tabMyCards.isSelected = index == 1
        binding.tabP2p.isSelected = index == 2

        binding.sectionBuy.visibility = if (index == 0) View.VISIBLE else View.GONE
        binding.sectionMyCards.visibility = if (index == 1) View.VISIBLE else View.GONE
        binding.sectionP2p.visibility = if (index == 2) View.VISIBLE else View.GONE

        when (index) {
            1 -> loadMyCards()
            2 -> loadP2PMarket()
        }
    }

    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    // Load products (Buy Card tab)
    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬

    private fun loadProducts() {
        binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.giftCardAction(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "list_products")
                )
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val products = resp.body()?.get("products") as? List<Map<String, Any>> ?: emptyList()
                    @Suppress("UNCHECKED_CAST")
                    val cats = resp.body()?.get("categories") as? List<String> ?: emptyList()

                    allProducts.clear()
                    allProducts.addAll(products)
                    categories.clear()
                    categories.add(CATEGORY_ALL)
                    categories.addAll(cats)

                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        binding.progressBar.visibility = View.GONE
                        setupCategoryDropdown()
                    }
                } else {
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        binding.progressBar.visibility = View.GONE
                        snack("Failed to load products")
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

    private fun setupCategoryDropdown() {
        val catAdapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, categories)
        binding.spinnerCategory.setAdapter(catAdapter)
        binding.spinnerCategory.threshold = 0
        binding.spinnerCategory.setText(CATEGORY_ALL, false)
        binding.spinnerCategory.setOnItemClickListener { _, _, pos, _ ->
            val query = binding.etSearch.text.toString()
            updateProductDropdown(categories[pos], query)
        }
        updateProductDropdown(CATEGORY_ALL)
    }

    private fun updateProductDropdown(category: String, query: String = "") {
        var filtered = if (category == CATEGORY_ALL) {
            allProducts
        } else {
            allProducts.filter { it["category"] as? String == category }
        }

        if (query.isNotEmpty()) {
            val q = query.lowercase()
            filtered = filtered.filter {
                val name = (it["name"] as? String)?.lowercase() ?: ""
                val country = (it["country_name"] as? String)?.lowercase() ?: ""
                name.contains(q) || country.contains(q)
            }
        }
        val names = filtered.map { it["name"] as? String ?: "" }
        val prodAdapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, names)
        binding.spinnerProduct.setAdapter(prodAdapter)
        binding.spinnerProduct.threshold = 0
        binding.spinnerProduct.setText("", false)
        clearProductSelection()

        binding.spinnerProduct.setOnItemClickListener { _, _, pos, _ ->
            filtered.getOrNull(pos)?.let { onProductSelected(it) }
        }
    }

    private fun onProductSelected(product: Map<String, Any>) {
        selectedProductId = (product["product_id"] as? Number)?.toInt() ?: 0
        selectedProductName = product["name"] as? String ?: ""
        selectedProductCurrency = product["currency"] as? String ?: "USD"
        selectedProductType = product["type"] as? String ?: "FIXED"
        selectedAmount = 0.0

        val type = selectedProductType.uppercase()
        val marginEndPx = android.util.TypedValue.applyDimension(
            android.util.TypedValue.COMPLEX_UNIT_DIP, 8f, resources.displayMetrics
        ).toInt()

        if (type == "RANGE") {
            val minVal = (product["min_value"] as? Number)?.toDouble() ?: 0.0
            val maxVal = (product["max_value"] as? Number)?.toDouble() ?: 0.0
            binding.layoutAmount.visibility = View.VISIBLE
            binding.tvRangeHint.text = "Min: $selectedProductCurrency ${minVal.toInt()} Ã¢â¬” Max: $selectedProductCurrency ${maxVal.toInt()}"
            binding.tvRangeHint.visibility = View.VISIBLE
            binding.layoutFixedValues.visibility = View.GONE

            // Remove any previously registered watcher before adding a new one
            amountWatcher?.let { binding.etAmount.removeTextChangedListener(it) }
            amountWatcher = object : android.text.TextWatcher {
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
                override fun afterTextChanged(s: android.text.Editable?) {
                    selectedAmount = s?.toString()?.toDoubleOrNull() ?: 0.0
                    updatePricePreview()
                }
            }
            binding.etAmount.addTextChangedListener(amountWatcher)
        } else {
            // FIXED denomination
            binding.layoutAmount.visibility = View.GONE
            binding.tvRangeHint.visibility = View.GONE
            binding.layoutFixedValues.visibility = View.VISIBLE
            binding.layoutFixedValues.removeAllViews()
            selectedAmount = 0.0

            @Suppress("UNCHECKED_CAST")
            val fixedList = product["fixed_values"] as? List<Any> ?: emptyList()
            fixedList.forEach { v ->
                val amount = (v as? Number)?.toDouble() ?: return@forEach
                val btn = Button(requireContext()).apply {
                    text = "$selectedProductCurrency ${amount.toInt()}"
                    textSize = 12f
                    isAllCaps = false
                    layoutParams = LinearLayout.LayoutParams(
                        LinearLayout.LayoutParams.WRAP_CONTENT,
                        LinearLayout.LayoutParams.WRAP_CONTENT
                    ).apply { marginEnd = marginEndPx }
                    setOnClickListener {
                        selectedAmount = amount
                        // Highlight selected button
                        binding.layoutFixedValues.forEach { child ->
                            (child as? Button)?.isSelected = false
                        }
                        isSelected = true
                        updatePricePreview()
                    }
                }
                binding.layoutFixedValues.addView(btn)
            }
        }

        binding.tvPricePreview.visibility = View.GONE
    }

    private fun updatePricePreview() {
        if (selectedAmount > 0 && selectedProductId > 0) {
            binding.tvPricePreview.text = "\u2248 $selectedProductCurrency ${selectedAmount.toInt()} (Rate applies at checkout)"
            binding.tvPricePreview.visibility = View.VISIBLE
        } else {
            binding.tvPricePreview.visibility = View.GONE
        }
    }

    private fun clearProductSelection() {
        selectedProductId = 0
        selectedProductName = ""
        selectedProductCurrency = "USD"
        selectedProductType = ""
        selectedAmount = 0.0
        amountWatcher?.let { binding.etAmount.removeTextChangedListener(it) }
        amountWatcher = null
        binding.etAmount.text?.clear()
        binding.layoutAmount.visibility = View.GONE
        binding.tvRangeHint.visibility = View.GONE
        binding.layoutFixedValues.visibility = View.GONE
        binding.tvPricePreview.visibility = View.GONE
    }

    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    // Purchase flow
    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬

    private fun confirmPurchase() {
        if (selectedProductId == 0) { snack("Select a gift card product"); return }
        if (selectedAmount <= 0) { snack("Select or enter an amount"); return }

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm Purchase")
            .setMessage("Product: $selectedProductName\nAmount: $selectedProductCurrency ${selectedAmount.toInt()}\n\nProceed?")
            .setPositiveButton("Enter PIN") { _, _ -> showPinDialog() }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun showPinDialog() {
        val pinView = layoutInflater.inflate(R.layout.dialog_pin_input, null)
        val etPin = pinView.findViewById<com.google.android.material.textfield.TextInputEditText>(R.id.et_pin)
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Enter Transaction PIN")
            .setView(pinView)
            .setPositiveButton("Confirm") { _, _ ->
                val pin = etPin?.text?.toString()?.trim() ?: ""
                if (!isValidPin(pin)) {
                    snack("PIN must be 4 digits")
                } else {
                    doPurchase(pin)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun doPurchase(pin: String) {
        LoadingOverlay.show(requireContext(), "Processing your purchase...")
        binding.btnBuy.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.giftCardAction(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "action" to "purchase",
                        "product_id" to selectedProductId,
                        "amount" to selectedAmount,
                        "pin" to pin
                    )
                )
                val status = resp.body()?.get("status") as? String ?: "error"
                val msg = resp.body()?.get("message") as? String ?: "Unknown error"
                val cardCode = resp.body()?.get("card_code") as? String ?: ""
                val cardPin = resp.body()?.get("card_pin") as? String ?: ""

                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnBuy.isEnabled = true
                    if (status.contains("success", true)) {
                        val result = buildString {
                            append(msg)
                            if (cardCode.isNotEmpty()) append("\n\nCard Code: $cardCode")
                            if (cardPin.isNotEmpty()) append("\nCard PIN: $cardPin")
                        }
                        MaterialAlertDialogBuilder(requireContext())
                            .setTitle("\u2705 Purchase Successful")
                            .setMessage(result)
                            .setPositiveButton("View My Cards") { _, _ -> showTab(1) }
                            .setNegativeButton("Done") { _, _ ->
                                requireActivity().onBackPressedDispatcher.onBackPressed()
                            }
                            .show()
                    } else {
                        snack(msg)
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

    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    // My Cards tab
    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬

    private fun loadMyCards() {
        binding.progressBar.visibility = View.VISIBLE
        binding.containerMyCards.removeAllViews()
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.giftCardAction(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "my_cards")
                )
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val cards = resp.body()?.get("cards") as? List<Map<String, Any>> ?: emptyList()
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        binding.progressBar.visibility = View.GONE
                        if (cards.isEmpty()) {
                            binding.containerMyCards.addView(emptyStateView("No cards yet. Buy a gift card to get started."))
                        } else {
                            cards.forEach { card -> binding.containerMyCards.addView(buildCardView(card)) }
                        }
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
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    private fun buildCardView(card: Map<String, Any>): View {
        val cardId = (card["id"] as? Number)?.toInt() ?: 0
        val name = card["product_name"] as? String ?: ""
        val code = card["code"] as? String ?: ""
        val pin = card["pin"] as? String ?: ""
        val value = (card["value"] as? Number)?.toDouble() ?: 0.0
        val currency = card["currency"] as? String ?: "USD"
        val isForSale = (card["is_for_sale"] as? Number)?.toInt() ?: 0
        val salePrice = (card["sale_price"] as? Number)?.toDouble() ?: 0.0
        val date = card["date"] as? String ?: ""

        val cardView = MaterialCardView(requireContext()).apply {
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = 12 }
            radius = 12f
            cardElevation = 2f
        }

        val inner = LinearLayout(requireContext()).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(16, 16, 16, 16)
        }

        inner.addView(textView("$name Ã¢â¬” $currency ${value.toInt()}", 16f, bold = true))
        inner.addView(textView("Code: $code", 14f))
        if (pin.isNotEmpty()) inner.addView(textView("PIN: $pin", 14f))
        inner.addView(textView(date, 12f, colorRes = R.color.text_secondary))

        if (isForSale == 1) {
            inner.addView(textView("Listed for \u20A6${salePrice.toLong()}", 13f, colorRes = R.color.success))
        } else if (cardId > 0) {
            // Offer to list for P2P sale
            val btnList = Button(requireContext()).apply {
                text = "Sell on P2P"
                textSize = 12f
                isAllCaps = false
                setOnClickListener { showListForSaleDialog(cardId) }
                layoutParams = LinearLayout.LayoutParams(
                    LinearLayout.LayoutParams.WRAP_CONTENT,
                    LinearLayout.LayoutParams.WRAP_CONTENT
                ).apply { topMargin = 8 }
            }
            inner.addView(btnList)
        }

        cardView.addView(inner)
        return cardView
    }

    private fun showListForSaleDialog(cardId: Int) {
        val etPrice = com.google.android.material.textfield.TextInputEditText(requireContext()).apply {
            inputType = android.text.InputType.TYPE_CLASS_NUMBER or android.text.InputType.TYPE_NUMBER_FLAG_DECIMAL
            hint = "Sale Price (NGN)"
            setPadding(48, 32, 48, 16)
        }
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("List Card for Sale")
            .setMessage("Enter the NGN price you want to list this card for on the P2P marketplace:")
            .setView(etPrice)
            .setPositiveButton("List") { _, _ ->
                val price = etPrice.text?.toString()?.toDoubleOrNull() ?: 0.0
                if (price > 0) doListForSale(cardId, price)
                else snack("Enter a valid price")
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun doListForSale(cardId: Int, price: Double) {
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.giftCardAction(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "action" to "list_for_sale",
                        "card_id" to cardId,
                        "price" to price
                    )
                )
                val msg = resp.body()?.get("message") as? String ?: ""
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    snack(msg)
                    loadMyCards()
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    // P2P Market tab
    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬

    private fun loadP2PMarket() {
        binding.progressBar.visibility = View.VISIBLE
        binding.containerP2p.removeAllViews()
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.giftCardAction(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "p2p_market")
                )
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val listings = resp.body()?.get("listings") as? List<Map<String, Any>> ?: emptyList()
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        binding.progressBar.visibility = View.GONE
                        if (listings.isEmpty()) {
                            binding.containerP2p.addView(emptyStateView("No listings available in the P2P market right now."))
                        } else {
                            listings.forEach { listing ->
                                binding.containerP2p.addView(buildListingView(listing))
                            }
                        }
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
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    private fun buildListingView(listing: Map<String, Any>): View {
        val listingId = (listing["id"] as? Number)?.toInt() ?: 0
        val seller = listing["seller"] as? String ?: ""
        val product = listing["product"] as? String ?: ""
        val value = (listing["value"] as? Number)?.toDouble() ?: 0.0
        val currency = listing["currency"] as? String ?: "USD"
        val priceNgn = (listing["price_ngn"] as? Number)?.toDouble() ?: 0.0

        val cardView = MaterialCardView(requireContext()).apply {
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = 12 }
            radius = 12f
            cardElevation = 2f
        }

        val inner = LinearLayout(requireContext()).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(16, 16, 16, 16)
        }

        inner.addView(textView("$product \u2014 $currency ${value.toInt()}", 16f, bold = true))
        inner.addView(textView("Seller: @$seller", 13f, colorRes = R.color.text_secondary))
        inner.addView(textView("Price: \u20A6${priceNgn.toLong()}", 14f, colorRes = R.color.primary))

        val btnBuy = Button(requireContext()).apply {
            text = "Buy Now"
            textSize = 12f
            isAllCaps = false
            setOnClickListener { confirmP2PBuy(listingId, product, priceNgn) }
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { topMargin = 8 }
        }
        inner.addView(btnBuy)

        cardView.addView(inner)
        return cardView
    }

    private fun confirmP2PBuy(listingId: Int, productName: String, priceNgn: Double) {
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Buy from P2P Market")
            .setMessage("Product: $productName\nPrice: \u20A6${priceNgn.toLong()}\n\nEnter your PIN to confirm.")
            .setPositiveButton("Enter PIN") { _, _ -> showP2PPinDialog(listingId, productName, priceNgn) }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun showP2PPinDialog(listingId: Int, productName: String, priceNgn: Double) {
        val pinView = layoutInflater.inflate(R.layout.dialog_pin_input, null)
        val etPin = pinView.findViewById<com.google.android.material.textfield.TextInputEditText>(R.id.et_pin)
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Confirm P2P Purchase")
            .setMessage("$productName \u2014 \u20A6${priceNgn.toLong()}")
            .setView(pinView)
            .setPositiveButton("Confirm") { _, _ ->
                val pin = etPin?.text?.toString()?.trim() ?: ""
                if (!isValidPin(pin)) snack("PIN must be 4 digits")
                else doP2PBuy(listingId, pin)
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun doP2PBuy(listingId: Int, pin: String) {
        LoadingOverlay.show(requireContext(), "Processing your purchase...")
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.giftCardAction(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "action" to "buy_p2p",
                        "card_id" to listingId,
                        "pin" to pin
                    )
                )
                val status = resp.body()?.get("status") as? String ?: "error"
                val msg = resp.body()?.get("message") as? String ?: "Unknown error"
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    if (status.contains("success", true)) {
                        MaterialAlertDialogBuilder(requireContext())
                            .setTitle("\u2705 Purchase Successful")
                            .setMessage(msg)
                            .setPositiveButton("View My Cards") { _, _ -> showTab(1) }
                            .setNegativeButton("Close", null)
                            .show()
                        loadP2PMarket()
                    } else {
                        snack(msg)
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬
    // View helpers
    // Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬Ã¢”â¬

    private fun textView(text: String, sizeSp: Float, bold: Boolean = false, colorRes: Int = R.color.text_primary): TextView {
        return TextView(requireContext()).apply {
            this.text = text
            textSize = sizeSp
            setTextColor(requireContext().getColor(colorRes))
            if (bold) setTypeface(null, android.graphics.Typeface.BOLD)
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply { bottomMargin = 4 }
        }
    }

    private fun emptyStateView(message: String): TextView {
        return TextView(requireContext()).apply {
            text = message
            textSize = 15f
            gravity = android.view.Gravity.CENTER
            setPadding(16, 48, 16, 48)
            setTextColor(requireContext().getColor(R.color.text_secondary))
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            )
        }
    }

    // Allows using forEach on a ViewGroup
    private fun LinearLayout.forEach(action: (View) -> Unit) {
        for (i in 0 until childCount) action(getChildAt(i))
    }

    private fun isValidPin(pin: String): Boolean = pin.length == 4 && pin.all { it.isDigit() }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
