import SwiftUI

struct PrintPlan: Codable, Identifiable {
    var id: String { plan_code }
    let plan_code: String
    let amount: Double
    let duration: String?
    let data_type: String?
    
    enum CodingKeys: String, CodingKey {
        case plan_code = "PLAN_CODE"
        case amount = "AMOUNT"
        case duration = "DURATION"
        case data_type = "DATA_TYPE"
    }
    
    // Custom decoding to safely parse Double/String amount
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        plan_code = try container.decode(String.self, forKey: .plan_code)
        duration = try? container.decode(String.self, forKey: .duration)
        data_type = try? container.decode(String.self, forKey: .data_type)
        
        if let amtDouble = try? container.decode(Double.self, forKey: .amount) {
            amount = amtDouble
        } else if let amtStr = try? container.decode(String.self, forKey: .amount), let amtDouble = Double(amtStr) {
            amount = amtDouble
        } else {
            amount = 0.0
        }
    }
}

struct PrintCardResponse: Codable {
    let status: String
    let MOBILE_NETWORK: [String: [PrintPlan]]?
}

struct PrintedCard: Codable, Identifiable {
    var id: String { epin }
    let epin: String
    let sn: String
}

struct PrintCardPurchaseResponse: Codable {
    let status: String
    let desc: String?
    let cards: [PrintedCard]?
}

struct PrintHubView: View {
    @State private var serviceType = "data"
    @State private var selectedNetwork = "mtn"
    @State private var selectedDataType = "sme-data"
    @State private var selectedPlanCode = ""
    @State private var quantity = "1"
    
    @State private var allPlans: [String: [PrintPlan]] = [:]
    @State private var generatedCards: [PrintedCard] = []
    @State private var isLoading = false
    @State private var isProcessing = false
    @State private var feedbackMessage = ""
    
    let networks = ["mtn", "glo", "airtel", "9mobile"]
    let dataTypes = [
        "sme-data": "SME Data",
        "cg-data": "Corporate Gifting",
        "dd-data": "Direct Data",
        "shared-data": "Shared Data"
    ]

