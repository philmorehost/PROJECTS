package com.payhub.guest.ui.splash

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountBalanceWallet
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.payhub.guest.ui.theme.PhPrimary
import com.payhub.guest.ui.theme.PhPrimaryDark
import kotlinx.coroutines.delay

@Composable
fun SplashScreen(onContinue: () -> Unit) {
    LaunchedEffect(Unit) {
        delay(1400)
        onContinue()
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Brush.verticalGradient(listOf(PhPrimary, PhPrimaryDark)))
            .clickable { onContinue() },
        contentAlignment = Alignment.Center,
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Box(
                modifier = Modifier
                    .size(76.dp)
                    .background(Color.White.copy(alpha = 0.15f), RoundedCornerShape(22.dp)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Filled.AccountBalanceWallet, contentDescription = null, tint = Color.White, modifier = Modifier.size(36.dp))
            }
            androidx.compose.foundation.layout.Spacer(Modifier.padding(top = 18.dp))
            Text("PayHub", color = Color.White, fontSize = 32.sp, fontWeight = FontWeight.ExtraBold)
            Text(
                "Instant top-ups, no login needed.",
                color = Color.White.copy(alpha = 0.85f),
                fontSize = 14.sp,
                modifier = Modifier.padding(top = 6.dp, bottom = 26.dp),
            )
            CircularProgressIndicator(color = Color.White, modifier = Modifier.size(28.dp))
            Text(
                "Tap anywhere to continue",
                color = Color.White.copy(alpha = 0.6f),
                fontSize = 11.sp,
                modifier = Modifier.padding(top = 20.dp),
            )
        }
    }
}
