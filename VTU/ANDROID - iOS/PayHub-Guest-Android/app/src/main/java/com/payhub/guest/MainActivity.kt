package com.payhub.guest

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.viewModels
import com.payhub.guest.navigation.GuestNavHost
import com.payhub.guest.ui.GuestViewModel
import com.payhub.guest.ui.theme.PayHubGuestTheme

class MainActivity : ComponentActivity() {

    private val viewModel: GuestViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            PayHubGuestTheme {
                GuestNavHost(viewModel = viewModel)
            }
        }
    }
}
