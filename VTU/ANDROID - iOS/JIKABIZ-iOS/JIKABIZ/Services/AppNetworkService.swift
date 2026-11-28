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

    private func inspectRequiredSetup(_ data: Data) -> Bool {
        guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let code = json["code"] as? String else { return false }
        if code == "KYC_REQUIRED" {
            DispatchQueue.main.async {
                ComplianceGateState.shared.activateLock()
                ComplianceGateState.shared.refresh()
            }
            return false
        }
        guard ["AUTHENTICATOR_SETUP_REQUIRED", "PASSWORD_CHANGE_REQUIRED"].contains(code) else { return false }
        DispatchQueue.main.async { RequiredSetupState.shared.activate() }
        return true
    }


    var apiKey: String? {
        get {
            UserDefaults.standard.removeObject(forKey: "user_api_key")
            let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword,
                kSecAttrService as String: "platform.app-session.v2", kSecAttrAccount as String: "bearer",
                kSecReturnData as String: true, kSecMatchLimit as String: kSecMatchLimitOne]
            var result: CFTypeRef?
            guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
                  let data = result as? Data else { return nil }
            return String(data: data, encoding: .utf8)
        }
        set {
            UserDefaults.standard.removeObject(forKey: "user_api_key")
            let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword,
                kSecAttrService as String: "platform.app-session.v2", kSecAttrAccount as String: "bearer"]
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

    private func isPublicAuth(_ endpoint: String) -> Bool {
        let name = endpoint.components(separatedBy: "/").last ?? endpoint
        return ["login.php", "login-otp.php", "register.php", "register-otp.php", "resend-register-otp.php", "resend-login-otp.php", "password-recovery.php", "forgot-password.php", "site-info.php"].contains(name)
    }

    func request<T: Decodable>(_ endpoint: String, params: [String: Any], completion: @escaping (Result<T, Error>) -> Void) {
        guard let url = URL(string: baseURL + "web/api/" + endpoint) else {
            completion(.failure(NSError(domain: "Invalid URL", code: 0, userInfo: nil)))
            return
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"

        var finalParams = params
        if !isPublicAuth(endpoint), let apiKey = self.apiKey {
            finalParams.removeValue(forKey: "api_key")
            finalParams["app_token"] = apiKey
            request.setValue("Bearer \(apiKey)", forHTTPHeaderField: "Authorization")
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

            if self.inspectRequiredSetup(data) {
                DispatchQueue.main.async { completion(.failure(NSError(domain: "RequiredSecuritySetup", code: 403, userInfo: [NSLocalizedDescriptionKey: "Complete required security setup on the website, then sign in again."]))) }
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

            if self.inspectRequiredSetup(data) {
                DispatchQueue.main.async { completion(.failure(NSError(domain: "RequiredSecuritySetup", code: 403, userInfo: [NSLocalizedDescriptionKey: "Complete required security setup on the website, then sign in again."]))) }
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
        if !isPublicAuth(endpoint), let apiKey = self.apiKey {
            finalParams.removeValue(forKey: "api_key")
            finalParams["app_token"] = apiKey
            request.setValue("Bearer \(apiKey)", forHTTPHeaderField: "Authorization")
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
            if self.inspectRequiredSetup(data) {
                DispatchQueue.main.async { completion(.failure(NSError(domain: "RequiredSecuritySetup", code: 403, userInfo: [NSLocalizedDescriptionKey: "Complete required security setup on the website, then sign in again."]))) }
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

import SwiftUI
import UIKit

// MARK: Required security setup (server-enforced; no credentials in browser URLs)
@MainActor
final class RequiredSetupState: ObservableObject {
    static let shared = RequiredSetupState()
    private var key: String { "required-setup:" + AppNetworkService.shared.mobileOrigin }
    @Published var blocked = false
    private init() { blocked = UserDefaults.standard.bool(forKey: key) }
    func activate() {
        UserDefaults.standard.set(true, forKey: key)
        AppNetworkService.shared.apiKey = nil
        blocked = true
    }
    func retry() { UserDefaults.standard.removeObject(forKey: key); blocked = false }
    var setupURL: URL? { URL(string: "web/SecuritySetup.php", relativeTo: URL(string: AppNetworkService.shared.mobileOrigin))?.absoluteURL }
}

struct RequiredSetupView: View {
    let retry: () -> Void
    @ObservedObject private var theme = BrandTheme.shared
    var body: some View {
        VStack(spacing: 24) {
            Text("Required security setup").font(.title).bold()
            Text("Sign in to your website and complete the required password change or Google Authenticator setup. Then return here and sign in again. App actions remain locked until the server confirms completion.")
            if let url = RequiredSetupState.shared.setupURL { Link("Open secure website", destination: url) }
            Button("Return to sign in", action: retry)
        }.padding(28).foregroundStyle(theme.onSurface).frame(maxWidth: .infinity, maxHeight: .infinity).background(theme.background).tint(theme.ink)
    }
}

struct BrandedStartupView: View {
    @ObservedObject private var theme = BrandTheme.shared
    var body: some View {
        VStack(spacing: 20) {
            Text(Bundle.main.object(forInfoDictionaryKey: "CFBundleDisplayName") as? String ?? "Welcome").font(.largeTitle).bold()
            ProgressView().tint(theme.onPrimary)
        }.foregroundStyle(theme.onPrimary).frame(maxWidth: .infinity, maxHeight: .infinity).background(theme.primary.ignoresSafeArea())
    }
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

@MainActor
final class ComplianceGateState: ObservableObject {
    static let shared = ComplianceGateState()
    @Published var locked: Bool = false
    @Published var kycStatus: Int = 0
    @Published var kycName: String = "Unverified"
    @Published var rejectReason: String = ""
    @Published var stillNeeded: [String] = []
    @Published var aiRating: Int = 0
    @Published var aiGrade: String = ""
    @Published var aiReason: String = ""
    @Published var unreadNotifications: Int = 0
    private var refreshing = false

    func activateLock() {
        locked = true
    }

    func reset() {
        locked = false
        kycStatus = 0
        kycName = "Unverified"
        rejectReason = ""
        stillNeeded = []
        aiRating = 0
        aiGrade = ""
        aiReason = ""
        unreadNotifications = 0
    }

    func refresh() {
        guard AppNetworkService.shared.apiKey != nil, !refreshing else { return }
        refreshing = true
        AppNetworkService.shared.requestObject("kyc.php", params: ["action": "status"]) { result in
            self.refreshing = false
            guard case .success(let json) = result,
                  (json["status"] as? String) == "success" else { return }
            let st = (json["kyc_status"] as? Int) ?? Int("\(json["kyc_status"] ?? 0)") ?? 0
            let enforced = (json["kyc_enforced"] as? Bool) ?? false
            let gateLocked = (json["kyc_gate_locked"] as? Bool) ?? (enforced && st != 2)
            self.kycStatus = st
            let kname = json["kyc_name"] as? String ?? ""
            if !kname.isEmpty { self.kycName = kname }
            self.rejectReason = json["kyc_reject_reason"] as? String ?? ""
            self.stillNeeded = (json["still_needed"] as? [String]) ?? []
            self.aiRating = (json["ai_rating"] as? Int) ?? 0
            self.aiGrade = json["ai_grade"] as? String ?? ""
            self.aiReason = json["ai_reason"] as? String ?? ""
            if let notifs = json["notifications"] as? [String: Any] {
                self.unreadNotifications = (notifs["unread"] as? Int) ?? 0
            }
            self.locked = gateLocked
        }
    }
}

private final class KYCComplianceRedirectGuard: NSObject, URLSessionTaskDelegate {
    func urlSession(_ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse, newRequest request: URLRequest, completionHandler: @escaping (URLRequest?) -> Void) { completionHandler(nil) }
}

extension AppNetworkService {
    func uploadKYCDocuments(
        docType: String,
        govtId: (data: Data, filename: String, mime: String)?,
        proofOfAddress: (data: Data, filename: String, mime: String)?,
        photo: Data?,
        video: Data?,
        completion: @escaping (Result<String, Error>) -> Void
    ) {
        let fail: (String) -> Void = { message in
            DispatchQueue.main.async {
                completion(.failure(NSError(domain: "KYCDocuments", code: 1, userInfo: [NSLocalizedDescriptionKey: message])))
            }
        }
        guard let token = apiKey, !token.isEmpty else { fail("Your app session is missing. Sign in again."); return }
        guard govtId != nil || proofOfAddress != nil || photo != nil || video != nil,
              let url = URL(string: mobileOrigin + "web/api/kyc.php"), url.scheme == "https" else {
            fail("Please select or capture at least one document.")
            return
        }
        let boundary = "KYCDOC-" + UUID().uuidString
        var body = Data()
        func appendText(_ value: String) { body.append(Data(value.utf8)) }
        appendText("--\(boundary)\r\nContent-Disposition: form-data; name=\"action\"\r\n\r\nupload_document\r\n")
        appendText("--\(boundary)\r\nContent-Disposition: form-data; name=\"app_token\"\r\n\r\n\(token)\r\n")
        if !docType.isEmpty {
            appendText("--\(boundary)\r\nContent-Disposition: form-data; name=\"doc_type\"\r\n\r\n\(docType)\r\n")
        }
        if let gid = govtId {
            appendText("--\(boundary)\r\nContent-Disposition: form-data; name=\"govt_id\"; filename=\"\(gid.filename)\"\r\nContent-Type: \(gid.mime)\r\n\r\n")
            body.append(gid.data)
            appendText("\r\n")
        }
        if let poa = proofOfAddress {
            appendText("--\(boundary)\r\nContent-Disposition: form-data; name=\"proof_of_address\"; filename=\"\(poa.filename)\"\r\nContent-Type: \(poa.mime)\r\n\r\n")
            body.append(poa.data)
            appendText("\r\n")
        }
        if let photo = photo {
            appendText("--\(boundary)\r\nContent-Disposition: form-data; name=\"selfie\"; filename=\"camera.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n")
            body.append(photo)
            appendText("\r\n")
        }
        if let video = video {
            appendText("--\(boundary)\r\nContent-Disposition: form-data; name=\"liveliness_video\"; filename=\"camera.mp4\"\r\nContent-Type: video/mp4\r\n\r\n")
            body.append(video)
            appendText("\r\n")
        }
        appendText("--\(boundary)--\r\n")
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.httpBody = body
        request.timeoutInterval = 90
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        let session = URLSession(configuration: .ephemeral, delegate: KYCComplianceRedirectGuard(), delegateQueue: nil)
        session.dataTask(with: request) { data, response, error in
            defer { session.finishTasksAndInvalidate() }
            guard error == nil, let data = data else {
                fail("Submission result is uncertain. Check your KYC status before retrying.")
                return
            }
            if self.inspectRequiredSetup(data) {
                fail("Complete required security setup on the website, then sign in again.")
                return
            }
            guard let http = response as? HTTPURLResponse,
                  let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
                fail("Could not confirm the server response. Check your KYC status before retrying.")
                return
            }
            guard (200..<300).contains(http.statusCode), json["status"] as? String == "success" else {
                fail(json["desc"] as? String ?? json["message"] as? String ?? "Submission was refused.")
                return
            }
            DispatchQueue.main.async {
                ComplianceGateState.shared.refresh()
                completion(.success(json["desc"] as? String ?? "Documents submitted for review."))
            }
        }.resume()
    }
}

// No redirects: never forward captured identity evidence or bearer credentials to another URL.
private final class KYCEvidenceRedirectGuard: NSObject, URLSessionTaskDelegate {
    func urlSession(_ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse, newRequest request: URLRequest, completionHandler: @escaping (URLRequest?) -> Void) { completionHandler(nil) }
}
extension AppNetworkService {
    func uploadKYCCaptures(photo: Data?, video: Data?, completion: @escaping (Result<String, Error>) -> Void) {
        let fail: (String) -> Void = { message in DispatchQueue.main.async { completion(.failure(NSError(domain: "KYCEvidence", code: 1, userInfo: [NSLocalizedDescriptionKey: message]))) } }
        guard let token = apiKey, !token.isEmpty else { fail("Your app session is missing. Sign in again."); return }
        guard photo != nil || video != nil,
              photo.map({ !$0.isEmpty && $0.count <= 512 * 1024 }) ?? true,
              video.map({ !$0.isEmpty && $0.count <= 3 * 1024 * 1024 }) ?? true,
              let url = URL(string: mobileOrigin + "web/api/kyc.php"), url.scheme == "https" else { fail("Invalid capture size or server configuration."); return }
        let boundary = "KYC-" + UUID().uuidString
        var body = Data()
        func text(_ value: String) { body.append(Data(value.utf8)) }
        text("--\(boundary)\r\nContent-Disposition: form-data; name=\"action\"\r\n\r\nupload_document\r\n")
        text("--\(boundary)\r\nContent-Disposition: form-data; name=\"app_token\"\r\n\r\n\(token)\r\n")
        if let photo = photo { text("--\(boundary)\r\nContent-Disposition: form-data; name=\"selfie\"; filename=\"camera.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n"); body.append(photo); text("\r\n") }
        if let video = video { text("--\(boundary)\r\nContent-Disposition: form-data; name=\"liveliness_video\"; filename=\"camera.mp4\"\r\nContent-Type: video/mp4\r\n\r\n"); body.append(video); text("\r\n") }
        text("--\(boundary)--\r\n")
        var request = URLRequest(url: url); request.httpMethod = "POST"; request.httpBody = body; request.timeoutInterval = 90
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        let session = URLSession(configuration: .ephemeral, delegate: KYCEvidenceRedirectGuard(), delegateQueue: nil)
        session.dataTask(with: request) { data, response, error in
            defer { session.finishTasksAndInvalidate() }
            guard error == nil, let data = data else { fail("Submission result is uncertain. Check your KYC status before retrying. Your captures were retained in this screen."); return }
            if self.inspectRequiredSetup(data) { fail("Complete required security setup on the website, then sign in again."); return }
            guard let http = response as? HTTPURLResponse,
                  let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else { fail("Could not confirm the server response. Check your KYC status before retrying."); return }
            guard (200..<300).contains(http.statusCode), json["status"] as? String == "success" else { fail(json["desc"] as? String ?? json["message"] as? String ?? "Submission was refused. Sign in again if your session has expired."); return }
            let accepted = json["accepted_media"] as? [String] ?? []
            guard (photo == nil || accepted.contains("selfie")), (video == nil || accepted.contains("liveliness_video")) else { fail("The server did not confirm every capture. Check your KYC status; do not assume all files were saved. The backend may need updating."); return }
            DispatchQueue.main.async { completion(.success(json["desc"] as? String ?? "Captures submitted for review. This is not approval.")) }
        }.resume()
    }
}
