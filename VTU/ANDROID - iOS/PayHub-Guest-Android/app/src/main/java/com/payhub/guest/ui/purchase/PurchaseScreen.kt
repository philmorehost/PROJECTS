package com.payhub.guest.ui.purchase

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.payhub.guest.ui.GuestViewModel
import com.payhub.guest.ui.theme.CError
import com.payhub.guest.ui.theme.CSuccess
import com.payhub.guest.ui.theme.CSuccessBg
import com.payhub.guest.ui.theme.CText
import com.payhub.guest.ui.theme.CText2
import com.payhub.guest.ui.theme.PhPrimary
import com.payhub.guest.ui.theme.PhPrimaryDark

private data class ServiceMeta(val title: String, val subtitle: String)

private val SERVICE_META = mapOf(
    "airtime" to ServiceMeta("Buy Airtime", "Top up any network instantly"),
    "data" to ServiceMeta("Buy Data", "SME, Shared, CG & Direct bundles"),
    "cable" to ServiceMeta("Cable TV", "DStv, GOtv, Startimes & Showmax"),
    "electricity" to ServiceMeta("Electricity", "Prepaid & postpaid tokens"),
    "exam" to ServiceMeta("Exam Pins", "WAEC, NECO, NABTEB & JAMB"),
    "betting" to ServiceMeta("Betting", "Fund your betting account"),
)

@Composable
fun PurchaseScreen(service: String, viewModel: GuestViewModel, onBack: () -> Unit, onCheckoutReady: () -> Unit) {
    LaunchedEffect(service) {
        viewModel.loadCatalog(service)
        viewModel.resetVerify()
        viewModel.resetCheckout()
    }
    val checkoutState by viewModel.checkoutState.collectAsState()
    LaunchedEffect(checkoutState) {
        if (checkoutState is GuestViewModel.CheckoutState.Ready) onCheckoutReady()
    }
    val meta = SERVICE_META[service] ?: ServiceMeta(service.replaceFirstChar { it.uppercase() }, "")

    Column(modifier = Modifier.fillMaxSize()) {
        // Banner
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .background(androidx.compose.ui.graphics.Brush.linearGradient(listOf(PhPrimary, PhPrimaryDark)))
                .padding(20.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                modifier = Modifier.size(36.dp).background(Color.White.copy(alpha = 0.15f), CircleShape).clickable { onBack() },
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Filled.ArrowBack, contentDescription = "Back", tint = Color.White)
            }
            Column(modifier = Modifier.padding(start = 14.dp)) {
                Text(meta.title, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 17.sp)
                Text(meta.subtitle, color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp)
            }
        }

        Column(
            modifier = Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp)
        ) {
            val catalogError by viewModel.catalogError.collectAsState()
            if (catalogError != null) {
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(top = 16.dp)
                        .background(CError.copy(alpha = 0.1f), RoundedCornerShape(14.dp))
                        .padding(14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        catalogError ?: "Couldn't load plans. Please try again.",
                        color = CError,
                        fontSize = 12.sp,
                        modifier = Modifier.weight(1f),
                    )
                    Text(
                        "Retry",
                        color = CError,
                        fontWeight = FontWeight.Bold,
                        fontSize = 12.sp,
                        modifier = Modifier
                            .clickable { viewModel.loadCatalog(service) }
                            .padding(start = 12.dp),
                    )
                }
            }
            when (service) {
                "airtime" -> AirtimeForm(viewModel)
                "data" -> DataForm(viewModel)
                "cable" -> CableForm(viewModel)
                "electricity" -> ElectricityForm(viewModel)
                "exam" -> ExamForm(viewModel)
                "betting" -> BettingForm(viewModel)
            }

            if (checkoutState is GuestViewModel.CheckoutState.Failed) {
                Text(
                    (checkoutState as GuestViewModel.CheckoutState.Failed).message,
                    color = CError,
                    fontSize = 12.sp,
                    modifier = Modifier.padding(top = 12.dp),
                )
            }
            androidx.compose.foundation.layout.Spacer(Modifier.padding(bottom = 24.dp))
        }
    }
}

@Composable
private fun EmailReceiptField(email: String, onChange: (String) -> Unit) {
    FieldLabel("Email (optional — we'll send your receipt here)")
    OutlinedTextField(
        value = email, onValueChange = onChange,
        placeholder = { Text("you@example.com") },
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
        modifier = Modifier.fillMaxWidth(), singleLine = true,
    )
}

