package com.mzeevtu.ui.ai

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.LiveData
import androidx.lifecycle.MutableLiveData
import androidx.lifecycle.viewModelScope
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.util.PreferenceManager
import kotlinx.coroutines.launch

data class ChatMessage(
    val text: String,
    val isUser: Boolean,
    val isError: Boolean = false,
    val timestamp: Long = System.currentTimeMillis()
)

class AIAssistantViewModel(application: Application) : AndroidViewModel(application) {

    private val api = RetrofitClient.getService()
    private val prefs = PreferenceManager(application)

    private val _messages = MutableLiveData<List<ChatMessage>>(
        listOf(ChatMessage("Hello! I am your AI VTU Assistant. How can I help you manage your VTU business today?", false))
    )
    val messages: LiveData<List<ChatMessage>> = _messages

    private val _isLoading = MutableLiveData<Boolean>(false)
    val isLoading: LiveData<Boolean> = _isLoading

    private val _error = MutableLiveData<String?>()
    val error: LiveData<String?> = _error

    fun sendMessage(promptText: String) {
        if (promptText.isBlank()) return

        val currentList = _messages.value.orEmpty().toMutableList()
        currentList.add(ChatMessage(promptText, true))
        _messages.value = currentList

        _isLoading.value = true
        _error.value = null

        viewModelScope.launch {
            try {
                val apiKey = prefs.getApiKey()
                val body = mapOf(
                    "prompt" to promptText,
                    "api_key" to apiKey
                )

                val response = api.getAiResponse(context = "user", body = body)
                if (response.isSuccessful) {
                    val respBody = response.body()
                    val status = respBody?.get("status") as? String
                    if (status == "success") {
                        val aiResponse = respBody["response"] as? String ?: "No response received"
                        
                        val updatedList = _messages.value.orEmpty().toMutableList()
                        updatedList.add(ChatMessage(aiResponse, false))
                        _messages.postValue(updatedList)
                    } else {
                        val errorMsg = respBody?.get("message") as? String ?: "Failed to get AI response"
                        _error.postValue(errorMsg)
                        
                        val updatedList = _messages.value.orEmpty().toMutableList()
                        updatedList.add(ChatMessage("\u26A0\uFE0F Error: $errorMsg", false, isError = true))
                        _messages.postValue(updatedList)
                    }
                } else {
                    val errCode = response.code()
                    val errMsg = "Server error ($errCode). Please check your connection or try again."
                    _error.postValue(errMsg)
                    
                    val updatedList = _messages.value.orEmpty().toMutableList()
                    updatedList.add(ChatMessage("\u26A0\uFE0F Error: $errMsg", false, isError = true))
                    _messages.postValue(updatedList)
                }
            } catch (e: com.google.gson.JsonSyntaxException) {
                val errMsg = "Something went wrong. Please try again."
                _error.postValue(errMsg)

                val updatedList = _messages.value.orEmpty().toMutableList()
                updatedList.add(ChatMessage("⚠️ $errMsg", false, isError = true))
                _messages.postValue(updatedList)
            } catch (e: Exception) {
                val errMsg = e.message ?: "Network connection failed"
                _error.postValue(errMsg)
                
                val updatedList = _messages.value.orEmpty().toMutableList()
                updatedList.add(ChatMessage("\u26A0\uFE0F Error: $errMsg", false, isError = true))
                _messages.postValue(updatedList)
            } finally {
                _isLoading.postValue(false)
            }
        }
    }
}
