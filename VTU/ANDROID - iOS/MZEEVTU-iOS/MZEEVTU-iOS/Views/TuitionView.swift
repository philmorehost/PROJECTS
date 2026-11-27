import SwiftUI

@MainActor
struct TuitionView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @State private var universities: [String] = []
    @State private var selectedQuickUniversity = ""
    @State private var university = ""
    @State private var studentName = ""
    @State private var studentId = ""
    @State private var amountUsd = ""
    @State private var pin = ""
    @State private var routeSnapshot = ""
    @State private var requestKey = ""
    @State private var providerInfo = "Loading institutions..."
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
                    Text("KYC verification is required before submitting tuition payments.")
                        .font(.subheadline)
                        .foregroundColor(.orange)
                    NavigationLink("Complete KYC Verification", destination: KYCView())
                        .foregroundColor(brandTheme.ink)
                }
            }

            Section(header: Text("Institution & Student Details"), footer: Text(providerInfo)) {
                if !universities.isEmpty {
                    Picker("Quick Institution List", selection: $selectedQuickUniversity) {
                        Text("Select or type below...").tag("")
                        ForEach(universities, id: \.self) { uni in
                            Text(uni).tag(uni)
                        }
                    }
                    .onChange(of: selectedQuickUniversity) { chosen in
                        if !chosen.isEmpty { university = chosen }
                    }
                }
                TextField("Institution Name", text: $university)
                TextField("Student Full Name", text: $studentName)
                TextField("Student ID / Matric No.", text: $studentId)
                TextField("Tuition Amount (USD)", text: $amountUsd)
                    .keyboardType(.decimalPad)
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
                        Text("Submit Tuition Request")
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

            Section(header: Text("My Tuition Requests")) {
                if requests.isEmpty {
                    Text("No tuition requests yet.").font(.caption).foregroundColor(.secondary)
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
        .navigationTitle("Pay Tuition Worldwide")
        .onAppear(perform: loadInfo)
        .blockingProgress(isVisible: isLoading, message: "Loading tuition service...")
    }

    private func loadInfo() {
        isLoading = true
        AppNetworkService.shared.requestObject("tuition.php", params: ["action": "info"]) { result in
            isLoading = false
            switch result {
            case .success(let body):
                guard body["status"] as? String == "success" else {
                    providerInfo = body["message"] as? String ?? "Tuition service unavailable."
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
                    ? "Routed via \(provider). Student ID is validated before settlement."
                    : "Tuition payment routing is temporarily unavailable."
                universities = body["universities"] as? [String] ?? []
                requests = body["requests"] as? [[String: Any]] ?? []
            case .failure:
                providerInfo = "Unable to connect. Please try again."
                routingAvailable = false
            }
        }
    }

    private func submit() {
        let uni = university.trimmingCharacters(in: .whitespacesAndNewlines)
        let sName = studentName.trimmingCharacters(in: .whitespacesAndNewlines)
        let sId = studentId.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let amt = Double(amountUsd.trimmingCharacters(in: .whitespaces)), amt >= 1 else {
            isSuccess = false; message = "Enter a valid tuition amount ($1+ USD)."
            return
        }
        guard !uni.isEmpty, !sName.isEmpty, !sId.isEmpty else {
            isSuccess = false; message = "Enter institution, student name, and student ID."
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
            "university": uni,
            "student_name": sName,
            "student_id": sId,
            "amount_usd": amt
        ]
        if !pin.isEmpty { params["pin"] = pin }

        AppNetworkService.shared.requestObject("tuition.php", params: params) { result in
            isSubmitting = false
            pin = ""
            switch result {
            case .success(let body):
                if body["status"] as? String == "success" {
                    isSuccess = true
                    message = body["message"] as? String ?? "Tuition request received."
                    requestKey = body["request_key"] as? String ?? requestKey
                    requests = body["requests"] as? [[String: Any]] ?? requests
                    studentName = ""; studentId = ""; amountUsd = ""
                } else {
                    isSuccess = false
                    message = body["message"] as? String ?? "Tuition submission failed."
                    if body["code"] as? String == "ROUTE_CHANGED" { loadInfo() }
                }
            case .failure:
                isSuccess = false
                message = "Network error. Retry safely without changing fields."
            }
        }
    }
}
