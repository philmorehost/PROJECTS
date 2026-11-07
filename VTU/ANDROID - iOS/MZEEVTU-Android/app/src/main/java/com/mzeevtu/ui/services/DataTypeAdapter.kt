package com.mzeevtu.ui.services

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.RadioButton
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView
import com.mzeevtu.R

data class DataTypeItem(
    val code: String,
    val name: String,
    val description: String
)

class DataTypeAdapter(
    private val items: List<DataTypeItem>,
    private val onSelected: (DataTypeItem) -> Unit
) : RecyclerView.Adapter<DataTypeAdapter.VH>() {

    private var selectedIndex = 0

    inner class VH(view: View) : RecyclerView.ViewHolder(view) {
        val rbType: RadioButton = view.findViewById(R.id.rb_type)
        val tvName: TextView = view.findViewById(R.id.tv_type_name)
        val tvDesc: TextView = view.findViewById(R.id.tv_type_desc)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH =
        VH(LayoutInflater.from(parent.context).inflate(R.layout.item_data_type, parent, false))

    override fun getItemCount() = items.size

    override fun onBindViewHolder(holder: VH, position: Int) {
        val item = items[position]
        holder.tvName.text = item.name
        holder.tvDesc.text = item.description
        holder.rbType.isChecked = (position == selectedIndex)
        holder.itemView.isSelected = (position == selectedIndex)
        holder.itemView.setOnClickListener {
            val prev = selectedIndex
            selectedIndex = holder.adapterPosition
            notifyItemChanged(prev)
            notifyItemChanged(selectedIndex)
            onSelected(item)
        }
    }

    fun selectByCode(code: String) {
        val idx = items.indexOfFirst { it.code == code }
        if (idx >= 0 && idx != selectedIndex) {
            val prev = selectedIndex
            selectedIndex = idx
            notifyItemChanged(prev)
            notifyItemChanged(selectedIndex)
        }
    }
}
