package com.datagifting.app.ui.transactions

import android.graphics.Color
import android.os.Bundle
import android.view.View
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.data.model.Transaction
import com.datagifting.app.databinding.FragmentTransactionsBinding
import com.datagifting.app.util.toNaira
import com.datagifting.app.util.PreferenceManager
import kotlinx.coroutines.launch

class TransactionsFragment : Fragment(R.layout.fragment_transactions) {

    private var _binding: FragmentTransactionsBinding? = null
    private val binding get() = _binding!!
    private val transactions = mutableListOf<Transaction>()
    private var currentOffset = 0
    private val pageSize = 20

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentTransactionsBinding.bind(view)
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnLoadMore.setOnClickListener { loadMore() }
        binding.swipeRefresh.setOnRefreshListener { loadTransactions(reset = true) }
        binding.swipeRefresh.setColorSchemeResources(R.color.primary)
        loadTransactions(reset = true)
    }

    private fun loadTransactions(reset: Boolean = false) {
        if (reset) { currentOffset = 0; transactions.clear() }
        binding.progressBar.visibility = View.VISIBLE

        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.getTransactions(mapOf("api_key" to prefs.getApiKey(),
                    "limit" to pageSize, "offset" to currentOffset))
                @Suppress("UNCHECKED_CAST")
                val list = resp.body()?.get("data") as? List<Map<String, Any>>
                val parsed = list?.mapNotNull { m ->
                    Transaction(
                        reference = m["reference"] as? String ?: "",
                        type = m["type"] as? String ?: "",
                        amount = (m["amount"] as? Double) ?: (m["amount"].toString().toDoubleOrNull() ?: 0.0),
                        discountedAmount = (m["discounted_amount"] as? Double) ?: (m["discounted_amount"].toString().toDoubleOrNull() ?: 0.0),
                        balanceBefore = (m["balance_before"] as? Double) ?: (m["balance_before"].toString().toDoubleOrNull() ?: 0.0),
                        balanceAfter = (m["balance_after"] as? Double) ?: (m["balance_after"].toString().toDoubleOrNull() ?: 0.0),
                        description = m["description"] as? String ?: "",
                        status = (m["status"] as? Double)?.toInt() ?: (m["status"].toString().toIntOrNull() ?: 0),
                        statusName = m["status_name"] as? String ?: statusLabel((m["status"] as? Double)?.toInt() ?: 0),
                        mode = m["mode"] as? String ?: "",
                        date = m["date"] as? String ?: ""
                    )
                } ?: emptyList()
                transactions.addAll(parsed)
                currentOffset += pageSize
                activity?.runOnUiThread { renderTransactions(); binding.progressBar.visibility = View.GONE; binding.swipeRefresh.isRefreshing = false }
            } catch (e: Exception) {
                activity?.runOnUiThread { binding.progressBar.visibility = View.GONE; binding.swipeRefresh.isRefreshing = false }
            }
        }
    }

    private fun statusLabel(status: Int) = when (status) {
        1 -> "Successful"; 2 -> "Pending"; 3 -> "Failed"; else -> "Unknown"
    }

    /** Returns green for success, yellow for pending, red for failed */
    private fun statusColor(status: Int) = when (status) {
        1 -> Color.parseColor("#2E7D32")   // GREEN - Successful
        2 -> Color.parseColor("#F9A825")   // YELLOW - Pending
        else -> Color.parseColor("#C62828") // RED - Failed
    }

    private fun loadMore() = loadTransactions(reset = false)

    private fun renderTransactions() {
        binding.containerTx.removeAllViews()
        binding.tvEmpty.visibility = if (transactions.isEmpty()) View.VISIBLE else View.GONE
        transactions.forEach { tx ->
            val row = layoutInflater.inflate(R.layout.item_transaction_row, binding.containerTx, false)
            row.findViewById<TextView>(R.id.tv_tx_desc).text = tx.description
            row.findViewById<TextView>(R.id.tv_tx_amount).apply {
                text = tx.amount.toNaira()
                setTextColor(requireContext().getColor(
                    if (tx.balanceAfter < tx.balanceBefore) R.color.debit else R.color.credit
                ))
            }
            row.findViewById<TextView>(R.id.tv_tx_date).text = tx.date
            // Color the status dot: green=success, yellow=pending, red=failed
            row.findViewById<View>(R.id.view_status_dot).setBackgroundColor(statusColor(tx.status))
            row.setOnClickListener { showDetail(tx) }
            binding.containerTx.addView(row)
        }
    }

    private fun showDetail(tx: Transaction) {
        ReceiptHelper.showReceiptDialog(this, tx)
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
