package com.schoolapp.portal

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import org.json.JSONObject
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

// ─── Theme Colors ────────────────────────────────────────────────────────────
val PrimaryBlue   = Color(0xFF2563EB)
val PrimaryIndigo = Color(0xFF4F46E5)
val DarkBg        = Color(0xFF0F172A)
val CardBg        = Color(0xFF1E293B)
val CardBorder    = Color(0xFF334155)
val TextPrimary   = Color(0xFFF1F5F9)
val TextSecondary = Color(0xFF94A3B8)
val AccentGreen   = Color(0xFF10B981)
val AccentAmber   = Color(0xFFF59E0B)
val AccentRed     = Color(0xFFEF4444)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme(
                colorScheme = darkColorScheme(
                    primary = PrimaryBlue,
                    background = DarkBg,
                    surface = CardBg,
                    onBackground = TextPrimary,
                    onSurface = TextPrimary
                )
            ) {
                Surface(modifier = Modifier.fillMaxSize(), color = DarkBg) {
                    AppNavigation()
                }
            }
        }
    }
}

data class AuthToken(
    val token: String,
    val role: String,
    val schoolId: String,
    val userName: String
)

// ─── State Holder ────────────────────────────────────────────────────────────
@Composable
fun AppNavigation() {
    var auth by remember { mutableStateOf<AuthToken?>(null) }
    if (auth == null) {
        LoginScreen(onLoginSuccess = { auth = it })
    } else {
        MainDashboard(auth = auth!!, onLogout = { auth = null })
    }
}

