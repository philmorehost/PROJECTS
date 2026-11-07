import SwiftUI

struct VirtualCardView: View {
    @State private var cards: [VirtualCard] = []
    @State private var isLoading = false

    var body: some View {
        List {
            if isLoading {
                ProgressView("Loading cards...")
            } else if cards.isEmpty {
                Text("You have no active virtual cards.")
                    .foregroundColor(.secondary)
            } else {
                ForEach(cards) { card in
                    VStack(alignment: .leading, spacing: 10) {
                        HStack {
                            Text(card.card_name)
                                .font(.headline)
                            Spacer()
                            Text(card.provider.uppercased())
                                .font(.caption)
                                .padding(4)
                                .background(Color.blue.opacity(0.1))
                                .cornerRadius(4)
                        }

                        Text(card.masked_pan)
                            .font(.system(.title3, design: .monospaced))

                        HStack {
                            VStack(alignment: .leading) {
                                Text("EXPIRY")
                                    .font(.caption2)
                                Text("\(card.expiry_month)/\(card.expiry_year)")
                            }
                            Spacer()
                            VStack(alignment: .trailing) {
                                Text("BALANCE")
                                    .font(.caption2)
                                Text("$\(card.balance_usd)")
                                    .bold()
                            }
                        }
                    }
                    .padding()
                    .background(Color.black)
                    .foregroundColor(.white)
                    .cornerRadius(15)
                    .padding(.vertical, 5)
                }
            }

            Section {
                NavigationLink(destination: CreateCardView()) {
                    Text("Create New Card")
                        .foregroundColor(.blue)
                        .bold()
                }
            }
        }
        .navigationTitle("Virtual Cards")
        .onAppear(perform: fetchCards)
    }

    func fetchCards() {
        isLoading = true
        AppNetworkService.shared.request("virtual-card.php", params: ["action": "list"]) { (result: Result<CardListResponse, Error>) in
            isLoading = false
            switch result {
            case .success(let response):
                self.cards = response.cards
            case .failure(let error):
                print("Error fetching cards: \(error)")
            }
        }
    }
}

struct CreateCardView: View {
    @State private var nameOnCard: String = ""
    @State private var amount: String = ""
    @State private var selectedProvider: String = "bsicards"
    @State private var isLoading = false
    @State private var isProcessing = false
    @State private var message: String = ""

    var body: some View {
        Form {
            Section(header: Text("Card Details")) {
                TextField("Name on Card", text: $nameOnCard)
                TextField("Initial Funding ($)", text: $amount)
                    .keyboardType(.decimalPad)
                Picker("Provider", selection: $selectedProvider) {
                    Text("BSI Cards").tag("bsicards")
                    Text("Chimoney").tag("chimoney")
                }
            }

            Button(action: createCard) {
                if isLoading {
                    ProgressView()
                } else {
                    Text("Create Card")
                        .frame(maxWidth: .infinity)
                }
            }
            .disabled(isLoading || nameOnCard.isEmpty || amount.isEmpty)

            if !message.isEmpty {
                Text(message)
                    .foregroundColor(message.contains("Success") ? .green : .red)
            }
        }
        .navigationTitle("New Card")
        .blockingProgress(isVisible: isProcessing, message: "Creating your card...")
    }

    func createCard() {
        isLoading = true
        isProcessing = true
        let params: [String: Any] = [
            "action": "create",
            "name": nameOnCard,
            "amount": amount,
            "provider": selectedProvider
        ]

        AppNetworkService.shared.request("virtual-card.php", params: params) { (result: Result<APIResponse, Error>) in
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

struct CardListResponse: Codable {
    let status: String
    let cards: [VirtualCard]
}

struct VirtualCard: Codable, Identifiable {
    let id: Int
    let card_name: String
    let masked_pan: String
    let expiry_month: String
    let expiry_year: String
    let balance_usd: String
    let provider: String
}
