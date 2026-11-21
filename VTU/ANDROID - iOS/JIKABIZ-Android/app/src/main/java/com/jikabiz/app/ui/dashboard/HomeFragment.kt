package com.jikabiz.app.ui.dashboard

import android.app.AlertDialog
import android.content.Intent
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.*
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.jikabiz.app.R
import com.jikabiz.app.api.RetrofitClient
import com.jikabiz.app.data.model.ApiResult
import com.jikabiz.app.ui.transactions.ReceiptHelper
import com.jikabiz.app.util.Constants
import com.jikabiz.app.util.PreferenceManager
import com.jikabiz.app.util.safeNavigate
import com.jikabiz.app.util.toNaira
import com.jikabiz.app.viewmodel.MainViewModel
import kotlinx.coroutines.launch

/** Reference-inspired home. All amounts are real; existing purchase/receipt flows stay intact. */
class HomeFragment : Fragment() {
    private val model: MainViewModel by activityViewModels()
    private lateinit var refresh: SwipeRefreshLayout
    private lateinit var root: LinearLayout
    private lateinit var balanceLabel: TextView
    private lateinit var greeting: TextView
    private lateinit var currencyButton: TextView
    private lateinit var trend: MobileBalanceTrend
    private lateinit var history: LinearLayout
    private lateinit var prefs: PreferenceManager
    private var visible = true
    private var selected = "NGN"
    private var wallets = listOf<Pair<String, String>>()
    private var directory = listOf<Map<String, String>>()
    private var balanceAvailable = false
    private val orange get() = com.jikabiz.app.util.BrandTheme.primary(requireContext())
    private fun dp(n: Int) = (n * resources.displayMetrics.density).toInt()
    private fun rounded(color: Int, radius: Int = 24) = GradientDrawable().apply { setColor(color); cornerRadius = dp(radius).toFloat() }
    private fun text(value: String, size: Float = 15f, color: Int = Color.WHITE) = TextView(requireContext()).apply {
        tag = "brand:text=" + if(color==Color.WHITE) "on_primary" else "on_surface"; text = value; textSize = size; setTextColor(color); setPadding(dp(8), dp(8), dp(8), dp(8))
    }
    private fun action(label: String, glyph: String, callback: () -> Unit) = LinearLayout(requireContext()).apply {
        orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER; minimumHeight = dp(80)
        val icon = text(glyph, 26f).apply { gravity = Gravity.CENTER; background = rounded(orange, 32); contentDescription = label }
        addView(icon, LinearLayout.LayoutParams(dp(54), dp(54)))
        addView(text(label, 12f)); isClickable = true; isFocusable = true; setOnClickListener { callback() }
    }
    override fun onCreateView(inflater: android.view.LayoutInflater, container: ViewGroup?, state: Bundle?): View {
        prefs = PreferenceManager(requireContext())
        refresh = SwipeRefreshLayout(requireContext())
        val scroll = ScrollView(requireContext()).apply { isFillViewport = true; tag="brand:bg=background"; setBackgroundColor(com.jikabiz.app.util.BrandTheme.color(requireContext(), "background")) }
        root = LinearLayout(requireContext()).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(16), dp(16), dp(16), dp(30)) }
        scroll.addView(root); refresh.addView(scroll)
        val hero = LinearLayout(requireContext()).apply {
            tag="brand:bg=primary"; orientation = LinearLayout.VERTICAL; setPadding(dp(18), dp(20), dp(18), dp(20))
            background = GradientDrawable(GradientDrawable.Orientation.TOP_BOTTOM, intArrayOf(orange, orange)).apply { cornerRadius = dp(32).toFloat() }
        }
        greeting = text("Hello, ${prefs.getString(Constants.KEY_FIRSTNAME)}", 18f)
        val header = LinearLayout(requireContext()).apply { gravity = Gravity.CENTER_VERTICAL }
        header.addView(greeting, LinearLayout.LayoutParams(0, -2, 1f))
        header.addView(text("◎", 25f).apply { contentDescription = "Account and notifications"; setOnClickListener { findNavController().safeNavigate(R.id.nav_profile) } })
        hero.addView(header)
        val titleRow = LinearLayout(requireContext()).apply { gravity = Gravity.CENTER_VERTICAL }
        titleRow.addView(text("Wallet balance  ◉", 13f).apply { isFocusable=true; contentDescription="Show or hide wallet balance"; setOnClickListener { visible = !visible; renderBalance() } }, LinearLayout.LayoutParams(0,-2,1f))
        currencyButton = text("NGN ⌄", 14f).apply { background = rounded(orange); setOnClickListener { chooseWallet() } }
        titleRow.addView(currencyButton); hero.addView(titleRow)
        balanceLabel = text("Loading…", 38f).apply { setTypeface(typeface, android.graphics.Typeface.BOLD); setPadding(dp(8), dp(28), dp(8), dp(25)) }
        hero.addView(balanceLabel)
        trend=MobileBalanceTrend(requireContext())
        hero.addView(trend,LinearLayout.LayoutParams(-1,dp(66)))
        hero.addView(text("Your money. Your next move.", 12f, Color.WHITE))
        val actions = LinearLayout(requireContext())
        listOf(action("Send", "↗") { services("Send") }, action("Receive", "↙") { openService("invoice") },
            action("Top up", "+") { findNavController().safeNavigate(R.id.nav_fund_wallet) }, action("More", "⋮") { services(null) })
            .forEach { actions.addView(it, LinearLayout.LayoutParams(0,-2,1f)) }
        hero.addView(actions); root.addView(hero)
        val shortcuts = LinearLayout(requireContext()).apply { setPadding(0,dp(18),0,dp(18)) }
        listOf(Triple("Crypto", "₿", "crypto"), Triple("Virtual card", "▱", "cards"), Triple("Gift card", "♧", "giftcards"), Triple("VTU", "▤", "vtu")).forEach { (title, icon, slug) ->
            val tile=action(title,icon) { if(slug=="vtu") services("VTU") else openService(slug) }.apply { tag="brand:bg=surface"; background=rounded(com.jikabiz.app.util.BrandTheme.color(requireContext(), "surface"),18) }
            for (i in 0 until tile.childCount) { val child=tile.getChildAt(i); if(child is TextView) child.tag="brand:text=on_surface"; if(child is ViewGroup) for(k in 0 until child.childCount) child.getChildAt(k).tag="brand:text=primary_text" }; shortcuts.addView(tile, LinearLayout.LayoutParams(0,dp(108),1f).apply { setMargins(dp(3),0,dp(3),0) })
        }
        root.addView(shortcuts)
        history = LinearLayout(requireContext()).apply { tag="brand:bg=surface"; orientation=LinearLayout.VERTICAL; background=rounded(Color.WHITE,28); setPadding(dp(12),dp(14),dp(12),dp(14)) }
        history.addView(text("Transaction history  →",20f,Color.BLACK).apply { setTypeface(typeface,1); setOnClickListener { findNavController().safeNavigate(R.id.nav_transactions) } })
        root.addView(history)
        root.addView(text("Rewards · KYC · Security · Support",13f,Color.LTGRAY).apply { gravity=Gravity.CENTER; minimumHeight=dp(48); setOnClickListener { services(null) } })
        return refresh
    }
    override fun onViewCreated(view: View, state: Bundle?) {
        model.profile.observe(viewLifecycleOwner) { result -> if(result is ApiResult.Success) greeting.text="Hello, ${result.data.firstname}" }
        model.transactions.observe(viewLifecycleOwner) { result ->
            if(result is ApiResult.Success) {
                trend.values=result.data.filter { it.status==1 }.reversed().map { it.balanceAfter }; trend.invalidate()
                while(history.childCount>1)history.removeViewAt(1)
                if(result.data.isEmpty())history.addView(text("No transactions yet",14f,Color.GRAY))
                result.data.take(5).forEach { tx ->
                    val row=LinearLayout(requireContext()).apply { orientation=LinearLayout.VERTICAL; setPadding(dp(4),dp(8),dp(4),dp(8)); isFocusable=true }
                    row.addView(text(tx.description,14f,Color.BLACK))
                    val status=when(tx.status) {1->"Completed";2->"Pending";else->"Failed"}
                    row.addView(text("${tx.amount.toNaira()} · $status · ${tx.date}",12f,Color.DKGRAY))
                    row.setOnClickListener { ReceiptHelper.showReceiptDialog(requireContext(),layoutInflater,tx) }
                    history.addView(row)
                }
            }
        }
        refresh.setOnRefreshListener { load() }; load()
    }
    private fun load() {
        model.loadProfile(); model.loadServices(); model.loadTransactions(limit=5)
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response=RetrofitClient.getService().mobileHome(mapOf("api_key" to prefs.getApiKey()))
                val body=response.body()
                if(response.isSuccessful && body?.get("status")=="success") {
                    @Suppress("UNCHECKED_CAST") val items=body["wallets"] as? List<Map<String, Any>> ?: emptyList()
                    wallets=items.mapNotNull { w -> val code=w["code"] as? String; val amount=w["balance"]?.toString(); if(code!=null && amount!=null) code to amount else null }
                    @Suppress("UNCHECKED_CAST") val services=body["services"] as? List<Map<String,String>> ?: emptyList()
                    directory=services; balanceAvailable=wallets.isNotEmpty()
                    if(wallets.none { it.first==selected })selected=wallets.firstOrNull()?.first ?: "NGN"
                } else { balanceAvailable=false; directory=emptyList() }
            } catch(_: Exception) { balanceAvailable=false }
            if(view!=null) { renderBalance(); refresh.isRefreshing=false }
        }
    }
    private fun renderBalance() {
        currencyButton.text="$selected ⌄"
        trend.visibility=if(visible && selected=="NGN") View.VISIBLE else View.GONE
        balanceLabel.text=when { !visible -> "••••••"; !balanceAvailable -> "Unavailable"; else -> {
            val amount=wallets.firstOrNull { it.first==selected }?.second?.toBigDecimalOrNull()
            if(amount==null) "Unavailable" else "$selected ${java.text.DecimalFormat("#,##0.00").format(amount)}"
        } }
    }
    private fun chooseWallet() {
        if(wallets.isEmpty()) { Toast.makeText(requireContext(),"Wallets are unavailable. Pull to refresh.",Toast.LENGTH_LONG).show(); return }
        AlertDialog.Builder(requireContext()).setTitle("Choose an existing wallet").setItems((wallets.map { it.first } + "Live currency conversion").toTypedArray()) { _,i -> if(i==wallets.size) openService("fx") else { selected=wallets[i].first; renderBalance() } }.show()
    }
    private fun services(group: String?) {
        val items=directory.filter { group==null || it["group"]==group }
        if(items.isEmpty()) { Toast.makeText(requireContext(),"No available services. Pull to refresh.",Toast.LENGTH_LONG).show(); return }
        AlertDialog.Builder(requireContext()).setTitle(group ?: "All services").setItems(items.map { "${it["group"]} · ${it["title"]}" }.toTypedArray()) { _,i -> openService(items[i]["id"] ?: "") }.show()
    }
    private fun openService(slug: String) {
        if(directory.none { it["id"]==slug }) { Toast.makeText(requireContext(),"This service is not available on your platform yet.",Toast.LENGTH_LONG).show(); return }
        MobileServiceNavigator.launch(this,slug)
    }
}

/** Draw only recorded balance snapshots; never invent sample earnings or trends. */
private class MobileBalanceTrend(context: android.content.Context): View(context) {
    var values: List<Double> = emptyList()
    private val paint=android.graphics.Paint(android.graphics.Paint.ANTI_ALIAS_FLAG).apply { color=Color.WHITE; strokeWidth=3f; style=android.graphics.Paint.Style.STROKE; strokeCap=android.graphics.Paint.Cap.ROUND }
    override fun onDraw(canvas: android.graphics.Canvas) {
        super.onDraw(canvas)
        if(values.size<2)return
        val low=values.minOrNull() ?: return; val high=values.maxOrNull() ?: return
        val range=(high-low).coerceAtLeast(1.0); val path=android.graphics.Path()
        values.forEachIndexed { i,v -> val x=width.toFloat()*i/(values.size-1); val y=(height-8)*(1-(v-low)/range).toFloat()+4; if(i==0)path.moveTo(x,y) else path.lineTo(x,y) }
        paint.color=com.jikabiz.app.util.BrandTheme.onPrimary(context); canvas.drawPath(path,paint)
    }
}
