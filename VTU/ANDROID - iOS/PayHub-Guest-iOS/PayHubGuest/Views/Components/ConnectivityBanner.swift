import SwiftUI

/// Sits above everything else in ContentView so it's visible no matter which screen is
/// showing. Stays up persistently while offline (there's no useful "dismiss" — every screen
/// depends on the network), then flashes a "Back Online" confirmation for a couple seconds on
/// recovery so the guest knows it's safe to retry whatever just failed.
struct ConnectivityBanner: View {
    let isOnline: Bool

    @State private var showRecovered = false
    @State private var wasOffline = false

    private var visible: Bool { !isOnline || showRecovered }

    var body: some View {
        Group {
            if visible {
                HStack(spacing: 8) {
                    Image(systemName: isOnline ? "checkmark.icloud.fill" : "wifi.slash")
                    Text(isOnline ? "Back Online" : "No Internet Connection")
                        .font(.system(size: 13, weight: .semibold))
                }
                .foregroundColor(.white)
                .padding(.horizontal, 16)
                .padding(.vertical, 10)
                .frame(maxWidth: .infinity)
                .background(isOnline ? PHColor.success : PHColor.error)
                .transition(.move(edge: .top).combined(with: .opacity))
            }
        }
        .animation(.easeInOut(duration: 0.25), value: visible)
        .onChange(of: isOnline) { online in
            if !online {
                wasOffline = true
                showRecovered = false
            } else if wasOffline {
                wasOffline = false
                showRecovered = true
                DispatchQueue.main.asyncAfter(deadline: .now() + 2.5) {
                    showRecovered = false
                }
            }
        }
    }
}
