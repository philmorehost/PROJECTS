import Foundation
import Network

/// Guest checkout depends entirely on live network calls (catalog, checkout-init, status poll)
/// with no offline mode — a guest losing signal mid-purchase needs to see that immediately
/// rather than stare at a spinner. NWPathMonitor needs no extra entitlement or Info.plist key.
@MainActor
final class ConnectivityMonitor: ObservableObject {
    @Published private(set) var isOnline: Bool = true

    private let monitor = NWPathMonitor()
    private let queue = DispatchQueue(label: "com.payhub.guest.connectivity")

    init() {
        monitor.pathUpdateHandler = { [weak self] path in
            let online = path.status == .satisfied
            Task { @MainActor in
                self?.isOnline = online
            }
        }
        monitor.start(queue: queue)
    }

    deinit {
        monitor.cancel()
    }
}
