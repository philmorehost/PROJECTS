package com.jikabiz.app.ui

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.graphics.Color
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.os.Build
import android.os.Bundle
import android.view.View
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.app.AppCompatDelegate
import androidx.lifecycle.lifecycleScope
import androidx.navigation.NavController
import androidx.navigation.fragment.NavHostFragment
import androidx.navigation.ui.setupWithNavController
import com.jikabiz.app.R
import com.jikabiz.app.api.RetrofitClient
import com.jikabiz.app.databinding.ActivityMainBinding
import com.jikabiz.app.ui.auth.LoginActivity
import com.jikabiz.app.util.AppUpdateChecker
import com.jikabiz.app.util.Constants
import com.jikabiz.app.util.PreferenceManager
import com.jikabiz.app.util.RequiredSetup
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var prefs: PreferenceManager
    private lateinit var connectivityManager: ConnectivityManager
    private lateinit var navController: NavController
    private var networkCallback: ConnectivityManager.NetworkCallback? = null
    private var isCurrentlyOnline = true
    private var kycGateLocked = false
    private var kycPollJob: kotlinx.coroutines.Job? = null

    private val complianceReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.action == RequiredSetup.ACTION_KYC_GATE_REQUIRED) {
                applyKycGateLock(true)
            }
            refreshComplianceGate()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        // Apply dark mode preference before super.onCreate / setContentView
        val prefsEarly = PreferenceManager(this)
        val darkModeEnabled = prefsEarly.getBoolean(Constants.KEY_DARK_MODE, false)
        AppCompatDelegate.setDefaultNightMode(
            if (darkModeEnabled) AppCompatDelegate.MODE_NIGHT_YES
            else AppCompatDelegate.MODE_NIGHT_NO
        )

        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)
        prefs = prefsEarly

        if (!prefs.isLoggedIn()) {
            startActivity(Intent(this, LoginActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
            })
            finish()
            return
        }

        val navHostFragment = supportFragmentManager.findFragmentById(R.id.nav_host_fragment) as NavHostFragment
        navController = navHostFragment.navController
        binding.bottomNav.setupWithNavController(navController)

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (kycGateLocked && navController.currentDestination?.id == R.id.nav_kyc) {
                    // Keep confined to the compliance gate until verified or signed out.
                    return
                }
                isEnabled = false
                onBackPressedDispatcher.onBackPressed()
                isEnabled = true
            }
        })

        navController.addOnDestinationChangedListener { _, destination, _ ->
            if (kycGateLocked && destination.id != R.id.nav_kyc) {
                navController.navigate(R.id.nav_kyc)
            }
        }

        if (prefs.getBoolean("kyc_gate_locked", false)) {
            applyKycGateLock(true)
        }

        setupNetworkMonitoring()

        // Weekly Play In-App Updates check. No-ops while the 7-day window is open, and does
        // nothing at all for APKs sideloaded from the website (Play has no record of them).
        AppUpdateChecker.check(this)
        refreshComplianceGate()
    }

    override fun onStart() {
        super.onStart()
        val filter = IntentFilter().apply {
            addAction(RequiredSetup.ACTION_KYC_GATE_REQUIRED)
            addAction(RequiredSetup.ACTION_COMPLIANCE_UPDATE)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(complianceReceiver, filter, Context.RECEIVER_NOT_EXPORTED)
        } else {
            @Suppress("DEPRECATION")
            registerReceiver(complianceReceiver, filter)
        }
    }

    override fun onStop() {
        super.onStop()
        runCatching { unregisterReceiver(complianceReceiver) }
    }

    override fun onResume() {
        super.onResume()
        // Resume an IMMEDIATE update the user interrupted, so the old code cannot keep running.
        AppUpdateChecker.resumePendingImmediateUpdate(this)
        refreshComplianceGate()
        startKycPollingIfNeeded()
        runOnboardingChecks()
    }

    override fun onPause() {
        super.onPause()
        kycPollJob?.cancel()
        kycPollJob = null
    }

    private fun startKycPollingIfNeeded() {
        kycPollJob?.cancel()
        if (!kycGateLocked) return
        kycPollJob = lifecycleScope.launch {
            while (kycGateLocked) {
                kotlinx.coroutines.delay(6000)
                refreshComplianceGate()
            }
        }
    }

    private fun runOnboardingChecks() {
        if (com.jikabiz.app.util.OnboardingCoordinator.needsTotp(prefs)) {
            startActivity(Intent(this, com.jikabiz.app.ui.security.TotpSetupActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
            })
            finish()
            return
        }
        if (kycGateLocked) return
        if (com.jikabiz.app.util.OnboardingCoordinator.needsPin(prefs)) {
            if (::navController.isInitialized && navController.currentDestination?.id != R.id.nav_set_pin) {
                runCatching { navController.navigate(R.id.nav_set_pin) }
            }
            return
        }
        if (com.jikabiz.app.util.OnboardingCoordinator.needsSecurityQuest(prefs)) {
            startActivity(Intent(this, com.jikabiz.app.ui.security.LockActivity::class.java))
            return
        }
        com.jikabiz.app.util.OnboardingCoordinator.maybeOfferBiometric(this, prefs)
    }

    fun refreshComplianceGate() {
        val apiKey = prefs.getApiKey()
        if (apiKey.isBlank()) return
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().kycAction(mapOf("api_key" to apiKey, "action" to "status"))
                val body = resp.body()
                if (resp.isSuccessful && body?.get("status") == "success") {
                    val wasLocked = kycGateLocked
                    com.jikabiz.app.util.OnboardingCoordinator.syncFlags(prefs, body)
                    val locked = (body["kyc_gate_locked"] as? Boolean)
                        ?: (((body["kyc_enforced"] as? Boolean) == true) && ((body["kyc_status"] as? Number)?.toInt() != 2))
                    prefs.saveBoolean("kyc_gate_locked", locked)
                    applyKycGateLock(locked)
                    if (locked) {
                        if (kycPollJob == null || kycPollJob?.isActive != true) startKycPollingIfNeeded()
                    } else {
                        kycPollJob?.cancel()
                        kycPollJob = null
                        if (wasLocked) {
                            Snackbar.make(binding.root, "Identity verified! Continuing setup…", Snackbar.LENGTH_SHORT).show()
                            if (::navController.isInitialized && navController.currentDestination?.id == R.id.nav_kyc) {
                                navController.popBackStack(R.id.nav_home, false)
                            }
                            runOnboardingChecks()
                        }
                    }
                }
            } catch (_: Exception) { }
        }
    }

    private fun applyKycGateLock(locked: Boolean) {
        kycGateLocked = locked
        binding.bottomNav.visibility = if (locked) View.GONE else View.VISIBLE
        if (locked && ::navController.isInitialized && navController.currentDestination?.id != R.id.nav_kyc) {
            runCatching { navController.navigate(R.id.nav_kyc) }
        } else if (!locked && ::navController.isInitialized && navController.currentDestination?.id == R.id.nav_kyc && prefs.getBoolean("kyc_just_unlocked", false)) {
            prefs.saveBoolean("kyc_just_unlocked", false)
            navController.popBackStack(R.id.nav_home, false)
            runOnboardingChecks()
        }
    }

    private fun setupNetworkMonitoring() {
        connectivityManager = getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager

        // Check initial state
        val activeNetwork = connectivityManager.activeNetwork
        val caps = connectivityManager.getNetworkCapabilities(activeNetwork)
        isCurrentlyOnline = caps != null && (caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)
                || caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR)
                || caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET))

        val request = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .build()

        networkCallback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                if (!isCurrentlyOnline) {
                    isCurrentlyOnline = true
                    runOnUiThread { showNetworkSnack("✅  Internet connection restored", Color.parseColor("#2E7D32")) }
                }
            }

            override fun onLost(network: Network) {
                isCurrentlyOnline = false
                runOnUiThread { showNetworkSnack("⚠️  No internet connection", Color.parseColor("#C62828"), indefinite = true) }
            }
        }
        connectivityManager.registerNetworkCallback(request, networkCallback!!)
    }

    private fun showNetworkSnack(msg: String, bgColor: Int, indefinite: Boolean = false) {
        val duration = if (indefinite) Snackbar.LENGTH_INDEFINITE else Snackbar.LENGTH_LONG
        Snackbar.make(binding.root, msg, duration).apply {
            view.setBackgroundColor(bgColor)
            view.findViewById<android.widget.TextView>(com.google.android.material.R.id.snackbar_text)
                ?.setTextColor(Color.WHITE)
        }.show()
    }

    override fun onDestroy() {
        super.onDestroy()
        networkCallback?.let { connectivityManager.unregisterNetworkCallback(it) }
    }
}
