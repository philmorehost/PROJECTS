package com.payhub.guest.ui.support

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Email
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.runtime.Composable
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
import com.payhub.guest.data.model.GuestSupportInfo
import com.payhub.guest.ui.theme.CText
import com.payhub.guest.ui.theme.CText2

private data class Faq(val q: String, val a: String)

private val FAQS = listOf(
    Faq("Do I need an account?", "No — PayHub lets you pay for airtime, data and bills instantly as a guest. No registration required."),
    Faq("Is my payment secure?", "Yes, all payments are processed securely through PayHub's checkout."),
    Faq("What if my data doesn't deliver?", "Contact our support team with your transaction reference and we'll resolve it immediately."),
)

@Composable
fun SupportScreen(supportInfo: GuestSupportInfo? = null) {
    val context = LocalContext.current
    // Falls back to placeholder contact info only if site-info.php hasn't returned real values yet.
    val whatsappPhone = supportInfo?.phone?.takeIf { it.isNotBlank() } ?: "2348000000000"
    val supportEmail = supportInfo?.email?.takeIf { it.isNotBlank() } ?: "support@payhub.com.ng"
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(horizontal = 20.dp)
            .verticalScroll(rememberScrollState())
    ) {
        Text("Support", fontWeight = FontWeight.Bold, fontSize = 20.sp, modifier = Modifier.padding(vertical = 20.dp))

        Column(
            modifier = Modifier
                .fillMaxWidth()
                .background(Color.White, RoundedCornerShape(18.dp))
                .padding(16.dp)
        ) {
            Text("Need help?", fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 12.dp))
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .background(Color(0xFF25D366), RoundedCornerShape(14.dp))
                    .clickable {
                        val intent = Intent(Intent.ACTION_VIEW, Uri.parse("https://wa.me/$whatsappPhone"))
                        context.startActivity(intent)
                    }
                    .padding(vertical = 14.dp),
                horizontalArrangement = androidx.compose.foundation.layout.Arrangement.Center,
            ) {
                Text("Chat on WhatsApp", color = Color.White, fontWeight = FontWeight.Bold)
            }
            androidx.compose.foundation.layout.Spacer(Modifier.padding(top = 10.dp))
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .background(Color(0xFFF1F5F9), RoundedCornerShape(14.dp))
                    .clickable {
                        val intent = Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:$supportEmail"))
                        context.startActivity(intent)
                    }
                    .padding(vertical = 14.dp),
                horizontalArrangement = androidx.compose.foundation.layout.Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Filled.Email, contentDescription = null, tint = CText, modifier = Modifier.padding(end = 8.dp))
                Text("Email Support", color = CText, fontWeight = FontWeight.Bold)
            }
        }

        androidx.compose.foundation.layout.Spacer(Modifier.padding(top = 16.dp))

        Column(
            modifier = Modifier
                .fillMaxWidth()
                .background(Color.White, RoundedCornerShape(18.dp))
                .padding(16.dp)
        ) {
            Text("Frequently Asked Questions", fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 8.dp))
            FAQS.forEach { faq -> FaqItem(faq) }
        }

        androidx.compose.foundation.layout.Spacer(Modifier.padding(bottom = 24.dp))
    }
}

@Composable
private fun FaqItem(faq: Faq) {
    var expanded by remember { mutableStateOf(false) }
    Column(modifier = Modifier.fillMaxWidth().padding(vertical = 8.dp).clickable { expanded = !expanded }) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = androidx.compose.foundation.layout.Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(faq.q, fontWeight = FontWeight.SemiBold, fontSize = 13.sp, color = CText, modifier = Modifier.padding(end = 8.dp))
            Icon(Icons.Filled.KeyboardArrowDown, contentDescription = null, tint = CText2)
        }
        if (expanded) {
            Text(faq.a, fontSize = 12.sp, color = CText2, modifier = Modifier.padding(top = 6.dp))
        }
    }
}
