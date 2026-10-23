import Foundation

/// Talks to web/guest-api/*.php — the stateless, unauthenticated Guest Mode backend.
/// Uses the platform default URLSession TLS trust (no custom trust-all delegate — see
/// this same security pass's fix to the Android RetrofitClient.kt for the bug this avoids).
final class GuestAPIService {

    static let shared = GuestAPIService()

    private let session: URLSession

    private init() {
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 30
        config.timeoutIntervalForResource = 30
        self.session = URLSession(configuration: config)
    }

    // MARK: - Catalog

    func getAirtimeCatalog() async -> ApiResult<AirtimeCatalogResponse> {
        await get(path: "web/guest-api/catalog.php", query: ["service": "airtime"])
    }
    func getDataCatalog() async -> ApiResult<DataCatalogResponse> {
        await get(path: "web/guest-api/catalog.php", query: ["service": "data"])
    }
    func getCableCatalog() async -> ApiResult<CableCatalogResponse> {
        await get(path: "web/guest-api/catalog.php", query: ["service": "cable"])
    }
    func getElectricCatalog() async -> ApiResult<ElectricCatalogResponse> {
        await get(path: "web/guest-api/catalog.php", query: ["service": "electric"])
    }
    func getExamCatalog() async -> ApiResult<ExamCatalogResponse> {
        await get(path: "web/guest-api/catalog.php", query: ["service": "exam"])
    }
    func getBettingCatalog() async -> ApiResult<BettingCatalogResponse> {
        await get(path: "web/guest-api/catalog.php", query: ["service": "betting"])
    }

    func identifyNetwork(phone: String) async -> ApiResult<NetworkDetectResponse> {
        await get(path: "web/guest-api/identify-network.php", query: ["phone": phone])
    }

    func verifyCustomer(body: [String: Any?]) async -> ApiResult<VerifyCustomerResponse> {
        await post(path: "web/guest-api/verify.php", body: body)
    }

    func initCheckout(body: [String: Any?]) async -> ApiResult<CheckoutInitResponse> {
        await post(path: "web/guest-api/checkout-init.php", body: body)
    }

    func getOrderStatus(reference: String) async -> ApiResult<GuestOrderStatusResponse> {
        await get(path: "web/guest-api/status.php", query: ["reference": reference])
    }

    func getSiteInfo() async -> ApiResult<SiteInfoResponse> {
        await get(path: "web/guest-api/site-info.php", query: [:])
    }

    // MARK: - Core request helpers

    private func get<T: Decodable>(path: String, query: [String: String]) async -> ApiResult<T> {
        guard var components = URLComponents(url: AppConfig.baseURL.appendingPathComponent(path), resolvingAgainstBaseURL: false) else {
            return .failure("Invalid request URL")
        }
        components.queryItems = query.map { URLQueryItem(name: $0.key, value: $0.value) }
        guard let url = components.url else { return .failure("Invalid request URL") }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("payhub-guest-ios", forHTTPHeaderField: "X-App-Source")

        return await execute(request)
    }

    private func post<T: Decodable>(path: String, body: [String: Any?]) async -> ApiResult<T> {
        let url = AppConfig.baseURL.appendingPathComponent(path)
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("payhub-guest-ios", forHTTPHeaderField: "X-App-Source")

        // Compact-map out nil values — JSONSerialization can't encode Optional<Any>.none directly.
        let cleaned = body.compactMapValues { $0 }
        guard let data = try? JSONSerialization.data(withJSONObject: cleaned) else {
            return .failure("Could not encode request")
        }
        request.httpBody = data

        return await execute(request)
    }

    private func execute<T: Decodable>(_ request: URLRequest) async -> ApiResult<T> {
        do {
            let (data, response) = try await session.data(for: request)
            guard let http = response as? HTTPURLResponse else {
                return .failure("No response from server")
            }
            let decoder = JSONDecoder()

            if (200...299).contains(http.statusCode) {
                if let decoded = try? decoder.decode(T.self, from: data) {
                    return .success(decoded)
                }
                return .failure("Server returned an unexpected response. Please try again.")
            } else {
                if let failure = try? decoder.decode(ApiFailure.self, from: data), let desc = failure.desc {
                    return .failure(desc)
                }
                return .failure("Request failed (\(http.statusCode))")
            }
        } catch {
            return .failure("Network error — please check your connection and try again.")
        }
    }
}
