package com.mzeevtu.ui.transactions

import android.app.DatePickerDialog
import android.content.Intent
import android.graphics.Color
import android.os.Bundle
import android.view.View
import android.widget.ArrayAdapter
import android.widget.EditText
import android.widget.ImageButton
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.Spinner
import android.widget.TextView
import android.widget.Toast
import androidx.core.content.FileProvider
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.data.model.Transaction
import com.mzeevtu.util.PreferenceManager
import com.mzeevtu.util.toNaira
import kotlinx.coroutines.launch
import java.io.File
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

class TransactionsFragment : Fragment(R.layout.fragment_transactions) {

    private lateinit var btnBack: ImageButton
    private lateinit var btnFilter: ImageButton
    private lateinit var btnExport: ImageButton
    private lateinit var layoutFilters: LinearLayout
    private lateinit var etFilterType: EditText
    private lateinit var spinnerFilterStatus: Spinner
    private lateinit var etStartDate: EditText
    private lateinit var etEndDate: EditText
    private lateinit var btnApplyFilters: View

    private lateinit var progressBar: ProgressBar
    private lateinit var tvEmpty: TextView
    private lateinit var swipeRefresh: SwipeRefreshLayout
    private lateinit var containerTx: LinearLayout
    private lateinit var btnLoadMore: View

    private val transactions = mutableListOf<Transaction>()
    private var currentOffset = 0
    private val pageSize = 20

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        
        btnBack = view.findViewById(R.id.btn_back)
        btnFilter = view.findViewById(R.id.btn_filter)
        btnExport = view.findViewById(R.id.btn_export)
        layoutFilters = view.findViewById(R.id.layout_filters)
        etFilterType = view.findViewById(R.id.et_filter_type)
        spinnerFilterStatus = view.findViewById(R.id.spinner_filter_status)
        etStartDate = view.findViewById(R.id.et_start_date)
        etEndDate = view.findViewById(R.id.et_end_date)
        btnApplyFilters = view.findViewById(R.id.btn_apply_filters)

        progressBar = view.findViewById(R.id.progress_bar)
        tvEmpty = view.findViewById(R.id.tv_empty)
        swipeRefresh = view.findViewById(R.id.swipe_refresh)
        containerTx = view.findViewById(R.id.container_tx)
        btnLoadMore = view.findViewById(R.id.btn_load_more)

        btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        btnLoadMore.setOnClickListener { loadMore() }
        swipeRefresh.setOnRefreshListener { loadTransactions(reset = true) }
        swipeRefresh.setColorSchemeResources(R.color.primary)

