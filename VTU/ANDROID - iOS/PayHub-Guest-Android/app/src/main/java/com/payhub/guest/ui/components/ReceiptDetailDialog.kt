package com.payhub.guest.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import com.payhub.guest.data.GuestServiceCatalog
import com.payhub.guest.data.model.GuestReceipt
import com.payhub.guest.ui.theme.CError
import com.payhub.guest.ui.theme.CSuccess
import com.payhub.guest.ui.theme.CText
import com.payhub.guest.ui.theme.CText2
import com.payhub.guest.ui.theme.PhPrimary
import com.payhub.guest.util.ReceiptRenderer
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Full receipt details for a history entry, opened by tapping a transaction on the Home
 * "Recent Transactions" list or the History tab. Shares reuse the exact same PDF/PNG renderer
 * as the post-purchase Receipt screen, so a shared history receipt is indistinguishable from
 * one shared right after payment.
 */
@Composable
fun ReceiptDetailDialog(receipt: GuestReceipt, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val entry = GuestServiceCatalog.ALL.find { it.key == receipt.service }
    val (statusLabel, statusColor) = when (receipt.status) {
        "success" -> "Successful" to CSuccess
        "pending", "processing" -> "Pending" to Color(0xFFF59E0B)
        else -> "Failed" to CError
    }

    Dialog(onDismissRequest = onDismiss) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .background(Color.White, RoundedCornerShape(24.dp))
                .padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("Transaction Details", fontWeight = FontWeight.Bold, fontSize = 16.sp, color = CText, modifier = Modifier.weight(1f))
                Icon(
                    Icons.Filled.Close,
                    contentDescription = "Close",
                    tint = CText2,
                    modifier = Modifier.clickable { onDismiss() },
                )
            }

            Icon(
                if (receipt.status == "success") Icons.Filled.CheckCircle else Icons.Filled.Schedule,
                contentDescription = null,
                tint = statusColor,
                modifier = Modifier.padding(top = 16.dp).size(48.dp),
            )
            Text("₦${"%,.0f".format(receipt.amountPaid)}", fontWeight = FontWeight.ExtraBold, fontSize = 24.sp, color = CText, modifier = Modifier.padding(top = 8.dp))
            Box(
                modifier = Modifier
                    .padding(top = 4.dp, bottom = 16.dp)
                    .background(statusColor.copy(alpha = 0.12f), CircleShape)
                    .padding(horizontal = 10.dp, vertical = 4.dp),
            ) {
                Text(statusLabel, fontSize = 11.sp, fontWeight = FontWeight.SemiBold, color = statusColor)
            }

            DetailRow("Service", entry?.title ?: receipt.service.replaceFirstChar(Char::uppercase))
            DetailRow("Recipient", receipt.recipient.ifBlank { "—" })
            DetailRow("Reference", receipt.reference)
            receipt.token?.let { DetailRow("Token", it) }
            receipt.tokenUnit?.let { DetailRow("Units", it) }
            DetailRow("Date", SimpleDateFormat("dd MMM yyyy, hh:mm a", Locale.getDefault()).format(Date(receipt.dateMillis)))
            DetailRow("Payment Method", "PayHub Checkout")

            Row(modifier = Modifier.fillMaxWidth().padding(top = 20.dp)) {
                ShareButton("Share PDF", Modifier.weight(1f)) {
                    val uri = ReceiptRenderer.savePdfToCache(context, receipt)
                    ReceiptRenderer.shareUri(context, uri, "application/pdf")
                }
                Spacer(Modifier.padding(start = 8.dp))
                ShareButton("Share Image", Modifier.weight(1f), bg = PhPrimary, fg = Color.White) {
                    val uri = ReceiptRenderer.saveBitmapToCache(context, receipt)
                    ReceiptRenderer.shareUri(context, uri, "image/png")
                }
            }
        }
    }
}

@Composable
private fun DetailRow(label: String, value: String) {
    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 5.dp)) {
        Text(label, color = CText2, fontSize = 13.sp, modifier = Modifier.weight(1f))
        Text(value, color = CText, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
    }
}

@Composable
private fun ShareButton(
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
