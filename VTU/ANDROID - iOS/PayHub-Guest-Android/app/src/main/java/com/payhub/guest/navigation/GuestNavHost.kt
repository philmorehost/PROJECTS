package com.payhub.guest.navigation

import androidx.compose.animation.core.animateDpAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.NavType
import androidx.navigation.navArgument
import com.payhub.guest.ui.GuestViewModel
import com.payhub.guest.ui.checkout.CheckoutScreen
import com.payhub.guest.ui.components.BottomNavBar
import com.payhub.guest.ui.components.ConnectivityBanner
import com.payhub.guest.ui.components.GuestHomeIndicator
import com.payhub.guest.ui.components.GuestNavBubble
import com.payhub.guest.ui.components.GuestNavSpringSpec
import com.payhub.guest.ui.components.GuestTab
import com.payhub.guest.ui.history.HistoryScreen
import com.payhub.guest.ui.home.HomeScreen
import com.payhub.guest.ui.purchase.PurchaseScreen
import com.payhub.guest.ui.receipt.ReceiptScreen
import com.payhub.guest.ui.services.ServicesScreen
import com.payhub.guest.ui.splash.SplashScreen
import com.payhub.guest.ui.support.SupportScreen
import com.payhub.guest.ui.theme.CBg

private object Routes {
    const val SPLASH = "splash"
    const val HOME = "home"
    const val SERVICES = "services"
    const val PURCHASE = "purchase/{service}"
    const val CHECKOUT = "checkout"
    const val RECEIPT = "receipt/{reference}"
    const val HISTORY = "history"
    const val SUPPORT = "support"

    fun purchase(service: String) = "purchase/$service"
    fun receipt(reference: String) = "receipt/$reference"
}

private val TOP_LEVEL_ROUTES = setOf(Routes.HOME, Routes.SERVICES, Routes.HISTORY, Routes.SUPPORT)

@Composable
fun GuestNavHost(viewModel: GuestViewModel) {
    val navController = rememberNavController()
    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination?.route
    val isTopLevel = currentRoute in TOP_LEVEL_ROUTES

    Box(modifier = Modifier.fillMaxSize().background(CBg)) {
        NavHost(navController = navController, startDestination = Routes.SPLASH, modifier = Modifier.fillMaxSize()) {
            composable(Routes.SPLASH) {
                SplashScreen(onContinue = {
                    navController.navigate(Routes.HOME) { popUpTo(Routes.SPLASH) { inclusive = true } }
                })
            }
            composable(Routes.HOME) {
                val enabledServices by viewModel.enabledServices.collectAsState()
                val transactionHistory by viewModel.transactionHistory.collectAsState()
                val isRefreshing by viewModel.isRefreshing.collectAsState()
                androidx.compose.runtime.LaunchedEffect(Unit) { viewModel.refreshPendingHistory() }
                HomeScreen(
                    enabledServices = enabledServices,
                    recentTransactions = transactionHistory,
                    isRefreshing = isRefreshing,
                    onRefresh = { viewModel.refresh() },
                    onOpenService = { service -> navController.navigate(Routes.purchase(service)) },
                    onOpenHistory = { navController.navigate(Routes.HISTORY) },
                )
            }
            composable(Routes.SERVICES) {
                val enabledServices by viewModel.enabledServices.collectAsState()
                val isRefreshing by viewModel.isRefreshing.collectAsState()
                ServicesScreen(
                    enabledServices = enabledServices,
                    isRefreshing = isRefreshing,
                    onRefresh = { viewModel.refresh() },
                    onOpenService = { service -> navController.navigate(Routes.purchase(service)) },
                )
            }
            composable(
                Routes.PURCHASE,
                arguments = listOf(navArgument("service") { type = NavType.StringType }),
            ) { entry ->
                val service = entry.arguments?.getString("service") ?: "airtime"
                PurchaseScreen(
                    service = service,
                    viewModel = viewModel,
                    onBack = { navController.popBackStack() },
                    onCheckoutReady = { navController.navigate(Routes.CHECKOUT) },
                )
            }
            composable(Routes.CHECKOUT) {
                CheckoutScreen(
                    viewModel = viewModel,
                    onCancel = {
                        viewModel.resetCheckout()
                        navController.popBackStack()
                    },
                    onPaymentComplete = { reference ->
                        navController.navigate(Routes.receipt(reference)) {
                            popUpTo(Routes.HOME)
                        }
                    },
                )
            }
            composable(
                Routes.RECEIPT,
                arguments = listOf(navArgument("reference") { type = NavType.StringType }),
            ) { entry ->
                val reference = entry.arguments?.getString("reference") ?: ""
                ReceiptScreen(
                    reference = reference,
                    viewModel = viewModel,
                    onDone = {
                        viewModel.resetCheckout()
                        viewModel.resetReceipt()
                        navController.navigate(Routes.HOME) { popUpTo(Routes.HOME) { inclusive = true } }
                    },
                )
            }
            composable(Routes.HISTORY) {
                val transactionHistory by viewModel.transactionHistory.collectAsState()
                val isRefreshing by viewModel.isRefreshing.collectAsState()
                androidx.compose.runtime.LaunchedEffect(Unit) { viewModel.refreshPendingHistory() }
                HistoryScreen(
                    history = transactionHistory,
                    isRefreshing = isRefreshing,
                    onRefresh = { viewModel.refresh() },
                )
            }
            composable(Routes.SUPPORT) {
                val supportInfo by viewModel.supportInfo.collectAsState()
                SupportScreen(supportInfo = supportInfo)
            }
        }

        if (isTopLevel) {
            val currentTab = when (currentRoute) {
                Routes.SERVICES -> GuestTab.Services
                Routes.HISTORY -> GuestTab.History
                Routes.SUPPORT -> GuestTab.Support
                else -> GuestTab.Home
            }
            // Home indicator sits closer to the true screen edge, independent of the floating
            // bar's own margin — matches the mockup's separate bottom:8px vs the bar's bottom:22px.
            Box(modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = 8.dp)) {
                GuestHomeIndicator()
            }
            BoxWithConstraints(
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
                    .padding(horizontal = 20.dp, vertical = 16.dp),
            ) {
                val tabIndex = GuestTab.values().indexOf(currentTab)
                val tabFraction = (tabIndex + 0.5f) / GuestTab.values().size
                val notchX by animateDpAsState(
                    targetValue = maxWidth * tabFraction,
                    animationSpec = GuestNavSpringSpec,
                    label = "notchX",
                )
                BottomNavBar(current = currentTab, notchX = notchX) { tab ->
                    val route = when (tab) {
                        GuestTab.Home -> Routes.HOME
                        GuestTab.Services -> Routes.SERVICES
                        GuestTab.History -> Routes.HISTORY
                        GuestTab.Support -> Routes.SUPPORT
                    }
                    navController.navigate(route) {
                        popUpTo(navController.graph.findStartDestination().id) { saveState = true }
                        launchSingleTop = true
                        restoreState = true
                    }
                }
                GuestNavBubble(current = currentTab, bubbleX = notchX)
            }
        }

        val isOnline by viewModel.isOnline.collectAsState()
        Box(modifier = Modifier.align(Alignment.TopCenter)) {
            ConnectivityBanner(isOnline = isOnline)
        }
    }
}
