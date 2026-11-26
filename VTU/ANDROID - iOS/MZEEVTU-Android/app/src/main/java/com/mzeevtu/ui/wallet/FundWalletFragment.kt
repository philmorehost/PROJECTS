package com.mzeevtu.ui.wallet

import android.annotation.SuppressLint
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.webkit.JavascriptInterface
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.lifecycleScope
import androidx.viewpager2.adapter.FragmentStateAdapter
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.FragmentFundWalletBinding
import com.mzeevtu.util.Constants
import com.mzeevtu.util.PreferenceManager
import com.google.android.material.snackbar.Snackbar
import com.google.android.material.tabs.TabLayoutMediator
import kotlinx.coroutines.launch

class FundWalletFragment : Fragment(R.layout.fragment_fund_wallet) {

    private var _binding: FragmentFundWalletBinding? = null
    private val binding get() = _binding!!

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentFundWalletBinding.bind(view)
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }

        val adapter = FundPagerAdapter(requireActivity())
        binding.viewPager.adapter = adapter

        TabLayoutMediator(binding.tabLayout, binding.viewPager) { tab, pos ->
            tab.text = when (pos) {
                0 -> "Virtual Banks"
                1 -> "Pay Online"
                else -> "Manual"
            }
        }.attach()
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}

// ── Tab Adapter ────────────────────────────────────────────────────────────────

class FundPagerAdapter(fa: FragmentActivity) : FragmentStateAdapter(fa) {
    override fun getItemCount() = 3
    override fun createFragment(position: Int): Fragment = when (position) {
        0 -> VirtualBanksTabFragment()
        1 -> CheckoutTabFragment()
        else -> ManualDepositTabFragment()
    }
}

// ── Tab 1: Virtual Banks ──────────────────────────────────────────────────────

class VirtualBanksTabFragment : Fragment() {

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View =
        inflater.inflate(R.layout.tab_virtual_banks, container, false)

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        val btnResync = view.findViewById<com.google.android.material.button.MaterialButton>(R.id.btn_resync)
        btnResync.setOnClickListener { loadBanks(view, forceResync = true) }
        loadBanks(view, forceResync = false)
    }

    private fun loadBanks(view: View, forceResync: Boolean) {
        val progress = view.findViewById<android.widget.ProgressBar>(R.id.progress_bar)
        val container = view.findViewById<android.widget.LinearLayout>(R.id.container_banks)
        val tvEmpty = view.findViewById<android.widget.TextView>(R.id.tv_empty)

        progress.visibility = View.VISIBLE
        container.removeAllViews()
        tvEmpty.visibility = View.GONE

        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val body = mutableMapOf<String, Any>("api_key" to prefs.getApiKey())
                if (forceResync) body["resync"] = "1"
                val resp = api.getVirtualBanks(body)
                @Suppress("UNCHECKED_CAST")
                val banks = resp.body()?.get("data") as? List<Map<String, Any>>
                val serverMsg = resp.body()?.get("message") as? String
                activity?.runOnUiThread {
                    progress.visibility = View.GONE
                    if (banks.isNullOrEmpty()) {
                        tvEmpty.visibility = View.VISIBLE
                        if (!serverMsg.isNullOrBlank()) {
                            tvEmpty.text = serverMsg
                        }
                    } else {
                        banks.forEach { bank ->
                            val card = layoutInflater.inflate(R.layout.item_bank_card, container, false)
                            card.findViewById<android.widget.TextView>(R.id.tv_item_bank_name).text =
                                bank["bank_name"] as? String ?: "Virtual Account"
                            card.findViewById<android.widget.TextView>(R.id.tv_item_account_name).text =
                                bank["account_name"] as? String ?: "---"
                            card.findViewById<android.widget.TextView>(R.id.tv_item_account_number).text =
                                bank["account_number"] as? String ?: "---"
                            container.addView(card)
                        }
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    progress.visibility = View.GONE
                    tvEmpty.visibility = View.VISIBLE
                    tvEmpty.text = "Failed to load accounts: ${e.message}"
                }
            }
        }
    }
}

