package com.mzeevtu.ui.transactions

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.widget.Toolbar
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.util.PreferenceManager
import kotlinx.coroutines.launch

class BatchTransactionsFragment : Fragment() {

    private lateinit var toolbar: Toolbar
    private lateinit var progressBar: ProgressBar
    private lateinit var tvEmpty: TextView
    private lateinit var swipeRefresh: SwipeRefreshLayout
    private lateinit var containerBatches: LinearLayout

    private val api = RetrofitClient.getService()
    private lateinit var prefs: PreferenceManager
    private val batches = mutableListOf<Map<String, Any>>()

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View? {
        val root = inflater.inflate(R.layout.fragment_batch_transactions, container, false)
        prefs = PreferenceManager(requireContext())

        toolbar = root.findViewById(R.id.toolbar)
        progressBar = root.findViewById(R.id.progressBar)
        tvEmpty = root.findViewById(R.id.tvEmpty)
        swipeRefresh = root.findViewById(R.id.swipeRefresh)
        containerBatches = root.findViewById(R.id.containerBatches)

        toolbar.setNavigationOnClickListener {
            requireActivity().onBackPressedDispatcher.onBackPressed()
        }

        swipeRefresh.setOnRefreshListener {
            loadBatches()
        }
        swipeRefresh.setColorSchemeResources(R.color.primary)

        loadBatches()

        return root
    }

    private fun loadBatches() {
        progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val resp = api.getBatchList(mapOf("api_key" to prefs.getApiKey()))
                if (resp.isSuccessful && resp.body()?.get("status") == "success") {
                    val list = resp.body()?.get("batches") as? List<Map<String, Any>>
                    batches.clear()
                    if (list != null) {
                        batches.addAll(list)
                    }
                    renderBatches()
                } else {
                    val desc = resp.body()?.get("desc") as? String ?: "Failed to load batch list"
                    Toast.makeText(requireContext(), desc, Toast.LENGTH_SHORT).show()
                }
            } catch (e: Exception) {
                Toast.makeText(requireContext(), "Error: ${e.message}", Toast.LENGTH_SHORT).show()
            } finally {
                progressBar.visibility = View.GONE
                swipeRefresh.isRefreshing = false
            }
        }
    }

    private fun renderBatches() {
        containerBatches.removeAllViews()
        if (batches.isEmpty()) {
            tvEmpty.visibility = View.VISIBLE
        } else {
            tvEmpty.visibility = View.GONE
            for (batch in batches) {
                val row = layoutInflater.inflate(R.layout.item_batch_row, containerBatches, false)
                val batchId = batch["batch_number"]?.toString() ?: ""
                val product = batch["product_name"]?.toString() ?: "Batch Product"
                val date = batch["date"]?.toString() ?: ""

                row.findViewById<TextView>(R.id.tv_batch_title).text = product
                row.findViewById<TextView>(R.id.tv_batch_date).text = date
                row.findViewById<TextView>(R.id.tv_batch_id).text = "#$batchId"

                containerBatches.addView(row)
            }
        }
    }
}
