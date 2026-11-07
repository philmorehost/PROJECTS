package com.datagifting.app.ui.rewards

import android.graphics.Color
import android.os.Bundle
import android.view.View
import android.widget.TextView
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.databinding.FragmentPointsHistoryBinding
import com.datagifting.app.util.PreferenceManager
import com.datagifting.app.util.endpointError
import kotlinx.coroutines.launch
import java.util.Locale

/**
 * VTU Coins ledger — mirrors web/PointsHistory.php.
 *
 * The daily purchase-streak bonus is collapsed server-side to one row per date, so this screen
 * does not have to de-duplicate anything itself.
 */
class PointsHistoryFragment : Fragment(R.layout.fragment_points_history) {

    private var _binding: FragmentPointsHistoryBinding? = null
    private val binding get() = _binding!!
    private lateinit var prefs: PreferenceManager

    private val entries = mutableListOf<Map<String, Any>>()
    private var offset = 0
    private val pageSize = 100

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentPointsHistoryBinding.bind(view)
        prefs = PreferenceManager(requireContext())

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnLoadMore.setOnClickListener { load(reset = false) }
        binding.swipeRefresh.setOnRefreshListener { load(reset = true) }
        binding.swipeRefresh.setColorSchemeResources(R.color.primary)

        load(reset = true)
    }

    private fun load(reset: Boolean) {
        if (reset) { offset = 0; entries.clear() }
        binding.progressBar.visibility = View.VISIBLE
        binding.tvError.visibility = View.GONE

        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().getPointsHistory(
                    mapOf("api_key" to prefs.getApiKey(), "limit" to pageSize, "offset" to offset)
                )
                val problem = endpointError(resp.isSuccessful, resp.code(), resp.body())
                if (problem != null) {
                    activity?.runOnUiThread {
                        binding.progressBar.visibility = View.GONE
                        binding.swipeRefresh.isRefreshing = false
                        showError(problem)
                    }
                    return@launch
                }
                val data = asMap(resp.body()?.get("data"))
                val page = data["entries"] as? List<Map<String, Any>> ?: emptyList()
                entries.addAll(page)
                offset += page.size

                activity?.runOnUiThread {
                    binding.progressBar.visibility = View.GONE
                    binding.swipeRefresh.isRefreshing = false
                    binding.tvPointsBalance.text =
                        String.format(Locale.US, "%,d", num(data["points_balance"], 0.0).toInt())
                    val streak = num(data["streak_day"], 0.0).toInt()
                    binding.tvStreak.text = if (streak > 0) "🔥 $streak Day Streak" else "No active streak"
                    val next = data["next_bonus"] as? String
                    val eligible = (data["is_eligible"] as? String) == "Yes"
                    if (!eligible && !next.isNullOrBlank()) {
                        binding.tvNextBonus.visibility = View.VISIBLE
                        binding.tvNextBonus.text = "Next bonus $next"
                    } else {
                        binding.tvNextBonus.visibility = View.GONE
                    }
                    // Only offer "Load More" when a full page came back, so the button cannot sit
                    // there doing nothing on the last page.
                    binding.btnLoadMore.visibility =
                        if (page.size >= pageSize) View.VISIBLE else View.GONE
                    render()
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    binding.progressBar.visibility = View.GONE
                    binding.swipeRefresh.isRefreshing = false
                    showError("Could not reach the server. Check your connection and try again.")
                }
            }
        }
    }

    /**
     * Failures are shown explicitly. Rendering blank fields on a failed request looked like a broken
     * screen when the real cause was an endpoint that had not been deployed.
     */
    private fun showError(message: String) {
        binding.tvError.text = message
        binding.tvError.visibility = View.VISIBLE
    }

    private fun render() {
        binding.containerPoints.removeAllViews()
        binding.tvEmpty.visibility = if (entries.isEmpty()) View.VISIBLE else View.GONE
        entries.forEach { e ->
            val row = layoutInflater.inflate(R.layout.item_points_row, binding.containerPoints, false)
            row.findViewById<TextView>(R.id.tv_point_label).text =
                (e["label"] as? String) ?: (e["log_type"] as? String).orEmpty()
            row.findViewById<TextView>(R.id.tv_point_date).text =
                ((e["date"] as? String) ?: "").replace("T", " ").take(16).ifBlank { "—" }

            val points = num(e["points"], 0.0).toInt()
            row.findViewById<TextView>(R.id.tv_point_amount).apply {
                text = if (points > 0) String.format(Locale.US, "+%,d", points)
                       else String.format(Locale.US, "%,d", points)
                setTextColor(Color.parseColor(if (points >= 0) "#2E7D32" else "#C62828"))
            }
            binding.containerPoints.addView(row)
        }
    }

    @Suppress("UNCHECKED_CAST")
    private fun asMap(any: Any?): Map<String, Any> = any as? Map<String, Any> ?: emptyMap()

    private fun num(any: Any?, fallback: Double): Double = when (any) {
        is Number -> any.toDouble()
        is String -> any.toDoubleOrNull() ?: fallback
        else -> fallback
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
