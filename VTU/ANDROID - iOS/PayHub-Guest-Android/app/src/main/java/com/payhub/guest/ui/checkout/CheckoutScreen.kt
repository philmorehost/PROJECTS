package com.payhub.guest.ui.checkout

import android.annotation.SuppressLint
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import com.payhub.guest.ui.GuestViewModel
import com.payhub.guest.ui.theme.PhPrimary
import com.payhub.guest.ui.theme.PhPrimaryDark

/**
 * Loads the PayHub-hosted checkout URL returned by checkout-init.php directly — no local
 * gateway-SDK HTML/JS bridge is needed (unlike the legacy wallet-funding WebView flow) since
 * Guest Mode uses PayHub exclusively and checkout-init.php already returns a ready-to-load
 * hosted page.
 *
 * Completion is detected two ways, whichever fires first:
 *  1. URL watch — the WebView lands on our guest-payment-complete.php callback.
 *  2. Status poll — viewModel.watchPayment() polls status.php every 3s. This is the one that
 *     actually matters in practice: PayHub's hosted page was observed redirecting to
 *     merchant.payhub.com.ng home INSTEAD of our callback_url, so the URL watch alone left
 *     paid guests stranded. status.php also self-verifies + fulfills server-side, so polling
 *     it both detects payment and drives crediting even if PayHub's webhook never arrives.
 * Both paths funnel through viewModel.paymentDetected so navigation fires exactly once.
 */
@SuppressLint("SetJavaScriptEnabled")
@Composable
fun CheckoutScreen(viewModel: GuestViewModel, onCancel: () -> Unit, onPaymentComplete: (reference: String) -> Unit) {
    val checkoutState by viewModel.checkoutState.collectAsState()
    val ready = checkoutState as? GuestViewModel.CheckoutState.Ready

    androidx.compose.runtime.LaunchedEffect(ready?.reference) {
        ready?.reference?.let { viewModel.watchPayment(it) }
    }
    val paid by viewModel.paymentDetected.collectAsState()
    androidx.compose.runtime.LaunchedEffect(paid) {
        paid?.let { reference ->
            viewModel.resetPaymentWatch()
            onPaymentComplete(reference)
        }
    }

    Box(modifier = Modifier.fillMaxSize().background(androidx.compose.ui.graphics.Brush.verticalGradient(listOf(PhPrimary, PhPrimaryDark)))) {
        if (ready != null) {
            AndroidView(
                modifier = Modifier.fillMaxSize(),
                factory = { context ->
                    WebView(context).apply {
                        settings.javaScriptEnabled = true
                        settings.domStorageEnabled = true
                        webViewClient = object : WebViewClient() {
                            override fun onPageStarted(view: WebView?, url: String?, favicon: android.graphics.Bitmap?) {
                                super.onPageStarted(view, url, favicon)
                                if (url != null && (url.contains("guest-payment-complete.php") || url.contains("payhub-success.php"))) {
                                    viewModel.notifyPaymentDetected(ready.reference)
                                }
                            }
                        }
                        loadUrl(ready.checkoutUrl)
                    }
                }
            )
        } else {
            Column(
                modifier = Modifier.fillMaxSize().padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = androidx.compose.foundation.layout.Arrangement.Center,
            ) {
                CircularProgressIndicator(color = Color.White)
                Text(
                    "Redirecting you to PayHub secure checkout…",
                    color = Color.White,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(top = 16.dp),
                )
            }
        }
        TextButton(onClick = onCancel, modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = 24.dp)) {
            Text("Cancel and go back", color = Color.White.copy(alpha = 0.85f))
        }
    }
}
