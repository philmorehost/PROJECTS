import Foundation

/// Base URL lives here, not hardcoded inline in networking calls — change this (or wire it to
/// an Xcode build-setting-driven Info.plist key, e.g. API_BASE_URL) before shipping to a
/// different environment. Matches the Android app's BuildConfig.BASE_URL approach.
enum AppConfig {
    static let baseURL: URL = {
        if let fromInfoPlist = Bundle.main.object(forInfoDictionaryKey: "API_BASE_URL") as? String,
           let url = URL(string: fromInfoPlist), !fromInfoPlist.isEmpty {
            return url
        }
        return URL(string: "https://payhub.com.ng/")!
    }()
}
