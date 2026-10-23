package com.payhub.guest.util

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import kotlinx.coroutines.channels.awaitClose
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.callbackFlow
import kotlinx.coroutines.flow.distinctUntilChanged

/**
 * Guest checkout depends entirely on live network calls (catalog, checkout-init, status poll)
 * with no offline mode — a guest losing signal mid-purchase needs to see that immediately
 * rather than stare at a spinner. Emits the current internet-reachability state and every
 * change; ACCESS_NETWORK_STATE (already declared in the manifest) is the only permission this
 * needs, unlike location-based Wi-Fi APIs.
 */
object ConnectivityObserver {
    fun observe(context: Context): Flow<Boolean> = callbackFlow {
        val connectivityManager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager

        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) { trySend(true) }
            override fun onLost(network: Network) { trySend(false) }
            override fun onCapabilitiesChanged(network: Network, capabilities: NetworkCapabilities) {
                trySend(capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET))
            }
        }

        val request = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .build()

        // Emit the current state immediately so the banner doesn't briefly assume "online"
        // before the first callback fires.
        val activeCapabilities = connectivityManager.activeNetwork?.let { connectivityManager.getNetworkCapabilities(it) }
        trySend(activeCapabilities?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) == true)

        connectivityManager.registerNetworkCallback(request, callback)
        awaitClose { connectivityManager.unregisterNetworkCallback(callback) }
    }.distinctUntilChanged()
}
