import Foundation

struct NetworkDetectEntry: Codable {
    let phone: String
    let network: String
}

struct NetworkDetectResponse: Codable {
    let status: String?
    let network: String?
    let data: [NetworkDetectEntry]?
}

struct VerifyCustomerResponse: Codable {
    let status: String
    let desc: String?
    let customerName: String?
    let customerAddress: String?

    enum CodingKeys: String, CodingKey {
        case status, desc
        case customerName = "customer_name"
        case customerAddress = "customer_address"
    }
}

struct CheckoutInitResponse: Codable {
    let status: String
    let reference: String?
    let amount: Double?
    let checkoutUrl: String?
    let desc: String?

    enum CodingKeys: String, CodingKey {
        case status, reference, amount, desc
        case checkoutUrl = "checkout_url"
    }
}

struct GuestOrderStatusResponse: Codable {
    let ref: String?
    let status: String?
    let service: String?
    let amount: String?
    let desc: String?
    let responseDesc: String?
    let meterNumber: String?
    let token: String?
    let tokenUnit: String?
    let customerId: String?

    enum CodingKeys: String, CodingKey {
        case ref, status, service, amount, desc, token
        case responseDesc = "response_desc"
        case meterNumber = "meter_number"
        case tokenUnit = "token_unit"
        case customerId = "customer_id"
    }
}

/// Local-only receipt snapshot used for the Receipt screen and PDF/image export.
/// Guest Mode stores no server-side transaction history — this is the only durable
/// copy of a purchase, generated client-side right after payment. Codable so
/// GuestHistoryStore can cache a list of these on-device (UserDefaults, JSON-encoded).
struct GuestReceipt: Codable, Identifiable {
    var id: String { reference }
    let reference: String
    let service: String
    let recipient: String
    let amountPaid: Double
    let status: String
    let date: Date
    var meterNumber: String? = nil
    var token: String? = nil
    var tokenUnit: String? = nil
}

/// A generic {"status":"failed","desc":"..."} error body shape used across web/guest-api/*.php.
struct ApiFailure: Codable {
    let status: String?
    let desc: String?
}

enum ApiResult<T> {
    case success(T)
    case failure(String)
}
