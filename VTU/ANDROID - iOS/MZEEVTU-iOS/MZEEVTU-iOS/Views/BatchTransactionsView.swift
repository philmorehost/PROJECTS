import SwiftUI

struct BatchItem: Codable, Identifiable {
    var id: String { batch_number }
    let batch_number: String
    let product_name: String
    let date: String
}

struct BatchListResponse: Codable {
    let status: String
    let batches: [BatchItem]?
    let desc: String?
}

struct BatchTransactionsView: View {
    @State private var batches: [BatchItem] = []
    @State private var isLoading = false
    @State private var errorMessage = ""

    var body: some View {
        ZStack {
            if isLoading {
                ProgressView("Loading batch history...")
            } else if batches.isEmpty {
                VStack(spacing: 12) {
                    Image(systemName: "tray.fill")
                        .font(.system(size: 48))
                        .foregroundColor(.gray)
                    Text("No batch transactions found")
                        .font(.headline)
                        .foregroundColor(.secondary)
                    if !errorMessage.isEmpty {
                        Text(errorMessage)
                            .font(.subheadline)
                            .foregroundColor(.red)
                            .padding()
                    }
                }
            } else {
                List(batches) { batch in
                    HStack {
                        VStack(alignment: .leading, spacing: 4) {
                            Text(batch.product_name)
                                .font(.headline)
                            Text(batch.date)
                                .font(.caption)
                                .foregroundColor(.secondary)
                        }
                        
                        Spacer()
                        
                        Text("#\(batch.batch_number)")
                            .font(.system(size: 14, weight: .bold, design: .monospaced))
                            .foregroundColor(.white)
                            .padding(.horizontal, 10)
                            .padding(.vertical, 4)
                            .background(Color.blue)
                            .cornerRadius(8)
                    }
                    .padding(.vertical, 4)
                }
                .listStyle(PlainListStyle())
            }
        }
        .navigationTitle("Batch History")
        .onAppear(perform: loadBatches)
    }

    private func loadBatches() {
        isLoading = true
        errorMessage = ""
        AppNetworkService.shared.request("batch-list.php", params: [:]) { (result: Result<BatchListResponse, Error>) in
            isLoading = false
            switch result {
            case .success(let response):
                if response.status == "success" {
                    self.batches = response.batches ?? []
                } else {
                    self.errorMessage = response.desc ?? "Failed to load batch list"
                }
            case .failure(let error):
                self.errorMessage = error.localizedDescription
            }
        }
    }
}
