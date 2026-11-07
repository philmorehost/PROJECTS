package com.mzeevtu.ui.services

import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.View
import android.widget.ArrayAdapter
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.FragmentBulkSmsBinding
import com.mzeevtu.util.LoadingOverlay
import com.mzeevtu.util.PreferenceManager
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.snackbar.Snackbar
import com.google.android.material.textfield.TextInputEditText
import kotlinx.coroutines.launch
import kotlin.math.ceil

class BulkSmsFragment : Fragment(R.layout.fragment_bulk_sms) {

    private var _binding: FragmentBulkSmsBinding? = null
    private val binding get() = _binding!!
    private val senderIds = mutableListOf<String>()

    private val networks = listOf("mtn", "airtel", "glo", "9mobile")

    companion object {
        private const val SMS_MAX_CHARS = 459
        private const val SMS_SINGLE_PAGE = 160
        private const val SMS_MULTI_PAGE = 153
        private val DIGITS_ONLY = Regex("[^0-9]")
        private const val MSG_NO_SENDER_IDS = "No approved Sender IDs. Register one to get started."

        fun calculatePages(length: Int): Int {
            if (length <= 0) return 0
            return if (length <= SMS_SINGLE_PAGE) 1 else ceil(length.toDouble() / SMS_MULTI_PAGE).toInt()
        }
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentBulkSmsBinding.bind(view)

        binding.btnSend.setOnClickListener { confirmSend() }
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnRegisterSenderId.setOnClickListener { showRegisterSenderIdDialog() }

        setupNetworkDropdown()
        setupTextWatchers()
        loadSenderIds()
    }

