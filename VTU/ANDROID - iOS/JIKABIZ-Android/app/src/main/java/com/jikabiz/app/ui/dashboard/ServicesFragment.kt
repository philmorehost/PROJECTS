package com.jikabiz.app.ui.dashboard

import android.graphics.Color
import android.os.Bundle
import android.view.View
import android.view.ViewGroup
import android.widget.*
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.jikabiz.app.api.RetrofitClient
import com.jikabiz.app.util.PreferenceManager
import kotlinx.coroutines.launch

/** Tenant-controlled service groups, including new services without app hard-coded URLs. */
class ServicesFragment: Fragment() {
    private lateinit var list: LinearLayout
    override fun onCreateView(inflater: android.view.LayoutInflater, container: ViewGroup?, state: Bundle?): View {
        val scroll=ScrollView(requireContext())
        list=LinearLayout(requireContext()).apply { orientation=LinearLayout.VERTICAL; setPadding(24,24,24,24) }
        scroll.addView(list); return scroll
    }
    override fun onViewCreated(view: View, state: Bundle?) { load() }
    private fun load() {
        list.removeAllViews(); list.addView(TextView(requireContext()).apply { text="Loading available services…" })
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response=RetrofitClient.getService().mobileHome(mapOf("api_key" to PreferenceManager(requireContext()).getApiKey()))
                val body=response.body()
                if(!response.isSuccessful || body?.get("status")!="success")throw IllegalStateException()
                @Suppress("UNCHECKED_CAST") val items=body["services"] as? List<Map<String,String>> ?: emptyList()
                if(view==null)return@launch
                list.removeAllViews()
                items.groupBy { it["group"] ?: "Services" }.forEach { (group,services) ->
                    list.addView(TextView(requireContext()).apply { text=group; textSize=22f; setPadding(8,28,8,16) })
                    services.forEach { service -> list.addView(Button(requireContext()).apply {
                        text=service["title"] ?: "Service"; setOnClickListener { MobileServiceNavigator.launch(this@ServicesFragment,service["id"] ?: "") }
                    }) }
                }
                if(items.isEmpty())list.addView(TextView(requireContext()).apply { text="No services are enabled for this account." })
            } catch(_: Exception) {
                if(view!=null) { list.removeAllViews(); list.addView(TextView(requireContext()).apply { text="Services are temporarily unavailable." }); list.addView(Button(requireContext()).apply { text="Retry"; setOnClickListener { load() } }) }
            }
        }
    }
}
