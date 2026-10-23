package com.payhub.guest.ui.receipt

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.payhub.guest.data.model.GuestOrderStatusResponse
import com.payhub.guest.data.model.GuestReceipt
import com.payhub.guest.ui.GuestViewModel
import com.payhub.guest.ui.theme.CError
import com.payhub.guest.ui.theme.CSuccess
import com.payhub.guest.ui.theme.CSuccessBg
import com.payhub.guest.ui.theme.CText
import com.payhub.guest.ui.theme.CText2
import com.payhub.guest.ui.theme.PhPrimary
import com.payhub.guest.util.ReceiptRenderer

@Composable
fun ReceiptScreen(reference: String, viewModel: GuestViewModel, onDone: () -> Unit) {
    val receiptState by viewModel.receiptState.collectAsState()

    LaunchedEffect(reference) { viewModel.pollOrderStatus(reference) }

    Column(modifier = Modifier.fillMaxSize().padding(20.dp).verticalScroll(rememberScrollState())) {
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(vertical = 16.dp)) {
            Icon(
                Icons.Filled.Close,
                contentDescription = "Close",
                modifier = Modifier.clickable { onDone() }.padding(end = 12.dp),
            )
            Text("Receipt", fontWeight = FontWeight.Bold, fontSize = 18.sp)
        }

        when (val s = receiptState) {
            is GuestViewModel.ReceiptState.Polling, GuestViewModel.ReceiptState.Idle -> {
                Column(
                    modifier = Modifier.fillMaxWidth().padding(vertical = 60.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    CircularProgressIndicator(color = PhPrimary)
                    Text("Confirming your payment…", fontWeight = FontWeight.Bold, color = CText, modifier = Modifier.padding(top = 16.dp))
                    Text("This usually takes a few seconds.", fontSize = 12.sp, color = CText2, modifier = Modifier.padding(top = 4.dp))
                }
            }
            is GuestViewModel.ReceiptState.Success -> ReceiptCard(s.order, viewModel, "success", onDone)
            is GuestViewModel.ReceiptState.Pending -> ReceiptCard(s.order, viewModel, "pending", onDone)
            is GuestViewModel.ReceiptState.Failed -> {
                Column(
                    modifier = Modifier.fillMaxWidth().padding(vertical = 40.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Icon(Icons.Filled.Close, contentDescription = null, tint = CError, modifier = Modifier.size(48.dp))
                    Text("We couldn't confirm this payment", fontWeight = FontWeight.Bold, color = CText, modifier = Modifier.padding(top = 12.dp))
                    Text(s.message, fontSize = 13.sp, color = CText2, modifier = Modifier.padding(top = 6.dp))
                    Button(onClick = onDone, colors = ButtonDefaults.buttonColors(containerColor = PhPrimary), modifier = Modifier.padding(top = 24.dp)) {
                        Text("Back to Home")
                    }
                }
            }
        }
    }
}

@Composable
private fun ReceiptCard(order: GuestOrderStatusResponse, viewModel: GuestViewModel, statusLabel: String, onDone: () -> Unit) {
    val context = LocalContext.current
    val pending = viewModel.pendingTransaction
    val receipt = remember(order) {
        GuestReceipt(
            reference = order.ref ?: "",
            service = pending?.service ?: order.service ?: "",
            recipient = pending?.recipient ?: "",
            amountPaid = order.amount?.toDoubleOrNull() ?: 0.0,
            status = statusLabel,
            dateMillis = System.currentTimeMillis(),
            meterNumber = order.meterNumber,
            token = order.token,
            tokenUnit = order.tokenUnit,
        )
    }
    // Keyed on status too: the poll now upgrades Pending -> Success in place, and the history
    // cache must reflect the final settled status, not the first one shown.
    LaunchedEffect(receipt.reference, receipt.status) { viewModel.saveReceipt(receipt) }

    var showEmailForm by remember { mutableStateOf(false) }
    var email by remember { mutableStateOf("") }

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .background(Color.White, RoundedCornerShape(24.dp))
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = if (statusLabel == "success") CSuccess else PhPrimary, modifier = Modifier.size(56.dp))
        Text("₦${"%,.0f".format(receipt.amountPaid)}", fontWeight = FontWeight.ExtraBold, fontSize = 26.sp, modifier = Modifier.padding(top = 12.dp))
        Text(if (statusLabel == "success") "Payment Successful" else "Payment Pending", color = CText2)
        pending?.email?.let { guestEmail ->
            if (statusLabel == "success") {
                Text(
                    "📧 A copy of your receipt has been sent to $guestEmail",
                    fontSize = 11.sp,
                    color = CSuccess,
                    modifier = Modifier.padding(top = 4.dp),
                )
            }
        }
        androidx.compose.foundation.layout.Spacer(Modifier.padding(bottom = 16.dp))

        ReceiptRow("Reference", receipt.reference)
        ReceiptRow("Service", receipt.service.replaceFirstChar(Char::uppercase))
        ReceiptRow("Recipient", receipt.recipient)
        receipt.token?.let { ReceiptRow("Token", it) }
        ReceiptRow("Amount Paid", "₦${"%,.0f".format(receipt.amountPaid)}")
        ReceiptRow("Payment Method", "PayHub Checkout")
    }

    Row(modifier = Modifier.fillMaxWidth().padding(top = 16.dp)) {
        ReceiptActionButton("Download PDF", Modifier.weight(1f)) {
            val uri = ReceiptRenderer.savePdfToCache(context, receipt)
            ReceiptRenderer.shareUri(context, uri, "application/pdf")
        }
        androidx.compose.foundation.layout.Spacer(Modifier.padding(start = 8.dp))
        ReceiptActionButton("Save Image", Modifier.weight(1f)) {
            val uri = ReceiptRenderer.saveBitmapToCache(context, receipt)
            ReceiptRenderer.shareUri(context, uri, "image/png")
        }
    }
    Row(modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) {
        ReceiptActionButton("Email Receipt", Modifier.weight(1f)) { showEmailForm = !showEmailForm }
        androidx.compose.foundation.layout.Spacer(Modifier.padding(start = 8.dp))
        ReceiptActionButton("WhatsApp", Modifier.weight(1f), bg = Color(0xFF25D366), fg = Color.White) {
            val uri = ReceiptRenderer.saveBitmapToCache(context, receipt)
            ReceiptRenderer.shareUri(context, uri, "image/png", targetPackage = "com.whatsapp")
        }
    }

    if (showEmailForm) {
        Row(modifier = Modifier.fillMaxWidth().padding(top = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(
                value = email, onValueChange = { email = it },
                placeholder = { Text("Enter your email") },
                keyboardOptions = KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Email),
                modifier = Modifier.weight(1f), singleLine = true,
            )
            androidx.compose.foundation.layout.Spacer(Modifier.padding(start = 8.dp))
            Button(onClick = {
                if (email.isNotBlank()) {
                    val uri = ReceiptRenderer.savePdfToCache(context, receipt)
                    ReceiptRenderer.emailReceipt(context, uri, receipt, email)
                }
            }, colors = ButtonDefaults.buttonColors(containerColor = PhPrimary)) {
                Text("Send")
            }
        }
    }

    Button(
        onClick = onDone,
        colors = ButtonDefaults.buttonColors(containerColor = PhPrimary),
        modifier = Modifier.fillMaxWidth().padding(top = 20.dp),
    ) {
        Text("Make Another Payment", fontWeight = FontWeight.Bold, modifier = Modifier.padding(vertical = 6.dp))
    }
}

@Composable
private fun ReceiptRow(label: String, value: String) {
    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
        Text(label, color = CText2, fontSize = 13.sp, modifier = Modifier.weight(1f))
        Text(value, color = CText, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
    }
}

@Composable
private fun ReceiptActionButton(
    label: String,
    modifier: Modifier = Modifier,
    bg: Color = Color(0xFFF1F5F9),
    fg: Color = CText,
    onClick: () -> Unit,
) {
    Column(
        modifier = modifier
            .background(bg, RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(vertical = 12.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(label, color = fg, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
    }
}