@Composable
private fun PayButton(amount: Int, enabled: Boolean, loading: Boolean, onClick: () -> Unit) {
    Button(
        onClick = onClick,
        enabled = enabled && !loading,
        colors = ButtonDefaults.buttonColors(containerColor = PhPrimary),
        shape = RoundedCornerShape(16.dp),
        modifier = Modifier.fillMaxWidth().padding(top = 20.dp),
    ) {
        Text(if (loading) "Please wait…" else "Pay ₦$amount", fontWeight = FontWeight.Bold, modifier = Modifier.padding(vertical = 6.dp))
    }
}

@Composable
private fun VerifiedCard(name: String, sub: String?) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(CSuccessBg, RoundedCornerShape(14.dp))
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(modifier = Modifier.size(36.dp).background(CSuccess, CircleShape))
        Column(modifier = Modifier.padding(start = 10.dp)) {
            Text(name, fontWeight = FontWeight.Bold, fontSize = 13.sp, color = CText)
            Text(sub ?: "Verified customer", fontSize = 11.sp, color = CText2)
        }
    }
}

// ---------------------------------------------------------------------------------------
// Airtime
// ---------------------------------------------------------------------------------------

@Composable
private fun AirtimeForm(vm: GuestViewModel) {
    val networks by vm.airtimeNetworks.collectAsState()
    val detected by vm.detectedNetwork.collectAsState()
    val checkoutState by vm.checkoutState.collectAsState()

    var network by remember { mutableStateOf<String?>(null) }
    var userPicked by remember { mutableStateOf(false) }
    var phone by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }

    LaunchedEffect(phone) { if (phone.length == 11) vm.detectNetwork(phone) }
    LaunchedEffect(detected) {
        if (!userPicked && detected != null) network = detected!!.lowercase()
    }

    FieldLabel("Select Network")
    ChipRow(networks.map { it.code }, labelOf = { it.uppercase() }, selected = network) { network = it; userPicked = true }

    FieldLabel("Phone Number")
    OutlinedTextField(
        value = phone, onValueChange = { phone = it.filter { c -> c.isDigit() }.take(11) },
        placeholder = { Text("080X XXX XXXX") },
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
        modifier = Modifier.fillMaxWidth(), singleLine = true,
    )

    AmountField("Amount", listOf(100, 200, 500, 1000), amount) { amount = it }

    EmailReceiptField(email) { email = it }

    val amt = amount.toIntOrNull() ?: 0
    val ready = network != null && phone.length == 11 && amt > 0
    PayButton(amt, ready, checkoutState is GuestViewModel.CheckoutState.Loading) {
        vm.startCheckout(
            service = "airtime",
            recipient = phone,
            fields = mapOf("network" to network, "phone_number" to phone, "amount" to amt, "email" to email.ifBlank { null }),
        )
    }
}

// ---------------------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------------------

@Composable
private fun DataForm(vm: GuestViewModel) {
    val catalog by vm.dataNetworks.collectAsState()
    val detected by vm.detectedNetwork.collectAsState()
    val checkoutState by vm.checkoutState.collectAsState()

    var network by remember { mutableStateOf<String?>(null) }
    var userPicked by remember { mutableStateOf(false) }
    var phone by remember { mutableStateOf("") }
    var dataType by remember { mutableStateOf<String?>(null) }
    var plan by remember { mutableStateOf<com.payhub.guest.data.model.DataPlan?>(null) }

    LaunchedEffect(phone) { if (phone.length == 11) vm.detectNetwork(phone) }
    LaunchedEffect(detected) {
        if (!userPicked && detected != null) network = detected!!.uppercase()
    }

    val networkPlans = catalog[network].orEmpty()
    val dataTypes = networkPlans.map { it.dataTypeCode }.distinct()
    val filteredPlans = networkPlans.filter { it.dataTypeCode == dataType }

    FieldLabel("Select Network")
    ChipRow(catalog.keys.toList(), labelOf = { it }, selected = network) { network = it; userPicked = true; dataType = null; plan = null }

    FieldLabel("Phone Number")
    OutlinedTextField(
        value = phone, onValueChange = { phone = it.filter { c -> c.isDigit() }.take(11) },
        placeholder = { Text("080X XXX XXXX") },
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
        modifier = Modifier.fillMaxWidth(), singleLine = true,
    )

    FieldLabel("Data Type")
    ChipRow(dataTypes, labelOf = { it.replace("-", " ").replaceFirstChar(Char::uppercase) }, selected = dataType) { dataType = it; plan = null }

    SimpleDropdown(
        label = "Choose a Plan",
        options = filteredPlans,
        labelOf = { "${it.productName} — ₦${it.amount} (${it.duration})" },
        selected = plan,
        placeholder = if (dataType == null) "Select a data type first" else "Select a plan",
        onSelect = { plan = it },
    )

    var email by remember { mutableStateOf("") }
    EmailReceiptField(email) { email = it }

    val amt = plan?.amount?.toDoubleOrNull()?.toInt() ?: 0
    val ready = network != null && phone.length == 11 && plan != null
    PayButton(amt, ready, checkoutState is GuestViewModel.CheckoutState.Loading) {
        vm.startCheckout(
            service = "data",
            recipient = phone,
            fields = mapOf(
                "network" to network?.lowercase(),
                "phone_number" to phone,
                "type" to plan?.dataTypeCode,
                "quantity" to plan?.productCode,
                "email" to email.ifBlank { null },
            ),
        )
    }
}

