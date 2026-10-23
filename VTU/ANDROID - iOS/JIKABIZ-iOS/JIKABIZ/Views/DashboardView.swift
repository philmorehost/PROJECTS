import SwiftUI

struct DashboardView: View {
    @EnvironmentObject var session: SessionManager
    @State private var balance: Double = 0.0
    @State private var isLoading = false

    var body: some View {
        NavigationView {
            ScrollView {
                VStack(spacing: 20) {
                    // Balance Card
                    VStack {
                        Text("Wallet Balance")
                            .font(.subheadline)
                            .foregroundColor(.white.opacity(0.8))
                        Text("₦\(String(format: "%.2f", balance))")
                            .font(.system(size: 34, weight: .bold))
                            .foregroundColor(.white)

                        Button(action: fetchBalance) {
                            Image(systemName: "arrow.clockwise")
                                .foregroundColor(.white)
                        }
                        .padding(.top, 5)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 30)
                    .background(LinearGradient(gradient: Gradient(colors: [Color.blue, Color.purple]), startPoint: .topLeading, endPoint: .bottomTrailing))
                    .cornerRadius(20)
                    .padding(.horizontal)

                    // Service Grid
                    LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible()), GridItem(.flexible())], spacing: 20) {
                        NavigationLink(destination: AirtimeView()) {
                            ServiceItem(icon: "phone.fill", label: "Airtime")
                        }
                        NavigationLink(destination: DataView()) {
                            ServiceItem(icon: "wifi", label: "Data")
                        }
                        NavigationLink(destination: BillPaymentView(serviceType: "electric")) {
                            ServiceItem(icon: "bolt.fill", label: "Electric")
                        }
                        NavigationLink(destination: BillPaymentView(serviceType: "cable")) {
                            ServiceItem(icon: "tv.fill", label: "Cable")
                        }
                        NavigationLink(destination: VirtualCardView()) {
                            ServiceItem(icon: "creditcard.fill", label: "Cards")
                        }
                        NavigationLink(destination: TransactionHistoryView()) {
                            ServiceItem(icon: "list.bullet.rectangle", label: "History")
                        }
                        // KYCView and AIChatView existed but nothing navigated to them, so identity
                        // verification and the assistant were unreachable. KYCView is now wired to
                        // web/api/kyc.php, so this is the entry point that makes it usable.
                        NavigationLink(destination: KYCView()) {
                            ServiceItem(icon: "person.crop.rectangle.badge.checkmark", label: "KYC")
                        }
                        NavigationLink(destination: AIChatView()) {
                            ServiceItem(icon: "sparkles", label: "AI Chat")
                        }
                    }
                    .padding()

                    // Rewards — referral, coin conversion and the coin ledger. All three are backed
                    // by the same server code the website uses (web/api/referral.php,
                    // coin-conversion.php, points-history.php), so the numbers match across surfaces.
                    VStack(alignment: .leading, spacing: 8) {
                        Text("Rewards")
                            .font(.headline)
                            .frame(maxWidth: .infinity, alignment: .leading)

                        NavigationLink(destination: ReferralView()) {
                            RewardRow(icon: "person.2.fill",
                                      title: "Refer and Earn",
                                      subtitle: "Invite friends and earn coins")
                        }
                        NavigationLink(destination: CoinsView()) {
                            RewardRow(icon: "bitcoinsign.circle.fill",
                                      title: "Convert Coins",
                                      subtitle: "Turn your VTU Coins into cash")
                        }
                        NavigationLink(destination: PointsHistoryView()) {
                            RewardRow(icon: "clock.arrow.circlepath",
                                      title: "Points History",
                                      subtitle: "Every coin earned and redeemed")
                        }
                    }
                    .padding(.horizontal)

                    Spacer()
                }
            }
            .navigationTitle("Dashboard")
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Logout") {
                        session.logout()
                    }
                }
            }
            .onAppear(perform: fetchBalance)
        }
    }

    func fetchBalance() {
        isLoading = true
        AppNetworkService.shared.request("profile.php", params: [:]) { (result: Result<ProfileResponse, Error>) in
            isLoading = false
            if case .success(let response) = result {
                if let data = response.data {
                    self.balance = Double(data.balance) ?? 0.0
                }
            }
        }
    }
}

struct ProfileResponse: Codable {
    let status: String
    let data: ProfileData?
}

struct ProfileData: Codable {
    let balance: String
}

struct ServiceItem: View {
    let icon: String
    let label: String

    var body: some View {
        VStack {
            Image(systemName: icon)
                .font(.title)
                .foregroundColor(.blue)
                .frame(width: 60, height: 60)
                .background(Color.blue.opacity(0.1))
                .cornerRadius(15)
            Text(label)
                .font(.caption)
                .fontWeight(.medium)
                .foregroundColor(.primary)
        }
    }
}

/// A full-width entry row for the Rewards section.
struct RewardRow: View {
    let icon: String
    let title: String
    let subtitle: String

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: icon)
                .font(.title3)
                .foregroundColor(.blue)
                .frame(width: 44, height: 44)
                .background(Color.blue.opacity(0.1))
                .cornerRadius(12)
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundColor(.primary)
                Text(subtitle)
                    .font(.caption2)
                    .foregroundColor(.secondary)
            }
            Spacer()
            Image(systemName: "chevron.right")
                .font(.caption)
                .foregroundColor(.secondary)
        }
        .padding(12)
        .background(Color(.secondarySystemBackground))
        .cornerRadius(14)
    }
}
