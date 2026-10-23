import SwiftUI

/// The VTU Coins ledger — mirrors web/PointsHistory.php.
///
/// The daily-bonus collapse happens server-side (the page unions in only MAX(id) per date), so this
/// view must NOT try to de-duplicate: showing every row it receives is what keeps the app's history
/// identical to the website's.
struct PointsHistoryView: View {
    @State private var loading = true
    @State private var errorText: String?
    @State private var balance = 0
    @State private var streakDay = 0
    @State private var nextBonus = ""
    @State private var entries: [[String: Any]] = []

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                if loading {
                    ProgressView().frame(maxWidth: .infinity).padding(.top, 40)
                } else if let message = errorText {
                    Text(message).foregroundColor(.red).padding(.top, 40)
                } else {
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            Text("COIN BALANCE")
                                .font(.caption2).fontWeight(.bold).foregroundColor(.secondary)
                            Text(bcThousands(balance)).font(.title2).fontWeight(.bold)
                        }
                        Spacer()
                        VStack(alignment: .trailing, spacing: 2) {
                            Text(streakDay > 0 ? "🔥 \(streakDay) Day Streak" : "No active streak")
                                .font(.footnote).fontWeight(.bold)
                            if !nextBonus.isEmpty {
                                Text("Next bonus \(nextBonus)")
                                    .font(.caption2).foregroundColor(.secondary)
                            }
                        }
                    }
                    .padding()
                    .background(Color.yellow.opacity(0.15))
                    .cornerRadius(12)

                    if entries.isEmpty {
                        Text("No points history found.")
                            .font(.footnote).foregroundColor(.secondary)
                            .frame(maxWidth: .infinity).padding(.top, 24)
                    } else {
                        ForEach(Array(entries.enumerated()), id: \.offset) { _, entry in
                            entryRow(entry)
                        }
                    }
                }
            }
            .padding()
        }
        .navigationTitle("Points History")
        .onAppear(perform: load)
    }

    @ViewBuilder
    private func entryRow(_ entry: [String: Any]) -> some View {
        let points = bcInt(entry["points"])
        let earned = points >= 0
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text(bcString(entry["label"]).isEmpty ? bcString(entry["log_type"]) : bcString(entry["label"]))
                    .font(.subheadline).fontWeight(.medium)
                Text(bcShortDate(bcString(entry["date"])))
                    .font(.caption2).foregroundColor(.secondary)
            }
            Spacer()
            Text((earned ? "+" : "") + bcThousands(points))
                .font(.subheadline)
                .fontWeight(.bold)
                .foregroundColor(earned ? Color.green : Color.red)
        }
        .padding()
        .background(Color(.secondarySystemBackground))
        .cornerRadius(10)
    }

    private func load() {
        loading = true
        errorText = nil
        AppNetworkService.shared.requestObject("points-history.php", params: [:]) { result in
            loading = false
            switch result {
            case .failure:
                errorText = "Unable to load your points history."
            case .success(let object):
                let payload = bcPayload(object)
                if let error = payload.error {
                    errorText = error
                    return
                }
                let d = payload.data
                balance = bcInt(d["points_balance"])
                streakDay = bcInt(d["streak_day"])
                nextBonus = bcString(d["next_bonus"])
                entries = bcArray(d["entries"])
            }
        }
    }
}
