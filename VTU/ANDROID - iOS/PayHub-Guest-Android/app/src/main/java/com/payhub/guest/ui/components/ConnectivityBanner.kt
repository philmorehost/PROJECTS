package com.payhub.guest.ui.components

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CloudDone
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.payhub.guest.ui.theme.CError
import com.payhub.guest.ui.theme.CSuccess
import kotlinx.coroutines.delay

/**
 * Sits above everything else in GuestNavHost so it's visible no matter which screen is showing.
 * Stays up persistently while offline (there's no useful "dismiss" — every screen depends on
 * the network), then flashes a "Back Online" confirmation for a couple seconds on recovery so
 * the guest knows it's safe to retry whatever just failed.
 */
@Composable
fun ConnectivityBanner(isOnline: Boolean) {
    var showRecovered by remember { mutableStateOf(false) }
    var wasOffline by remember { mutableStateOf(false) }

    LaunchedEffect(isOnline) {
        if (!isOnline) {
            wasOffline = true
            showRecovered = false
        } else if (wasOffline) {
            wasOffline = false
            showRecovered = true
            delay(2500)
            showRecovered = false
        }
    }

    AnimatedVisibility(
        visible = !isOnline || showRecovered,
        enter = slideInVertically { -it } + fadeIn(),
        exit = slideOutVertically { -it } + fadeOut(),
    ) {
        val offline = !isOnline
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .background(if (offline) CError else CSuccess)
                .padding(horizontal = 16.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(
                if (offline) Icons.Filled.CloudOff else Icons.Filled.CloudDone,
                contentDescription = null,
                tint = Color.White,
                modifier = Modifier.padding(end = 8.dp),
            )
            Text(
                if (offline) "No Internet Connection" else "Back Online",
                color = Color.White,
                fontWeight = FontWeight.SemiBold,
                fontSize = 13.sp,
            )
        }
    }
}
