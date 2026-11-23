import SwiftUI

struct TransactionHistoryView: View {
    @State private var transactions: [[String: Any]] = []
    @State private var isLoading = false
    @State private var error = ""
    @State private var hasMore = true
    @State private var receipt: MobileReceipt?
    private let pageSize = 20

    var body: some View {
        List {
            if !error.isEmpty {
                Text(error).foregroundColor(.red)
                Button("Retry") { fetchHistory(reset: transactions.isEmpty) }
            }
            if transactions.isEmpty && !isLoading && error.isEmpty {
                Text("No transactions found.").foregroundColor(.secondary)
            }
            ForEach(Array(transactions.enumerated()), id: \.offset) { _, row in
                Button { receipt = MobileReceipt(row: row) } label: {
                    HStack {
                        VStack(alignment: .leading) {
                            Text(row["type"] as? String ?? "Transaction").font(.headline)
                            Text(row["date"] as? String ?? "").font(.caption).foregroundColor(.secondary)
                        }
                        Spacer()
                        VStack(alignment: .trailing) {
                            Text("NGN \(String(format: "%.2f", MobileReceipt.number(row["discounted_amount"] ?? row["amount"]) ?? 0))")
                            Text(status(row)).font(.caption).foregroundColor(.secondary)
                        }
                    }
                }.buttonStyle(.plain)
            }
            if isLoading { ProgressView("Fetching transactions…") }
            else if hasMore && error.isEmpty && !transactions.isEmpty {
                Button("Load more") { fetchHistory(reset: false) }
            }
        }
        .navigationTitle("History")
        .toolbar { Button("Refresh") { fetchHistory(reset: true) }.disabled(isLoading) }
        .onAppear { if transactions.isEmpty { fetchHistory(reset: true) } }
        .sheet(item: $receipt) { item in MobileReceiptView(receipt: item) }
    }

    private func status(_ row: [String: Any]) -> String {
        switch Int(MobileReceipt.number(row["status"]) ?? 0) {
        case 1: return "Completed"
        case 2: return "Pending"
        case 3: return "Failed"
        default: return "Unknown"
        }
    }

    private func fetchHistory(reset: Bool) {
        guard !isLoading else { return }
        isLoading = true
        error = ""
        AppNetworkService.shared.requestObject("transactions.php", params: ["limit": pageSize, "offset": reset ? 0 : transactions.count]) { result in
            DispatchQueue.main.async {
                isLoading = false
                switch result {
                case .success(let body):
                    guard body["status"] as? String == "success", let rows = body["data"] as? [[String: Any]],
                          rows.allSatisfy({ ($0["reference"] as? String)?.isEmpty == false }) else {
                        error = body["message"] as? String ?? "Invalid history response. Please retry."
                        return
                    }
                    transactions = reset ? rows : transactions + rows
                    hasMore = rows.count == pageSize
                case .failure:
                    error = "Unable to load history. Check your connection and retry."
                }
            }
        }
    }
}