    var filteredPlans: [PrintPlan] {
        guard let networkPlans = allPlans[selectedNetwork.uppercased()] else { return [] }
        return networkPlans.filter { plan in
            if serviceType == "data" {
                return plan.data_type?.lowercased() == selectedDataType
            } else {
                return plan.data_type == nil || plan.data_type?.isEmpty == true
            }
        }
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 20) {
                // Product Selection Tab
                VStack(alignment: .leading, spacing: 8) {
                    Text("SELECT PRODUCT TYPE")
                        .font(.caption)
                        .fontWeight(.bold)
                        .foregroundColor(.secondary)
                    
                    Picker("Product", selection: $serviceType) {
                        Text("Data").tag("data")
                        Text("Airtime").tag("airtime")
                        Text("Cable").tag("cable")
                        Text("Electric").tag("electric")
                        Text("Exam").tag("exam")
                    }
                    .pickerStyle(SegmentedPickerStyle())
                }
                .padding(.horizontal)

                // Network Selector
                VStack(alignment: .leading, spacing: 8) {
                    Text("SELECT PROVIDER")
                        .font(.caption)
                        .fontWeight(.bold)
                        .foregroundColor(.secondary)
                    
                    Picker("Network", selection: $selectedNetwork) {
                        ForEach(networks, id: \.self) { net in
                            Text(net.uppercased()).tag(net)
                        }
                    }
                    .pickerStyle(SegmentedPickerStyle())
                }
                .padding(.horizontal)

                // Data Type Selector (Only if Data is selected)
                if serviceType == "data" {
                    VStack(alignment: .leading, spacing: 8) {
                        Text("DATA TYPE")
                            .font(.caption)
                            .fontWeight(.bold)
                            .foregroundColor(.secondary)
                        
                        Picker("Data Type", selection: $selectedDataType) {
                            ForEach(dataTypes.sorted(by: { $0.key < $1.key }), id: \.key) { key, value in
                                Text(value).tag(key)
                            }
                        }
                        .pickerStyle(MenuPickerStyle())
                        .frame(maxWidth: .infinity)
                        .padding(4)
                        .background(Color(.systemGray6))
                        .cornerRadius(8)
                    }
                    .padding(.horizontal)
                }

                // Plan Selector Dropdown
                VStack(alignment: .leading, spacing: 8) {
                    Text("SELECT PLAN")
                        .font(.caption)
                        .fontWeight(.bold)
                        .foregroundColor(.secondary)
                    
                    Picker("Plan", selection: $selectedPlanCode) {
                        Text("Select Plan").tag("")
                        ForEach(filteredPlans) { plan in
                            Text("\(plan.plan_code) - ₦\(String(format: "%.2f", plan.amount))").tag(plan.plan_code)
                        }
                    }
                    .pickerStyle(MenuPickerStyle())
                    .frame(maxWidth: .infinity)
                    .padding(4)
                    .background(Color(.systemGray6))
                    .cornerRadius(8)
                }
                .padding(.horizontal)

                // Quantity Input
                VStack(alignment: .leading, spacing: 8) {
                    Text("QUANTITY (1 - 40)")
                        .font(.caption)
                        .fontWeight(.bold)
                        .foregroundColor(.secondary)
                    
                    TextField("Quantity", text: $quantity)
                        .textFieldStyle(RoundedBorderTextFieldStyle())
                        .keyboardType(.numberPad)
                }
                .padding(.horizontal)

                // Print Cards Action Button
                Button(action: purchasePrintCards) {
                    if isLoading {
                        ProgressView()
                            .progressViewStyle(CircularProgressViewStyle(tint: .white))
                            .frame(maxWidth: .infinity, minHeight: 52)
                    } else {
                        Text("PRINT CARDS")
                            .font(.headline)
                            .foregroundColor(.white)
                            .frame(maxWidth: .infinity, minHeight: 52)
                    }
                }
                .background(Color.blue)
                .cornerRadius(26)
                .padding(.horizontal)
                .disabled(isLoading || selectedPlanCode.isEmpty)

                if !feedbackMessage.isEmpty {
                    Text(feedbackMessage)
                        .font(.callout)
                        .foregroundColor(feedbackMessage.contains("Success") ? .green : .red)
                        .padding()
                }

                // Generated Cards Display
                if !generatedCards.isEmpty {
                    VStack(alignment: .leading, spacing: 12) {
                        Text("GENERATED CARDS")
                            .font(.caption)
                            .fontWeight(.bold)
                            .foregroundColor(.secondary)
                            .padding(.horizontal)
                        
                        ForEach(generatedCards) { card in
                            VStack(spacing: 8) {
                                HStack {
                                    Text(selectedNetwork.uppercased() + " \(serviceType.uppercased()) CARD")
                                        .font(.headline)
                                        .foregroundColor(.white)
                                    Spacer()
                                    Text("Plan: \(selectedPlanCode)")
                                        .font(.subheadline)
                                        .foregroundColor(.white.opacity(0.8))
                                }
                                
                                Divider().background(Color.white)
                                
                                VStack(alignment: .leading, spacing: 4) {
                                    Text("PIN: \(card.epin)")
                                        .font(.system(size: 18, weight: .bold, design: .monospaced))
                                        .foregroundColor(.yellow)
                                    Text("S/N: \(card.sn)")
                                        .font(.system(size: 14, design: .monospaced))
                                        .foregroundColor(.white.opacity(0.9))
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                            }
                            .padding()
                            .background(
                                LinearGradient(gradient: Gradient(colors: [Color.blue.opacity(0.95), Color.purple.opacity(0.95)]), startPoint: .topLeading, endPoint: .bottomTrailing)
                            )
                            .cornerRadius(12)
                            .padding(.horizontal)
                        }
                    }
                }

                Spacer()
            }
            .padding(.top)
        }
        .navigationTitle("Print Hub")
        .blockingProgress(isVisible: isProcessing, message: "Generating your cards...")
        .onAppear(perform: loadPlans)
    }

    private func loadPlans() {
        isLoading = true
        AppNetworkService.shared.request("databundle-card-plans.php", params: [:]) { (result: Result<PrintCardResponse, Error>) in
            isLoading = false
            switch result {
            case .success(let response):
                if response.status == "success" {
                    self.allPlans = response.MOBILE_NETWORK ?? [:]
                } else {
                    self.feedbackMessage = "Failed to load print plans"
                }
            case .failure(let error):
                self.feedbackMessage = "Error: \(error.localizedDescription)"
            }
        }
    }

    private func purchasePrintCards() {
        guard let qtyVal = Int(quantity), qtyVal >= 1, qtyVal <= 40 else {
            feedbackMessage = "Quantity must be between 1 and 40"
            return
        }

        isLoading = true
        isProcessing = true
        feedbackMessage = ""
        generatedCards = []

        var params: [String: Any] = [
            "network": selectedNetwork,
            "service_type": serviceType,
            "plan_code": selectedPlanCode,
            "quantity": String(qtyVal)
        ]
        
        if serviceType == "data" {
            params["data_type"] = selectedDataType
        }

        AppNetworkService.shared.request("databundle-card.php", params: params) { (result: Result<PrintCardPurchaseResponse, Error>) in
            isLoading = false
            isProcessing = false
            switch result {
            case .success(let response):
                if response.status == "success", let cards = response.cards {
                    self.generatedCards = cards
                    self.feedbackMessage = "Success! Generated \(cards.count) cards."
                } else {
                    self.feedbackMessage = response.desc ?? "Failed to generate cards"
                }
            case .failure(let error):
                self.feedbackMessage = "Error: \(error.localizedDescription)"
            }
        }
    }
}
