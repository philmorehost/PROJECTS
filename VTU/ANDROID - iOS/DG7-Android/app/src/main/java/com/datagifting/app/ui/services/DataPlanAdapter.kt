package com.datagifting.app.ui.services

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.RadioButton
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView
import com.datagifting.app.R
import java.text.NumberFormat
import java.util.Locale

data class DataPlanItem(
    val id: String,
    val code: String,
    val name: String,
    val amount: String,
    val duration: String,
    val dataTypeCode: String
)

class DataPlanAdapter(
    private val onSelected: (DataPlanItem) -> Unit
) : RecyclerView.Adapter<DataPlanAdapter.VH>() {

    private val items = mutableListOf<DataPlanItem>()
    private var selectedIndex = -1

    inner class VH(view: View) : RecyclerView.ViewHolder(view) {
        val rbPlan: RadioButton = view.findViewById(R.id.rb_plan)
        val tvName: TextView = view.findViewById(R.id.tv_plan_name)
        val tvDuration: TextView = view.findViewById(R.id.tv_plan_duration)
        val tvPrice: TextView = view.findViewById(R.id.tv_plan_price)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH =
        VH(LayoutInflater.from(parent.context).inflate(R.layout.item_data_plan, parent, false))

    override fun getItemCount() = items.size

    override fun onBindViewHolder(holder: VH, position: Int) {
        val item = items[position]
        holder.tvName.text = item.name
        holder.tvDuration.text = item.duration
        holder.tvPrice.text = "₦${formatAmount(item.amount)}"
        holder.rbPlan.isChecked = (position == selectedIndex)
        holder.itemView.isSelected = (position == selectedIndex)
        holder.itemView.setOnClickListener {
            val prev = selectedIndex
            selectedIndex = holder.adapterPosition
            notifyItemChanged(prev)
            notifyItemChanged(selectedIndex)
            onSelected(item)
        }
    }

    fun submitList(newItems: List<DataPlanItem>) {
        items.clear()
        items.addAll(newItems)
        selectedIndex = -1
        notifyDataSetChanged()
    }

    fun clearSelection() {
        val prev = selectedIndex
        selectedIndex = -1
        if (prev >= 0) notifyItemChanged(prev)
    }

    private fun formatAmount(amount: String): String {
        val d = amount.toDoubleOrNull() ?: return amount
        return if (d == d.toLong().toDouble()) {
            NumberFormat.getNumberInstance(Locale.US).format(d.toLong())
        } else {
            NumberFormat.getNumberInstance(Locale.US).apply { minimumFractionDigits = 2; maximumFractionDigits = 2 }.format(d)
        }
    }
}
