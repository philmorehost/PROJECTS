import Foundation
import Security

class AppNetworkService {
    static let shared = AppNetworkService()

    private var baseURL: String {
        // Optional build-time tenant configuration; never accept an arbitrary in-app URL.
        if let configured = Bundle.main.object(forInfoDictionaryKey: "PlatformBaseURL") as? String,
           let url = URL(string: configured), url.scheme == "https", url.host != nil,
           url.user == nil, url.password == nil, url.query == nil, url.fragment == nil {
            return configured.hasSuffix("/") ? configured : configured + "/"
        }
        return "https://jikabiz.com/"
    }

    var mobileOrigin: String { baseURL }

    var apiKey: String? {
        get {
            UserDefaults.standard.removeObject(forKey: "user_api_key")
            let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword,
                kSecAttrService as String: "platform.app-session", kSecAttrAccount as String: "bearer",
                kSecReturnData as String: true, kSecMatchLimit as String: kSecMatchLimitOne]
            var result: CFTypeRef?
            guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
                  let data = result as? Data else { return nil }
            return String(data: data, encoding: .utf8)
        }
        set {
            UserDefaults.standard.removeObject(forKey: "user_api_key")
            let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword,
                kSecAttrService as String: "platform.app-session", kSecAttrAccount as String: "bearer"]
            SecItemDelete(query as CFDictionary)
            if let token = newValue, let data = token.data(using: .utf8) {
                var item = query
                item[kSecValueData as String] = data
                item[kSecAttrAccessible as String] = kSecAttrAccessibleWhenUnlockedThisDeviceOnly
                _ = SecItemAdd(item as CFDictionary, nil)
            }
        }
    }

    /// Mirrors the server's `security_pin_set`. The website finishes registration with
    /// `header("Location: SecurityPIN.php")` and `web/Dashboard.php` bounces PIN-less accounts back
    /// there, so the app reproduces the same gate in `JIKABIZApp` instead of letting a new account
    /// reach the dashboard with no transaction PIN.
    var pinSet: Bool {
        get { UserDefaults.standard.bool(forKey: "security_pin_set") }
        set { UserDefaults.standard.set(newValue, forKey: "security_pin_set") }
    }

    /// The vendor's `force_security_pin` flag (returned by profile.php / login.php as `pin_required`).
    var pinRequired: Bool {
        get { UserDefaults.standard.bool(forKey: "pin_required") }
        set { UserDefaults.standard.set(newValue, forKey: "pin_required") }
    }

    func request<T: Decodable>(_ endpoint: String, params: [String: Any], completion: @escaping (Result<T, Error>) -> Void) {
        guard let url = URL(string: baseURL + "web/api/" + endpoint) else {
            completion(.failure(NSError(domain: "Invalid URL", code: 0, userInfo: nil)))
            return
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"

        var finalParams = params
        if let apiKey = self.apiKey {
            finalParams["api_key"] = apiKey
        }

        var components = URLComponents()
        components.queryItems = finalParams.map { URLQueryItem(name: $0.key, value: "\($0.value)") }

        if let bodyString = components.query {
            request.httpBody = bodyString.data(using: .utf8)
        }

        request.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")

        URLSession.shared.dataTask(with: request) { data, response, error in
            if let error = error {
                DispatchQueue.main.async { completion(.failure(error)) }
                return
            }

            guard let data = data else {
                DispatchQueue.main.async { completion(.failure(NSError(domain: "No data", code: 0, userInfo: nil))) }
                return
            }

            do {
                let decoded = try JSONDecoder().decode(T.self, from: data)
                DispatchQueue.main.async { completion(.success(decoded)) }
            } catch {
                // If decoding fails, check if it's a string response (common in legacy APIs)
                if let stringResponse = String(data: data, encoding: .utf8) {
                    print("Raw Response: \(stringResponse)")
                }
                DispatchQueue.main.async { completion(.failure(error)) }
            }
        }.resume()
    }

    func requestJSON<T: Decodable>(_ rawPath: String, params: [String: Any], completion: @escaping (Result<T, Error>) -> Void) {
        guard let url = URL(string: baseURL + rawPath) else {
            completion(.failure(NSError(domain: "Invalid URL", code: 0, userInfo: nil)))
            return
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        
        if let apiKey = self.apiKey {
            request.setValue("Bearer \(apiKey)", forHTTPHeaderField: "Authorization")
        }

        request.httpBody = try? JSONSerialization.data(withJSONObject: params)

        URLSession.shared.dataTask(with: request) { data, response, error in
            if let error = error {
                DispatchQueue.main.async { completion(.failure(error)) }
                return
            }

            guard let data = data else {
                DispatchQueue.main.async { completion(.failure(NSError(domain: "No data", code: 0, userInfo: nil))) }
                return
            }

            do {
                let decoded = try JSONDecoder().decode(T.self, from: data)
                DispatchQueue.main.async { completion(.success(decoded)) }
            } catch {
                if let stringResponse = String(data: data, encoding: .utf8) {
                    print("Raw JSON Response: \(stringResponse)")
                }
                DispatchQueue.main.async { completion(.failure(error)) }
            }
        }.resume()
    }

    /// POSTs to `web/api/<endpoint>` and hands back the raw JSON object.
    ///
    /// Why not the typed `request(…)` above: the DGV7 endpoints build their replies straight from
    /// mysqli_fetch_assoc(), which returns EVERY column as a String regardless of its SQL type. So
    /// `points` arrives as "500" and `amount` as "1.00", and a typed Codable struct fails to decode a
    /// perfectly valid response. These screens therefore read a dictionary and coerce with the
    /// helpers in RewardParsing.swift.
    func requestObject(_ endpoint: String,
                       params: [String: Any],
                       completion: @escaping (Result<[String: Any], Error>) -> Void) {
        guard let url = URL(string: baseURL + "web/api/" + endpoint) else {
            completion(.failure(NSError(domain: "Invalid URL", code: 0, userInfo: nil)))
            return
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"

        var finalParams = params
        if let apiKey = self.apiKey {
            finalParams["api_key"] = apiKey
        }

        var components = URLComponents()
        components.queryItems = finalParams.map { URLQueryItem(name: $0.key, value: "\($0.value)") }
        request.httpBody = components.query?.data(using: .utf8)
        request.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")

        URLSession.shared.dataTask(with: request) { data, _, error in
            if let error = error {
                DispatchQueue.main.async { completion(.failure(error)) }
                return
            }
            guard let data = data,
                  let object = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
                DispatchQueue.main.async {
                    completion(.failure(NSError(domain: "Malformed response", code: 0, userInfo: nil)))
                }
                return
            }
            DispatchQueue.main.async { completion(.success(object)) }
        }.resume()
    }
}

struct APIResponse: Codable {
    let status: String
    let message: String?
    let desc: String?
}

// MARK: - Tenant branding (public configuration, never account credentials)
import SwiftUI
import UIKit

@MainActor
final class BrandTheme: ObservableObject {
    static let shared = BrandTheme()
    @Published private var document: [String: Any] = [:]
    private var fetching = false
    private var lastAttempt = Date.distantPast
    private var origin: String { AppNetworkService.shared.mobileOrigin }
    private var cacheKey: String { "public-theme-v1:" + origin }
    private init() {
        if let data = UserDefaults.standard.data(forKey: cacheKey),
           let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any], Self.valid(json) { document = json }
    }
    nonisolated private static func ui(_ hex: String) -> UIColor {
        let n = UInt32(hex.dropFirst(), radix: 16) ?? 0
        return UIColor(red: CGFloat((n >> 16) & 255)/255, green: CGFloat((n >> 8) & 255)/255, blue: CGFloat(n & 255)/255, alpha: 1)
    }
    nonisolated private static func luminance(_ hex: String) -> Double {
        let n = UInt32(hex.dropFirst(), radix: 16) ?? 0
        let values = [Double((n >> 16) & 255), Double((n >> 8) & 255), Double(n & 255)].map { value -> Double in
            let c = value/255; return c <= 0.04045 ? c/12.92 : pow((c+0.055)/1.055, 2.4)
        }
        return values[0]*0.2126 + values[1]*0.7152 + values[2]*0.0722
    }
    nonisolated private static func contrast(_ a: String, _ b: String) -> Double { let x=luminance(a), y=luminance(b); return (max(x,y)+0.05)/(min(x,y)+0.05) }
    nonisolated private static func valid(_ json: [String: Any]) -> Bool {
        guard json["schema_version"] as? Int == 1, let version=json["version"] as? String,
              version.range(of: "^[0-9a-f]{64}$", options: .regularExpression) != nil, let modes=json["modes"] as? [String: [String: String]] else { return false }
        for mode in ["light", "dark"] {
            guard let p=modes[mode] else { return false }
            for key in ["primary", "on_primary", "primary_text", "background", "surface", "on_surface", "outline"] {
                guard let hex=p[key], hex.range(of: "^#[0-9A-Fa-f]{6}$", options: .regularExpression) != nil else { return false }
            }
            guard contrast(p["on_primary"]!,p["primary"]!) >= 4.5,
                  contrast(p["primary_text"]!,p["surface"]!) >= 4.5,
                  contrast(p["primary_text"]!,p["background"]!) >= 4.5,
                  contrast(p["on_surface"]!,p["surface"]!) >= 4.5,
                  contrast(p["outline"]!,p["surface"]!) >= 3 else { return false }
        }
        return true
    }
    private func token(_ key: String) -> Color {
        let modes = document["modes"] as? [String: [String: String]] ?? [:]
        return Color(UIColor { traits in
            let dark = traits.userInterfaceStyle == .dark
            let fallback: String
            switch key {
            case "primary": fallback = "#287BFF"
            case "on_primary": fallback = "#000000"
            case "surface", "background": fallback = dark ? "#0F172A" : "#FFFFFF"
            default: fallback = dark ? "#FFFFFF" : "#000000"
            }
            return Self.ui(modes[dark ? "dark" : "light"]?[key] ?? fallback)
        })
    }
    var primary: Color { token("primary") }
    var onPrimary: Color { token("on_primary") }
    var ink: Color { token("primary_text") }
    var surface: Color { token("surface") }
    var background: Color { token("background") }
    var onSurface: Color { token("on_surface") }
    func refresh() async {
        guard !fetching, Date().timeIntervalSince(lastAttempt) >= 60,
              let url=URL(string: origin + "web/api/theme.php"), url.scheme == "https" else { return }
        fetching=true; lastAttempt=Date(); defer { fetching=false }
        var request=URLRequest(url: url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 8)
        request.httpShouldHandleCookies=false
        request.setValue("no-cache", forHTTPHeaderField: "Cache-Control")
        do {
            let (data,response)=try await URLSession.shared.data(for: request)
            guard let http=response as? HTTPURLResponse, http.statusCode==200,
                  http.url?.scheme=="https", http.url?.host==url.host,
                  let body=try JSONSerialization.jsonObject(with: data) as? [String: Any],
                  body["status"] as? String=="success", let config=body["data"] as? [String: Any], Self.valid(config) else { return }
            document=config
            if let encoded=try? JSONSerialization.data(withJSONObject: config) { UserDefaults.standard.set(encoded, forKey: cacheKey) }
        } catch { /* Last-known-good origin-scoped palette remains usable offline. */ }
    }
}
