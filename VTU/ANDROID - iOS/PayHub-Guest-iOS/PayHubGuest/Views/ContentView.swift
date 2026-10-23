import SwiftUI

struct ContentView: View {
    @StateObject private var viewModel = GuestViewModel()
    @StateObject private var connectivity = ConnectivityMonitor()

    private var isTopLevel: Bool {
        switch viewModel.screen {
        case .home, .services, .history, .support: return true
        default: return false
        }
    }

    private var currentTab: GuestTab {
        switch viewModel.screen {
        case .services: return .services
        case .history: return .history
        case .support: return .support
        default: return .home
        }
    }

    var body: some View {
        ZStack(alignment: .bottom) {
            PHColor.bg.ignoresSafeArea()

            switch viewModel.screen {
            case .splash:
                SplashView { viewModel.screen = .home }
            case .home:
                HomeView(viewModel: viewModel)
            case .services:
                ServicesView(viewModel: viewModel)
            case .purchase(let service):
                PurchaseView(service: service, viewModel: viewModel)
            case .checkout:
                CheckoutView(
                    viewModel: viewModel,
                    onCancel: {
                        viewModel.resetCheckout()
                        viewModel.goBack()
                    },
                    onPaymentComplete: { reference in
                        viewModel.setTab(.home)
                        viewModel.navigate(to: .receipt(reference))
                    }
                )
            case .receipt(let reference):
                ReceiptView(reference: reference, viewModel: viewModel) {
                    viewModel.resetCheckout()
                    viewModel.resetReceipt()
                    viewModel.setTab(.home)
                }
            case .history:
                HistoryView(history: viewModel.transactionHistory, viewModel: viewModel)
                    .onAppear { viewModel.refreshPendingHistory() }
            case .support:
                SupportView(supportInfo: viewModel.supportInfo)
            }

            if isTopLevel {
                // Home indicator sits closer to the true screen edge, independent of the
                // floating bar's own margin — matches the mockup's separate bottom:8px vs the
                // bar's bottom:22px.
                GuestHomeIndicator()
                    .padding(.bottom, 8)

                GuestBottomNavArea(current: currentTab) { tab in
                    viewModel.setTab(tab.screen)
                }
                .padding(.horizontal, 20)
                .padding(.bottom, 16)
            }

            VStack {
                ConnectivityBanner(isOnline: connectivity.isOnline)
                Spacer()
            }
        }
    }
}
