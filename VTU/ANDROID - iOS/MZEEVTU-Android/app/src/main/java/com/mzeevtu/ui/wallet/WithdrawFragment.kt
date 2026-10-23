package com.mzeevtu.ui.wallet

import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Filter
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.data.model.Bank
import com.mzeevtu.databinding.FragmentWithdrawBinding
import com.mzeevtu.util.LoadingOverlay
import com.mzeevtu.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class WithdrawFragment : Fragment(R.layout.fragment_withdraw) {

    private var _binding: FragmentWithdrawBinding? = null
    private val binding get() = _binding!!
    private lateinit var prefs: PreferenceManager

    private val allBanks = mutableListOf<Bank>()
    private var selectedBankCode = ""
    private var selectedBankName = ""
    private var verifiedAccountName = ""
    private var verifyJob: Job? = null

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentWithdrawBinding.bind(view)
        prefs = PreferenceManager(requireContext())

        loadBanksFromAssets()
        setupAccountWatcher()
        binding.btnWithdraw.setOnClickListener { showPinDialog() }
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
    }

    /** Load all 645 banks from assets/banks.json and set up AutoCompleteTextView */
    private fun loadBanksFromAssets() {
        try {
            val json = requireContext().assets.open("banks.json").bufferedReader().readText()
            val type = object : TypeToken<List<Bank>>() {}.type
            val banks: List<Bank> = Gson().fromJson(json, type)
            allBanks.clear()
            allBanks.addAll(banks)
            setupBankAutocomplete()
        } catch (e: Exception) {
            snack("Failed to load bank list")
        }
    }

    /** AutoCompleteTextView with real-time filtering Ã¢â¬” mirrors the TomSelect behavior from the website */
    private fun setupBankAutocomplete() {
        val adapter = BankSearchAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, allBanks)
        binding.etBankSearch.setAdapter(adapter)
        binding.etBankSearch.threshold = 1 // Filter from first character

        binding.etBankSearch.setOnItemClickListener { _, _, position, _ ->
            val bank = adapter.getItem(position) ?: return@setOnItemClickListener
            selectedBankCode = bank.bankCode
            selectedBankName = bank.bankName
            binding.etBankSearch.setText(bank.bankName, false)
            // Trigger account verification if account number is already entered
            val accNo = binding.etAccountNumber.text?.toString()?.trim() ?: ""
            if (accNo.length == 10) {
                triggerVerify(accNo)
            }
        }

        // Clear verified name when bank changes
        binding.etBankSearch.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                if (s?.toString()?.trim() != selectedBankName) {
                    selectedBankCode = ""
                    binding.tvVerifiedName.visibility = View.GONE
                    verifiedAccountName = ""
                }
            }
        })
    }

    private fun setupAccountWatcher() {
        binding.etAccountNumber.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                val accNo = s?.toString()?.trim() ?: ""
                if (accNo.length == 10 && selectedBankCode.isNotEmpty()) {
                    triggerVerify(accNo)
                }
            }
        })
    }

    private fun triggerVerify(accountNumber: String) {
        verifyJob?.cancel()
        verifyJob = lifecycleScope.launch {
            delay(600)
            verifyBankAccount(accountNumber)
        }
    }

    private suspend fun verifyBankAccount(accountNumber: String) {
        binding.tvVerifiedName.visibility = View.GONE
        binding.tvVerifyProgress.visibility = View.VISIBLE
        try {
            val api = RetrofitClient.getService()
            val resp = api.verifyBank(mapOf(
                "api_key" to prefs.getApiKey(),
                "bank_code" to selectedBankCode,
                "account_number" to accountNumber
            ))
            val name = resp.body()?.get("account_name") as? String
                ?: resp.body()?.get("name") as? String ?: ""
            val resolvedCode = resp.body()?.get("mapped_bank_code") as? String ?: selectedBankCode
            if (resolvedCode.isNotEmpty()) selectedBankCode = resolvedCode
            verifiedAccountName = name
            activity?.runOnUiThread {
                binding.tvVerifyProgress.visibility = View.GONE
                if (name.isNotEmpty()) {
                    binding.tvVerifiedName.text = "\u2714 $name"
                    binding.tvVerifiedName.visibility = View.VISIBLE
                } else {
                    binding.tvVerifiedName.text = "\u26A0 Account not found"
                    binding.tvVerifiedName.setTextColor(requireContext().getColor(R.color.error))
                    binding.tvVerifiedName.visibility = View.VISIBLE
                }
            }
        } catch (_: Exception) {
            activity?.runOnUiThread { binding.tvVerifyProgress.visibility = View.GONE }
        }
    }

    private fun showPinDialog() {
        val accountNumber = binding.etAccountNumber.text?.toString()?.trim() ?: ""
        val amount = binding.etAmount.text?.toString()?.trim() ?: ""
        if (selectedBankCode.isEmpty()) { snack("Select a bank from the list"); return }
        if (accountNumber.length != 10) { snack("Account number must be 10 digits"); return }
        if (verifiedAccountName.isEmpty()) { snack("Please wait for account verification"); return }
        if (amount.isEmpty() || (amount.toDoubleOrNull() ?: 0.0) <= 0) { snack("Enter a valid amount"); return }

        val pinBinding = layoutInflater.inflate(R.layout.dialog_pin_input, null)
        val etPin = pinBinding.findViewById<com.google.android.material.textfield.TextInputEditText>(R.id.et_pin)
        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Enter Transaction PIN")
            .setMessage("Withdraw \u20A6$amount to $verifiedAccountName\n$selectedBankName ($accountNumber)")
            .setView(pinBinding)
            .setPositiveButton("Confirm") { _, _ ->
                val pin = etPin?.text?.toString()?.trim() ?: ""
                if (pin.length != 4) { snack("PIN must be 4 digits") } else {
                    doWithdraw(accountNumber, amount, pin)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun doWithdraw(accountNumber: String, amount: String, pin: String) {
        LoadingOverlay.show(requireContext(), "Processing withdrawal...")
        binding.btnWithdraw.isEnabled = false
        lifecycleScope.launch {
            try {
                val api = RetrofitClient.getService()
                val resp = api.withdrawToBank(mapOf(
                    "api_key" to prefs.getApiKey(),
                    "bank_code" to selectedBankCode,
                    "account_number" to accountNumber,
                    "amount" to amount,
                    "pin" to pin
                ))
                val status = resp.body()?.get("status") as? String ?: "failed"
                val msg = resp.body()?.get("message") as? String ?: resp.body()?.get("desc") as? String ?: ""
                activity?.runOnUiThread {
                    LoadingOverlay.dismiss()
                    binding.btnWithdraw.isEnabled = true
                    if (status.contains("success", true)) {
                        MaterialAlertDialogBuilder(requireContext())
                            .setTitle("\u2714 Withdrawal Successful")
                            .setMessage(msg)
                            .setPositiveButton("Done") { _, _ -> requireActivity().onBackPressedDispatcher.onBackPressed() }
                            .show()
                    } else snack(msg)
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    LoadingOverlay.dismiss()
                    binding.btnWithdraw.isEnabled = true
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}

/**
 * Custom ArrayAdapter for bank search autocomplete.
 * Filters by bank name as user types Ã¢â¬” equivalent to TomSelect on the website.
 */
class BankSearchAdapter(
    context: android.content.Context,
    resource: Int,
    private val allBanks: List<Bank>
) : ArrayAdapter<Bank>(context, resource, mutableListOf()) {

    private val filteredBanks = mutableListOf<Bank>()
    private val filterObj = BankFilter()

    init { filteredBanks.addAll(allBanks) }

    override fun getCount() = filteredBanks.size
    override fun getItem(position: Int) = filteredBanks.getOrNull(position)
    override fun getView(position: Int, convertView: android.view.View?, parent: android.view.ViewGroup): android.view.View {
        val view = convertView ?: android.view.LayoutInflater.from(context)
            .inflate(android.R.layout.simple_dropdown_item_1line, parent, false)
        (view as? android.widget.TextView)?.text = filteredBanks.getOrNull(position)?.bankName ?: ""
        return view
    }
    override fun getFilter(): Filter = filterObj

    inner class BankFilter : Filter() {
        override fun performFiltering(constraint: CharSequence?): FilterResults {
            val results = FilterResults()
            val query = constraint?.toString()?.lowercase()?.trim() ?: ""
            val filtered = if (query.isEmpty()) allBanks
            else allBanks.filter { it.bankName.lowercase().contains(query) }
            results.values = filtered
            results.count = filtered.size
            return results
        }
        @Suppress("UNCHECKED_CAST")
        override fun publishResults(constraint: CharSequence?, results: FilterResults?) {
            filteredBanks.clear()
            filteredBanks.addAll(results?.values as? List<Bank> ?: emptyList())
            notifyDataSetChanged()
        }
        override fun convertResultToString(resultValue: Any?) = (resultValue as? Bank)?.bankName ?: ""
    }
}
