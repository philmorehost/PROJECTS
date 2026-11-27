import SwiftUI

@MainActor
struct EsimView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @State private var plans: [[String: Any]] = []
    @State private var selectedPlanIndex = 0
    @State private var email = ""
    @State private var pin = ""
    @State private var requestKey = ""
    @State private var railInfo = "Loading eSIM plans..."
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
                    Text("KYC verification is required before ordering a travel eSIM.")
                        .font(.subheadline)
                        .foregroundColor(.orange)
                    NavigationLink("Complete KYC Verification", destination: KYCView())
                        .foregroundColor(brandTheme.ink)
                }
            }

            Section(header: Text("Select eSIM Plan"), footer: Text(railInfo)) {
                if !plans.isEmpty {
                    Picker("Plan", selection: $selectedPlanIndex) {
                        ForEach(0..<plans.count, id: \.self) { idx in
                            let p = plans[idx]
                            let name = p["name"] as? String ?? "Plan"
                            let data = p["data"] as? String ?? ""
                            let days = p["days"] as? String ?? ""
                            let price = p["price_display"] as? String ?? ""
                            Text("\(name) · \(data) / \(days) · \(price)").tag(idx)
                        }
                    }
                    if selectedPlanIndex < plans.count,
                       let countries = plans[selectedPlanIndex]["countries"] as? String,
                       !countries.isEmpty {
                        Text("Coverage: \(countries)")
                            .font(.caption)
                            .foregroundColor(.secondary)
                    }
                }
                TextField("Delivery Email for QR Activation", text: $email)
                    .keyboardType(.emailAddress)
                    .autocapitalization(.none)
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
                        Text("Order eSIM")
                            .frame(maxWidth: .infinity)
                            .foregroundColor(brandTheme.onPrimary)
                            .bold()
                    }
                }
                .listRowBackground(brandTheme.primary)
                .disabled(plans.isEmpty || kycRequired || isSubmitting)
            }

            if !message.isEmpty {
                Section {
                    Text(message).foregroundColor(isSuccess ? .green : .red)
                }
            }

            Section(header: Text("My eSIM Orders")) {
                if requests.isEmpty {
                    Text("No eSIM orders yet.").font(.caption).foregroundColor(.secondary)
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
        .navigationTitle("eSIM")
        .onAppear(perform: loadInfo)
        .blockingProgress(isVisible: isLoading, message: "Loading eSIM plans...")
    }

    private func loadInfo() {
        isLoading = true
        AppNetworkService.shared.requestObject("esim.php", params: ["action": "info"]) { result in
            isLoading = false
            switch result {
            case .success(let body):
                guard body["status"] as? String == "success" else {
                    railInfo = body["message"] as? String ?? "eSIM service unavailable."
                    return
                }
                requestKey = body["request_key"] as? String ?? ""
                kycRequired = (body["kyc_required"] as? Bool) ?? false
                pinRequired = (body["pin_required"] as? Bool) ?? true
                let rail = body["rail_label"] as? String ?? "Aggregator"
                railInfo = "Travel data in 190+ countries · Rail: \(rail)"
                plans = body["plans"] as? [[String: Any]] ?? []
                if email.isEmpty, let defEmail = body["default_email"] as? String {
                    email = defEmail
                }
                requests = body["requests"] as? [[String: Any]] ?? []
            case .failure:
                railInfo = "Unable to connect. Please try again."
            }
        }
    }

    private func submit() {
        guard selectedPlanIndex < plans.count else {
            isSuccess = false; message = "Select an eSIM plan."
            return
        }
        let cleanEmail = email.trimmingCharacters(in: .whitespacesAndNewlines)
        guard cleanEmail.contains("@"), cleanEmail.contains(".") else {
            isSuccess = false; message = "Enter a valid delivery email address."
            return
        }
        if pinRequired && pin.trimmingCharacters(in: .whitespaces).count != 4 {
            isSuccess = false; message = "Enter your 4-digit Transaction PIN."
            return
        }

        let plan = plans[selectedPlanIndex]
        isSubmitting = true
        message = ""
        var params: [String: Any] = [
            "action": "submit",
            "request_key": requestKey,
            "plan_id": plan["plan_id"] as? String ?? "",
            "plan": plan["name"] as? String ?? "",
            "email": cleanEmail
        ]
        if !pin.isEmpty { params["pin"] = pin }

        AppNetworkService.shared.requestObject("esim.php", params: params) { result in
            isSubmitting = false
            pin = ""
            switch result {
            case .success(let body):
                if body["status"] as? String == "success" {
                    isSuccess = true
                    message = body["message"] as? String ?? "eSIM order received."
                    requestKey = body["request_key"] as? String ?? requestKey
                    requests = body["requests"] as? [[String: Any]] ?? requests
                } else {
                    isSuccess = false
                    message = body["message"] as? String ?? "eSIM order failed."
                }
            case .failure:
                isSuccess = false
                message = "Network error. Retry safely without changing fields."
            }
        }
    }
}