// ─── Login Screen ────────────────────────────────────────────────────────────
@Composable
fun LoginScreen(onLoginSuccess: (AuthToken) -> Unit) {
    var email     by remember { mutableStateOf("") }
    var password  by remember { mutableStateOf("") }
    var role      by remember { mutableStateOf("student") }
    var schoolUrl by remember { mutableStateOf("https://schoolapp.ng") }
    var error     by remember { mutableStateOf<String?>(null) }
    var isLoading by remember { mutableStateOf(false) }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(listOf(Color(0xFF0B0F19), Color(0xFF1E293B), Color(0xFF0B0F19)))
            )
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(24.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            // Logo / Brand
            Box(
                modifier = Modifier
                    .size(72.dp)
                    .clip(RoundedCornerShape(20.dp))
                    .background(Brush.linearGradient(listOf(PrimaryBlue, PrimaryIndigo))),
                contentAlignment = Alignment.Center
            ) {
                Icon(Icons.Filled.School, contentDescription = null, tint = Color.White, modifier = Modifier.size(40.dp))
            }

            Spacer(modifier = Modifier.height(20.dp))

            Text("School Portal", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.ExtraBold, color = TextPrimary)
            Text("Multi-tenant School Management", fontSize = 13.sp, color = TextSecondary, modifier = Modifier.padding(top = 4.dp))

            Spacer(modifier = Modifier.height(36.dp))

            // Error display
            error?.let {
                Card(modifier = Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = AccentRed.copy(alpha = 0.12f)), shape = RoundedCornerShape(12.dp)) {
                    Row(modifier = Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Filled.Warning, contentDescription = null, tint = AccentRed, modifier = Modifier.size(16.dp))
                        Spacer(modifier = Modifier.width(8.dp))
                        Text(it, color = AccentRed, fontSize = 13.sp)
                    }
                }
                Spacer(modifier = Modifier.height(12.dp))
            }

            // School URL
            OutlinedTextField(
                value = schoolUrl,
                onValueChange = { schoolUrl = it },
                label = { Text("School Portal URL", color = TextSecondary) },
                modifier = Modifier.fillMaxWidth(),
                colors = OutlinedTextFieldDefaults.colors(
                    focusedBorderColor = PrimaryBlue, unfocusedBorderColor = CardBorder,
                    focusedTextColor = TextPrimary, unfocusedTextColor = TextPrimary
                ),
                shape = RoundedCornerShape(12.dp),
                singleLine = true,
                leadingIcon = { Icon(Icons.Filled.Language, null, tint = TextSecondary, modifier = Modifier.size(18.dp)) }
            )

            Spacer(modifier = Modifier.height(12.dp))

            // Email
            OutlinedTextField(
                value = email,
                onValueChange = { email = it },
                label = { Text("Email / Username", color = TextSecondary) },
                modifier = Modifier.fillMaxWidth(),
                colors = OutlinedTextFieldDefaults.colors(focusedBorderColor = PrimaryBlue, unfocusedBorderColor = CardBorder, focusedTextColor = TextPrimary, unfocusedTextColor = TextPrimary),
                shape = RoundedCornerShape(12.dp),
                singleLine = true,
                leadingIcon = { Icon(Icons.Filled.Person, null, tint = TextSecondary, modifier = Modifier.size(18.dp)) }
            )

            Spacer(modifier = Modifier.height(12.dp))

            // Password
            OutlinedTextField(
                value = password,
                onValueChange = { password = it },
                label = { Text("Password", color = TextSecondary) },
                visualTransformation = PasswordVisualTransformation(),
                modifier = Modifier.fillMaxWidth(),
                colors = OutlinedTextFieldDefaults.colors(focusedBorderColor = PrimaryBlue, unfocusedBorderColor = CardBorder, focusedTextColor = TextPrimary, unfocusedTextColor = TextPrimary),
                shape = RoundedCornerShape(12.dp),
                singleLine = true,
                leadingIcon = { Icon(Icons.Filled.Lock, null, tint = TextSecondary, modifier = Modifier.size(18.dp)) }
            )

            Spacer(modifier = Modifier.height(12.dp))

            // Role selector
            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("student", "teacher", "parent").forEach { r ->
                    val selected = role == r
                    OutlinedButton(
                        onClick = { role = r },
                        modifier = Modifier.weight(1f),
                        shape = RoundedCornerShape(10.dp),
                        colors = ButtonDefaults.outlinedButtonColors(
                            containerColor = if (selected) PrimaryBlue.copy(alpha = 0.2f) else Color.Transparent,
                            contentColor = if (selected) PrimaryBlue else TextSecondary
                        ),
                        border = ButtonDefaults.outlinedButtonBorder.copy(
                            brush = Brush.horizontalGradient(if (selected) listOf(PrimaryBlue, PrimaryBlue) else listOf(CardBorder, CardBorder))
                        )
                    ) {
                        Text(r.replaceFirstChar { it.uppercase() }, fontSize = 11.sp, fontWeight = if (selected) FontWeight.Bold else FontWeight.Normal)
                    }
                }
            }

            Spacer(modifier = Modifier.height(20.dp))

            // Login button
            if (isLoading) {
                CircularProgressIndicator(color = PrimaryBlue)
            } else {
                Button(
                    onClick = {
                        isLoading = true
                        error = null
                        thread {
                            try {
                                val url = URL("$schoolUrl/api/v1/auth.php")
                                val conn = url.openConnection() as HttpURLConnection
                                conn.requestMethod = "POST"
                                conn.setRequestProperty("Content-Type", "application/json")
                                conn.doOutput = true
                                val json = JSONObject().apply {
                                    put("email", email)
                                    put("password", password)
                                    put("role", role)
                                }
                                val os = OutputStreamWriter(conn.outputStream)
                                os.write(json.toString()); os.flush(); os.close()
                                if (conn.responseCode == 200) {
                                    val resp = conn.inputStream.bufferedReader().use { it.readText() }
                                    val parsed = JSONObject(resp)
                                    onLoginSuccess(AuthToken(
                                        token = parsed.optString("token", ""),
                                        role = role,
                                        schoolId = parsed.optString("school_id", ""),
                                        userName = parsed.optString("name", email)
                                    ))
                                } else {
                                    error = "Authentication failed. Check your credentials."
                                }
                                conn.disconnect()
                            } catch (e: Exception) {
                                error = "Connection error: ${e.message}"
                            } finally {
                                isLoading = false
                            }
                        }
                    },
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    shape = RoundedCornerShape(14.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = PrimaryBlue)
                ) {
                    Icon(Icons.Filled.Login, null, modifier = Modifier.size(20.dp))
                    Spacer(modifier = Modifier.width(8.dp))
                    Text("Sign In", fontWeight = FontWeight.Bold, fontSize = 15.sp)
                }
            }
        }
    }
}

