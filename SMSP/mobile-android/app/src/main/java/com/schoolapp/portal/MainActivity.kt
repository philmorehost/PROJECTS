package com.schoolapp.portal

import android.os.Bundle
import android.util.Log
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

const val BASE_URL = "https://portal.schoolapp.ng"

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
    var showSplash by remember { mutableStateOf(true) }
    var auth by remember { mutableStateOf<AuthToken?>(null) }
    
    if (showSplash) {
        SplashScreen(onSplashFinished = { showSplash = false })
    } else if (auth == null) {
        LoginScreen(onLoginSuccess = { auth = it })
    } else {
        MainDashboard(auth = auth!!, onLogout = { auth = null })
    }
}

// ─── Splash Screen ───────────────────────────────────────────────────────────
@Composable
fun SplashScreen(onSplashFinished: () -> Unit) {
    val scale = remember { Animatable(0.5f) }
    
    LaunchedEffect(key1 = true) {
        // First bounce
        scale.animateTo(1.2f, animationSpec = tween(durationMillis = 400))
        scale.animateTo(0.9f, animationSpec = tween(durationMillis = 300))
        // Second bounce
        scale.animateTo(1.1f, animationSpec = tween(durationMillis = 300))
        scale.animateTo(1.0f, animationSpec = tween(durationMillis = 200))
        delay(1200)
        onSplashFinished()
    }
    
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Brush.verticalGradient(listOf(DarkBg, CardBg))),
        contentAlignment = Alignment.Center
    ) {
        Image(
            painter = painterResource(id = R.drawable.splash_logo),
            contentDescription = "SchoolApp Logo",
            modifier = Modifier
                .size(160.dp)
                .scale(scale.value)
                .clip(RoundedCornerShape(32.dp))
        )
    }
}

