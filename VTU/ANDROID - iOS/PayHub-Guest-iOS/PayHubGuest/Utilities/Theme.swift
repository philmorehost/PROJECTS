import SwiftUI

/// Mirrors scratch/payhub-guest-app-mockup.html's :root CSS custom properties exactly
/// (also mirrored in the Android app's ui/theme/Color.kt for cross-platform consistency).
enum PHColor {
    static let primary = Color(hex: 0x0D6EFD)
    static let primaryDark = Color(hex: 0x0A58CA)
    static let primaryLight = Color(hex: 0xE7F0FF)

    static let bg = Color(hex: 0xF6F9FF)
    static let surface = Color.white
    static let text = Color(hex: 0x1E293B)
    static let text2 = Color(hex: 0x64748B)
    static let success = Color(hex: 0x22C55E)
    static let successBg = Color(hex: 0xDCFCE7)
    static let warning = Color(hex: 0xF59E0B)
    static let error = Color(hex: 0xEF4444)

    static let airtime = Color(hex: 0xFF6B35)
    static let airtimeBg = Color(hex: 0xFFF0EB)
    static let data = Color(hex: 0x4361EE)
    static let dataBg = Color(hex: 0xEEF0FD)
    static let electric = Color(hex: 0xF7B731)
    static let electricBg = Color(hex: 0xFEFAE0)
    static let cable = Color(hex: 0x26DE81)
    static let cableBg = Color(hex: 0xE8FDF3)
    static let exam = Color(hex: 0xFC5C65)
    static let examBg = Color(hex: 0xFEECEE)
    static let betting = Color(hex: 0x14B8A6)
    static let bettingBg = Color(hex: 0xE0FBF7)
}

extension Color {
    init(hex: UInt32, alpha: Double = 1.0) {
        self.init(
            .sRGB,
            red: Double((hex >> 16) & 0xFF) / 255,
            green: Double((hex >> 8) & 0xFF) / 255,
            blue: Double(hex & 0xFF) / 255,
            opacity: alpha
        )
    }
}
