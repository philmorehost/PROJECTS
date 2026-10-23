import SwiftUI

struct GuestServiceEntry: Identifiable {
    let id = UUID()
    /// App-internal navigation key (used by viewModel.navigate(to: .purchase(key))).
    let key: String
    /// Key as stored in sas_service_control / catalog.php's `service=` param — only differs
    /// from `key` for electricity ("electricity" navigation key vs "electric" backend key).
    let serviceControlKey: String
    let title: String
    /// Terser label for HomeView's compact quick-action tiles (e.g. "Electric" vs "Electricity").
    let shortLabel: String
    let caption: String
    let icon: String
    let color: Color
    let bg: Color
}

/// Single source of truth for the guest app's service tiles — previously ServicesView.swift and
/// HomeView.swift each hardcoded their own separate list, which would silently drift. Both views
/// now read ALL and filter it against the site-info.php-driven enabledServices map.
enum GuestServiceCatalog {
    static let all: [GuestServiceEntry] = [
        GuestServiceEntry(key: "airtime", serviceControlKey: "airtime", title: "Airtime", shortLabel: "Airtime", caption: "Top up any network", icon: "phone.fill", color: PHColor.airtime, bg: PHColor.airtimeBg),
        GuestServiceEntry(key: "data", serviceControlKey: "data", title: "Data Bundle", shortLabel: "Data", caption: "SME, Shared, CG & Direct", icon: "wifi", color: PHColor.data, bg: PHColor.dataBg),
        GuestServiceEntry(key: "cable", serviceControlKey: "cable", title: "Cable TV", shortLabel: "Cable TV", caption: "DStv, GOtv & more", icon: "tv.fill", color: PHColor.cable, bg: PHColor.cableBg),
        GuestServiceEntry(key: "electricity", serviceControlKey: "electric", title: "Electricity", shortLabel: "Electric", caption: "Prepaid & postpaid", icon: "bolt.fill", color: PHColor.electric, bg: PHColor.electricBg),
        GuestServiceEntry(key: "exam", serviceControlKey: "exam", title: "Exam Pins", shortLabel: "Exam Pins", caption: "WAEC, NECO & more", icon: "graduationcap.fill", color: PHColor.exam, bg: PHColor.examBg),
        GuestServiceEntry(key: "betting", serviceControlKey: "betting", title: "Betting", shortLabel: "Betting", caption: "Fund your wallet", icon: "dice.fill", color: PHColor.betting, bg: PHColor.bettingBg),
    ]

    /// A service key absent from `enabledServices` is treated as enabled — mirrors
    /// isServiceEnabled()'s own default on the backend (no row = not yet configured, not disabled).
    static func filterEnabled(_ enabledServices: [String: Int]) -> [GuestServiceEntry] {
        all.filter { (enabledServices[$0.serviceControlKey] ?? 1) != 0 }
    }
}