// ─── Login Screen ────────────────────────────────────────────────────────────
@Composable
fun LoginScreen(onLoginSuccess: (AuthToken) -> Unit) {
    var email     by remember { mutableStateOf("") }
    var password  by remember { mutableStateOf("") }
    var role      by remember { mutableStateOf("student") }
    var error     by remember { mutableStateOf<String?>(null) }
    var isLoading by remember { mutableStateOf(false) }
    
    val coroutineScope = rememberCoroutineScope()

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Brush.verticalGradient(listOf(Color(0xFF0B0F19), Color(0xFF1E293B), Color(0xFF0B0F19))))
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(24.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Image(
                painter = painterResource(id = R.drawable.splash_logo),
                contentDescription = null,
                modifier = Modifier
                    .size(84.dp)
                    .clip(RoundedCornerShape(24.dp))
            )

            Spacer(modifier = Modifier.height(20.dp))

            Text("School Portal", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.ExtraBold, color = TextPrimary)
            Text("Multi-tenant School Management", fontSize = 13.sp, color = TextSecondary, modifier = Modifier.padding(top = 4.dp))

            Spacer(modifier = Modifier.height(36.dp))

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

            if (isLoading) {
                CircularProgressIndicator(color = PrimaryBlue)
            } else {
                Button(
                    onClick = {
                        isLoading = true
                        error = null
                        coroutineScope.launch(Dispatchers.IO) {
                            try {
                                val url = URL("$BASE_URL/api/v1/auth.php")
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
                                    val token = parsed.optString("token", "")
                                    val schoolId = parsed.optString("school_id", "")
                                    val name = parsed.optString("name", email)
                                    withContext(Dispatchers.Main) {
                                        onLoginSuccess(AuthToken(
                                            token = token,
                                            role = role,
                                            schoolId = schoolId,
                                            userName = name
                                        ))
                                    }
                                } else {
                                    withContext(Dispatchers.Main) {
                                        error = "Authentication failed. Check your credentials."
                                    }
                                }
                                conn.disconnect()
                            } catch (e: Exception) {
                                Log.e("LoginScreen", "Connection error", e)
                                withContext(Dispatchers.Main) {
                                    error = "Connection error: ${e.localizedMessage}"
                                }
                            } finally {
                                withContext(Dispatchers.Main) {
                                    isLoading = false
                                }
                            }
                        }
                    },
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    shape = RoundedCornerShape(14.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = PrimaryBlue)
                ) {
                    Icon(Icons.Filled.ArrowForward, null, modifier = Modifier.size(20.dp))
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
    var showAiTutor by remember { mutableStateOf(false) }
    var showTimetable by remember { mutableStateOf(false) }
    var showReportCard by remember { mutableStateOf(false) }

    val coroutineScope = rememberCoroutineScope()

    LaunchedEffect(auth.token) {
        coroutineScope.launch(Dispatchers.IO) {
            try {
                val url = URL("$BASE_URL/api/v1/student.php")
                val conn = url.openConnection() as HttpURLConnection
                conn.requestMethod = "GET"
                conn.setRequestProperty("Authorization", "Bearer ${auth.token}")
                if (conn.responseCode == 200) {
                    val resp = conn.inputStream.bufferedReader().use { it.readText() }
                    val data = JSONObject(resp).optJSONObject("data")
                    val profile = data?.optJSONObject("profile")
                    val academic = data?.optJSONObject("academic")
                    
                    val name = "${profile?.optString("firstname", "")} ${profile?.optString("lastname", "")}".trim()
                    val cName = academic?.optString("class_name", "N/A") ?: "N/A"
                    val aNo = profile?.optString("admission_number", "N/A") ?: "N/A"
                    
                    withContext(Dispatchers.Main) {
                        studentName = name.ifEmpty { auth.userName }
                        className   = cName
                        admNo       = aNo
                    }
                }
                conn.disconnect()
            } catch (e: Exception) {
                Log.e("HomeTab", "Error fetching student details", e)
                withContext(Dispatchers.Main) {
                    className = "Unavailable"
                }
            }
        }
    }

    if (showAiTutor) {
        AiTutorDialog(auth = auth, onDismiss = { showAiTutor = false })
    }
    if (showTimetable) {
        PortalWebDialog(url = "$BASE_URL/dashboard.php?page=academics&jwt_login=${auth.token}", onDismiss = { showTimetable = false })
    }
    if (showReportCard) {
        PortalWebDialog(url = "$BASE_URL/dashboard.php?page=evaluation&jwt_login=${auth.token}", onDismiss = { showReportCard = false })
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp)
    ) {
        item {
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
                                val initial = if (studentName.isNotEmpty()) studentName.substring(0, 1).uppercase() else "?"
                                Text(
                                    initial,
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
                            val displayRole = if (auth.role.isNotEmpty()) auth.role.substring(0, 1).uppercase() + auth.role.substring(1) else ""
                            InfoChip("Role", displayRole)
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
                QuickActionCard("📊", "Results", PrimaryBlue, onClick = { /* ResultsTab is bottom nav */ }, modifier = Modifier.weight(1f))
                QuickActionCard("💳", "Pay Fees", AccentGreen, onClick = { /* FeesTab is bottom nav */ }, modifier = Modifier.weight(1f))
                QuickActionCard("📣", "Notices", AccentAmber, onClick = { /* NoticesTab is bottom nav */ }, modifier = Modifier.weight(1f))
            }
        }

        item {
            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                QuickActionCard("📖", "Timetable", PrimaryIndigo, onClick = { showTimetable = true }, modifier = Modifier.weight(1f))
                QuickActionCard("📄", "Report Card", Color(0xFF8B5CF6), onClick = { showReportCard = true }, modifier = Modifier.weight(1f))
                QuickActionCard("🎓", "AI Tutor", Color(0xFF06B6D4), onClick = { showAiTutor = true }, modifier = Modifier.weight(1f))
            }
        }

        item {
            OutlinedButton(
                onClick = onLogout,
                modifier = Modifier.fillMaxWidth().height(48.dp),
                shape = RoundedCornerShape(12.dp),
                colors = ButtonDefaults.outlinedButtonColors(contentColor = AccentRed),
                border = ButtonDefaults.outlinedButtonBorder.copy(brush = Brush.horizontalGradient(listOf(AccentRed.copy(alpha = 0.5f), AccentRed.copy(alpha = 0.5f))))
            ) {
                Icon(Icons.Filled.ExitToApp, null, modifier = Modifier.size(18.dp))
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
fun QuickActionCard(emoji: String, label: String, color: Color, onClick: () -> Unit, modifier: Modifier = Modifier) {
    Card(
        modifier = modifier.clickable { onClick() },
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

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiTutorDialog(auth: AuthToken, onDismiss: () -> Unit) {
    var queryText by remember { mutableStateOf("") }
    val messages = remember { mutableStateListOf<Pair<String, Boolean>>(
        Pair("Hello! I am your AI Study Buddy. How can I help you with your classes today?", false)
    )}
    var isSending by remember { mutableStateOf(false) }
    
    val coroutineScope = rememberCoroutineScope()

    AlertDialog(
        onDismissRequest = onDismiss,
        title = {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("🎓 AI Study Buddy", fontWeight = FontWeight.Bold, fontSize = 18.sp, color = TextPrimary)
            }
        },
        text = {
            Column(modifier = Modifier.fillMaxWidth().height(300.dp)) {
                LazyColumn(
                    modifier = Modifier.weight(1f).fillMaxWidth().padding(bottom = 8.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    items(messages) { (text, isUser) ->
                        Box(
                            modifier = Modifier.fillMaxWidth(),
                            contentAlignment = if (isUser) Alignment.CenterEnd else Alignment.CenterStart
                        ) {
                            Card(
                                shape = RoundedCornerShape(
                                    topStart = 12.dp,
                                    topEnd = 12.dp,
                                    bottomStart = if (isUser) 12.dp else 2.dp,
                                    bottomEnd = if (isUser) 2.dp else 12.dp
                                ),
                                colors = CardDefaults.cardColors(
                                    containerColor = if (isUser) PrimaryBlue else CardBg
                                ),
                                border = if (isUser) null else androidx.compose.foundation.BorderStroke(1.dp, CardBorder),
                                modifier = Modifier.widthIn(max = 220.dp)
                            ) {
                                Text(
                                    text = text,
                                    color = Color.White,
                                    fontSize = 13.sp,
                                    modifier = Modifier.padding(10.dp)
                                )
                            }
                        }
                    }
                }
                
                if (isSending) {
                    Box(modifier = Modifier.fillMaxWidth().padding(4.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(modifier = Modifier.size(20.dp), color = PrimaryBlue, strokeWidth = 2.dp)
                    }
                }

                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    OutlinedTextField(
                        value = queryText,
                        onValueChange = { queryText = it },
                        placeholder = { Text("Ask a question...", color = TextSecondary, fontSize = 13.sp) },
                        modifier = Modifier.weight(1f),
                        shape = RoundedCornerShape(10.dp),
                        singleLine = true,
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = PrimaryBlue,
                            unfocusedBorderColor = CardBorder,
                            focusedTextColor = TextPrimary,
                            unfocusedTextColor = TextPrimary
                        ),
                        textStyle = LocalTextStyle.current.copy(fontSize = 13.sp)
                    )
                    
                    Button(
                        onClick = {
                            if (queryText.trim().isNotEmpty() && !isSending) {
                                val userMsg = queryText.trim()
                                messages.add(Pair(userMsg, true))
                                queryText = ""
                                isSending = true
                                coroutineScope.launch(Dispatchers.IO) {
                                    try {
                                        val url = URL("$BASE_URL/api/v1/ai.php")
                                        val conn = url.openConnection() as HttpURLConnection
                                        conn.requestMethod = "POST"
                                        conn.setRequestProperty("Content-Type", "application/json")
                                        conn.setRequestProperty("Authorization", "Bearer ${auth.token}")
                                        conn.doOutput = true
                                        val json = JSONObject().apply {
                                            put("action", "chat_study_buddy")
                                            put("query", userMsg)
                                            put("context_material", "Student: ${auth.userName}")
                                        }
                                        val os = OutputStreamWriter(conn.outputStream)
                                        os.write(json.toString()); os.flush(); os.close()
                                        if (conn.responseCode == 200) {
                                            val resp = conn.inputStream.bufferedReader().use { it.readText() }
                                            val parsed = JSONObject(resp)
                                            val reply = parsed.optString("answer", "No response from tutor.")
                                            withContext(Dispatchers.Main) {
                                                messages.add(Pair(reply, false))
                                            }
                                        } else {
                                            withContext(Dispatchers.Main) {
                                                messages.add(Pair("Could not reach AI Tutor. Please try again.", false))
                                            }
                                        }
                                        conn.disconnect()
                                    } catch (e: Exception) {
                                        Log.e("AiTutor", "Error", e)
                                        withContext(Dispatchers.Main) {
                                            messages.add(Pair("Error: ${e.localizedMessage}", false))
                                        }
                                    } finally {
                                        withContext(Dispatchers.Main) {
                                            isSending = false
                                        }
                                    }
                                }
                            }
                        },
                        enabled = queryText.trim().isNotEmpty() && !isSending,
                        shape = RoundedCornerShape(10.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = PrimaryBlue),
                        contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp)
                    ) {
                        Text("Send", fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
        },
        confirmButton = {
            TextButton(onClick = onDismiss) {
                Text("Close", color = TextSecondary, fontWeight = FontWeight.Bold)
            }
        },
        containerColor = DarkBg,
        tonalElevation = 6.dp
    )
}

// ─── Results Tab ─────────────────────────────────────────────────────────────
@Composable
fun ResultsTab(auth: AuthToken) {
    WebViewScreen(url = "$BASE_URL/dashboard.php?page=evaluation&jwt_login=${auth.token}")
}

// ─── Fees Tab ────────────────────────────────────────────────────────────────
@Composable
fun FeesTab(auth: AuthToken) {
    WebViewScreen(url = "$BASE_URL/dashboard.php?page=billing&jwt_login=${auth.token}")
}

// ─── Notices Tab ─────────────────────────────────────────────────────────────
@Composable
fun NoticesTab(auth: AuthToken) {
    WebViewScreen(url = "$BASE_URL/dashboard.php?page=communications&jwt_login=${auth.token}")
}

// ─── WebView Integration ─────────────────────────────────────────────────────
@Composable
fun WebViewScreen(url: String) {
    AndroidView(
        factory = { context ->
            WebView(context).apply {
                webViewClient = WebViewClient()
                settings.javaScriptEnabled = true
                settings.domStorageEnabled = true
                settings.databaseEnabled = true
                settings.cacheMode = android.webkit.WebSettings.LOAD_DEFAULT
                try {
                    clearCache(true)
                } catch (e: Exception) {
                    Log.e("WebView", "Error clearing cache", e)
                }
                loadUrl(url)
            }
        },
        modifier = Modifier.fillMaxSize()
    )
}

@Composable
fun PortalWebDialog(url: String, onDismiss: () -> Unit) {
    Dialog(
        onDismissRequest = onDismiss,
        properties = DialogProperties(usePlatformDefaultWidth = false)
    ) {
        Box(modifier = Modifier.fillMaxSize().background(DarkBg)) {
            Column {
                Row(
                    modifier = Modifier.fillMaxWidth().padding(12.dp),
                    horizontalArrangement = Arrangement.End
                ) {
                    IconButton(onClick = onDismiss) {
                        Icon(Icons.Filled.Close, contentDescription = "Close", tint = TextPrimary)
                    }
                }
                WebViewScreen(url = url)
            }
        }
    }
}
