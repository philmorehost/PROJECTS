import SwiftUI
import UIKit

/// "Refer and Earn".
///
/// The code and link come from the server and are the same values the website shows, so a link
/// shared from the app credits the same account as one shared from the dashboard.
///
/// Sign-ups and actual rewards are shown SEPARATELY: a referral only pays out once the referred
/// person completes their first transaction, so merging "pending" into "earned" would promise coins
/// the user has not been given yet.
struct ReferralView: View {
    @State private var loading = true
    @State private var errorText: String?
    @State private var code = ""
    @State private var link = ""
    @State private var shareMessage = ""
    @State private var bonus = 0
    @State private var coinsBalance = 0
    @State private var coinsFromReferrals = 0
    @State private var totalReferrals = 0
    @State private var qualifiedReferrals = 0
    @State private var pendingReferrals = 0
    @State private var coinsEnabled = true
    @State private var referred: [[String: Any]] = []
    @State private var showingShare = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                if loading {
                    ProgressView().frame(maxWidth: .infinity).padding(.top, 40)
                } else if let message = errorText {
                    Text(message).foregroundColor(.red).padding(.top, 40)
                } else {
                    Text("Share your referral code with friends. Once they sign up and complete their first transaction, you earn coins.")
                        .font(.footnote)
                        .foregroundColor(.secondary)

                    HStack {
                        Text("Bonus per successful referral")
                        Spacer()
                        Text(bcThousands(bonus) + " Coins").fontWeight(.bold)
                    }
                    .padding()
                    .background(Color.yellow.opacity(0.15))
                    .cornerRadius(12)

                    Text("YOUR REFERRAL CODE")
                        .font(.caption).fontWeight(.bold).foregroundColor(.secondary)
                    HStack {
                        Text(code.isEmpty ? "—" : code).font(.title3).fontWeight(.bold)
                        Spacer()
                        Button("Copy") { UIPasteboard.general.string = code }
                            .disabled(code.isEmpty)
                    }
                    .padding()
                    .background(Color(.secondarySystemBackground))
                    .cornerRadius(12)

                    Text("YOUR REFERRAL LINK")
                        .font(.caption).fontWeight(.bold).foregroundColor(.secondary)
                    Text(link.isEmpty ? "—" : link)
                        .font(.caption)
                        .foregroundColor(.blue)
                        .padding()
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Color(.secondarySystemBackground))
                        .cornerRadius(12)

                    Button {
                        showingShare = true
                    } label: {
                        Text("SHARE REFERRAL LINK").frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(link.isEmpty)

                    HStack(spacing: 8) {
                        statBox(value: totalReferrals, label: "Signed up", colour: .primary)
                        statBox(value: qualifiedReferrals, label: "Earned", colour: .green)
                        statBox(value: pendingReferrals, label: "Pending", colour: .orange)
                    }

                    Text("A referral counts as Earned once that person completes their first transaction.")
                        .font(.caption2)
                        .foregroundColor(.secondary)

                    if coinsEnabled {
                        VStack(spacing: 8) {
                            HStack {
                                Text("Coins earned from referrals")
                                Spacer()
                                Text(bcThousands(coinsFromReferrals))
                                    .fontWeight(.bold).foregroundColor(.green)
                            }
                            HStack {
                                Text("Total coin balance")
                                Spacer()
                                Text(bcThousands(coinsBalance)).fontWeight(.bold)
                            }
                            NavigationLink(destination: CoinsView()) {
                                Text("CONVERT COINS TO CASH").frame(maxWidth: .infinity)
                            }
                            .buttonStyle(.bordered)
                            .padding(.top, 4)
                        }
                        .padding()
                        .background(Color(.secondarySystemBackground))
                        .cornerRadius(12)
                    }

                    Text("PEOPLE YOU REFERRED")
                        .font(.caption).fontWeight(.bold).foregroundColor(.secondary)

                    if referred.isEmpty {
                        Text("You have not referred anyone yet.")
                            .font(.footnote)
                            .foregroundColor(.secondary)
                            .frame(maxWidth: .infinity)
                            .padding()
                    } else {
                        ForEach(Array(referred.enumerated()), id: \.offset) { _, user in
                            referredRow(user)
                        }
                    }
                }
            }
            .padding()
        }
        .navigationTitle("Refer and Earn")
        .onAppear(perform: load)
        .sheet(isPresented: $showingShare) {
            BCShareSheet(items: [shareText])
        }
    }

    @ViewBuilder
    private func referredRow(_ user: [String: Any]) -> some View {
        let isQualified = bcBool(user["qualified"])
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text(displayName(user)).font(.subheadline).fontWeight(.medium)
                Text("Joined " + String(bcString(user["joined"]).prefix(10)))
                    .font(.caption2)
                    .foregroundColor(.secondary)
            }
            Spacer()
            Text(isQualified ? "Earned" : "Pending")
                .font(.caption)
                .fontWeight(.bold)
                .foregroundColor(isQualified ? Color.green : Color.orange)
        }
        .padding()
        .background(Color(.secondarySystemBackground))
        .cornerRadius(10)
    }

    private func statBox(value: Int, label: String, colour: Color) -> some View {
        VStack(spacing: 2) {
            Text("\(value)").font(.title3).fontWeight(.bold).foregroundColor(colour)
            Text(label).font(.caption2).foregroundColor(.secondary)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 10)
        .background(Color(.secondarySystemBackground))
        .cornerRadius(10)
    }

    private func displayName(_ user: [String: Any]) -> String {
        let name = bcString(user["name"])
        return name.isEmpty ? bcString(user["username"]) : name
    }

    private var shareText: String {
        if link.isEmpty { return code }
        return shareMessage.isEmpty ? link : shareMessage + "\n" + link
    }

    private func load() {
        loading = true
        errorText = nil
        AppNetworkService.shared.requestObject("referral.php", params: [:]) { result in
            loading = false
            switch result {
            case .failure:
                errorText = "Unable to load your referral details."
            case .success(let object):
                let payload = bcPayload(object)
                if let error = payload.error {
                    errorText = error
                    return
                }
                let d = payload.data
                code = bcString(d["referral_code"])
                link = bcString(d["referral_link"])
                shareMessage = bcString(d["share_message"])
                bonus = bcInt(d["referral_bonus"])
                coinsBalance = bcInt(d["coins_balance"])
                coinsFromReferrals = bcInt(d["coins_from_referrals"])
                totalReferrals = bcInt(d["total_referrals"])
                qualifiedReferrals = bcInt(d["qualified_referrals"])
                pendingReferrals = bcInt(d["pending_referrals"])
                coinsEnabled = bcBool(d["coins_enabled"])
                referred = bcArray(d["referred_users"])
            }
        }
    }
}
