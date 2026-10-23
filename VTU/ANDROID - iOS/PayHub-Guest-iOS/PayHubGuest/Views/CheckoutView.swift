import SwiftUI
import WebKit

/// Loads the PayHub-hosted checkout URL returned by checkout-init.php directly — no local
/// gateway-SDK HTML/JS bridge is needed since Guest Mode uses PayHub exclusively and
/// checkout-init.php already returns a ready-to-load hosted page.
///
/// Completion is detected two ways, whichever fires first:
///  1. URL watch — the WKWebView lands on our guest-payment-complete.php callback.
///  2. Status poll — viewModel.watchPayment() polls status.php every 3s. This is the one that
///     actually matters in practice: PayHub's hosted page was observed redirecting to
///     merchant.payhub.com.ng home INSTEAD of our callback_url, so the URL watch alone left
///     paid guests stranded. status.php also self-verifies + fulfills server-side, so polling
///     it both detects payment and drives crediting even if PayHub's webhook never arrives.
/// Both paths funnel through viewModel.paymentDetected so navigation fires exactly once.
struct CheckoutView: View {
    @ObservedObject var viewModel: GuestViewModel
    let onCancel: () -> Void
    let onPaymentComplete: (String) -> Void

    var body: some View {
        ZStack(alignment: .bottom) {
            LinearGradient(colors: [PHColor.primary, PHColor.primaryDark], startPoint: .top, endPoint: .bottom)
                .ignoresSafeArea()

            if case .ready(let reference, let checkoutUrl, _) = viewModel.checkoutState, let url = URL(string: checkoutUrl) {
                PayHubWebView(url: url) { loadedUrl in
                    let s = loadedUrl.absoluteString
                    if s.contains("guest-payment-complete.php") || s.contains("payhub-success.php") {
                        viewModel.notifyPaymentDetected(reference: reference)
                    }
                }
                .onAppear { viewModel.watchPayment(reference: reference) }
            } else {
                VStack(spacing: 16) {
                    ProgressView().tint(.white)
                    Text("Redirecting you to PayHub secure checkout…")
                        .font(.system(size: 15, weight: .bold))
                        .foregroundColor(.white)
                }
            }

            Button("Cancel and go back") { onCancel() }
                .font(.system(size: 13))
                .foregroundColor(.white.opacity(0.85))
                .padding(.bottom, 24)
        }
        .onChange(of: viewModel.paymentDetected) { detected in
            if let reference = detected {
                viewModel.resetPaymentWatch()
                onPaymentComplete(reference)
            }
        }
    }
}

private struct PayHubWebView: UIViewRepresentable {
    let url: URL
    let onNavigate: (URL) -> Void

    func makeCoordinator() -> Coordinator { Coordinator(onNavigate: onNavigate) }

    func makeUIView(context: Context) -> WKWebView {
        let webView = WKWebView()
        webView.navigationDelegate = context.coordinator
        webView.load(URLRequest(url: url))
        return webView
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {}

    final class Coordinator: NSObject, WKNavigationDelegate {
        let onNavigate: (URL) -> Void
        init(onNavigate: @escaping (URL) -> Void) { self.onNavigate = onNavigate }

        func webView(_ webView: WKWebView, didStartProvisionalNavigation navigation: WKNavigation!) {
            if let url = webView.url { onNavigate(url) }
        }
    }
}
