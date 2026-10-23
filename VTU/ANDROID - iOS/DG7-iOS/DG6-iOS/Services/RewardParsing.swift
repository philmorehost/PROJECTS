import Foundation
import SwiftUI
import UIKit

// MARK: - Tolerant JSON coercion
//
// Every value in these responses comes out of PHP's mysqli_fetch_assoc(), which returns each column
// as a String whatever its SQL type: an INT arrives as "500", a DECIMAL as "1.00", a TINYINT as "1".
// Casting with `as? Int` therefore fails on data that is perfectly valid, so these helpers accept a
// Number OR a String. They also mean a later schema change (an INT becoming a DECIMAL) cannot
// silently break a screen.

func bcInt(_ any: Any?) -> Int {
    switch any {
    case let n as Int: return n
    case let n as Int64: return Int(n)
    case let d as Double: return Int(d)
    case let b as Bool: return b ? 1 : 0
    case let s as String: return Int(Double(s) ?? 0)
    default: return 0
    }
}

func bcDouble(_ any: Any?) -> Double {
    switch any {
    case let d as Double: return d
    case let n as Int: return Double(n)
    case let n as Int64: return Double(n)
    case let b as Bool: return b ? 1 : 0
    case let s as String: return Double(s) ?? 0
    default: return 0
    }
}

func bcString(_ any: Any?) -> String {
    switch any {
    case let s as String: return s
    case let n as NSNumber: return n.stringValue
    default: return ""
    }
}

/// The endpoints report booleans as real JSON booleans (`coins_enabled`) and also as the strings
/// "Yes"/"No" (`is_eligible`, `qualified`), because that is what the website prints.
func bcBool(_ any: Any?) -> Bool {
    switch any {
    case let b as Bool: return b
    case let n as Int: return n != 0
    case let s as String:
        let t = s.trimmingCharacters(in: .whitespaces).lowercased()
        return t == "yes" || t == "true" || t == "1"
    default: return false
    }
}

func bcDict(_ any: Any?) -> [String: Any] { any as? [String: Any] ?? [:] }
func bcArray(_ any: Any?) -> [[String: Any]] { any as? [[String: Any]] ?? [] }

/// Unwraps the `data` object the endpoints wrap their payload in, and surfaces the server's own
/// error message when `status` is not "success".
func bcPayload(_ object: [String: Any]) -> (data: [String: Any], error: String?) {
    let status = bcString(object["status"]).lowercased()
    if status == "success" {
        return (bcDict(object["data"]), nil)
    }
    let message = bcString(object["message"])
    return ([:], message.isEmpty ? "The server rejected the request." : message)
}

// MARK: - Formatting

func bcNaira(_ value: Double) -> String {
    let f = NumberFormatter()
    f.numberStyle = .decimal
    f.minimumFractionDigits = 2
    f.maximumFractionDigits = 2
    let number = f.string(from: NSNumber(value: value)) ?? String(format: "%.2f", value)
    return "₦" + number
}

func bcThousands(_ value: Int) -> String {
    let f = NumberFormatter()
    f.numberStyle = .decimal
    f.maximumFractionDigits = 0
    return f.string(from: NSNumber(value: value)) ?? "\(value)"
}

/// The endpoints return MySQL timestamps ("2026-02-01 09:00:00") for some fields and ISO-ish
/// strings for others, so this only trims rather than attempting to parse either form.
func bcShortDate(_ raw: String) -> String {
    guard !raw.isEmpty else { return "—" }
    let cleaned = raw.replacingOccurrences(of: "T", with: " ")
    return String(cleaned.prefix(16))
}

/// Sends the payload to the share sheet. ShareLink needs iOS 16 and the target is iOS 15.
struct BCShareSheet: UIViewControllerRepresentable {
    let items: [Any]

    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: items, applicationActivities: nil)
    }

    func updateUIViewController(_ controller: UIActivityViewController, context: Context) {}
}
