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
        return "https://app.mzeevtu.com/"
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
    /// there, so the app reproduces the same gate in `MZEEVTU_iOSApp` instead of letting a new
    /// account reach the dashboard with no transaction PIN.
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

    /// POSTs to `web/api/<endpoint>` and hands back the raw JSON object.
    ///
    /// Why not the typed `request(...)`: the DGV7 endpoints build their replies straight from
    /// mysqli_fetch_assoc(), which returns EVERY column as a String regardless of its SQL type. So
    /// `points` arrives as "500" and `amount` as "1.00", and a typed Codable struct fails to decode
    /// a perfectly valid response. These screens read a dictionary and coerce with RewardParsing.swift.
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