// ─── Main Dashboard (Bottom Navigation) ──────────────────────────────────────
@Composable
fun MainDashboard(auth: AuthToken, onLogout: () -> Unit) {
    var selectedTab by remember { mutableIntStateOf(0) }

    Scaffold(
        containerColor = DarkBg,
        bottomBar = {
            NavigationBar(containerColor = CardBg, tonalElevation = 0.dp) {
                val tabs = listOf(
                    Triple("Home", Icons.Filled.Home, 0),
                    Triple("Results", Icons.Filled.Assessment, 1),
                    Triple("Fees", Icons.Filled.CreditCard, 2),
                    Triple("Notices", Icons.Filled.Notifications, 3)
                )
                tabs.forEach { (label, icon, idx) ->
                    NavigationBarItem(
                        selected = selectedTab == idx,
                        onClick = { selectedTab = idx },
                        icon = { Icon(icon, contentDescription = label, modifier = Modifier.size(22.dp)) },
                        label = { Text(label, fontSize = 10.sp) },
                        colors = NavigationBarItemDefaults.colors(
                            selectedIconColor = PrimaryBlue,
                            selectedTextColor = PrimaryBlue,
                            unselectedIconColor = TextSecondary,
                            unselectedTextColor = TextSecondary,
                            indicatorColor = PrimaryBlue.copy(alpha = 0.15f)
                        )
                    )
                }
            }
        }
    ) { innerPadding ->
        Box(modifier = Modifier.padding(innerPadding)) {
            when (selectedTab) {
                0 -> HomeTab(auth = auth, onLogout = onLogout)
                1 -> ResultsTab(auth = auth)
                2 -> FeesTab(auth = auth)
                3 -> NoticesTab(auth = auth)
            }
        }
    }
}

