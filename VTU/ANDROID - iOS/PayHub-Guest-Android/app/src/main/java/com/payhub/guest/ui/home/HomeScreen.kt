package com.payhub.guest.ui.home

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountBalanceWallet
import androidx.compose.material.icons.filled.History
import androidx.compose.material.icons.filled.NotificationsNone
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.payhub.guest.data.GuestServiceCatalog
import com.payhub.guest.data.model.GuestReceipt
import com.payhub.guest.ui.components.PullToRefreshWrapper
import com.payhub.guest.ui.theme.CError
import com.payhub.guest.ui.theme.CSuccess
import com.payhub.guest.ui.theme.CText
import com.payhub.guest.ui.theme.CText2
import com.payhub.guest.ui.theme.PhPrimary
import com.payhub.guest.ui.theme.PhPrimaryDark
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

data class QuickAction(val label: String, val icon: ImageVector, val color: Color, val onClick: () -> Unit)

@Composable
fun HomeScreen(
    enabledServices: Map<String, Int>,
    recentTransactions: List<GuestReceipt> = emptyList(),
    isRefreshing: Boolean = false,
    onRefresh: () -> Unit = {},
    onOpenService: (String) -> Unit,
    onOpenHistory: () -> Unit,
) {
    val actions = GuestServiceCatalog.filterEnabled(enabledServices).map { s ->
        QuickAction(s.shortLabel, s.icon, s.color) { onOpenService(s.key) }
    } + QuickAction("History", Icons.Filled.History, PhPrimary) { onOpenHistory() }

    var selected by remember { mutableStateOf<GuestReceipt?>(null) }
    selected?.let { com.payhub.guest.ui.components.ReceiptDetailDialog(receipt = it, onDismiss = { selected = null }) }

    PullToRefreshWrapper(isRefreshing = isRefreshing, onRefresh = onRefresh) {
    Column(modifier = Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp)) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(top = 24.dp, bottom = 16.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(
                    modifier = Modifier.size(36.dp).background(PhPrimary, RoundedCornerShape(11.dp)),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Filled.AccountBalanceWallet, contentDescription = null, tint = Color.White, modifier = Modifier.size(18.dp))
                }
                Text("PayHub", fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, modifier = Modifier.padding(start = 10.dp))
            }
            Box(
                modifier = Modifier.size(40.dp).background(Color.White, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Filled.NotificationsNone, contentDescription = "Notifications", tint = CText2)
            }
        }

        // Hero
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .background(Brush.linearGradient(listOf(PhPrimary, PhPrimaryDark)), RoundedCornerShape(24.dp))
                .padding(20.dp)
        ) {
            Column {
                Text(
                    "Buy Airtime, Data & Bills — Instantly",
                    color = Color.White,
                    fontWeight = FontWeight.Bold,
                    fontSize = 18.sp,
                )
                Text(
                    "Pay once, get instant delivery. No sign up, no wallet needed.",
                    color = Color.White.copy(alpha = 0.85f),
                    fontSize = 12.sp,
                    modifier = Modifier.padding(top = 6.dp, bottom = 12.dp),
                )
                Row {
                    TrustChip("⚡ Instant Delivery")
                    androidx.compose.foundation.layout.Spacer(Modifier.padding(start = 6.dp))
                    TrustChip("🛡 Secured by PayHub")
                }
            }
        }

        androidx.compose.foundation.layout.Spacer(Modifier.padding(top = 20.dp))

        LazyVerticalGrid(
            columns = GridCells.Fixed(4),
            contentPadding = PaddingValues(vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
            modifier = Modifier.fillMaxWidth().size(240.dp),
        ) {
            items(actions) { action ->
                Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.clickable(onClick = action.onClick)) {
                    Box(
                        modifier = Modifier.size(52.dp).background(action.color, RoundedCornerShape(16.dp)),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(action.icon, contentDescription = action.label, tint = Color.White)
                    }
                    Text(action.label, fontSize = 11.sp, fontWeight = FontWeight.SemiBold, color = CText, modifier = Modifier.padding(top = 6.dp))
                }
            }
        }

        if (recentTransactions.isNotEmpty()) {
            androidx.compose.foundation.layout.Spacer(Modifier.padding(top = 12.dp))
            Row(
                modifier = Modifier.fillMaxWidth().padding(bottom = 10.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text("Recent Transactions", fontWeight = FontWeight.Bold, fontSize = 15.sp, color = CText)
                Text(
                    "See All",
                    fontSize = 12.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = PhPrimary,
                    modifier = Modifier.clickable { onOpenHistory() },
                )
            }
            Column(verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(bottom = 24.dp)) {
                recentTransactions.take(3).forEach { receipt -> RecentTransactionRow(receipt) { selected = receipt } }
            }
        }
    }
    }
}

@Composable
private fun RecentTransactionRow(receipt: GuestReceipt, onClick: () -> Unit) {
    val entry = GuestServiceCatalog.ALL.find { it.key == receipt.service }
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(Color.White, RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier.size(38.dp).background(entry?.bg ?: CText2.copy(alpha = 0.12f), RoundedCornerShape(11.dp)),
            contentAlignment = Alignment.Center,
        ) {
            entry?.let { Icon(it.icon, contentDescription = null, tint = it.color, modifier = Modifier.size(18.dp)) }
        }
        Column(modifier = Modifier.weight(1f).padding(start = 10.dp)) {
            Text(entry?.title ?: receipt.service.replaceFirstChar(Char::uppercase), fontWeight = FontWeight.SemiBold, fontSize = 13.sp, color = CText)
            Text(
                SimpleDateFormat("dd MMM, hh:mm a", Locale.getDefault()).format(Date(receipt.dateMillis)),
                fontSize = 11.sp,
                color = CText2,
            )
        }
        Column(horizontalAlignment = Alignment.End) {
            Text("₦${"%,.0f".format(receipt.amountPaid)}", fontWeight = FontWeight.Bold, fontSize = 13.sp, color = CText)
            val pendingLike = receipt.status == "pending" || receipt.status == "processing"
            Text(
                if (receipt.status == "success") "Successful" else if (pendingLike) "Pending" else "Failed",
                fontSize = 10.sp,
                fontWeight = FontWeight.SemiBold,
                color = if (receipt.status == "success") CSuccess else if (pendingLike) Color(0xFFF59E0B) else CError,
            )
        }
    }
}

@Composable
private fun TrustChip(text: String) {
    Box(
        modifier = Modifier
            .background(Color.White.copy(alpha = 0.18f), RoundedCornerShape(999.dp))
            .padding(horizontal = 10.dp, vertical = 5.dp)
    ) {
        Text(text, color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.SemiBold)
    }
}
