import SwiftUI

@MainActor
struct IntlTransferView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @State private var services: [(slug: String, label: String)] = []
    @State private var selectedService = ""
    @State private var currencies: [String] = ["USD", "GBP", "EUR", "CNY", "CAD", "AUD", "AED"]
    @State private var selectedCurrency = "USD"
    @State private var amountUsd = ""
    @State private var recipientName = ""
    @State private var recipientCountry = ""
    @State private var recipientAccount = ""
    @State private var pin = ""
    @State private var routeSnapshot = ""
    @State private var requestKey = ""
    @State private var providerInfo = "Loading transfer corridors..."
    @State private var quoteNote = ""
    @State private var routingAvailable = false
    @State private var kycRequired = false
    @State private var pinRequired = true
    @State private var isLoading = false
    @State private var isSubmitting = false
    @State private var message = ""
    @State private var isSuccess = false
    @State private var requests: [[String: Any]] = []

    var body: some View {
        Form {
            if kycRequired {
                Section {
                    Text("KYC verification is required before sending money abroad.")
                        .font(.subheadline)
                        .foregroundColor(.orange)
                    NavigationLink("Complete KYC Verification", destination: KYCView())
                        .foregroundColor(brandTheme.ink)
                }
            }

            Section(header: Text("Transfer Corridor"), footer: Text(providerInfo)) {
                if !services.isEmpty {
                    Picker("Corridor", selection: $selectedService) {
                        ForEach(services, id: \.slug) { item in
                            Text(item.label).tag(item.slug)
                        }
                    }
                }
                Picker("Destination Currency", selection: $selectedCurrency) {
                    ForEach(currencies, id: \.self) { cur in
                        Text(cur).tag(cur)
                    }
                }
                TextField("Amount (USD)", text: $amountUsd)
                    .keyboardType(.decimalPad)
                Button("Check Indicative Quote") { fetchQuote() }
                    .foregroundColor(brandTheme.ink)
                if !quoteNote.isEmpty {
                    Text(quoteNote).font(.caption).foregroundColor(.secondary)
                }
            }

            Section(header: Text("Recipient Details")) {
                TextField("Recipient Full Name", text: $recipientName)
                TextField("Recipient Country (e.g. China, UK)", text: $recipientCountry)
                TextField("Account / IBAN / Alipay / Routing ID", text: $recipientAccount)
                if pinRequired {
                    SecureField("4-digit Transaction PIN", text: $pin)
                        .keyboardType(.numberPad)
                }
            }

            Section {
                Button(action: submit) {
                    if isSubmitting {
                        ProgressView().frame(maxWidth: .infinity)
                    } else {
                        Text("Submit Transfer Request")
                            .bold()
                            .frame(maxWidth: .infinity)
                            .foregroundColor(brandTheme.onPrimary)
                    }
                }
                .listRowBackground(brandTheme.primary)
                .disabled(!routingAvailable || kycRequired || isSubmitting)
            }

            if !message.isEmpty {
                Section {
                    Text(message).foregroundColor(isSuccess ? .green : .red)
                }
            }

            Section(header: Text("Recent Transfer Requests")) {
                if requests.isEmpty {
                    Text("No transfer requests yet.").font(.caption).foregroundColor(.secondary)
                } else {
                    ForEach(Array(requests.enumerated()), id: \.offset) { _, row in
                        VStack(alignment: .leading, spacing: 4) {
                            Text("\(row["reference"] as? String ?? "") · \((row["status"] as? String ?? "pending").uppercased())")
                                .font(.subheadline).bold()
                            Text(row["summary"] as? String ?? "")
                                .font(.caption)
                            Text(row["created_at"] as? String ?? "")
                                .font(.caption2).foregroundColor(.secondary)
                        }
                        .padding(.vertical, 2)
                    }
                }
            }
        }
        .navigationTitle("Send Money Abroad")
        .onAppear(perform: loadInfo)
        .blockingProgress(isVisible: isLoading, message: "Loading transfer corridors...")
    }

    private func loadInfo() {
        isLoading = true
        AppNetworkService.shared.requestObject("intl-transfer.php", params: ["action": "info"]) { result in
            isLoading = false
            switch result {
            case .success(let body):
                guard body["status"] as? String == "success" else {
                    providerInfo = body["message"] as? String ?? "Transfer service unavailable."
                    routingAvailable = false
                    return
                }
                routeSnapshot = body["route_snapshot"] as? String ?? ""
                requestKey = body["request_key"] as? String ?? ""
                kycRequired = (body["kyc_required"] as? Bool) ?? false
                pinRequired = (body["pin_required"] as? Bool) ?? true
                routingAvailable = (body["routing_available"] as? Bool) ?? false
                let provider = body["provider_label"] as? String ?? ""
                providerInfo = routingAvailable
                    ? "Routed via \(provider). Requests are verified before settlement."
                    : "Transfer routing is temporarily unavailable."

                let rawServices = body["services"] as? [[String: Any]] ?? []
                services = rawServices.compactMap { row in
                    guard let slug = row["slug"] as? String, let label = row["label"] as? String else { return nil }
                    return (slug: slug, label: label)
                }
                if selectedService.isEmpty || !services.contains(where: { $0.slug == selectedService }) {
                    selectedService = services.first?.slug ?? ""
                }
                if let rawCur = body["currencies"] as? [String], !rawCur.isEmpty {
                    currencies = rawCur
                }
                requests = body["requests"] as? [[String: Any]] ?? []
            case .failure:
                providerInfo = "Unable to connect. Please try again."
                routingAvailable = false
            }
        }
    }

    private func fetchQuote() {
        guard let amt = Double(amountUsd.trimmingCharacters(in: .whitespaces)), amt > 0 else {
            quoteNote = "Enter a valid USD amount first."
            return
        }
        quoteNote = "Checking quote..."
        AppNetworkService.shared.requestObject("intl-transfer.php", params: [
            "action": "quote",
            "amount_usd": amt,
            "currency": selectedCurrency
        ]) { result in
            if case .success(let body) = result {
                quoteNote = body["quote_note"] as? String ?? (body["message"] as? String ?? "Quote unavailable")
            } else {
                quoteNote = "Quote unavailable"
            }
        }
    }

    private func submit() {
        guard let amt = Double(amountUsd.trimmingCharacters(in: .whitespaces)), amt >= 1 else {
            isSuccess = false; message = "Enter a valid amount ($1+ USD)."
            return
        }
        let name = recipientName.trimmingCharacters(in: .whitespacesAndNewlines)
        let country = recipientCountry.trimmingCharacters(in: .whitespacesAndNewlines)
        let account = recipientAccount.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !selectedService.isEmpty, !name.isEmpty, !country.isEmpty, !account.isEmpty else {
            isSuccess = false; message = "Fill in all recipient and corridor fields."
            return
        }
        if pinRequired && pin.trimmingCharacters(in: .whitespaces).count != 4 {
            isSuccess = false; message = "Enter your 4-digit Transaction PIN."
            return
        }

        isSubmitting = true
        message = ""
        var params: [String: Any] = [
            "action": "submit",
            "route_snapshot": routeSnapshot,
            "request_key": requestKey,
            "service": selectedService,
            "currency": selectedCurrency,
            "amount_usd": amt,
            "recipient_name": name,
            "recipient_country": country,
            "recipient_account": account
        ]
        if !pin.isEmpty { params["pin"] = pin }

        AppNetworkService.shared.requestObject("intl-transfer.php", params: params) { result in
            isSubmitting = false
            pin = ""
            switch result {
            case .success(let body):
                if body["status"] as? String == "success" {
                    isSuccess = true
                    message = body["message"] as? String ?? "Transfer request submitted."
                    requestKey = body["request_key"] as? String ?? requestKey
                    requests = body["requests"] as? [[String: Any]] ?? requests
                    amountUsd = ""; recipientName = ""; recipientCountry = ""; recipientAccount = ""
                } else {
                    isSuccess = false
                    message = body["message"] as? String ?? "Transfer request failed."
                    if body["code"] as? String == "ROUTE_CHANGED" { loadInfo() }
                }
            case .failure:
                isSuccess = false
                message = "Network error. Retry safely without changing fields."
            }
        }
    }
}
