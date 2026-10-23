package com.payhub.guest.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable

private val PayHubColorScheme = lightColorScheme(
    primary = PhPrimary,
    onPrimary = CSurface,
    secondary = PhPrimaryDark,
    background = CBg,
    surface = CSurface,
    onBackground = CText,
    onSurface = CText,
    error = CError,
)

@Composable
fun PayHubGuestTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = PayHubColorScheme,
        typography = PayHubTypography,
        content = content
    )
}
