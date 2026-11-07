package com.jikabiz.app.ui.rewards

import android.os.Bundle
import android.view.View
import android.widget.TextView
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.jikabiz.app.R
import com.jikabiz.app.api.RetrofitClient
import com.jikabiz.app.databinding.FragmentCoinsBinding
import com.jikabiz.app.util.PreferenceManager
import com.jikabiz.app.util.endpointError
import kotlinx.coroutines.launch
import java.util.Locale

/**
 * Convert VTU Coins to wallet cash.
 *
 * Mirrors web/CoinConversion.php, and deliberately keeps the same rules rather than a friendlier
 * version of them: a conversion is a REQUEST that an admin approves, so the message after
 * submitting says "pending approval" instead of implying the money has arrived. The rate, minimum
 * and balance all come from the server so a vendor's own settings are what the user sees.
 */
class CoinsFragment : Fragment(R.layout.fragment_coins) {

    private var _binding: FragmentCoinsBinding? = null
    private val binding get() = _binding!!
    private lateinit var prefs: PreferenceManager

    private var rate = 0.0
    private var minPoints = 0
    private var balance = 0

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentCoinsBinding.bind(view)
        prefs = PreferenceManager(requireContext())

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.etPoints.addTextChangedListener(
            object : android.text.TextWatcher {
                override fun beforeTextChanged(s: CharSequence?, a: Int, b: Int, c: Int) {}
                override fun onTextChanged(s: CharSequence?, a: Int, b: Int, c: Int) {}
                override fun afterTextChanged(s: android.text.Editable?) = refreshEstimate()
            }
        )
        binding.btnSubmit.setOnClickListener { submit() }

        load()
    }

    private fun load() {
        binding.progressBar.visibility = View.VISIBLE
        binding.tvError.visibility = View.GONE
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService()
                    .coinConversion(mapOf("api_key" to prefs.getApiKey()))
                val problem = endpointError(resp.isSuccessful, resp.code(), resp.body())
                if (problem != null) {
                    activity?.runOnUiThread {
                        binding.progressBar.visibility = View.GONE
                        showError(problem)
                    }
                    return@launch
                }
                val data = asMap(resp.body()?.get("data"))
                rate = num(data["conversion_rate"], 0.0)
                minPoints = num(data["min_points_conversion"], 0.0).toInt()
                balance = num(data["points_balance"], 0.0).toInt()
                val history = data["history"] as? List<Map<String, Any>> ?: emptyList()
                activity?.runOnUiThread {
                    binding.progressBar.visibility = View.GONE
                    renderHeader()
                    renderHistory(history)
                    refreshEstimate()
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    binding.progressBar.visibility = View.GONE
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

    private fun renderHeader() {
        binding.tvCoinsBalance.text = String.format(Locale.US, "%,d Coins", balance)
        // Rate is "N coins = ₦1", exactly as the web page words it.
        binding.tvRate.text = if (rate > 0) "${rate.toInt()} Coins = ₦1.00" else "—"
        binding.tvMin.text = String.format(Locale.US, "%,d Coins", minPoints)
    }

    /** Recompute the preview and the button state — the web page does the same in JS. */
    private fun refreshEstimate() {
        val points = binding.etPoints.text?.toString()?.trim()?.toIntOrNull() ?: 0
        val naira = if (rate > 0) points / rate else 0.0
        binding.tvNairaValue.text = "You will receive: " + naira(naira)

        val hint = when {
            points <= 0 -> null
            points < minPoints -> "Minimum conversion is ${String.format(Locale.US, "%,d", minPoints)} Coins"
            points > balance -> "You only have ${String.format(Locale.US, "%,d", balance)} Coins"
            else -> null
        }
        binding.tvHint.visibility = if (hint == null) View.GONE else View.VISIBLE
        binding.tvHint.text = hint ?: ""
        binding.btnSubmit.isEnabled = hint == null && points > 0
    }

    private fun submit() {
        val points = binding.etPoints.text?.toString()?.trim()?.toIntOrNull() ?: 0
        if (points <= 0) return
        binding.btnSubmit.isEnabled = false
        binding.progressBar.visibility = View.VISIBLE

        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().coinConversion(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "submit", "points" to points)
                )
                val body = resp.body()
                val ok = (body?.get("status") as? String) == "success"
                val msg = body?.get("message") as? String
                    ?: if (ok) "Request submitted" else "Conversion failed"
                activity?.runOnUiThread {
                    binding.progressBar.visibility = View.GONE
                    Toast.makeText(requireContext(), msg, Toast.LENGTH_LONG).show()
                    if (ok) {
                        binding.etPoints.setText("")
                        load()   // refresh balance + history from the server, not from assumption
                    } else {
                        binding.btnSubmit.isEnabled = true
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    binding.progressBar.visibility = View.GONE
                    binding.btnSubmit.isEnabled = true
                    Toast.makeText(requireContext(), "Network error. Please try again.", Toast.LENGTH_SHORT).show()
                }
            }
        }
    }

    private fun renderHistory(history: List<Map<String, Any>>) {
        binding.containerHistory.removeAllViews()
        binding.tvEmpty.visibility = if (history.isEmpty()) View.VISIBLE else View.GONE
        history.forEach { row ->
            val item = layoutInflater.inflate(R.layout.item_conversion_row, binding.containerHistory, false)
            // request_date is shown because completion_date is null until an admin decides;
            // falling back keeps older rows legible either way.
            val date = (row["request_date"] as? String) ?: (row["completion_date"] as? String) ?: ""
            item.findViewById<TextView>(R.id.tv_conv_date).text = prettyDate(date)
            item.findViewById<TextView>(R.id.tv_conv_points).text =
                String.format(Locale.US, "%,d Coins", num(row["points"], 0.0).toInt())
            item.findViewById<TextView>(R.id.tv_conv_amount).text = naira(num(row["amount"], 0.0))
            val status = (row["status"] as? String) ?: "pending"
            item.findViewById<TextView>(R.id.tv_conv_status).apply {
                text = status.replaceFirstChar { it.uppercase() }
                setTextColor(statusColor(status))
            }
            binding.containerHistory.addView(item)
        }
    }

    private fun statusColor(status: String) = when (status.lowercase()) {
        "approved" -> android.graphics.Color.parseColor("#2E7D32")
        "declined", "rejected" -> android.graphics.Color.parseColor("#C62828")
        else -> android.graphics.Color.parseColor("#F9A825")
    }

    private fun prettyDate(raw: String): String =
        raw.replace("T", " ").take(16).ifBlank { "—" }

    private fun naira(v: Double) = String.format(Locale.US, "₦%,.2f", v)

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