        setupFilters()
        loadTransactions(reset = true)
    }

    private fun setupFilters() {
        btnFilter.setOnClickListener {
            layoutFilters.visibility = if (layoutFilters.visibility == View.VISIBLE) View.GONE else View.VISIBLE
        }

        // Setup status spinner
        val statusList = listOf("All Statuses", "Successful", "Pending", "Failed")
        val statusAdapter = ArrayAdapter(requireContext(), android.R.layout.simple_spinner_item, statusList)
        statusAdapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item)
        spinnerFilterStatus.adapter = statusAdapter

        // Setup date pickers
        etStartDate.setOnClickListener { showDatePicker(etStartDate) }
        etEndDate.setOnClickListener { showDatePicker(etEndDate) }

        btnApplyFilters.setOnClickListener {
            layoutFilters.visibility = View.GONE
            loadTransactions(reset = true)
        }

        btnExport.setOnClickListener {
            exportToCSV()
        }
    }

    private fun showDatePicker(editText: EditText) {
        val calendar = Calendar.getInstance()
        DatePickerDialog(
            requireContext(),
            { _, year, month, dayOfMonth ->
                val calendarSelected = Calendar.getInstance().apply {
                    set(Calendar.YEAR, year)
                    set(Calendar.MONTH, month)
                    set(Calendar.DAY_OF_MONTH, dayOfMonth)
                }
                val format = SimpleDateFormat("yyyy-MM-dd", Locale.US)
                editText.setText(format.format(calendarSelected.time))
            },
            calendar.get(Calendar.YEAR),
            calendar.get(Calendar.MONTH),
            calendar.get(Calendar.DAY_OF_MONTH)
        ).show()
    }

    private fun loadTransactions(reset: Boolean = false) {
        if (reset) { currentOffset = 0; transactions.clear() }
        progressBar.visibility = View.VISIBLE

        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()

                val statusSelection = when (spinnerFilterStatus.selectedItemPosition) {
                    1 -> "1"
                    2 -> "2"
                    3 -> "3"
                    else -> ""
                }

                val params = mutableMapOf<String, Any>(
                    "api_key" to prefs.getApiKey(),
                    "limit" to pageSize,
                    "offset" to currentOffset,
                    "type" to etFilterType.text.toString().trim(),
                    "status" to statusSelection,
                    "start_date" to etStartDate.text.toString().trim(),
                    "end_date" to etEndDate.text.toString().trim()
                )

                val resp = api.getTransactions(params)
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
                activity?.runOnUiThread {
                    renderTransactions()
                    progressBar.visibility = View.GONE
                    swipeRefresh.isRefreshing = false
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    progressBar.visibility = View.GONE
                    swipeRefresh.isRefreshing = false
                }
            }
        }
    }

    private fun statusLabel(status: Int) = when (status) {
        1 -> "Successful"; 2 -> "Pending"; 3 -> "Failed"; else -> "Unknown"
    }

    private fun statusColor(status: Int) = when (status) {
        1 -> Color.parseColor("#2E7D32")
        2 -> Color.parseColor("#F9A825")
        else -> Color.parseColor("#C62828")
    }

    private fun loadMore() = loadTransactions(reset = false)

    private fun renderTransactions() {
        containerTx.removeAllViews()
        tvEmpty.visibility = if (transactions.isEmpty()) View.VISIBLE else View.GONE
        transactions.forEach { tx ->
            val row = layoutInflater.inflate(R.layout.item_transaction_row, containerTx, false)
            row.findViewById<TextView>(R.id.tv_tx_desc).text = tx.description
            row.findViewById<TextView>(R.id.tv_tx_amount).apply {
                text = tx.amount.toNaira()
                setTextColor(requireContext().getColor(
                    if (tx.balanceAfter < tx.balanceBefore) R.color.debit else R.color.credit
                ))
            }
            row.findViewById<TextView>(R.id.tv_tx_date).text = tx.date
            row.findViewById<View>(R.id.view_status_dot).setBackgroundColor(statusColor(tx.status))
            row.setOnClickListener { showDetail(tx) }
            containerTx.addView(row)
        }
    }

    private fun showDetail(tx: Transaction) {
        ReceiptHelper.showReceiptDialog(this, tx)
    }

    private fun exportToCSV() {
        if (transactions.isEmpty()) {
            Toast.makeText(requireContext(), "No transactions to export", Toast.LENGTH_SHORT).show()
            return
        }

        try {
            val csvBuilder = StringBuilder()
            csvBuilder.append("Date,Reference,Type,Description,Amount,Status\n")
            for (tx in transactions) {
                csvBuilder.append("\"${tx.date}\",\"${tx.reference}\",\"${tx.type}\",\"${tx.description}\",\"${tx.amount}\",\"${tx.statusName}\"\n")
            }

            val file = File(requireContext().cacheDir, "transactions_export.csv")
            file.writeText(csvBuilder.toString())

            val uri = FileProvider.getUriForFile(requireContext(), "${requireContext().packageName}.fileprovider", file)
            val intent = Intent(Intent.ACTION_SEND).apply {
                type = "text/csv"
                putExtra(Intent.EXTRA_SUBJECT, "Transaction Logs Export")
                putExtra(Intent.EXTRA_STREAM, uri)
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            }
            startActivity(Intent.createChooser(intent, "Share Transaction CSV Log"))
        } catch (e: Exception) {
            Toast.makeText(requireContext(), "Export failed: ${e.message}", Toast.LENGTH_SHORT).show()
        }
    }
}