// ─── Home Tab ────────────────────────────────────────────────────────────────
@Composable
fun HomeTab(auth: AuthToken, onLogout: () -> Unit) {
    var studentName by remember { mutableStateOf(auth.userName) }
    var className   by remember { mutableStateOf("Loading...") }
    var admNo       by remember { mutableStateOf("...") }

    LaunchedEffect(auth.token) {
        thread {
            try {
                val url = URL("https://schoolapp.ng/api/v1/student.php")
                val conn = url.openConnection() as HttpURLConnection
                conn.requestMethod = "GET"
                conn.setRequestProperty("Authorization", "Bearer ${auth.token}")
                if (conn.responseCode == 200) {
                    val resp = conn.inputStream.bufferedReader().use { it.readText() }
                    val data = JSONObject(resp).optJSONObject("data")
                    val profile = data?.optJSONObject("profile")
                    val academic = data?.optJSONObject("academic")
                    studentName = "${profile?.optString("firstname", "")} ${profile?.optString("lastname", "")}".trim()
                    className   = academic?.optString("class_name", "N/A") ?: "N/A"
                    admNo       = profile?.optString("admission_number", "N/A") ?: "N/A"
                }
                conn.disconnect()
            } catch (e: Exception) { className = "Unavailable" }
        }
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)
    ) {
        item {
            // Header / Welcome Card
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(20.dp),
                colors = CardDefaults.cardColors(containerColor = Color.Transparent),
                border = null
            ) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(Brush.linearGradient(listOf(PrimaryBlue, PrimaryIndigo)), shape = RoundedCornerShape(20.dp))
                        .padding(20.dp)
                ) {
                    Column {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Box(
                                modifier = Modifier.size(48.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)),
                                contentAlignment = Alignment.Center
                            ) {
                                Text(
                                    studentName.firstOrNull()?.uppercase() ?: "?",
                                    color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp
                                )
                            }
                            Spacer(modifier = Modifier.width(12.dp))
                            Column {
                                Text("Welcome back,", color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp)
                                Text(studentName, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
                            }
                        }
                        Spacer(modifier = Modifier.height(16.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                            InfoChip("Class", className)
                            InfoChip("Adm No", admNo)
                            InfoChip("Role", auth.role.replaceFirstChar { it.uppercase() })
                        }
                    }
                }
            }
        }

        item {
            Text("Quick Actions", color = TextSecondary, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
        }

        item {
            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                QuickActionCard("📊", "Results", PrimaryBlue, modifier = Modifier.weight(1f))
                QuickActionCard("💳", "Pay Fees", AccentGreen, modifier = Modifier.weight(1f))
                QuickActionCard("📣", "Notices", AccentAmber, modifier = Modifier.weight(1f))
            }
        }

        item {
            // Logout button
            OutlinedButton(
                onClick = onLogout,
                modifier = Modifier.fillMaxWidth().height(48.dp),
                shape = RoundedCornerShape(12.dp),
                colors = ButtonDefaults.outlinedButtonColors(contentColor = AccentRed),
                border = ButtonDefaults.outlinedButtonBorder.copy(brush = Brush.horizontalGradient(listOf(AccentRed.copy(alpha = 0.5f), AccentRed.copy(alpha = 0.5f))))
            ) {
                Icon(Icons.Filled.Logout, null, modifier = Modifier.size(18.dp))
                Spacer(modifier = Modifier.width(8.dp))
                Text("Sign Out", fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
fun InfoChip(label: String, value: String) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Text(label, color = Color.White.copy(alpha = 0.7f), fontSize = 9.sp)
        Text(value, color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
fun QuickActionCard(emoji: String, label: String, color: Color, modifier: Modifier = Modifier) {
    Card(
        modifier = modifier,
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = color.copy(alpha = 0.12f)),
        border = androidx.compose.foundation.BorderStroke(1.dp, color.copy(alpha = 0.3f))
    ) {
        Column(
            modifier = Modifier.padding(14.dp).fillMaxWidth(),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Text(emoji, fontSize = 24.sp)
            Spacer(modifier = Modifier.height(4.dp))
            Text(label, color = color, fontSize = 11.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center)
        }
    }
}

// ─── Results Tab ─────────────────────────────────────────────────────────────
@Composable
fun ResultsTab(auth: AuthToken) {
    Column(modifier = Modifier.fillMaxSize().padding(20.dp)) {
        Text("My Results", color = TextPrimary, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
        Spacer(modifier = Modifier.height(4.dp))
        Text("View your academic performance", color = TextSecondary, fontSize = 13.sp)
        Spacer(modifier = Modifier.height(20.dp))
        Card(
            modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(16.dp),
            colors = CardDefaults.cardColors(containerColor = CardBg),
            border = androidx.compose.foundation.BorderStroke(1.dp, CardBorder)
        ) {
            Column(modifier = Modifier.padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text("📄", fontSize = 40.sp)
                Spacer(modifier = Modifier.height(12.dp))
                Text("Result Portal", color = TextPrimary, fontWeight = FontWeight.Bold)
                Text("Your report cards and academic results are\navailable in the full portal.", color = TextSecondary, fontSize = 12.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 8.dp))
            }
        }
    }
}

// ─── Fees Tab ────────────────────────────────────────────────────────────────
@Composable
fun FeesTab(auth: AuthToken) {
    Column(modifier = Modifier.fillMaxSize().padding(20.dp)) {
        Text("Fees & Payments", color = TextPrimary, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
        Spacer(modifier = Modifier.height(4.dp))
        Text("Manage school fee payments", color = TextSecondary, fontSize = 13.sp)
        Spacer(modifier = Modifier.height(20.dp))
        Card(
            modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(16.dp),
            colors = CardDefaults.cardColors(containerColor = CardBg),
            border = androidx.compose.foundation.BorderStroke(1.dp, CardBorder)
        ) {
            Column(modifier = Modifier.padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text("💳", fontSize = 40.sp)
                Spacer(modifier = Modifier.height(12.dp))
                Text("Fee Portal", color = TextPrimary, fontWeight = FontWeight.Bold)
                Text("View your outstanding bills and payment history\nin the full web portal.", color = TextSecondary, fontSize = 12.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 8.dp))
            }
        }
    }
}

// ─── Notices Tab ─────────────────────────────────────────────────────────────
@Composable
fun NoticesTab(auth: AuthToken) {
    Column(modifier = Modifier.fillMaxSize().padding(20.dp)) {
        Text("Notice Board", color = TextPrimary, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
        Spacer(modifier = Modifier.height(4.dp))
        Text("School announcements and circulars", color = TextSecondary, fontSize = 13.sp)
        Spacer(modifier = Modifier.height(20.dp))
        Card(
            modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(16.dp),
            colors = CardDefaults.cardColors(containerColor = CardBg),
            border = androidx.compose.foundation.BorderStroke(1.dp, CardBorder)
        ) {
            Column(modifier = Modifier.padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text("🔔", fontSize = 40.sp)
                Spacer(modifier = Modifier.height(12.dp))
                Text("Notices", color = TextPrimary, fontWeight = FontWeight.Bold)
                Text("No announcements available at this time.\nCheck back later.", color = TextSecondary, fontSize = 12.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 8.dp))
            }
        }
    }
}
