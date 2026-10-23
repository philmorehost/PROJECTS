import SwiftUI

struct BillPaymentView: View {
    @State private var selectedCategory: String = "Electricity"
    @State private var meterNumber: String = ""
    @State private var amount: String = ""
    @State private var selectedProvider: String = ""
    @State private var providers: [BillProvider] = []
    @State private var isLoading = false
    @State private var isProcessing = false
    @State private var message: String = ""

    let categories = ["Electricity", "Cable TV"]

    var body: some View {
        Form {
            Section(header: Text("Bill Category")) {
                Picker("Category", selection: $selectedCategory) {
                    ForEach(categories, id: \.self) {
                        Text($0)
                    }
                }
                .onChange(of: selectedCategory) { _ in fetchProviders() }

                Picker("Provider", selection: $selectedProvider) {
                    ForEach(providers, id: \.code) { provider in
                        Text(provider.name).tag(provider.code)
                    }
                }
            }

            Section(header: Text("Details")) {
                TextField(selectedCategory == "Electricity" ? "Meter Number" : "SmartCard Number", text: $meterNumber)
                    .keyboardType(.numberPad)

                TextField("Amount", text: $amount)
                    .keyboardType(.numberPad)
            }

            Section {
                Button(action: payBill) {
                    if isLoading {
                        ProgressView()
                    } else {
                        Text("Pay Bill")
                            .frame(maxWidth: .infinity)
                            .foregroundColor(.white)
                    }
                }
                .listRowBackground(Color.blue)
                .disabled(isLoading || meterNumber.isEmpty || amount.isEmpty || selectedProvider.isEmpty)
            }

            if !message.isEmpty {
                Section {
                    Text(message)
                        .foregroundColor(message.contains("Success") ? .green : .red)
                }
            }
        }
        .navigationTitle("Bills Payment")
        .blockingProgress(isVisible: isProcessing, message: "Processing your payment...")
        .onAppear(perform: fetchProviders)
    }

    func fetchProviders() {
        let type = selectedCategory == "Electricity" ? "electric" : "cable"
        AppNetworkService.shared.request("fetch_providers.php", params: ["type": type]) { (result: Result<[BillProvider], Error>) in
            switch result {
            case .success(let fetchedProviders):
                self.providers = fetchedProviders
                if let first = fetchedProviders.first {
                    self.selectedProvider = first.code
                }
            case .failure:
                self.providers = []
            }
        }
    }

    func payBill() {
        isLoading = true
        isProcessing = true
        let params: [String: Any] = [
            "number": meterNumber,
            "amount": amount,
            "provider": selectedProvider,
            "category": selectedCategory.lowercased(),
            "action": "bill_payment"
        ]

        AppNetworkService.shared.request("purchase.php", params: params) { (result: Result<APIResponse, Error>) in
            isLoading = false
            isProcessing = false
            switch result {
            case .success(let response):
                message = response.desc ?? response.message ?? "Status: \(response.status)"
            case .failure(let error):
                message = "Error: \(error.localizedDescription)"
            }
        }
    }
}

struct BillProvider: Codable {
    let name: String
    let code: String
}