// ── Tab 2: Inline Checkout ────────────────────────────────────────────────────

class CheckoutTabFragment : Fragment() {

    private lateinit var webView: WebView
    private lateinit var scrollForm: View
    private lateinit var btnCloseWebView: android.widget.Button
    private lateinit var progressGateways: android.widget.ProgressBar
    private lateinit var containerGateways: android.widget.LinearLayout
    private lateinit var tvNoGateways: android.widget.TextView
    private lateinit var etAmount: com.google.android.material.textfield.TextInputEditText
    private var feePreviewCard: android.widget.LinearLayout? = null
    private var tvBreakdownTitle: android.widget.TextView? = null
    private var tvBreakdownCredit: android.widget.TextView? = null
    private var tvBreakdownGatewayFee: android.widget.TextView? = null
    private var tvBreakdownMarkup: android.widget.TextView? = null
    private var tvBreakdownTotalPay: android.widget.TextView? = null
    private val gatewayButtons = mutableListOf<Pair<Map<String, Any>, com.google.android.material.button.MaterialButton>>()

    private var fundingConfig: Map<String, Any>? = null
    private var activeGateway = ""
    private var activeReference = ""

    private data class FeeBreakdown(
        val walletCredit: Double,
        val gatewayFee: Double,
        val checkoutSurcharge: Double,
        val vendorMarkup: Double,
        val requestAmount: Double,
        val userPayAmount: Double,
        val markupPercent: Double,
        val gatewayFeeLabel: String
    )

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View =
        inflater.inflate(R.layout.tab_checkout, container, false)

    @SuppressLint("SetJavaScriptEnabled")
    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        scrollForm = view.findViewById(R.id.scroll_form)
        webView = view.findViewById(R.id.webview_checkout)
        btnCloseWebView = view.findViewById(R.id.btn_close_webview)
        progressGateways = view.findViewById(R.id.progress_gateways)
        containerGateways = view.findViewById(R.id.container_gateways)
        tvNoGateways = view.findViewById(R.id.tv_no_gateways)
        etAmount = view.findViewById(R.id.et_checkout_amount)