// ---------------------------------------------------------------------------------------
// Cable
// ---------------------------------------------------------------------------------------

@Composable
private fun CableForm(vm: GuestViewModel) {
    val catalog by vm.cableProviders.collectAsState()
    val verifyState by vm.verifyState.collectAsState()
    val checkoutState by vm.checkoutState.collectAsState()

    var provider by remember { mutableStateOf<String?>(null) }
    var iucNumber by remember { mutableStateOf("") }
    var pkg by remember { mutableStateOf<com.payhub.guest.data.model.CablePlan?>(null) }

    val plans = catalog[provider].orEmpty()

    FieldLabel("Select Provider")
    ChipRow(catalog.keys.toList(), labelOf = { it }, selected = provider) { provider = it; pkg = null; vm.resetVerify() }

    FieldLabel("Smartcard / IUC Number")
    VerifyRow(
        value = iucNumber,
        onValueChange = { iucNumber = it.filter { c -> c.isDigit() }; vm.resetVerify() },
        placeholder = "e.g. 1234567890",
        verifying = verifyState is GuestViewModel.VerifyState.Loading,
        onVerify = {
            if (provider != null && iucNumber.isNotBlank() && pkg != null) {
                vm.verifyCustomer("cable", mapOf("type" to provider!!.lowercase(), "iuc_number" to iucNumber, "package" to pkg!!.packageName))
            }
        },
    )
    when (val vs = verifyState) {
        is GuestViewModel.VerifyState.Verified -> VerifiedCard(vs.name, vs.address)
        is GuestViewModel.VerifyState.Failed -> Text(vs.message, color = CError, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
        else -> {}
    }

    SimpleDropdown(
        label = "Choose a Package",
        options = plans,
        labelOf = { "${it.packageName} — ₦${it.amount}" },
        selected = pkg,
        placeholder = if (provider == null) "Select a provider first" else "Select a package",
        onSelect = { pkg = it; vm.resetVerify() },
    )

    var email by remember { mutableStateOf("") }
    EmailReceiptField(email) { email = it }

    val amt = pkg?.amount?.toDoubleOrNull()?.toInt() ?: 0
    val ready = provider != null && iucNumber.isNotBlank() && pkg != null
    PayButton(amt, ready, checkoutState is GuestViewModel.CheckoutState.Loading) {
        vm.startCheckout(
            service = "cable",
            recipient = iucNumber,
            fields = mapOf("type" to provider?.lowercase(), "iuc_number" to iucNumber, "package" to pkg?.packageName, "email" to email.ifBlank { null }),
        )
    }
}

// ---------------------------------------------------------------------------------------
// Electricity
// ---------------------------------------------------------------------------------------

@Composable
private fun ElectricityForm(vm: GuestViewModel) {
    val providers by vm.electricProviders.collectAsState()
    val verifyState by vm.verifyState.collectAsState()
    val checkoutState by vm.checkoutState.collectAsState()

    var disco by remember { mutableStateOf<GuestViewModel.ElectricProvider?>(null) }
    var meterType by remember { mutableStateOf<String?>(null) }
    var meterNumber by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }

    SimpleDropdown(
        label = "Select Disco",
        options = providers,
        labelOf = { it.label },
        selected = disco,
        placeholder = "Choose your electricity provider",
        onSelect = { disco = it; vm.resetVerify() },
    )

    FieldLabel("Meter Type")
    ChipRow(listOf("prepaid", "postpaid"), labelOf = { it.replaceFirstChar(Char::uppercase) }, selected = meterType) { meterType = it }

    FieldLabel("Meter Number")
    VerifyRow(
        value = meterNumber,
        onValueChange = { meterNumber = it.filter { c -> c.isDigit() }; vm.resetVerify() },
        placeholder = "e.g. 04512378965",
        verifying = verifyState is GuestViewModel.VerifyState.Loading,
        onVerify = {
            if (disco != null && meterType != null && meterNumber.isNotBlank()) {
                vm.verifyCustomer("electricity", mapOf("provider" to disco!!.code, "type" to meterType, "meter_number" to meterNumber))
            }
        },
    )
    when (val vs = verifyState) {
        is GuestViewModel.VerifyState.Verified -> VerifiedCard(vs.name, vs.address)
        is GuestViewModel.VerifyState.Failed -> Text(vs.message, color = CError, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
        else -> {}
    }

    AmountField("Amount", listOf(1000, 2000, 5000, 10000), amount) { amount = it }

    var email by remember { mutableStateOf("") }
    EmailReceiptField(email) { email = it }

    val amt = amount.toIntOrNull() ?: 0
    val ready = disco != null && meterType != null && meterNumber.isNotBlank() && amt > 0
    PayButton(amt, ready, checkoutState is GuestViewModel.CheckoutState.Loading) {
        vm.startCheckout(
            service = "electricity",
            recipient = meterNumber,
            fields = mapOf("provider" to disco?.code, "type" to meterType, "meter_number" to meterNumber, "amount" to amt, "email" to email.ifBlank { null }),
        )
    }
}

// ---------------------------------------------------------------------------------------
// Exam
// ---------------------------------------------------------------------------------------

@Composable
private fun ExamForm(vm: GuestViewModel) {
    val catalog by vm.examPlans.collectAsState()
    val checkoutState by vm.checkoutState.collectAsState()

    var body by remember { mutableStateOf<String?>(null) }
    var plan by remember { mutableStateOf<com.payhub.guest.data.model.ExamPlan?>(null) }
    var email by remember { mutableStateOf("") }

    val plans = catalog[body].orEmpty()

    FieldLabel("Exam Body")
    ChipRow(catalog.keys.toList(), labelOf = { it }, selected = body) { body = it; plan = null }

    SimpleDropdown(
        label = "Exam Type",
        options = plans,
        labelOf = { "${it.examType.replace("_", " ").replaceFirstChar(Char::uppercase)} — ₦${it.amount}" },
        selected = plan,
        placeholder = if (body == null) "Select an exam body first" else "Select an exam type",
        onSelect = { plan = it },
    )

    FieldLabel("Email (optional — we'll send your PIN here too)")
    OutlinedTextField(
        value = email, onValueChange = { email = it },
        placeholder = { Text("you@example.com") },
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
        modifier = Modifier.fillMaxWidth(), singleLine = true,
    )

    val amt = plan?.amount?.toDoubleOrNull()?.toInt() ?: 0
    val ready = body != null && plan != null
    PayButton(amt, ready, checkoutState is GuestViewModel.CheckoutState.Loading) {
        vm.startCheckout(
            service = "exam",
            recipient = body ?: "",
            fields = mapOf("type" to body?.lowercase(), "quantity" to plan?.examType, "email" to email.ifBlank { null }),
        )
    }
}

// ---------------------------------------------------------------------------------------
// Betting
// ---------------------------------------------------------------------------------------

@Composable
private fun BettingForm(vm: GuestViewModel) {
    val providers by vm.bettingProviders.collectAsState()
    val verifyState by vm.verifyState.collectAsState()
    val checkoutState by vm.checkoutState.collectAsState()

    var provider by remember { mutableStateOf<com.payhub.guest.data.model.BettingProvider?>(null) }
    var customerId by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }

    SimpleDropdown(
        label = "Select Bookmaker",
        options = providers,
        labelOf = { it.providerName },
        selected = provider,
        placeholder = "Choose your bookmaker",
        onSelect = { provider = it; vm.resetVerify() },
    )

    FieldLabel("Customer / Account ID")
    VerifyRow(
        value = customerId,
        onValueChange = { customerId = it.filter { c -> c.isDigit() }; vm.resetVerify() },
        placeholder = "e.g. 1234567890",
        verifying = verifyState is GuestViewModel.VerifyState.Loading,
        onVerify = {
            if (provider != null && customerId.isNotBlank()) {
                vm.verifyCustomer("betting", mapOf("provider" to provider!!.providerCode, "customer_id" to customerId))
            }
        },
    )
    when (val vs = verifyState) {
        is GuestViewModel.VerifyState.Verified -> VerifiedCard(vs.name, vs.address)
        is GuestViewModel.VerifyState.Failed -> Text(vs.message, color = CError, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
        else -> {}
    }

    AmountField("Amount", listOf(500, 1000, 2000, 5000), amount) { amount = it }

    var email by remember { mutableStateOf("") }
    EmailReceiptField(email) { email = it }

    val amt = amount.toIntOrNull() ?: 0
    val ready = provider != null && customerId.isNotBlank() && amt > 0
    PayButton(amt, ready, checkoutState is GuestViewModel.CheckoutState.Loading) {
        vm.startCheckout(
            service = "betting",
            recipient = customerId,
            fields = mapOf("provider" to provider?.providerCode, "customer_id" to customerId, "amount" to amt, "email" to email.ifBlank { null }),
        )
    }
}
