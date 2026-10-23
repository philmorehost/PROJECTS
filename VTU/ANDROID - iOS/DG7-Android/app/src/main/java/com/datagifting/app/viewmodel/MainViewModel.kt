package com.datagifting.app.viewmodel

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.LiveData
import androidx.lifecycle.MutableLiveData
import androidx.lifecycle.viewModelScope
import com.datagifting.app.data.model.ApiResult
import com.datagifting.app.data.model.Transaction
import com.datagifting.app.data.model.User
import com.datagifting.app.data.repository.ServicesRepository
import com.datagifting.app.util.PreferenceManager
import kotlinx.coroutines.launch

class MainViewModel(application: Application) : AndroidViewModel(application) {

    private val repo = ServicesRepository(application)
    private val prefs = PreferenceManager(application)

    private val _profile = MutableLiveData<ApiResult<User>>()
    val profile: LiveData<ApiResult<User>> = _profile

    private val _transactions = MutableLiveData<ApiResult<List<Transaction>>>()
    val transactions: LiveData<ApiResult<List<Transaction>>> = _transactions

    private val _enabledServices = MutableLiveData<Set<String>>(prefs.getEnabledServices())
    val enabledServices: LiveData<Set<String>> = _enabledServices

    private val _txResult = MutableLiveData<ApiResult<Map<String, Any>>>()
    val txResult: LiveData<ApiResult<Map<String, Any>>> = _txResult

    private val _balance = MutableLiveData(prefs.getDouble(com.datagifting.app.util.Constants.KEY_BALANCE))
    val balance: LiveData<Double> = _balance

    fun loadServices() {
        viewModelScope.launch {
            when (val result = repo.getEnabledServices()) {
                is ApiResult.Success -> _enabledServices.postValue(result.data)
                else -> {}
            }
        }
    }

    fun loadProfile() {
        viewModelScope.launch {
            _profile.postValue(ApiResult.Loading)
            when (val result = repo.getProfile()) {
                is ApiResult.Success -> {
                    val d = result.data
                    val user = User(
                        username = d["username"] as? String ?: "",
                        firstname = d["firstname"] as? String ?: "",
                        lastname = d["lastname"] as? String ?: "",
                        email = d["email"] as? String ?: "",
                        phone = d["phone"] as? String ?: "",
                        balance = (d["balance"] as? String)?.toDoubleOrNull() ?: 0.0,
                        apiKey = d["api_key"] as? String ?: "",
                        accountLevel = (d["account_level"] as? Double)?.toInt() ?: 1,
                        levelName = d["level_name"] as? String ?: "",
                        kycStatus = (d["kyc_status"] as? Double)?.toInt() ?: 0,
                        kycVerified = d["kyc_verified"] as? String ?: "No",
                        securityPinSet = d["security_pin_set"] as? Boolean ?: false
                    )
                    // Cache the vendor's live PIN-enforcement state so purchase screens can decide
                    // whether to prompt for a PIN without a separate fetch (see requireTransactionPin
                    // on the backend, the actual enforcement point — this is just UX to avoid a purchase
                    // bouncing off a server rejection).
                    prefs.saveBoolean(com.datagifting.app.util.Constants.KEY_PIN_REQUIRED, d["pin_required"] as? Boolean ?: false)
                    prefs.saveBoolean(com.datagifting.app.util.Constants.KEY_PIN_SET, user.securityPinSet)
                    _balance.postValue(user.balance)
                    _profile.postValue(ApiResult.Success(user))
                }
                is ApiResult.Error -> _profile.postValue(result)
                else -> {}
            }
        }
    }

    fun loadTransactions(limit: Int = 50, offset: Int = 0) {
        viewModelScope.launch {
            _transactions.postValue(ApiResult.Loading)
            when (val result = repo.getTransactions(limit, offset)) {
                is ApiResult.Success -> {
                    @Suppress("UNCHECKED_CAST")
                    val list = result.data.mapNotNull { item ->
                        val m = item as? Map<String, Any> ?: return@mapNotNull null
                        Transaction(
                            reference = m["reference"] as? String ?: "",
                            type = m["type"] as? String ?: "",
                            amount = (m["amount"] as? Double) ?: 0.0,
                            discountedAmount = (m["discounted_amount"] as? Double) ?: 0.0,
                            balanceBefore = (m["balance_before"] as? Double) ?: 0.0,
                            balanceAfter = (m["balance_after"] as? Double) ?: 0.0,
                            description = m["description"] as? String ?: "",
                            status = (m["status"] as? Double)?.toInt() ?: 0,
                            statusName = m["status_name"] as? String ?: "",
                            mode = m["mode"] as? String ?: "",
                            date = m["date"] as? String ?: ""
                        )
                    }
                    _transactions.postValue(ApiResult.Success(list))
                }
                is ApiResult.Error -> _transactions.postValue(result)
                else -> {}
            }
        }
    }
}
