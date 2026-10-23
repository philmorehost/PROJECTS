import Foundation

struct SiteInfoResponse: Codable {
    let status: String?
    let data: SiteInfoData?
}

struct SiteInfoData: Codable {
    let siteTitle: String?
    let logoUrl: String?
    let primaryColor: String?
    let secondaryColor: String?
    /// A service key absent from this map must be treated as enabled — it means the admin
    /// never touched Service Control Centre for it, not that it's disabled. Only an explicit
    /// 0 hides a service.
    let services: [String: Int]?
    let currencySymbol: String?
    let support: GuestSupportInfo?

    enum CodingKeys: String, CodingKey {
        case siteTitle = "site_title"
        case logoUrl = "logo_url"
        case primaryColor = "primary_color"
        case secondaryColor = "secondary_color"
        case services
        case currencySymbol = "currency_symbol"
        case support
    }
}

struct GuestSupportInfo: Codable {
    let email: String?
    let phone: String?
    let address: String?
}
