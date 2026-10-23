package com.payhub.guest.ui.services

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.payhub.guest.data.GuestServiceCatalog
import com.payhub.guest.ui.components.PullToRefreshWrapper
import com.payhub.guest.ui.theme.CText
import com.payhub.guest.ui.theme.CText2

@Composable
fun ServicesScreen(
    enabledServices: Map<String, Int>,
    isRefreshing: Boolean = false,
    onRefresh: () -> Unit = {},
    onOpenService: (String) -> Unit,
) {
    val services = GuestServiceCatalog.filterEnabled(enabledServices)
    PullToRefreshWrapper(isRefreshing = isRefreshing, onRefresh = onRefresh) {
    Column(modifier = Modifier.fillMaxSize().padding(horizontal = 20.dp)) {
        Text("All Services", fontWeight = FontWeight.Bold, fontSize = 20.sp, modifier = Modifier.padding(vertical = 20.dp))
        LazyVerticalGrid(
            columns = GridCells.Fixed(2),
            contentPadding = PaddingValues(bottom = 24.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            items(services) { s ->
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .aspectRatio(1f)
                        .background(s.bg, RoundedCornerShape(20.dp))
                        .clickable { onOpenService(s.key) }
                        .padding(16.dp),
                    verticalArrangement = Arrangement.Center,
                ) {
                    Box(
                        modifier = Modifier.size(44.dp).background(s.color, RoundedCornerShape(14.dp)),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(s.icon, contentDescription = s.title, tint = Color.White)
                    }
                    Text(s.title, fontWeight = FontWeight.Bold, fontSize = 14.sp, color = CText, modifier = Modifier.padding(top = 10.dp))
                    Text(s.caption, fontSize = 11.sp, color = CText2, modifier = Modifier.padding(top = 2.dp))
                }
            }
        }
    }
    }
}
