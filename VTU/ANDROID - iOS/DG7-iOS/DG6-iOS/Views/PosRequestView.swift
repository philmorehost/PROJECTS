import SwiftUI

@MainActor
struct PosRequestView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @State private var businessName = ""
    @State private var contactPhone = ""
    @State private var deviceTypes: [(id: String, label: String)] = [
        ("android", "Android POS"),
        ("classic", "Classic POS"),
        ("mini", "Mini POS")
    ]
    @State private var selectedDevice = "android"
    @State private var quantity = "1"
    @State private var deliveryAddress = ""
    @State private var pin = ""
    @State private var routeSnapshot = ""
    @State private var requestKey = ""
    @State private var providerInfo = "Loading POS options..."
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
                    Text("KYC verification is required before requesting a POS device.")
                        .font(.subheadline)
                        .foregroundColor(.orange)
                    NavigationLink("Complete KYC Verification", destination: KYCView())
                        .foregroundColor(brandTheme.ink)
                }
            }

            Section(header: Text("Business & Terminal Details"), footer: Text(providerInfo)) {
                TextField("Business Name", text: $businessName)
                TextField("Contact Phone", text: $contactPhone)
                    .keyboardType(.phonePad)
                Picker("POS Model", selection: $selectedDevice) {
                    ForEach(deviceTypes, id: \.id) { item in
                        Text(item.label).tag(item.id)
                    }
                }
                TextField("Quantity (1 - 20)", text: $quantity)
                    .keyboardType(.numberPad)
                TextField("Delivery Address", text: $deliveryAddress)
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
                        Text("Submit POS Request")
                            .frame(maxWidth: .infinity)
                            .foregroundColor(brandTheme.onPrimary)
                            .bold()
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

            Section(header: Text("My POS Requests")) {
                if requests.isEmpty {
                    Text("No POS device requests yet.").font(.caption).foregroundColor(.secondary)
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
        .navigationTitle("Request POS Device")
        .onAppear(perform: loadInfo)
        .blockingProgress(isVisible: isLoading, message: "Loading POS service...")
    }

    private func loadInfo() {
        isLoading = true
        AppNetworkService.shared.requestObject("pos-request.php", params: ["action": "info"]) { result in
            isLoading = false
            switch result {
            case .success(let body):
                guard body["status"] as? String == "success" else {
                    providerInfo = body["message"] as? String ?? "POS request service unavailable."
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
                    ? "Routed via \(provider). Our team will verify your business and arrange delivery."
                    : "POS terminal requests are temporarily unavailable."
                if contactPhone.isEmpty, let defPhone = body["default_phone"] as? String {
                    contactPhone = defPhone
                }
                let rawDevices = body["device_types"] as? [[String: Any]] ?? []
                if !rawDevices.isEmpty {
                    deviceTypes = rawDevices.compactMap { row in
                        guard let id = row["id"] as? String, let label = row["label"] as? String else { return nil }
                        return (id: id, label: label)
                    }
                }
                requests = body["requests"] as? [[String: Any]] ?? []
            case .failure:
                providerInfo = "Unable to connect. Please try again."
                routingAvailable = false
            }
        }
    }

    private func submit() {
        let biz = businessName.trimmingCharacters(in: .whitespacesAndNewlines)
        let phone = contactPhone.trimmingCharacters(in: .whitespacesAndNewlines)
        let addr = deliveryAddress.trimmingCharacters(in: .whitespacesAndNewlines)
        let qty = Int(quantity.trimmingCharacters(in: .whitespaces)) ?? 0
        guard !biz.isEmpty, !addr.isEmpty, (1...20).contains(qty) else {
            isSuccess = false; message = "Enter business name, delivery address, and quantity (1 - 20)."
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
            "business_name": biz,
            "contact_phone": phone,
            "device_type": selectedDevice,
            "quantity": qty,
            "delivery_address": addr
        ]
        if !pin.isEmpty { params["pin"] = pin }

        AppNetworkService.shared.requestObject("pos-request.php", params: params) { result in
            isSubmitting = false
            pin = ""
            switch result {
            case .success(let body):
                if body["status"] as? String == "success" {
                    isSuccess = true
                    message = body["message"] as? String ?? "POS request received."
                    requestKey = body["request_key"] as? String ?? requestKey
                    requests = body["requests"] as? [[String: Any]] ?? requests
                    businessName = ""; deliveryAddress = ""; quantity = "1"
                } else {
                    isSuccess = false
                    message = body["message"] as? String ?? "POS request failed."
                    if body["code"] as? String == "ROUTE_CHANGED" { loadInfo() }
                }
            case .failure:
                isSuccess = false
                message = "Network error. Retry safely without changing fields."
            }
        }
    }
}
