package com.mzeevtu.ui.dashboard

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ImageButton
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.util.PreferenceManager
import com.mzeevtu.util.toNaira
import kotlinx.coroutines.launch

class AIBudgetingFragment : Fragment() {

    private lateinit var btnBack: ImageButton
    private lateinit var progressBar: ProgressBar
    private lateinit var tvTotalSpent: TextView
    private lateinit var tvTxCount: TextView
    private lateinit var tvPotentialSavings: TextView
    private lateinit var tvBurnRate: TextView
    private lateinit var lineChartView: SimpleLineChartView

    private val api = RetrofitClient.getService()
    private lateinit var prefs: PreferenceManager

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View? {
        val root = inflater.inflate(R.layout.fragment_ai_budgeting, container, false)
        prefs = PreferenceManager(requireContext())

        btnBack = root.findViewById(R.id.btn_back)
        progressBar = root.findViewById(R.id.progressBar)
        tvTotalSpent = root.findViewById(R.id.tvTotalSpent)
        tvTxCount = root.findViewById(R.id.tvTxCount)
        tvPotentialSavings = root.findViewById(R.id.tvPotentialSavings)
        tvBurnRate = root.findViewById(R.id.tvBurnRate)
        lineChartView = root.findViewById(R.id.lineChartView)

        btnBack.setOnClickListener {
            requireActivity().onBackPressedDispatcher.onBackPressed()
        }

        loadBudgetStats()

        return root
    }

    private fun loadBudgetStats() {
        progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val resp = api.getBudgetStats(mapOf("api_key" to prefs.getApiKey()))
                if (resp.isSuccessful && resp.body()?.get("status") == "success") {
                    val body = resp.body()!!
                    
                    val totalSpent = (body["total_spent"] as? Number)?.toDouble() ?: 0.0
                    val txCount = (body["trans_count"] as? Number)?.toInt() ?: 0
                    val savings = (body["potential_savings"] as? Number)?.toDouble() ?: 0.0
                    val burnDays = (body["burn_rate_days"] as? Number)?.toInt() ?: 12
                    
                    @Suppress("UNCHECKED_CAST")
                    val rawForecast = body["forecast"] as? List<Number>
                    val forecastList = rawForecast?.map { it.toFloat() } ?: listOf(50f, 80f, 40f, 65f)

                    tvTotalSpent.text = totalSpent.toNaira()
                    tvTxCount.text = "$txCount successful transactions analyzed."
                    tvPotentialSavings.text = "${savings.toNaira()} / month"
                    tvBurnRate.text = "$burnDays Days Remaining"
                    
                    lineChartView.setData(forecastList)
                } else {
                    val desc = resp.body()?.get("desc") as? String ?: "Failed to load budget insights"
                    Toast.makeText(requireContext(), desc, Toast.LENGTH_SHORT).show()
                }
            } catch (e: Exception) {
                Toast.makeText(requireContext(), "Error: ${e.message}", Toast.LENGTH_SHORT).show()
            } finally {
                progressBar.visibility = View.GONE
            }
        }
    }
}
