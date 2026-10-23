package com.mzeevtu.ui.ai

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import android.widget.ImageButton
import android.widget.ProgressBar
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.lifecycle.ViewModelProvider
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.mzeevtu.R

class AIAssistantFragment : Fragment() {

    private lateinit var viewModel: AIAssistantViewModel
    private lateinit var messageEditText: EditText
    private lateinit var sendButton: ImageButton
    private lateinit var chatRecyclerView: RecyclerView
    private lateinit var loadingIndicator: ProgressBar
    private lateinit var chatAdapter: ChatAdapter

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View? {
        val root = inflater.inflate(R.layout.fragment_ai_assistant, container, false)
        
        viewModel = ViewModelProvider(this)[AIAssistantViewModel::class.java]

        messageEditText = root.findViewById(R.id.messageEditText)
        sendButton = root.findViewById(R.id.sendButton)
        chatRecyclerView = root.findViewById(R.id.chatRecyclerView)
        loadingIndicator = root.findViewById(R.id.loadingIndicator)

        val quickPrompts = mapOf(
            R.id.chip_airtime to "Buy MTN Airtime for 08061234567",
            R.id.chip_data to "Buy 2GB MTN Data for 08061234567",
            R.id.chip_electricity to "Pay Electricity Bill for meter 12345678901",
            R.id.chip_cable to "Renew GOtv Subscription for smartcard 1234567890",
            R.id.chip_betting to "Fund my Bet9ja account with ID 12345678",
            R.id.chip_exam to "Buy WAEC Result Checker PIN"
        )
        quickPrompts.forEach { (chipId, prompt) ->
            root.findViewById<View>(chipId).setOnClickListener {
                messageEditText.setText(prompt)
                messageEditText.setSelection(prompt.length)
            }
        }

        chatAdapter = ChatAdapter()
        chatRecyclerView.layoutManager = LinearLayoutManager(requireContext()).apply {
            stackFromEnd = true
        }
        chatRecyclerView.adapter = chatAdapter

        sendButton.setOnClickListener {
            val message = messageEditText.text.toString().trim()
            if (message.isNotEmpty()) {
                viewModel.sendMessage(message)
                messageEditText.setText("")
            }
        }

        viewModel.messages.observe(viewLifecycleOwner) { messages ->
            chatAdapter.submitList(messages)
            if (messages.isNotEmpty()) {
                chatRecyclerView.smoothScrollToPosition(messages.size - 1)
            }
        }

        viewModel.isLoading.observe(viewLifecycleOwner) { isLoading ->
            loadingIndicator.visibility = if (isLoading) View.VISIBLE else View.GONE
            sendButton.isEnabled = !isLoading
        }

        return root
    }

    private class ChatAdapter : RecyclerView.Adapter<ChatAdapter.ChatViewHolder>() {
        private var list: List<ChatMessage> = emptyList()

        fun submitList(newList: List<ChatMessage>) {
            list = newList
            notifyDataSetChanged()
        }

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ChatViewHolder {
            val view = LayoutInflater.from(parent.context).inflate(R.layout.item_chat_message, parent, false)
            return ChatViewHolder(view)
        }

        override fun onBindViewHolder(holder: ChatViewHolder, position: Int) {
            holder.bind(list[position])
        }

        override fun getItemCount(): Int = list.size

        class ChatViewHolder(itemView: View) : RecyclerView.ViewHolder(itemView) {
            private val layoutAssistant: View = itemView.findViewById(R.id.layoutAssistant)
            private val tvAssistantText: TextView = itemView.findViewById(R.id.tvAssistantText)
            private val layoutUser: View = itemView.findViewById(R.id.layoutUser)
            private val tvUserText: TextView = itemView.findViewById(R.id.tvUserText)

            fun bind(message: ChatMessage) {
                if (message.isUser) {
                    layoutUser.visibility = View.VISIBLE
                    layoutAssistant.visibility = View.GONE
                    tvUserText.text = message.text
                } else {
                    layoutUser.visibility = View.GONE
                    layoutAssistant.visibility = View.VISIBLE
                    tvAssistantText.text = message.text
                    if (message.isError) {
                        layoutAssistant.setBackgroundResource(R.drawable.bg_chat_bubble_error)
                        tvAssistantText.setTextColor(android.graphics.Color.parseColor("#B91C1C"))
                    } else {
                        layoutAssistant.setBackgroundResource(R.drawable.bg_chat_bubble_assistant)
                        tvAssistantText.setTextColor(itemView.context.getColor(R.color.text_primary))
                    }
                }
            }
        }
    }
}
