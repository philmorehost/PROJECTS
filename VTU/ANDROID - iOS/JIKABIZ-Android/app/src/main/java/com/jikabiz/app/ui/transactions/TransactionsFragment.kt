package com.jikabiz.app.ui.transactions

import android.graphics.Color
import android.os.Bundle
import android.view.View
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.jikabiz.app.R
import com.jikabiz.app.api.RetrofitClient
import com.jikabiz.app.data.model.Transaction
import com.jikabiz.app.databinding.FragmentTransactionsBinding
import com.jikabiz.app.util.toNaira
import com.jikabiz.app.util.PreferenceManager
import kotlinx.coroutines.launch

class TransactionsFragment : Fragment(R.layout.fragment_transactions) {

    private var _binding: FragmentTransactionsBinding? = null
    private val binding get() = _binding!!
    private val transactions = mutableListOf<Transaction>()
    private var currentOffset = 0
    private val pageSize = 20
    private var historyLoading = false

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentTransactionsBinding.bind(view)
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnLoadMore.setOnClickListener { loadMore() }
        binding.swipeRefresh.setOnRefreshListener { loadTransactions(reset = true) }
        binding.swipeRefresh.setColorSchemeColors(com.jikabiz.app.util.BrandTheme.ink(requireContext()))
        loadTransactions(reset = true)
    }

    private fun loadTransactions(reset: Boolean = false) {
        if (historyLoading) return
        historyLoading = true
        val requestedOffset = if (reset) 0 else currentOffset
        binding.progressBar.visibility = View.VISIBLE

        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.getTransactions(mapOf("api_key" to prefs.getApiKey(),
                    "limit" to pageSize, "offset" to requestedOffset))
                if (!resp.isSuccessful || resp.body()?.get("status") != "success")
                    throw IllegalStateException(resp.body()?.get("message") as? String ?: "Unable to load history. Please retry.")
                @Suppress("UNCHECKED_CAST")
                val list = resp.body()?.get("data") as? List<Map<String, Any>> ?: throw IllegalStateException("Invalid history response")
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
                        date = m["date"] as? String ?: "",
                            receiptDetails = (m["receipt_details"] as? List<*>)?.mapNotNull { detail ->
                                val fields = detail as? Map<*, *> ?: return@mapNotNull null
                                val label = fields["label"] as? String ?: return@mapNotNull null
                                val value = fields["value"] as? String ?: return@mapNotNull null
                                com.jikabiz.app.data.model.ReceiptDetail(label, value)
                            } ?: emptyList()
                    )
                } ?: emptyList()
                if (reset) transactions.clear()
                transactions.addAll(parsed)
                currentOffset = requestedOffset + parsed.size
                activity?.runOnUiThread { if (_binding == null) return@runOnUiThread; renderTransactions(); binding.progressBar.visibility = View.GONE; binding.swipeRefresh.isRefreshing = false }
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                activity?.runOnUiThread { if (_binding == null) return@runOnUiThread; binding.progressBar.visibility = View.GONE; binding.swipeRefresh.isRefreshing = false; android.widget.Toast.makeText(requireContext(), e.message ?: "Unable to load history. Please retry.", android.widget.Toast.LENGTH_LONG).show() }
            } finally { historyLoading = false }
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
