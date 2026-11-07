import SwiftUI

/// Convert VTU Coins to wallet cash.
///
/// Mirrors web/CoinConversion.php, and deliberately keeps the same rules rather than a friendlier
/// version of them: a conversion is a REQUEST that an admin approves, so the confirmation says
/// "pending approval" instead of implying the money has arrived. Rate, minimum and balance all come
/// from the server so a vendor's own settings are what the user sees.
struct CoinsView: View {
    @State private var loading = true
    @State private var submitting = false
    @State private var errorText: String?
    @State private var statusText: String?
    @State private var balance = 0
    @State private var rate: Double = 0
    @State private var minPoints = 0
    @State private var input = ""
    @State private var history: [[String: Any]] = []

    private var points: Int { Int(input.trimmingCharacters(in: .whitespaces)) ?? 0 }
    private var nairaValue: Double { rate > 0 ? Double(points) / rate : 0 }

    /// Explains a disabled button rather than leaving it a mystery, exactly as the web page does.
    private var hint: String? {
        if points <= 0 { return nil }
        if points < minPoints { return "Minimum conversion is " + bcThousands(minPoints) + " Coins" }
        if points > balance { return "You only have " + bcThousands(balance) + " Coins" }
        return nil
    }

    private var canSubmit: Bool { hint == nil && points > 0 && !submitting }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                if loading {
                    ProgressView().frame(maxWidth: .infinity).padding(.top, 40)
                } else if let message = errorText {
                    Text(message).foregroundColor(.red).padding(.top, 40)
                } else {
                    VStack(spacing: 6) {
                        HStack {
                            Text("CURRENT BALANCE").font(.caption).fontWeight(.bold)
                            Spacer()
                            Text(bcThousands(balance) + " Coins").font(.title3).fontWeight(.bold)
                        }
                        HStack {
                            Text("Rate").font(.caption).foregroundColor(.secondary)
                            Spacer()
                            Text(rate > 0 ? "\(Int(rate)) Coins = ₦1.00" : "—")
                                .font(.caption).foregroundColor(.secondary)
                        }
                        HStack {
                            Text("Minimum").font(.caption).foregroundColor(.secondary)
                            Spacer()
                            Text(bcThousands(minPoints) + " Coins").font(.caption).foregroundColor(.secondary)
                        }
                    }
                    .padding()
                    .background(Color.yellow.opacity(0.15))
                    .cornerRadius(12)

                    Text("POINTS TO CONVERT")
                        .font(.caption).fontWeight(.bold).foregroundColor(.secondary)
                    TextField("0", text: $input)
                        .keyboardType(.numberPad)
                        .font(.title2)
                        .padding()
                        .background(Color(.secondarySystemBackground))
                        .cornerRadius(10)

                    Text("You will receive: " + bcNaira(nairaValue))
                        .font(.headline)
                        .foregroundColor(.green)
                        .frame(maxWidth: .infinity)

                    if let hint = hint {
                        Text(hint).font(.caption).foregroundColor(.red).frame(maxWidth: .infinity)
                    }
                    if let statusText = statusText {
                        Text(statusText).font(.caption).foregroundColor(.blue).frame(maxWidth: .infinity)
                    }

                    Button {
                        submit()
                    } label: {
                        Text(submitting ? "SUBMITTING…" : "SUBMIT REQUEST").frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(!canSubmit)

                    Text("CONVERSION HISTORY")
                        .font(.caption).fontWeight(.bold).foregroundColor(.secondary)

                    if history.isEmpty {
                        Text("No conversion history found.")
                            .font(.footnote).foregroundColor(.secondary)
                            .frame(maxWidth: .infinity).padding()
                    } else {
                        ForEach(Array(history.enumerated()), id: \.offset) { _, row in
                            historyRow(row)
                        }
                    }
                }
            }
            .padding()
        }
        .navigationTitle("Convert Coins")
        .onAppear(perform: load)
    }

    @ViewBuilder
    private func historyRow(_ row: [String: Any]) -> some View {
        // request_date is shown because completion_date is null until an admin decides; falling
        // back keeps older rows legible either way.
        let requested = bcString(row["request_date"])
        let completed = bcString(row["completion_date"])
        let status = bcString(row["status"]).lowercased()
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text(bcShortDate(requested.isEmpty ? completed : requested))
                    .font(.caption)
                Text(bcThousands(bcInt(row["points"])) + " Coins")
                    .font(.caption2).foregroundColor(.secondary)
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 2) {
                Text(bcNaira(bcDouble(row["amount"]))).fontWeight(.bold)
                Text(status.isEmpty ? "Pending" : status.capitalized)
                    .font(.caption2)
                    .fontWeight(.bold)
                    .foregroundColor(colour(for: status))
            }
        }
        .padding()
        .background(Color(.secondarySystemBackground))
        .cornerRadius(10)
    }

    private func colour(for status: String) -> Color {
        switch status {
        case "approved": return .green
        case "declined", "rejected": return .red
        default: return .orange
        }
    }

    private func load() {
        loading = true
        errorText = nil
        AppNetworkService.shared.requestObject("coin-conversion.php", params: [:]) { result in
            loading = false
            switch result {
            case .failure:
                errorText = "Unable to load coin settings."
            case .success(let object):
                let payload = bcPayload(object)
                if let error = payload.error {
                    errorText = error
                    return
                }
                let d = payload.data
                balance = bcInt(d["points_balance"])
                rate = bcDouble(d["conversion_rate"])
                minPoints = bcInt(d["min_points_conversion"])
                history = bcArray(d["history"])
            }
        }
    }

    private func submit() {
        guard points > 0 else { return }
        submitting = true
        statusText = nil
        AppNetworkService.shared.requestObject(
            "coin-conversion.php",
            params: ["action": "submit", "points": points]
        ) { result in
            submitting = false
            switch result {
            case .failure:
                statusText = "Network error. Please try again."
            case .success(let object):
                statusText = bcString(object["message"])
                if bcString(object["status"]).lowercased() == "success" {
                    input = ""
                    load()   // refresh balance + history from the server, not from assumption
                }
            }
        }
    }
}