    private fun setupNetworkDropdown() {
        val adapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, networks)
        binding.spinnerNetwork.setAdapter(adapter)
        binding.spinnerNetwork.threshold = 0
    }

    private fun countValidPhones(input: String): Int =
        input.split(",", "\n")
            .map { it.trim().replace(DIGITS_ONLY, "") }
            .count { it.length == 11 }

    private fun setupTextWatchers() {
        binding.etMessage.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                val len = s?.length ?: 0
                val notice = if (len >= SMS_MAX_CHARS) " Ã¢â¬” SMS 3 Pages Maximum" else ""
                binding.tvCharCount.text = "$len/$SMS_MAX_CHARS$notice"
            }
        })

        binding.etRecipients.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                binding.tvPhoneCount.text = "Numbers: ${countValidPhones(s?.toString() ?: "")}"
            }
        })
    }

    private fun loadSenderIds() {
        binding.tvNoSenderId.text = "Loading Sender IDs\u2026"
        binding.tvNoSenderId.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.getSenderIds(mapOf("api_key" to prefs.getApiKey()))
                if (resp.isSuccessful) {
                    @Suppress("UNCHECKED_CAST")
                    val data = resp.body()?.get("data") as? List<Map<String, String>>
                    data?.let { list ->
                        senderIds.clear()
                        list.filter { it["status"] == "Approved" }
                            .mapNotNull { it["sender_id"] }
                            .forEach { senderIds.add(it) }
                        activity?.runOnUiThread {
                            if (_binding == null) return@runOnUiThread
                            populateSenderDropdown()
                        }
                    } ?: activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        binding.tvNoSenderId.text = MSG_NO_SENDER_IDS
                        binding.tvNoSenderId.visibility = View.VISIBLE
                    }
                } else {
                    activity?.runOnUiThread {
                        if (_binding == null) return@runOnUiThread
                        binding.tvNoSenderId.text = "Failed to load Sender IDs. Tap '+ Register ID' to add one."
                        binding.tvNoSenderId.visibility = View.VISIBLE
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.tvNoSenderId.text = "Could not load Sender IDs: ${e.message}"
                    binding.tvNoSenderId.visibility = View.VISIBLE
                }
            }
        }
    }

    private fun populateSenderDropdown() {
        if (senderIds.isEmpty()) {
            binding.tvNoSenderId.text = MSG_NO_SENDER_IDS
            binding.tvNoSenderId.visibility = View.VISIBLE
            return
        }
        binding.tvNoSenderId.visibility = View.GONE
        val adapter = ArrayAdapter(requireContext(), android.R.layout.simple_dropdown_item_1line, senderIds)
        binding.spinnerSenderId.setAdapter(adapter)
        binding.spinnerSenderId.threshold = 0
    }

    /** Dialog to register a new Sender ID (submit sender_id + sample_message) */
    private fun showRegisterSenderIdDialog() {
        val dialogView = layoutInflater.inflate(R.layout.dialog_register_sender_id, null)
        val etSenderId = dialogView.findViewById<TextInputEditText>(R.id.et_sender_id)
        val etSampleMsg = dialogView.findViewById<TextInputEditText>(R.id.et_sample_message)

        MaterialAlertDialogBuilder(requireContext())
            .setTitle("Register Sender ID")
            .setMessage("Your Sender ID must be approved before use.\nEnter your desired Sender ID and provide a sample message for review.")
            .setView(dialogView)
            .setPositiveButton("Submit for Review") { _, _ ->
                val senderId = etSenderId?.text?.toString()?.trim() ?: ""
                val sampleMsg = etSampleMsg?.text?.toString()?.trim() ?: ""
                if (senderId.isEmpty() || sampleMsg.isEmpty()) {
                    snack("Both fields are required")
                } else {
                    submitSenderId(senderId, sampleMsg)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun submitSenderId(senderId: String, sampleMessage: String) {
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.submitSenderId(mapOf(
                    "api_key" to prefs.getApiKey(),
                    "sender_id" to senderId,
                    "sample_message" to sampleMessage
                ))
                val body = resp.body()
                val status = body?.get("status") as? String ?: "error"
                val msg = body?.get("message") as? String ?: "Submission failed"
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    if (status == "success") {
                        snack("Sender ID submitted for review. You'll be notified when approved.")
                        loadSenderIds()
                    } else {
                        snack(msg)
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    private fun confirmSend() {
        val senderId = binding.spinnerSenderId.text?.toString()?.trim() ?: ""
        val network = binding.spinnerNetwork.text?.toString()?.trim()?.lowercase() ?: ""
        val recipients = binding.etRecipients.text?.toString()?.trim() ?: ""
        val message = binding.etMessage.text?.toString()?.trim() ?: ""
        val pages = calculatePages(message.length)
        val phoneCount = countValidPhones(recipients)
        when {
            senderId.isEmpty() -> snack("Select a Sender ID")
            network.isEmpty() || !networks.contains(network) -> snack("Select a network")
            recipients.isEmpty() || phoneCount == 0 -> snack("Enter valid recipient phone numbers")
            message.isEmpty() -> snack("Enter message text")
            pages > 3 -> snack("Message exceeds 3 SMS pages (max 459 characters)")
            else -> {
                MaterialAlertDialogBuilder(requireContext())
                    .setTitle("Confirm SMS")
                    .setMessage("From: $senderId\nNetwork: ${network.uppercase()}\nTo: $phoneCount recipient(s)\nPages: $pages per number\nMessage: $message")
                    .setPositiveButton("Send") { _, _ -> doSend(senderId, network, recipients, message) }
                    .setNegativeButton("Cancel", null).show()
            }
        }
    }

    private fun doSend(senderId: String, network: String, recipients: String, message: String) {
        LoadingOverlay.show(requireContext(), "Sending your message...")
        binding.btnSend.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.sendBulkSms(mapOf(
                    "api_key" to prefs.getApiKey(),
                    "sender_id" to senderId,
                    "network" to network,
                    "phone_number" to recipients,
                    "message" to message,
                    "type" to "standard_sms"
                ))
                val body = resp.body()
                val status = body?.get("status") as? String ?: "failed"
                val msg = body?.get("desc") as? String ?: body?.get("message") as? String ?: ""
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnSend.isEnabled = true
                    if (status.contains("success", true)) {
                        MaterialAlertDialogBuilder(requireContext()).setTitle("\u2705 SMS Sent").setMessage(msg)
                            .setPositiveButton("Done") { _, _ -> requireActivity().onBackPressedDispatcher.onBackPressed() }.show()
                    } else snack(if (msg.isNotEmpty()) msg else "Transaction failed")
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    LoadingOverlay.dismiss()
                    binding.btnSend.isEnabled = true
                    snack(e.message ?: "Error")
                }
            }
        }
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()
    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
