import Foundation

/// Guest Mode has no login/session, so there is nothing on the server to page through — history
/// is cached on-device only, as JSON in UserDefaults, keyed by transaction reference.
enum GuestHistoryStore {
    private static let key = "guest_history_receipts"
    private static let maxEntries = 50

    static func load() -> [GuestReceipt] {
        guard let data = UserDefaults.standard.data(forKey: key) else { return [] }
        guard let receipts = try? JSONDecoder().decode([GuestReceipt].self, from: data) else { return [] }
        return receipts.sorted { $0.date > $1.date }
    }

    @discardableResult
    static func save(_ receipt: GuestReceipt) -> [GuestReceipt] {
        var deduped = load().filter { $0.reference != receipt.reference }
        deduped.insert(receipt, at: 0)
        let updated = Array(deduped.sorted { $0.date > $1.date }.prefix(maxEntries))
        if let data = try? JSONEncoder().encode(updated) {
            UserDefaults.standard.set(data, forKey: key)
        }
        return updated
    }
}
