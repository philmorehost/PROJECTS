package com.payhub.guest.ui.history

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Receipt
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.payhub.guest.data.GuestServiceCatalog
import com.payhub.guest.data.model.GuestReceipt
import com.payhub.guest.ui.components.PullToRefreshWrapper
import com.payhub.guest.ui.components.ReceiptDetailDialog
import com.payhub.guest.ui.theme.CError
import com.payhub.guest.ui.theme.CSuccess
import com.payhub.guest.ui.theme.CText
import com.payhub.guest.ui.theme.CText2
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Guest Mode has no server-side transaction history (no login means nothing to fetch by user) —
 * this list is the on-device cache written by ReceiptScreen (see GuestHistoryStore), not a fetch
 * from the backend. Still an empty state when the guest hasn't completed a purchase yet.
 * Tapping an entry opens the full ReceiptDetailDialog with share-as-PDF/image.
 */
@Composable
fun HistoryScreen(history: List<GuestReceipt> = emptyList(), isRefreshing: Boolean = false, onRefresh: () -> Unit = {}) {
    var selected by remember { mutableStateOf<GuestReceipt?>(null) }
    selected?.let { ReceiptDetailDialog(receipt = it, onDismiss = { selected = null }) }

    PullToRefreshWrapper(isRefreshing = isRefreshing, onRefresh = onRefresh) {
        if (history.isEmpty()) {
            Column(
                modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 32.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center,
            ) {
                Text("Transaction History", fontWeight = FontWeight.Bold, fontSize = 20.sp, modifier = Modifier.padding(bottom = 40.dp))
                Icon(Icons.Filled.Receipt, contentDescription = null, tint = CText2, modifier = Modifier.padding(bottom = 12.dp))
                Text("No saved history", fontWeight = FontWeight.Bold, color = CText, modifier = Modifier.padding(bottom = 6.dp))
                Text(
                    "Your completed purchases will show up here, saved on this device only.",
                    fontSize = 13.sp,
                    color = CText2,
                    textAlign = TextAlign.Center,
                )
            }
        } else {
            Column(modifier = Modifier.fillMaxSize().padding(horizontal = 20.dp)) {
                Text("Transaction History", fontWeight = FontWeight.Bold, fontSize = 20.sp, modifier = Modifier.padding(vertical = 20.dp))
                LazyColumn(
                    contentPadding = PaddingValues(bottom = 100.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    items(history, key = { it.reference }) { receipt ->
                        HistoryRow(receipt) { selected = receipt }
                    }
                }
            }
        }
    }
}

@Composable
private fun HistoryRow(receipt: GuestReceipt, onClick: () -> Unit) {
    val entry = GuestServiceCatalog.ALL.find { it.key == receipt.service }
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(Color.White, RoundedCornerShape(16.dp))
            .clickable(onClick = onClick)
            .padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier.size(44.dp).background(entry?.bg ?: CText2.copy(alpha = 0.12f), RoundedCornerShape(13.dp)),
            contentAlignment = Alignment.Center,
        ) {
            entry?.let { Icon(it.icon, contentDescription = null, tint = it.color) }
        }
        Column(modifier = Modifier.weight(1f).padding(start = 12.dp)) {
            Text(entry?.title ?: receipt.service.replaceFirstChar(Char::uppercase), fontWeight = FontWeight.SemiBold, fontSize = 14.sp, color = CText)
            Text(receipt.recipient.ifBlank { receipt.reference }, fontSize = 12.sp, color = CText2, modifier = Modifier.padding(top = 2.dp))
            Text(formatDate(receipt.dateMillis), fontSize = 11.sp, color = CText2, modifier = Modifier.padding(top = 2.dp))
        }
        Column(horizontalAlignment = Alignment.End) {
            Text("₦${"%,.0f".format(receipt.amountPaid)}", fontWeight = FontWeight.Bold, fontSize = 14.sp, color = CText)
            StatusBadge(receipt.status)
        }
    }
}

@Composable
private fun StatusBadge(status: String) {
    val (label, color) = when (status) {
        "success" -> "Successful" to CSuccess
        "pending", "processing" -> "Pending" to Color(0xFFF59E0B)
        else -> "Failed" to CError
    }
    Box(
        modifier = Modifier
            .padding(top = 4.dp)
            .background(color.copy(alpha = 0.12f), CircleShape)
            .padding(horizontal = 8.dp, vertical = 3.dp),
    ) {
        Text(label, fontSize = 10.sp, fontWeight = FontWeight.SemiBold, color = color)
    }
}

private fun formatDate(millis: Long): String =
    SimpleDateFormat("dd MMM yyyy, hh:mm a", Locale.getDefault()).format(Date(millis))
