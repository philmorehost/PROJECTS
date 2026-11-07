import SwiftUI

struct DataView: View {
    @State private var phoneNumber: String = ""
    @State private var selectedNetwork: String = "MTN"
    @State private var selectedPlan: String = ""
    @State private var plans: [DataPlan] = []
    @State private var isLoading = false
    @State private var isProcessing = false
    @State private var message: String = ""

    let networks = ["MTN", "Airtel", "Glo", "9mobile"]

    var body: some View {
        Form {
            Section(header: Text("Purchase Data Bundle")) {
                Picker("Network", selection: $selectedNetwork) {
                    ForEach(networks, id: \.self) {
                        Text($0)
                    }
                }
                .onChange(of: selectedNetwork) { _ in fetchPlans() }

                TextField("Phone Number", text: $phoneNumber)
                    .keyboardType(.phonePad)

                Picker("Select Plan", selection: $selectedPlan) {
                    ForEach(plans, id: \.plan_code) { plan in
                        Text("\(plan.name) - ₦\(plan.price)").tag(plan.plan_code)
                    }
                }
            }

            Section {
                Button(action: purchaseData) {
                    if isLoading {
                        ProgressView()
                    } else {
                        Text("Purchase Data")
                            .frame(maxWidth: .infinity)
                            .foregroundColor(.white)
                    }
                }
                .listRowBackground(Color.blue)
                .disabled(isLoading || selectedPlan.isEmpty)
            }

            if !message.isEmpty {
                Section {
                    Text(message)
                        .foregroundColor(message.contains("Success") ? .green : .red)
                }
            }
        }
        .navigationTitle("Data Bundles")
        .blockingProgress(isVisible: isProcessing, message: "Processing your purchase...")
        .onAppear(perform: fetchPlans)
    }

    func fetchPlans() {
        // Fetch plans for selected network
        AppNetworkService.shared.request("fetch_plans.php", params: ["network": selectedNetwork.lowercased()]) { (result: Result<[DataPlan], Error>) in
            switch result {
            case .success(let fetchedPlans):
                self.plans = fetchedPlans
                if let firstPlan = fetchedPlans.first {
                    self.selectedPlan = firstPlan.plan_code
                }
            case .failure:
                self.plans = []
            }
        }
    }

    func purchaseData() {
        isLoading = true
        isProcessing = true
        let params: [String: Any] = [
            "phone": phoneNumber,
            "plan": selectedPlan,
            "network": selectedNetwork.lowercased(),
            "action": "data"
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

struct DataPlan: Codable {
    let name: String
    let price: String
    let plan_code: String
}
