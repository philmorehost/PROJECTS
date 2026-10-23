import SwiftUI

struct AirtimeView: View {
    @State private var phoneNumber: String = ""
    @State private var amount: String = ""
    @State private var selectedNetwork: String = "MTN"
    @State private var isLoading = false
    @State private var isProcessing = false
    @State private var message: String = ""

    let networks = ["MTN", "Airtel", "Glo", "9mobile"]

    var body: some View {
        Form {
            Section(header: Text("Purchase Airtime")) {
                Picker("Network", selection: $selectedNetwork) {
                    ForEach(networks, id: \.self) {
                        Text($0)
                    }
                }

                TextField("Phone Number", text: $phoneNumber)
                    .keyboardType(.phonePad)

                TextField("Amount", text: $amount)
                    .keyboardType(.numberPad)
            }

            Section {
                Button(action: purchaseAirtime) {
                    if isLoading {
                        ProgressView()
                    } else {
                        Text("Purchase Now")
                            .frame(maxWidth: .infinity)
                            .foregroundColor(.white)
                    }
                }
                .listRowBackground(Color.blue)
                .disabled(isLoading)
            }

            if !message.isEmpty {
                Section {
                    Text(message)
                        .foregroundColor(message.contains("Success") ? .green : .red)
                }
            }
        }
        .navigationTitle("Airtime")
        .blockingProgress(isVisible: isProcessing, message: "Processing your purchase...")
    }

    func purchaseAirtime() {
        guard !phoneNumber.isEmpty, !amount.isEmpty else {
            message = "Please fill all fields"
            return
        }

        isLoading = true
        isProcessing = true
        let params: [String: Any] = [
            "phone": phoneNumber,
            "amount": amount,
            "network": selectedNetwork.lowercased(),
            "action": "airtime"
        ]

        AppNetworkService.shared.request("purchase.php", params: params) { (result: Result<APIResponse, Error>) in
            isLoading = false
            isProcessing = false
            switch result {
            case .success(let response):
                message = response.desc ?? response.message ?? "Transaction status: \(response.status)"
            case .failure(let error):
                message = "Error: \(error.localizedDescription)"
            }
        }
    }
}
