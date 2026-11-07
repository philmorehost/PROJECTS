import SwiftUI

struct TransactionItem: Codable, Identifiable {
    var id: String { reference }
    let reference: String
    let type: String
    let amount: Double
    let description: String
    let status: Int
    let status_name: String
    let date: String
}

struct TransactionListResponse: Codable {
    let status: String
    let data: [TransactionItem]?
}

struct TransactionHistoryView: View {
    @State private var transactions: [TransactionItem] = []
    @State private var isLoading = false
    @State private var feedbackMessage = ""
    
    // Filters States
    @State private var showFilters = false
    @State private var filterType = ""
    @State private var filterStatus = ""
    @State private var startDate = Date()
    @State private var endDate = Date()
    @State private var useDateFilter = false

    // Export State
    @State private var exportURL: URL? = nil
    @State private var showShareSheet = false

    let statusList = [
        ("All Statuses", ""),
        ("Successful", "1"),
        ("Pending", "2"),
        ("Failed", "3")
    ]

    var body: some View {
        VStack(spacing: 0) {
            // Filtering indicator banner
            if !filterType.isEmpty || !filterStatus.isEmpty || useDateFilter {
                HStack {
                    Text("Filters Active")
                        .font(.caption)
                        .fontWeight(.bold)
                        .foregroundColor(.blue)
                    Spacer()
                    Button("Reset") {
                        filterType = ""
                        filterStatus = ""
                        useDateFilter = false
                        fetchHistory()
                    }
                    .font(.caption)
                    .foregroundColor(.red)
                }
                .padding(.horizontal)
                .padding(.vertical, 8)
                .background(Color.blue.opacity(0.1))
            }

            List {
                if isLoading {
                    HStack {
                        Spacer()
                        ProgressView("Fetching transaction logs...")
                        Spacer()
                    }
                    .padding()
                } else if transactions.isEmpty {
                    Text("No transactions found.")
                        .foregroundColor(.secondary)
                        .padding()
                } else {
                    ForEach(transactions) { tx in
                        HStack {
                            VStack(alignment: .leading, spacing: 4) {
                                Text(tx.description)
                                    .font(.headline)
                                Text(tx.date)
                                    .font(.caption)
                                    .foregroundColor(.secondary)
                            }
                            Spacer()
                            VStack(alignment: .trailing, spacing: 4) {
                                Text("₦\(String(format: "%.2f", tx.amount))")
                                    .font(.headline)
                                    .foregroundColor(tx.status == 1 ? .green : (tx.status == 2 ? .yellow : .red))
                                Text(tx.status_name)
                                    .font(.caption2)
                                    .fontWeight(.medium)
                            }
                        }
                        .padding(.vertical, 4)
                    }
                }
            }
            .listStyle(PlainListStyle())
        }
        .navigationTitle("History")
        .navigationBarItems(trailing: 
            HStack(spacing: 16) {
                Button(action: { showFilters.toggle() }) {
                    Image(systemName: "line.horizontal.3.decrease.circle")
                        .font(.title3)
                }
                Button(action: exportToCSV) {
                    Image(systemName: "square.and.arrow.up")
                        .font(.title3)
                }
            }
        )
        .sheet(isPresented: $showFilters) {
            NavigationView {
                Form {
                    Section(header: Text("Filter Criteria")) {
                        TextField("Search type (e.g. airtime, data)", text: $filterType)
                            .textFieldStyle(RoundedBorderTextFieldStyle())
                        
                        Picker("Status", selection: $filterStatus) {
                            ForEach(statusList, id: \.1) { label, value in
                                Text(label).tag(value)
                            }
                        }
                        
                        Toggle("Filter by Date Range", isOn: $useDateFilter)
                        
                        if useDateFilter {
                            DatePicker("Start Date", selection: $startDate, displayedComponents: .date)
                            DatePicker("End Date", selection: $endDate, displayedComponents: .date)
                        }
                    }
                    
                    Section {
                        Button("Apply Filters") {
                            showFilters = false
                            fetchHistory()
                        }
                        .frame(maxWidth: .infinity)
                        .foregroundColor(.blue)
                    }
                }
                .navigationTitle("Filters")
                .navigationBarItems(leading: Button("Cancel") { showFilters = false })
            }
        }
        .sheet(isPresented: $showShareSheet) {
            if let url = exportURL {
                ShareSheet(activityItems: [url])
            }
        }
        .onAppear(perform: fetchHistory)
    }

    func fetchHistory() {
        isLoading = true
        var params: [String: Any] = [:]
        
        if !filterType.isEmpty { params["type"] = filterType }
        if !filterStatus.isEmpty { params["status"] = filterStatus }
        if useDateFilter {
            let formatter = DateFormatter()
            formatter.dateFormat = "yyyy-MM-dd"
            params["start_date"] = formatter.string(from: startDate)
            params["end_date"] = formatter.string(from: endDate)
        }

        AppNetworkService.shared.request("transactions.php", params: params) { (result: Result<TransactionListResponse, Error>) in
            isLoading = false
            switch result {
            case .success(let response):
                self.transactions = response.data ?? []
            case .failure(let error):
                print("History error: \(error)")
            }
        }
    }

    func exportToCSV() {
        guard !transactions.isEmpty else { return }

        var csvString = "Date,Reference,Type,Description,Amount,Status\n"
        for tx in transactions {
            csvString.append("\"\(tx.date)\",\"\(tx.reference)\",\"\(tx.type)\",\"\(tx.description)\",\"\(tx.amount)\",\"\(tx.status_name)\"\n")
        }

        let tempDirectory = FileManager.default.temporaryDirectory
        let fileURL = tempDirectory.appendingPathComponent("transactions_export.csv")

        do {
            try csvString.write(to: fileURL, atomically: true, encoding: .utf8)
            self.exportURL = fileURL
            self.showShareSheet = true
        } catch {
            print("CSV write error: \(error)")
        }
    }
}

// SwiftUI ShareSheet wrapper
struct ShareSheet: UIViewControllerRepresentable {
    let activityItems: [Any]
    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: activityItems, applicationActivities: nil)
    }
    func updateUIViewController(_ uiViewController: UIActivityViewController, context: Context) {}
}
