import SwiftUI

@main
@MainActor
struct MZEEVTUApp: App {
    @StateObject private var requiredSetup = RequiredSetupState.shared
    @State private var checkingSession = true
    @State private var validatingSession = false
    @StateObject private var brandTheme = BrandTheme.shared
    @Environment(\.scenePhase) private var themeScenePhase
    @State private var isLoggedIn = AppNetworkService.shared.apiKey != nil
    // The web cannot be reached past the dashboard without a PIN: web/Register.php ends with
    // `header("Location: SecurityPIN.php")` and web/Dashboard.php bounces PIN-less accounts back
    // there. Mirroring that here is what makes an iOS sign-up actually usable.
    @State private var pinSet = AppNetworkService.shared.pinSet

    var body: some Scene {
        WindowGroup {
            Group {
            if requiredSetup.blocked {
                RequiredSetupView(retry: {
                    isLoggedIn = false
                    requiredSetup.retry()
                })
            } else if checkingSession {
                BrandedStartupView()
            } else if !isLoggedIn {
                LoginView(onLoginSuccess: {
                    pinSet = AppNetworkService.shared.pinSet
                    isLoggedIn = true
                })
                .environmentObject(SessionManager(isLoggedIn: $isLoggedIn))
            } else if !pinSet {
                PinSetupView(
                    onComplete: { pinSet = true },
                    onLogout: {
                        AppNetworkService.shared.apiKey = nil
                        AppNetworkService.shared.pinSet = false
                        isLoggedIn = false
                    }
                )
                .environmentObject(SessionManager(isLoggedIn: $isLoggedIn))
            } else {
                DashboardView()
                    .environmentObject(SessionManager(isLoggedIn: $isLoggedIn))
            }
            }
            .tint(brandTheme.ink)
            .task {
                validateSession()
                await brandTheme.refresh()
            }
            .onChange(of: themeScenePhase) { phase in
                if phase == .active { validateSession(); Task { await brandTheme.refresh() } }
            }
            .onReceive(Timer.publish(every: 60, on: .main, in: .common).autoconnect()) { _ in
                if themeScenePhase == .active { Task { await brandTheme.refresh() } }
            }
        }
    }
    private func validateSession() {
        guard !validatingSession else { return }
        guard AppNetworkService.shared.apiKey != nil else { checkingSession = false; return }
        checkingSession = true
        validatingSession = true
        AppNetworkService.shared.requestObject("profile.php", params: [:]) { _ in
            checkingSession = false
            validatingSession = false
        }
    }

}

class SessionManager: ObservableObject {
    @Binding var isLoggedIn: Bool

    init(isLoggedIn: Binding<Bool>) {
        self._isLoggedIn = isLoggedIn
    }

    func logout() {
        AppNetworkService.shared.apiKey = nil
        AppNetworkService.shared.pinSet = false
        isLoggedIn = false
    }
}
