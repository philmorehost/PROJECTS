package com.payhub.guest.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.payhub.guest.data.GuestHistoryStore
import com.payhub.guest.data.model.BettingProvider
import com.payhub.guest.data.model.CablePlan
import com.payhub.guest.data.model.DataPlan
import com.payhub.guest.data.model.ExamPlan
import com.payhub.guest.data.model.GuestOrderStatusResponse
import com.payhub.guest.data.model.GuestReceipt
import com.payhub.guest.data.model.GuestSupportInfo
import com.payhub.guest.data.repository.ApiResult
import com.payhub.guest.data.repository.GuestRepository
import com.payhub.guest.util.ConnectivityObserver
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

/**
 * Single shared ViewModel for the whole guest flow (Home -> Purchase -> Checkout -> Receipt).
 * The app has no login/session, so one activity-scoped ViewModel holding "current transaction"
 * state is simpler and safer here than threading arguments through five nav destinations.
 *
 * AndroidViewModel (not plain ViewModel) so transaction history can be cached on-device via
 * GuestHistoryStore — there is no server-side history for an anonymous guest to fetch instead.
 */
class GuestViewModel(application: Application) : AndroidViewModel(application) {

    private val repo = GuestRepository()

    // ---------- Local transaction history (on-device only — no server-side guest history) ----------

    private val _transactionHistory = MutableStateFlow(GuestHistoryStore.load(application))
    val transactionHistory: StateFlow<List<GuestReceipt>> = _transactionHistory.asStateFlow()

    /** Idempotent per reference — safe to call every time the Receipt screen recomposes. */
    fun saveReceipt(receipt: GuestReceipt) {
        _transactionHistory.value = GuestHistoryStore.save(getApplication(), receipt)
    }

    // ---------- Site info / Service Control Centre honoring ----------

    // A service key absent here is treated as enabled (see GuestServiceCatalog.filterEnabled) —
    // start with an empty map rather than null so screens render the full catalog immediately
    // and only narrow down once (if ever) the admin has actually disabled something.
    private val _enabledServices = MutableStateFlow<Map<String, Int>>(emptyMap())
    val enabledServices: StateFlow<Map<String, Int>> = _enabledServices.asStateFlow()

    private val _supportInfo = MutableStateFlow<GuestSupportInfo?>(null)
    val supportInfo: StateFlow<GuestSupportInfo?> = _supportInfo.asStateFlow()

    init {
        viewModelScope.launch { loadSiteInfoOnce() }
    }

    private suspend fun loadSiteInfoOnce() {
        when (val r = repo.getSiteInfo()) {
            is ApiResult.Success -> {
                r.data.data?.services?.let { _enabledServices.value = it }
                _supportInfo.value = r.data.data?.support
            }
            is ApiResult.Error -> {} // Keep the empty-map default (every service stays visible).
        }
    }

    // ---------- Connectivity (online/offline banner) ----------