        webView.settings.javaScriptEnabled = true
        webView.settings.domStorageEnabled = true
        webView.webChromeClient = WebChromeClient()
        webView.addJavascriptInterface(CheckoutBridge(), "AndroidBridge")
        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val url = request.url.toString()
                if (url.contains("payhub-success.php")) {
                    return false
                }
                if (url.contains("Dashboard") || url.contains("Fund")) {
                    val ref = activeReference
                    if (ref.isNotEmpty() && activeGateway.isNotEmpty()) {
                        CheckoutBridge().onSuccess(ref)
                    } else {
                        showForm()
                        snack("Payment completed — wallet will be updated shortly.")
                    }
                    return true
                }
                return false
            }
        }

        btnCloseWebView.setOnClickListener { showForm() }
        attachFeePreviewCard()
        etAmount.addTextChangedListener(object : android.text.TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: android.text.Editable?) {
                refreshFeePreview()
            }
        })
        loadGateways()
    }

    private fun attachFeePreviewCard() {
        val innerLayout = (scrollForm as? android.widget.ScrollView)?.getChildAt(0) as? android.widget.LinearLayout ?: return
        val ctx = requireContext()
        val card = android.widget.LinearLayout(ctx).apply {
            orientation = android.widget.LinearLayout.VERTICAL
            setPadding(16.dpToPx(), 12.dpToPx(), 16.dpToPx(), 12.dpToPx())
            background = android.graphics.drawable.GradientDrawable().apply {
                setColor(android.graphics.Color.parseColor("#F8FAFC"))
                setStroke(1.dpToPx(), android.graphics.Color.parseColor("#CBD5E1"))
                cornerRadius = 10.dpToPx().toFloat()
            }
            layoutParams = android.widget.LinearLayout.LayoutParams(
                android.widget.LinearLayout.LayoutParams.MATCH_PARENT,
                android.widget.LinearLayout.LayoutParams.WRAP_CONTENT
            ).also { it.bottomMargin = 16.dpToPx() }
            visibility = View.GONE
        }

        fun makeRow(label: String, bold: Boolean = false): Pair<android.widget.TextView, android.widget.TextView> {
            val row = android.widget.LinearLayout(ctx).apply {
                orientation = android.widget.LinearLayout.HORIZONTAL
                layoutParams = android.widget.LinearLayout.LayoutParams(
                    android.widget.LinearLayout.LayoutParams.MATCH_PARENT,
                    android.widget.LinearLayout.LayoutParams.WRAP_CONTENT
                ).also { it.bottomMargin = 4.dpToPx() }
            }
            val tvL = android.widget.TextView(ctx).apply {
                text = label
                textSize = 13f
                setTextColor(android.graphics.Color.parseColor(if (bold) "#0F172A" else "#475569"))
                if (bold) setTypeface(null, android.graphics.Typeface.BOLD)
                layoutParams = android.widget.LinearLayout.LayoutParams(0, android.widget.LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
            }
            val tvV = android.widget.TextView(ctx).apply {
                text = "₦0.00"
                textSize = 13f
                setTextColor(android.graphics.Color.parseColor(if (bold) "#0F172A" else "#1E293B"))
                if (bold) setTypeface(null, android.graphics.Typeface.BOLD)
            }
            row.addView(tvL)
            row.addView(tvV)
            card.addView(row)
            return tvL to tvV
        }

        val title = android.widget.TextView(ctx).apply {
            text = "Deposit Fee Breakdown"
            textSize = 13f
            setTypeface(null, android.graphics.Typeface.BOLD)
            setTextColor(android.graphics.Color.parseColor("#0F172A"))
            layoutParams = android.widget.LinearLayout.LayoutParams(
                android.widget.LinearLayout.LayoutParams.MATCH_PARENT,
                android.widget.LinearLayout.LayoutParams.WRAP_CONTENT
            ).also { it.bottomMargin = 8.dpToPx() }
        }
        card.addView(title)
        tvBreakdownTitle = title
        tvBreakdownCredit = makeRow("Wallet Credited").second
        tvBreakdownGatewayFee = makeRow("Gateway Fee (Auto)").second
        tvBreakdownMarkup = makeRow("Service Markup").second
        tvBreakdownTotalPay = makeRow("Total You Pay", bold = true).second

        val idx = innerLayout.indexOfChild(containerGateways)
        if (idx >= 0) {
            innerLayout.addView(card, idx)
        } else {
            innerLayout.addView(card)
        }
        feePreviewCard = card
    }

    private fun round2(value: Double): Double = kotlin.math.round(value * 100.0) / 100.0

    private fun formatNaira(value: Double): String = "₦" + String.format(java.util.Locale.US, "%,.2f", value)

    private fun computeGatewayFee(rule: Map<*, *>?, gross: Double): Double {
        val g = maxOf(0.0, round2(gross))
        if (g <= 0.0 || rule == null) return 0.0
        val pct = maxOf(0.0, (rule["percent"] as? Number)?.toDouble() ?: 0.0)
        val flat = maxOf(0.0, (rule["flat"] as? Number)?.toDouble() ?: 0.0)
        val waive = maxOf(0.0, (rule["waive_flat_below"] as? Number)?.toDouble() ?: 0.0)
        val cap = maxOf(0.0, (rule["cap"] as? Number)?.toDouble() ?: 0.0)
        val applyFlat = if (flat > 0.0 && (waive <= 0.0 || g >= waive)) flat else 0.0
        var fee = (g * (pct / 100.0)) + applyFlat
        if (cap > 0.0) fee = minOf(cap, fee)
        return maxOf(0.0, round2(fee))
    }

    private fun solveGrossForNet(rule: Map<*, *>?, netNeeded: Double): Double {
        val net = maxOf(0.0, round2(netNeeded))
        if (net <= 0.0 || rule == null) return net
        val rate = maxOf(0.0, (rule["percent"] as? Number)?.toDouble() ?: 0.0) / 100.0
        val flat = maxOf(0.0, (rule["flat"] as? Number)?.toDouble() ?: 0.0)
        val waive = maxOf(0.0, (rule["waive_flat_below"] as? Number)?.toDouble() ?: 0.0)
        val cap = maxOf(0.0, (rule["cap"] as? Number)?.toDouble() ?: 0.0)
        if (rate >= 0.99) return net
        val candidates = mutableListOf<Double>()
        if (rate > 0.0) {
            val c1 = round2(net / (1.0 - rate))
            if (waive <= 0.0 || c1 < waive) candidates.add(c1)
        } else {
            candidates.add(net)
        }
        candidates.add(round2((net + flat) / (1.0 - rate)))
        if (cap > 0.0) candidates.add(round2(net + cap))
        var best: Double? = null
        for (cand in candidates) {
            if (cand < net) continue
            for (k in 0..3) {
                val t = round2(cand + k * 0.01)
                val f = computeGatewayFee(rule, t)
                if (round2(t - f) >= net) {
                    if (best == null || t < best) best = t
                    break
                }
            }
        }
        return best ?: net
    }

    private fun computeBreakdown(gw: Map<String, Any>, enteredAmount: Double): FeeBreakdown {
        val rawMarkup = (gw["markup_percent"] as? Number)?.toDouble()
            ?: (gw["percentage"]?.toString()?.toDoubleOrNull())
            ?: 0.5
        val markup = round2(rawMarkup.coerceIn(0.0, 1.0))
        val rule = gw["gateway_fee_rule"] as? Map<*, *>
        val credit = round2(enteredAmount)
        val vendorMarkup = round2(credit * (markup / 100.0))
        val netNeeded = round2(credit + vendorMarkup)
        val requestAmount = if (rule != null) solveGrossForNet(rule, netNeeded) else netNeeded
        val gwFee = maxOf(0.0, round2(requestAmount - netNeeded))
        val surchargePct = maxOf(0.0, (rule?.get("checkout_surcharge_percent") as? Number)?.toDouble() ?: 0.0)
        val surcharge = if (surchargePct > 0.0) round2(requestAmount * (surchargePct / 100.0)) else 0.0
        val userPay = round2(requestAmount + surcharge)
        val gwLabel = (gw["gateway_fee_label"] as? String).orEmpty()
        return FeeBreakdown(
            walletCredit = credit,
            gatewayFee = gwFee,
            checkoutSurcharge = surcharge,
            vendorMarkup = vendorMarkup,
            requestAmount = requestAmount,
            userPayAmount = userPay,
            markupPercent = markup,
            gatewayFeeLabel = gwLabel
        )
    }

    private fun refreshFeePreview() {
        val amount = etAmount.text?.toString()?.trim()?.toDoubleOrNull()
        val firstGw = gatewayButtons.firstOrNull()?.first
        if (amount == null || amount < 100.0 || firstGw == null) {
            feePreviewCard?.visibility = View.GONE
            gatewayButtons.forEach { (gw, btn) ->
                val name = (gw["gateway_name"] as? String ?: "").replaceFirstChar { it.uppercase() }
                btn.text = "Pay with $name"
            }
            return
        }

        val previewGw = gatewayButtons.find { (it.first["gateway_name"] as? String) == activeGateway }?.first ?: firstGw
        val bd = computeBreakdown(previewGw, amount)
        val previewName = (previewGw["gateway_name"] as? String ?: "").replaceFirstChar { it.uppercase() }
        tvBreakdownTitle?.text = "$previewName Deposit Preview"
        tvBreakdownCredit?.text = formatNaira(bd.walletCredit)
        tvBreakdownGatewayFee?.text = formatNaira(round2(bd.gatewayFee + bd.checkoutSurcharge))
        tvBreakdownMarkup?.text = "${formatNaira(bd.vendorMarkup)} (${String.format(java.util.Locale.US, "%.2f", bd.markupPercent)}%)"
        tvBreakdownTotalPay?.text = formatNaira(bd.userPayAmount)
        feePreviewCard?.visibility = View.VISIBLE

        gatewayButtons.forEach { (gw, btn) ->
            val name = (gw["gateway_name"] as? String ?: "").replaceFirstChar { it.uppercase() }
            val gwBd = computeBreakdown(gw, amount)
            btn.text = "Pay with $name — ${formatNaira(gwBd.userPayAmount)} (Credit ${formatNaira(gwBd.walletCredit)})"
        }
    }

    private fun loadGateways() {
        progressGateways.visibility = View.VISIBLE
        containerGateways.removeAllViews()
        gatewayButtons.clear()
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.getFundingConfig(mapOf("api_key" to prefs.getApiKey()))
                @Suppress("UNCHECKED_CAST")
                val config = resp.body()
                fundingConfig = config
                @Suppress("UNCHECKED_CAST")
                val gateways = config?.get("gateways") as? List<Map<String, Any>>
                activity?.runOnUiThread {
                    progressGateways.visibility = View.GONE
                    if (gateways.isNullOrEmpty()) {
                        tvNoGateways.visibility = View.VISIBLE
                    } else {
                        gateways.forEach { gw ->
                            val name = gw["gateway_name"] as? String ?: return@forEach
                            addGatewayButton(gw, name)
                        }
                        refreshFeePreview()
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    progressGateways.visibility = View.GONE
                    tvNoGateways.visibility = View.VISIBLE
                }
            }
        }
    }

    private fun addGatewayButton(gw: Map<String, Any>, name: String) {
        val label = name.replaceFirstChar { it.uppercase() }
        val btn = com.google.android.material.button.MaterialButton(requireContext()).apply {
            text = "Pay with $label"
            layoutParams = android.widget.LinearLayout.LayoutParams(
                android.widget.LinearLayout.LayoutParams.MATCH_PARENT,
                android.widget.LinearLayout.LayoutParams.WRAP_CONTENT
            ).also { it.bottomMargin = 12.dpToPx() }
            setOnClickListener {
                activeGateway = name
                refreshFeePreview()
                launchGateway(name)
            }
        }
        gatewayButtons.add(gw to btn)
        containerGateways.addView(btn)
    }

    private fun launchGateway(gatewayName: String) {
        val amountStr = etAmount.text?.toString()?.trim() ?: ""
        val amount = amountStr.toDoubleOrNull()
        if (amount == null || amount < 100.0) { snack("Enter a valid amount (minimum ₦100)"); return }

        val config = fundingConfig ?: run { snack("Gateway config not loaded"); return }
        @Suppress("UNCHECKED_CAST")
        val gateways = config["gateways"] as? List<Map<String, Any>> ?: emptyList()
        val gw = gateways.find { (it["gateway_name"] as? String) == gatewayName } ?: run {
            snack("Gateway not available"); return
        }

        val publicKey = gw["public_key"] as? String ?: ""
        val contractCode = gw["contract_code"] as? String ?: ""
        val email = config["email"] as? String ?: ""
        val userName = config["name"] as? String ?: ""
        val phone = config["phone"] as? String ?: ""
        val reference = "APP_${System.currentTimeMillis()}_${(100..999).random()}"
        val localBreakdown = computeBreakdown(gw, amount)

        // Register server-bound checkout intent before opening gateway
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val resp = RetrofitClient.getService().createCheckout(mapOf(
                    "api_key" to prefs.getApiKey(),
                    "reference" to reference,
                    "amount" to amount,
                    "gateway" to gatewayName,
                    "fee_mode" to "customer_pays"
                ))
                val body = resp.body()
                val status = body?.get("status") as? String ?: ""
                if (!resp.isSuccessful || status.equals("error", ignoreCase = true)) {
                    val errMsg = body?.get("message") as? String ?: "Unable to initialize $gatewayName checkout."
                    activity?.runOnUiThread { snack(errMsg) }
                    return@launch
                }
                val serverRef = (body?.get("payhub_reference") as? String)?.takeIf { it.isNotBlank() }
                    ?: (body?.get("reference") as? String)?.takeIf { it.isNotBlank() }
                    ?: reference
                val chargeAmount = (body?.get("amount")?.toString()?.toDoubleOrNull()) ?: localBreakdown.requestAmount
                val authUrl = body?.get("authorization_url") as? String ?: ""
                activeGateway = gatewayName
                activeReference = serverRef
                activity?.runOnUiThread {
                    if (authUrl.isNotBlank()) {
                        scrollForm.visibility = View.GONE
                        webView.visibility = View.VISIBLE
                        btnCloseWebView.visibility = View.VISIBLE
                        webView.loadUrl(authUrl)
                    } else {
                        openGatewayWebView(gatewayName, publicKey, contractCode, email, userName, phone, serverRef, chargeAmount)
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread { snack("Unable to initialize checkout: ${e.message ?: "network error"}") }
            }
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun openGatewayWebView(
        gateway: String, publicKey: String, contractCode: String,
        email: String, userName: String, phone: String, reference: String, amount: Double
    ) {
        val baseUrl = Constants.BASE_URL
        val amountKobo = kotlin.math.round(amount * 100.0).toInt()
        val html = buildCheckoutHtml(gateway, publicKey, contractCode, email, userName, phone, reference, amount, amountKobo, baseUrl)

        scrollForm.visibility = View.GONE
        webView.visibility = View.VISIBLE
        btnCloseWebView.visibility = View.VISIBLE

        webView.loadDataWithBaseURL(baseUrl, html, "text/html", "UTF-8", null)
    }

    private fun buildCheckoutHtml(
        gateway: String, publicKey: String, contractCode: String,
        email: String, userName: String, phone: String,
        reference: String, amount: Double, amountKobo: Int, baseUrl: String
    ): String {
        val sdkScript = when (gateway.lowercase()) {
            "paystack" -> """<script src="https://js.paystack.co/v1/inline.js"></script>"""
            "monnify" -> """<script src="https://sdk.monnify.com/plugin/monnify.js"></script>"""
            "flutterwave" -> """<script src="https://checkout.flutterwave.com/v3.js"></script>"""
            "beewave" -> """<script src="https://merchant.beewave.ng/checkout.min.js" defer></script>"""
            else -> ""
        }

        val initScript = when (gateway.lowercase()) {
            "paystack" -> """
                var handler = PaystackPop.setup({
                    key: '$publicKey',
                    email: '$email',
                    amount: $amountKobo,
                    currency: 'NGN',
                    ref: '$reference',
                    onClose: function() { AndroidBridge.onClose(); },
                    callback: function(response) { AndroidBridge.onSuccess('$reference'); }
                });
                handler.openIframe();
            """.trimIndent()
            "monnify" -> """
                MonnifySDK.initialize({
                    amount: $amount,
                    currency: 'NGN',
                    reference: '$reference',
                    customerFullName: '$userName',
                    customerEmail: '$email',
                    apiKey: '$publicKey',
                    contractCode: '$contractCode',
                    paymentDescription: 'Wallet Funding',
                    onComplete: function(r) { AndroidBridge.onSuccess('$reference'); },
                    onClose: function() { AndroidBridge.onClose(); }
                });
            """.trimIndent()
            "flutterwave" -> """
                FlutterwaveCheckout({
                    public_key: '$publicKey',
                    tx_ref: '$reference',
                    amount: $amount,
                    currency: 'NGN',
                    payment_options: 'card,banktransfer,ussd',
                    customer: { email: '$email', phone_number: '$phone', name: '$userName' },
                    customizations: { title: 'Wallet Funding' },
                    callback: function(p) { AndroidBridge.onSuccess('$reference'); },
                    onclose: function() { AndroidBridge.onClose(); }
                });
            """.trimIndent()
            "beewave" -> """
                BeefinanceCheckout.open({
                    accessKey: '$publicKey',
                    name: '$userName',
                    email: '$email',
                    phone: '$phone',
                    amount: $amount,
                    reference: '$reference'
                });
            """.trimIndent()
            "payhub" -> """
                // Redirect to Payhub server-side checkout page
                var checkoutUrl = '${baseUrl}web/api/payhub-checkout.php?reference=$reference&amount=$amount&email=' + encodeURIComponent('$email') + '&name=' + encodeURIComponent('$userName');
                window.location.href = checkoutUrl;
            """.trimIndent()
            else -> ""
        }

        return """
<!DOCTYPE html>
<html>
<head><meta name="viewport" content="width=device-width, initial-scale=1">
$sdkScript
</head>
<body style="background:#f6f9fc;font-family:sans-serif;padding:24px;text-align:center;">
<p style="color:#444;font-size:15px;">Initializing ${gateway.replaceFirstChar { it.uppercase() }} checkout...</p>
<script>
window.onload = function() {
    try { $initScript } catch(e) {
        document.body.innerHTML = '<p style="color:red">Failed to initialize gateway: ' + e.message + '</p>';
    }
};
</script>
</body>
</html>
        """.trimIndent()
    }

    private fun showForm() {
        scrollForm.visibility = View.VISIBLE
        webView.visibility = View.GONE
        btnCloseWebView.visibility = View.GONE
        webView.loadUrl("about:blank")
    }

    inner class CheckoutBridge {
        @JavascriptInterface
        fun onSuccess(reference: String) {
            activity?.runOnUiThread {
                showForm()
                snack("Payment received — verifying with server...")
            }
            // Server-side verification to ensure the wallet is credited
            val gateway = activeGateway
            lifecycleScope.launch {
                try {
                    val prefs = PreferenceManager(requireContext())
                    val resp = RetrofitClient.getService().verifyFunding(mapOf(
                        "api_key" to prefs.getApiKey(),
                        "reference" to reference,
                        "gateway" to gateway
                    ))
                    val status = resp.body()?.get("status") as? String ?: ""
                    val msg = resp.body()?.get("message") as? String ?: ""
                    activity?.runOnUiThread {
                        if (status == "success") {
                            snack(if (msg.isNotBlank()) "✅ $msg" else "✅ Wallet funded successfully!")
                        } else if (status == "pending") {
                            snack("Payment submitted. Wallet will be funded shortly.")
                        } else if (msg.isNotEmpty()) {
                            snack("Payment recorded: $msg")
                        }
                    }
                } catch (e: Exception) {
                    // Webhook will handle crediting if server verify fails
                    android.util.Log.w("FundWallet", "verifyFunding failed: ${e.message}")
                    activity?.runOnUiThread {
                        snack("Payment submitted. Wallet will be funded shortly via webhook.")
                    }
                }
            }
        }

        @JavascriptInterface
        fun onClose() {
            activity?.runOnUiThread { showForm() }
        }
    }

    private fun snack(msg: String) {
        view?.let { Snackbar.make(it, msg, Snackbar.LENGTH_LONG).show() }
    }

    private fun Int.dpToPx(): Int = (this * resources.displayMetrics.density).toInt()
}

// ── Tab 3: Manual Deposit ─────────────────────────────────────────────────────

class ManualDepositTabFragment : Fragment() {

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View =
        inflater.inflate(R.layout.tab_manual_deposit, container, false)

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        val containerBanks = view.findViewById<android.widget.LinearLayout>(R.id.container_admin_banks)
        val progress = view.findViewById<android.widget.ProgressBar>(R.id.progress_manual)
        val tvNone = view.findViewById<android.widget.TextView>(R.id.tv_no_manual)
        val etAmount = view.findViewById<com.google.android.material.textfield.TextInputEditText>(R.id.et_manual_amount)
        val etGateway = view.findViewById<com.google.android.material.textfield.TextInputEditText>(R.id.et_manual_gateway)
        val btnSubmit = view.findViewById<com.google.android.material.button.MaterialButton>(R.id.btn_submit_manual)

        progress.visibility = View.VISIBLE

        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.getPlatformBanks(mapOf("api_key" to prefs.getApiKey()))
                @Suppress("UNCHECKED_CAST")
                val data = resp.body()?.get("data") as? Map<String, Any>
                val isDisabled = (resp.body()?.get("disabled") as? Boolean) == true
                val disabledMsg = resp.body()?.get("message") as? String
                @Suppress("UNCHECKED_CAST")
                val banks = data?.get("banks") as? List<Map<String, Any>>
                activity?.runOnUiThread {
                    progress.visibility = View.GONE
                    if (isDisabled) {
                        tvNone.visibility = View.VISIBLE
                        tvNone.text = disabledMsg ?: "Manual bank funding is currently disabled."
                        btnSubmit.isEnabled = false
                    } else if (banks.isNullOrEmpty()) {
                        tvNone.visibility = View.VISIBLE
                    } else {
                        banks.forEach { bank ->
                            val card = layoutInflater.inflate(R.layout.item_bank_card, containerBanks, false)
                            card.findViewById<android.widget.TextView>(R.id.tv_item_bank_name).text =
                                "${bank["bank_name"] as? String ?: "Bank"}  •  Fee: ₦${bank["fee"] ?: "0"}"
                            card.findViewById<android.widget.TextView>(R.id.tv_item_account_name).text =
                                bank["account_name"] as? String ?: "---"
                            card.findViewById<android.widget.TextView>(R.id.tv_item_account_number).text =
                                bank["account_number"] as? String ?: "---"
                            containerBanks.addView(card)
                        }
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread { progress.visibility = View.GONE; tvNone.visibility = View.VISIBLE }
            }
        }

        btnSubmit.setOnClickListener {
            val amount = etAmount.text?.toString()?.trim() ?: ""
            val gateway = etGateway.text?.toString()?.trim() ?: ""
            if (amount.isEmpty() || (amount.toDoubleOrNull() ?: 0.0) <= 0) {
                snack(view, "Enter a valid amount"); return@setOnClickListener
            }
            submitNotification(view, btnSubmit, amount, gateway)
        }
    }

    private fun submitNotification(
        view: View,
        btn: com.google.android.material.button.MaterialButton,
        amount: String,
        gateway: String
    ) {
        btn.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.notifyManualFund(mapOf(
                    "api_key" to prefs.getApiKey(),
                    "amount" to amount,
                    "gateway" to gateway.ifEmpty { "Manual Bank Deposit" }
                ))
                val body = resp.body()
                var status = body?.get("status") as? String
                var msg = body?.get("message") as? String
                if (body == null || msg.isNullOrBlank()) {
                    val errText = try { resp.errorBody()?.string() } catch (_: Exception) { null }
                    if (!errText.isNullOrBlank()) {
                        try {
                            val obj = org.json.JSONObject(errText)
                            status = status ?: obj.optString("status", "error")
                            msg = obj.optString("message").ifBlank { obj.optString("desc", "") }
                        } catch (_: Exception) {}
                    }
                }
                val finalStatus = status ?: if (resp.isSuccessful) "success" else "error"
                val finalMsg = msg?.takeIf { it.isNotBlank() }
                    ?: if (!resp.isSuccessful) "Request failed (HTTP ${resp.code()}). Please try again." else "Unknown error"
                activity?.runOnUiThread {
                    btn.isEnabled = true
                    if (finalStatus.contains("success", true)) {
                        android.widget.Toast.makeText(requireContext(), finalMsg, android.widget.Toast.LENGTH_LONG).show()
                    } else snack(view, finalMsg)
                }
            } catch (e: Exception) {
                activity?.runOnUiThread { btn.isEnabled = true; snack(view, e.message ?: "Error") }
            }
        }
    }

    private fun snack(v: View, msg: String) = Snackbar.make(v, msg, Snackbar.LENGTH_LONG).show()
}

