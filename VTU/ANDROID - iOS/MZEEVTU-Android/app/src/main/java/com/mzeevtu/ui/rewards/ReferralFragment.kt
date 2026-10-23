package com.mzeevtu.ui.rewards

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.TextView
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.FragmentReferralBinding
import com.mzeevtu.util.PreferenceManager
import com.mzeevtu.util.endpointError
import kotlinx.coroutines.launch
import java.util.Locale

/**
 * "Refer and Earn".
 *
 * The code and link are produced by the server and are the same values the website shows, so a
 * link shared from the app credits the same account as one shared from the dashboard.
 *
 * Sign-ups and actual rewards are shown separately. A referral only pays out once the referred
 * person completes their first transaction, so lumping "pending" in with "earned" would promise
 * users coins they have not been given yet.
 */
class ReferralFragment : Fragment(R.layout.fragment_referral) {

    private var _binding: FragmentReferralBinding? = null
    private val binding get() = _binding!!
    private lateinit var prefs: PreferenceManager

    // Held as fields so the Copy/Share handlers can be attached once, up front, and still read the
    // latest values (see onViewCreated).
    private var referralCode = ""
    private var referralLink = ""
    private var shareMessage = ""
    private var loadProblem: String? = null

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentReferralBinding.bind(view)
        prefs = PreferenceManager(requireContext())

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnOpenCoins.setOnClickListener {
            findNavController().navigate(R.id.nav_coins)
        }

        // Wired here rather than in render(), so the buttons always respond. Previously a failed
        // request meant render() never ran, the listeners were never attached, and a tap did
        // nothing at all - indistinguishable from a broken button, and it hid the real reason (404).
        binding.btnCopyCode.setOnClickListener {
            if (referralCode.isBlank()) explain("Your referral code is not available yet.")
            else copy("Referral code", referralCode)
        }
        binding.btnShareLink.setOnClickListener {
            if (referralLink.isBlank()) {
                explain("Your referral link is not available yet.")
            } else {
                share(
                    listOf(shareMessage, referralLink)
                        .filter { it.isNotBlank() }
                        .joinToString("\n")
                )
            }
        }

        load()
    }

    private fun load() {
        binding.progressBar.visibility = View.VISIBLE
        binding.tvError.visibility = View.GONE
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService()
                    .getReferral(mapOf("api_key" to prefs.getApiKey()))
                val problem = endpointError(resp.isSuccessful, resp.code(), resp.body())
                if (problem != null) {
                    activity?.runOnUiThread {
                        binding.progressBar.visibility = View.GONE
                        loadProblem = problem
                        showError(problem)
                    }
                    return@launch
                }
                val data = asMap(resp.body()?.get("data"))
                activity?.runOnUiThread {
                    binding.progressBar.visibility = View.GONE
                    render(data)
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    binding.progressBar.visibility = View.GONE
                    loadProblem = "Could not reach the server."
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

    private fun render(d: Map<String, Any>) {
        loadProblem = null
        referralCode = (d["referral_code"] as? String).orEmpty()
        referralLink = (d["referral_link"] as? String).orEmpty()
        shareMessage = (d["share_message"] as? String).orEmpty()

        binding.tvReferralCode.text = referralCode.ifBlank { "—" }
        binding.tvReferralLink.text = referralLink.ifBlank { "—" }
        binding.tvBonus.text = String.format(Locale.US, "%,d Coins", num(d["referral_bonus"], 0.0).toInt())
        binding.tvTotalReferrals.text = num(d["total_referrals"], 0.0).toInt().toString()
        binding.tvQualifiedReferrals.text = num(d["qualified_referrals"], 0.0).toInt().toString()
        binding.tvPendingReferrals.text = num(d["pending_referrals"], 0.0).toInt().toString()
        binding.tvCoinsFromReferrals.text = String.format(Locale.US, "%,d", num(d["coins_from_referrals"], 0.0).toInt())
        binding.tvCoinsBalance.text = String.format(Locale.US, "%,d", num(d["coins_balance"], 0.0).toInt())

        // Conversion is a separate service (isServiceEnabled('vtu_coins')); when a vendor has it
        // off, offering the button would lead to a dead screen.
        val coinsEnabled = d["coins_enabled"] as? Boolean ?: true
        binding.cardCoins.visibility = if (coinsEnabled) View.VISIBLE else View.GONE

        val users = d["referred_users"] as? List<Map<String, Any>> ?: emptyList()
        binding.tvReferredEmpty.visibility = if (users.isEmpty()) View.VISIBLE else View.GONE
        binding.containerReferred.removeAllViews()
        users.forEach { u ->
            // Reusing the ledger row shape: label = who, date = when they joined,
            // amount = whether the referral has actually paid out yet.
            val row = layoutInflater.inflate(R.layout.item_points_row, binding.containerReferred, false)
            row.findViewById<TextView>(R.id.tv_point_label).text =
                (u["name"] as? String)?.ifBlank { null } ?: (u["username"] as? String).orEmpty()
            row.findViewById<TextView>(R.id.tv_point_date).text =
                "Joined " + prettyDate((u["joined"] as? String).orEmpty())
            val qualified = (u["qualified"] as? String) == "Yes"
            row.findViewById<TextView>(R.id.tv_point_amount).apply {
                text = if (qualified) "Earned" else "Pending"
                setTextColor(
                    android.graphics.Color.parseColor(if (qualified) "#2E7D32" else "#F9A825")
                )
            }
            binding.containerReferred.addView(row)
        }
    }

    /** Explains why a button did nothing, preferring the real load failure over a generic note. */
    private fun explain(fallback: String) {
        Toast.makeText(requireContext(), loadProblem ?: fallback, Toast.LENGTH_LONG).show()
    }

    private fun copy(label: String, value: String) {
        if (value.isBlank()) return
        val cm = requireContext().getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        cm.setPrimaryClip(ClipData.newPlainText(label, value))
        Toast.makeText(requireContext(), "$label copied", Toast.LENGTH_SHORT).show()
    }

    private fun share(text: String) {
        if (text.isBlank()) return
        val send = Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_TEXT, text)
        }
        startActivity(Intent.createChooser(send, "Share referral link"))
    }

    private fun prettyDate(raw: String): String = raw.replace("T", " ").take(10).ifBlank { "—" }

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
