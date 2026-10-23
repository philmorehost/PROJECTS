package com.datagifting.app.ui

import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.app.AppCompatDelegate
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.NavHostFragment
import androidx.navigation.ui.setupWithNavController
import com.datagifting.app.R
import com.datagifting.app.databinding.ActivityMainBinding
import com.datagifting.app.ui.auth.LoginActivity
import com.datagifting.app.util.Constants
import com.datagifting.app.util.PreferenceManager
import com.google.android.material.snackbar.Snackbar

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var prefs: PreferenceManager
    private lateinit var connectivityManager: ConnectivityManager
    private var networkCallback: ConnectivityManager.NetworkCallback? = null
    private var isCurrentlyOnline = true

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
        val navController = navHostFragment.navController
        binding.bottomNav.setupWithNavController(navController)

        setupNetworkMonitoring()
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
