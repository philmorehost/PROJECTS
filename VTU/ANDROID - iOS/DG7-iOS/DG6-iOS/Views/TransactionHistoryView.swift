import SwiftUI

struct TransactionHistoryView: View {
    @State private var transactions: [Transaction] = []
    @State private var isLoading = false

    var body: some View {
        List {
            if isLoading {
                ProgressView("Fetching transactions...")
            } else if transactions.isEmpty {
                Text("No transactions found.")
                    .foregroundColor(.secondary)
            } else {
                ForEach(transactions) { tx in
                    HStack {
                        VStack(alignment: .leading) {
                            Text(tx.type_alternative)
                                .font(.headline)
                            Text(tx.date)
                                .font(.caption)
                                .foregroundColor(.secondary)
                        }
                        Spacer()
                        VStack(alignment: .trailing) {
                            Text("₦\(tx.amount)")
                                .font(.headline)
                                .foregroundColor(tx.status == "1" ? .green : .red)
                            Text(tx.status == "1" ? "Successful" : "Failed")
                                .font(.caption)
                        }
                    }
                }
            }
        }
        .navigationTitle("History")
        .onAppear(perform: fetchHistory)
    }

    func fetchHistory() {
        isLoading = true
        AppNetworkService.shared.request("transactions.php", params: [:]) { (result: Result<TransactionResponse, Error>) in
            isLoading = false
            switch result {
            case .success(let response):
                self.transactions = response.transactions
            case .failure(let error):
                print("History error: \(error)")
            }
        }
    }
}

struct TransactionResponse: Codable {
    let status: String
    let transactions: [Transaction]
}

struct Transaction: Codable, Identifiable {
    let id: Int
    let type_alternative: String
    let amount: String
    let date: String
    let status: String
}
