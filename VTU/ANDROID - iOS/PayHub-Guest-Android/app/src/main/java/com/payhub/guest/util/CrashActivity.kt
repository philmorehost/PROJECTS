package com.payhub.guest.util

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.os.Process
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.payhub.guest.MainActivity

/**
 * Standalone (non-Compose-nav) crash screen — launched directly by CrashHandler, deliberately
 * independent of GuestViewModel/GuestNavHost since whatever just crashed may have left that
 * state broken. Lets a tester copy the exact stack trace without needing logcat/Android Studio.
 */
class CrashActivity : ComponentActivity() {
    companion object {
        const val EXTRA_TRACE = "trace"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val trace = intent.getStringExtra(EXTRA_TRACE)
            ?: CrashHandler.lastCrash(this)
            ?: "No crash details were captured."

        setContent {
            MaterialTheme {
                CrashScreen(
                    trace = trace,
                    onCopy = {
                        val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                        clipboard.setPrimaryClip(ClipData.newPlainText("PayHub Guest crash log", trace))
                        Toast.makeText(this, "Crash log copied", Toast.LENGTH_SHORT).show()
                    },
                    onRestart = {
                        CrashHandler.clearLastCrash(this)
                        val intent = Intent(this, MainActivity::class.java).apply {
                            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
                        }
                        startActivity(intent)
                        Process.killProcess(Process.myPid())
                    },
                )
            }
        }
    }
}

@Composable
private fun CrashScreen(trace: String, onCopy: () -> Unit, onRestart: () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(Color(0xFF0B0B0F))
            .padding(20.dp)
    ) {
        Text("App Crashed", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 20.sp)
        Text(
            "Copy this log and share it so the issue can be traced.",
            color = Color(0xFF9CA3AF),
            fontSize = 13.sp,
            modifier = Modifier.padding(top = 6.dp, bottom = 16.dp),
        )
        Box(
            modifier = Modifier
                .weight(1f)
                .fillMaxWidth()
                .background(Color(0xFF1A1A22), RoundedCornerShape(12.dp))
                .padding(14.dp)
        ) {
            Column(modifier = Modifier.verticalScroll(rememberScrollState())) {
                Text(trace, color = Color(0xFFE5E7EB), fontSize = 12.sp, fontFamily = FontFamily.Monospace)
            }
        }
        Row(modifier = Modifier.fillMaxWidth().padding(top = 16.dp)) {
            Button(onClick = onCopy, modifier = Modifier.weight(1f)) { Text("Copy Log") }
            Spacer(Modifier.padding(start = 8.dp))
            Button(onClick = onRestart, modifier = Modifier.weight(1f)) { Text("Restart App") }
        }
    }
}
