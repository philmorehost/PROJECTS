import Foundation

// Field names match web/guest-api/catalog.php's exact JSON keys (uppercase — inherited from
// the authenticated API's existing *-plans.php contract, kept identical for guest mode).

struct AirtimeNetworkInfo: Codable {
    let productCode: String
    let discountPercent: String

    enum CodingKeys: String, CodingKey {
        case productCode = "PRODUCT_CODE"
        case discountPercent = "DISCOUNT_PERCENT"
    }
}

struct AirtimeCatalogResponse: Codable {
    let airtimeVtu: [String: AirtimeNetworkInfo]?
    let status: String?
    let desc: String?

    enum CodingKeys: String, CodingKey {
        case airtimeVtu = "AIRTIME_VTU"
        case status, desc
    }
}

struct DataPlan: Codable, Identifiable, Hashable {
    let id: String
    let productCode: String
    let productName: String
    let dataType: String
    let dataTypeCode: String
    let amount: String
    let duration: String

    enum CodingKeys: String, CodingKey {
        case id = "ID"
        case productCode = "PRODUCT_CODE"
        case productName = "PRODUCT_NAME"
        case dataType = "DATA_TYPE"
        case dataTypeCode = "DATA_TYPE_CODE"
        case amount = "AMOUNT"
        case duration = "DURATION"
    }
}

struct DataCatalogResponse: Codable {
    let mobileNetwork: [String: [DataPlan]]?
    let status: String?
    let desc: String?

    enum CodingKeys: String, CodingKey {
        case mobileNetwork = "MOBILE_NETWORK"
        case status, desc
    }
}

struct CablePlan: Codable, Identifiable, Hashable {
    let id: String
    let packageName: String
    let amount: String

    enum CodingKeys: String, CodingKey {
        case id = "ID"
        case packageName = "PACKAGE"
        case amount = "AMOUNT"
    }
}

struct CableCatalogResponse: Codable {
    let cableSubscription: [String: [CablePlan]]?
    let status: String?
    let desc: String?

    enum CodingKeys: String, CodingKey {
        case cableSubscription = "CABLE_SUBSCRIPTION"
        case status, desc
    }
}

struct ElectricProviderInfo: Codable {
    let providerCode: String
    let discountPercent: String

    enum CodingKeys: String, CodingKey {
        case providerCode = "PROVIDER_CODE"
        case discountPercent = "DISCOUNT_PERCENT"
    }
}

struct ElectricCatalogResponse: Codable {
    let electricPayment: [String: ElectricProviderInfo]?
    let status: String?
    let desc: String?

    enum CodingKeys: String, CodingKey {
        case electricPayment = "ELECTRIC_PAYMENT"
        case status, desc
    }
}

struct ExamPlan: Codable, Identifiable, Hashable {
    let id: String
    let examType: String
    let amount: String

    enum CodingKeys: String, CodingKey {
        case id = "ID"
        case examType = "EXAM_TYPE"
        case amount = "AMOUNT"
    }
}

struct ExamCatalogResponse: Codable {
    let examPin: [String: [ExamPlan]]?
    let status: String?
    let desc: String?

    enum CodingKeys: String, CodingKey {
        case examPin = "EXAM_PIN"
        case status, desc
    }
}

struct BettingProvider: Codable, Identifiable, Hashable {
    var id: String { providerCode }
    let providerCode: String
    let providerName: String

    enum CodingKeys: String, CodingKey {
        case providerCode = "provider_code"
        case providerName = "provider_name"
    }
}

struct BettingCatalogResponse: Codable {
    let bettingProviders: [BettingProvider]?
    let status: String?
    let desc: String?

    enum CodingKeys: String, CodingKey {
        case bettingProviders = "BETTING_PROVIDERS"
        case status, desc
    }
}