    val isOnline: StateFlow<Boolean> = ConnectivityObserver.observe(application)
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), true)

    // ---------- Pull-to-refresh (Home / Services / History) ----------

    private val _isRefreshing = MutableStateFlow(false)
    val isRefreshing: StateFlow<Boolean> = _isRefreshing.asStateFlow()

    /** Re-fetches site-info (service toggles, support contact) and re-checks any pending
     *  history entries against the server — shared pull-to-refresh handler for every
     *  top-level screen, since none of them have their own separate "loading" concept. */
    fun refresh() {
        if (_isRefreshing.value) return
        viewModelScope.launch {
            _isRefreshing.value = true
            try {
                loadSiteInfoOnce()
                refreshPendingHistoryOnce()
            } finally {
                _isRefreshing.value = false
            }
        }
    }

    // ---------- Catalog (fetched once per service, cached for the session) ----------

    data class AirtimeNetwork(val code: String, val label: String, val discountPercent: String)
    data class ElectricProvider(val code: String, val label: String, val discountPercent: String)

    private val _airtimeNetworks = MutableStateFlow<List<AirtimeNetwork>>(emptyList())
    val airtimeNetworks: StateFlow<List<AirtimeNetwork>> = _airtimeNetworks.asStateFlow()

    private val _dataNetworks = MutableStateFlow<Map<String, List<DataPlan>>>(emptyMap())
    val dataNetworks: StateFlow<Map<String, List<DataPlan>>> = _dataNetworks.asStateFlow()

    private val _cableProviders = MutableStateFlow<Map<String, List<CablePlan>>>(emptyMap())
    val cableProviders: StateFlow<Map<String, List<CablePlan>>> = _cableProviders.asStateFlow()

    private val _electricProviders = MutableStateFlow<List<ElectricProvider>>(emptyList())
    val electricProviders: StateFlow<List<ElectricProvider>> = _electricProviders.asStateFlow()

    private val _examPlans = MutableStateFlow<Map<String, List<ExamPlan>>>(emptyMap())
    val examPlans: StateFlow<Map<String, List<ExamPlan>>> = _examPlans.asStateFlow()

    private val _bettingProviders = MutableStateFlow<List<BettingProvider>>(emptyList())
    val bettingProviders: StateFlow<List<BettingProvider>> = _bettingProviders.asStateFlow()

    // Surfaced so the Purchase screen can show a real error + retry button instead of a
    // silently-empty plan list when a catalog fetch fails.
    private val _catalogError = MutableStateFlow<String?>(null)
    val catalogError: StateFlow<String?> = _catalogError.asStateFlow()

    fun loadCatalog(service: String) {
        _catalogError.value = null
        viewModelScope.launch {
            when (service) {
                "airtime" -> when (val r = repo.getAirtimeCatalog()) {
                    is ApiResult.Success -> _airtimeNetworks.value = (r.data.airtimeVtu ?: emptyMap()).map { (label, info) ->
                        AirtimeNetwork(label.lowercase(), label, info.discountPercent)
                    }
                    is ApiResult.Error -> _catalogError.value = r.message
                }
                "data" -> when (val r = repo.getDataCatalog()) {
                    is ApiResult.Success -> _dataNetworks.value = r.data.mobileNetwork ?: emptyMap()
                    is ApiResult.Error -> _catalogError.value = r.message
                }
                "cable" -> when (val r = repo.getCableCatalog()) {
                    is ApiResult.Success -> _cableProviders.value = r.data.cableSubscription ?: emptyMap()
                    is ApiResult.Error -> _catalogError.value = r.message
                }
                "electricity" -> when (val r = repo.getElectricCatalog()) {
                    is ApiResult.Success -> _electricProviders.value = (r.data.electricPayment ?: emptyMap()).map { (label, info) ->
                        ElectricProvider(label.lowercase(), label, info.discountPercent)
                    }
                    is ApiResult.Error -> _catalogError.value = r.message
                }
                "exam" -> when (val r = repo.getExamCatalog()) {
                    is ApiResult.Success -> _examPlans.value = r.data.examPin ?: emptyMap()
                    is ApiResult.Error -> _catalogError.value = r.message
                }
                "betting" -> when (val r = repo.getBettingCatalog()) {
                    is ApiResult.Success -> _bettingProviders.value = r.data.bettingProviders ?: emptyList()
                    is ApiResult.Error -> _catalogError.value = r.message
                }
            }
        }
    }

    // ---------- Network auto-detect (airtime/data phone fields) ----------

    private val _detectedNetwork = MutableStateFlow<String?>(null)
    val detectedNetwork: StateFlow<String?> = _detectedNetwork.asStateFlow()

    fun detectNetwork(phone: String) {
        if (phone.length != 11) { _detectedNetwork.value = null; return }
        viewModelScope.launch {
            when (val r = repo.identifyNetwork(phone)) {
                is ApiResult.Success -> {
                    val net = r.data.network
                    _detectedNetwork.value = if (net.isNullOrBlank() || net == "Invalid") null else net
                }
                is ApiResult.Error -> _detectedNetwork.value = null
            }
        }
    }

    // ---------- Customer verification (cable/electric/betting) ----------

    sealed class VerifyState {
        object Idle : VerifyState()
        object Loading : VerifyState()
        data class Verified(val name: String, val address: String?) : VerifyState()
        data class Failed(val message: String) : VerifyState()
    }

    private val _verifyState = MutableStateFlow<VerifyState>(VerifyState.Idle)
    val verifyState: StateFlow<VerifyState> = _verifyState.asStateFlow()

    fun resetVerify() { _verifyState.value = VerifyState.Idle }

    fun verifyCustomer(service: String, fields: Map<String, Any?>) {
        _verifyState.value = VerifyState.Loading
        viewModelScope.launch {
            val backendService = if (service == "electricity") "electric" else service
            val body = fields + ("service" to backendService)
            when (val r = repo.verifyCustomer(body)) {
                is ApiResult.Success -> {
                    val d = r.data
                    _verifyState.value = if (d.status == "success") {
                        VerifyState.Verified(d.customerName ?: "Verified customer", d.customerAddress)
                    } else {
                        VerifyState.Failed(d.desc ?: "Unable to verify customer")
                    }
                }
                is ApiResult.Error -> _verifyState.value = VerifyState.Failed(r.message)
            }
        }
    }

    // ---------- Checkout ----------

    sealed class CheckoutState {
        object Idle : CheckoutState()
        object Loading : CheckoutState()
        data class Ready(val reference: String, val checkoutUrl: String, val amount: Double) : CheckoutState()
        data class Failed(val message: String) : CheckoutState()
    }

    private val _checkoutState = MutableStateFlow<CheckoutState>(CheckoutState.Idle)
    val checkoutState: StateFlow<CheckoutState> = _checkoutState.asStateFlow()

    /** Snapshot of what's being purchased, kept for the Receipt screen after payment completes. */
    data class PendingTransaction(val service: String, val recipient: String, val email: String? = null)
    var pendingTransaction: PendingTransaction? = null
        private set

    fun startCheckout(service: String, recipient: String, fields: Map<String, Any?>) {
        _checkoutState.value = CheckoutState.Loading
        pendingTransaction = PendingTransaction(service, recipient, (fields["email"] as? String)?.takeIf { it.isNotBlank() })
        viewModelScope.launch {
            val backendService = if (service == "electricity") "electric" else service
            val body = fields + ("service" to backendService)
            when (val r = repo.initCheckout(body)) {
                is ApiResult.Success -> {
                    val d = r.data
                    _checkoutState.value = if (d.status == "success" && !d.checkoutUrl.isNullOrBlank() && d.reference != null) {
                        CheckoutState.Ready(d.reference, d.checkoutUrl, d.amount ?: 0.0)
                    } else {
                        CheckoutState.Failed(d.desc ?: "Could not start checkout")
                    }
                }
                is ApiResult.Error -> _checkoutState.value = CheckoutState.Failed(r.message)
            }
        }
    }

    fun resetCheckout() {
        _checkoutState.value = CheckoutState.Idle
        // Also drop any stale payment detection — without this, cancelling a checkout after
        // the poll already fired would instantly "complete" the next checkout that opens.
        _paymentDetected.value = null
    }

    // ---------- Checkout payment watcher (poll-driven return-to-app) ----------

    // PayHub's hosted checkout does not reliably honor our callback_url (observed live: it
    // redirects to merchant.payhub.com.ng home instead), so URL-watching alone can leave the
    // guest stranded on PayHub's site after paying. While the checkout WebView is open we ALSO
    // poll status.php — which itself verifies + fulfills server-side — and advance to the
    // Receipt screen the moment the order moves past pending_payment, redirect or no redirect.
    private val _paymentDetected = MutableStateFlow<String?>(null)
    val paymentDetected: StateFlow<String?> = _paymentDetected.asStateFlow()

    fun watchPayment(reference: String) {
        viewModelScope.launch {
            while (checkoutState.value is CheckoutState.Ready && _paymentDetected.value == null) {
                when (val r = repo.getOrderStatus(reference)) {
                    is ApiResult.Success -> {
                        val st = r.data.status
                        if (st != null && st != "pending_payment" && st != "unknown") {
                            _paymentDetected.value = reference
                            return@launch
                        }
                    }
                    is ApiResult.Error -> {} // transient — keep watching
                }
                delay(2000)
            }
        }
    }

    /** Fast path: the WebView spotted a completion URL before the next poll tick. */
    fun notifyPaymentDetected(reference: String) { _paymentDetected.value = reference }

    fun resetPaymentWatch() { _paymentDetected.value = null }

    // ---------- Order status polling (after the WebView reports the PayHub redirect) ----------

    sealed class ReceiptState {
        object Idle : ReceiptState()
        object Polling : ReceiptState()
        data class Success(val order: GuestOrderStatusResponse) : ReceiptState()
        data class Pending(val order: GuestOrderStatusResponse) : ReceiptState()
        data class Failed(val message: String) : ReceiptState()
    }

    private val _receiptState = MutableStateFlow<ReceiptState>(ReceiptState.Idle)
    val receiptState: StateFlow<ReceiptState> = _receiptState.asStateFlow()

    /** Polls status.php until fulfillment settles (success/failed) or ~2 minutes pass.
     *  Unlike the first version, a "pending" answer does NOT stop the poll: Airtime and Direct
     *  Data providers routinely answer pending at purchase and settle within a minute — the
     *  server requeries the provider on each poll (throttled), so we keep listening and show
     *  the Pending receipt in the meantime, upgrading it in place when the true status lands. */
    fun pollOrderStatus(reference: String) {
        _receiptState.value = ReceiptState.Polling
        viewModelScope.launch {
            repeat(60) { _ ->
                when (val r = repo.getOrderStatus(reference)) {
                    is ApiResult.Success -> {
                        val order = r.data
                        when (order.status) {
                            "success" -> { _receiptState.value = ReceiptState.Success(order); return@launch }
                            "failed" -> { _receiptState.value = ReceiptState.Failed(order.desc ?: "Transaction failed"); return@launch }
                            "pending" -> { _receiptState.value = ReceiptState.Pending(order) } // keep polling for the settled status
                            // pending_payment / processing -> keep polling, fulfillment hasn't landed yet
                        }
                    }
                    is ApiResult.Error -> { /* transient — keep polling */ }
                }
                delay(2000)
            }
            if (_receiptState.value is ReceiptState.Polling) {
                _receiptState.value = ReceiptState.Failed("We couldn't confirm your payment yet. Check your email/WhatsApp receipt, or contact support with your reference.")
            }
        }
    }

    /** Re-checks stored history entries still marked pending/processing against the server and
     *  updates the on-device cache to the true settled status — called when Home/History opens
     *  so a transaction that settled after the guest closed the Receipt screen still corrects
     *  itself the next time they look. */
    fun refreshPendingHistory() {
        if (_transactionHistory.value.none { it.status == "pending" || it.status == "processing" }) return
        viewModelScope.launch { refreshPendingHistoryOnce() }
    }

    private suspend fun refreshPendingHistoryOnce() {
        val stale = _transactionHistory.value.filter { it.status == "pending" || it.status == "processing" }
        for (receipt in stale) {
            when (val r = repo.getOrderStatus(receipt.reference)) {
                is ApiResult.Success -> {
                    val st = r.data.status
                    if (st == "success" || st == "failed") {
                        saveReceipt(receipt.copy(status = st, token = r.data.token ?: receipt.token))
                    }
                }
                is ApiResult.Error -> {} // transient — try again next visit
            }
        }
    }

    fun resetReceipt() { _receiptState.value = ReceiptState.Idle }
}
