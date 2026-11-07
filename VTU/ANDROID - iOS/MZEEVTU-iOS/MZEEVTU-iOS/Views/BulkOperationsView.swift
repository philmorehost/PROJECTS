import SwiftUI

struct BulkOperationsView: View {
    @State private var serviceType: String = "airtime"
    @State private var recipients: String = ""
    @State private var paramValue: String = ""
    
    @State private var validationSummary: String = "Live Validation: 0 numbers parsed"
    @State private var validationDetails: String = "MTN: 0 | Airtel: 0 | Glo: 0 | 9mobile: 0"
    
    @State private var isProcessing = false
    @State private var progressMessage = ""
    @State private var feedbackMessage = ""
    @State private var successCount = 0
    @State private var totalCount = 0

    let services = ["Airtime", "Data", "SMS"]

    var body: some View {
        ScrollView {
            VStack(spacing: 20) {
                // Service Picker
                VStack(alignment: .leading, spacing: 8) {
                    Text("SELECT SERVICE")
                        .font(.caption)
                        .fontWeight(.bold)
                        .foregroundColor(.secondary)
                    
                    Picker("Service", selection: $serviceType) {
                        Text("Airtime").tag("airtime")
                        Text("Data").tag("data")
                        Text("SMS").tag("sms")
                    }
                    .pickerStyle(SegmentedPickerStyle())
                }
                .padding(.horizontal)

                // Recipient list editor
                VStack(alignment: .leading, spacing: 8) {
                    Text("RECIPIENT NUMBERS (COMMA SEPARATED)")
                        .font(.caption)
                        .fontWeight(.bold)
                        .foregroundColor(.secondary)
                    
                    TextEditor(text: $recipients)
                        .frame(height: 120)
                        .padding(4)
                        .background(Color(.systemGray6))
                        .cornerRadius(12)
                        .overlay(
                            RoundedRectangle(cornerRadius: 12)
                                .stroke(Color(.systemGray4), lineWidth: 1)
                        )
                        .onChange(of: recipients) { val in
                            validateNumbers(val)
                        }
                }
                .padding(.horizontal)

                // Live Validation Panel
                VStack(alignment: .leading, spacing: 6) {
                    Text(validationSummary)
                        .font(.system(size: 13, weight: .bold))
                    Text(validationDetails)
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding()
                .background(Color(.systemGray5))
                .cornerRadius(12)
                .padding(.horizontal)

                // Parameter Field
                VStack(alignment: .leading, spacing: 8) {
                    Text(serviceType == "sms" ? "SMS MESSAGE TEXT" : (serviceType == "data" ? "PLAN VALUE (₦)" : "AMOUNT PER NUMBER (₦)"))
                        .font(.caption)
                        .fontWeight(.bold)
                        .foregroundColor(.secondary)
                    
                    TextField(serviceType == "sms" ? "Enter batch message to send..." : "Enter amount (e.g. 100)", text: $paramValue)
                        .textFieldStyle(RoundedBorderTextFieldStyle())
                        .keyboardType(serviceType == "sms" ? .default : .numberPad)
                }
                .padding(.horizontal)

                // Submit button
                Button(action: executeBatch) {
                    if isProcessing {
                        VStack {
                            ProgressView()
                                .progressViewStyle(CircularProgressViewStyle(tint: .white))
                            Text(progressMessage)
                                .font(.caption)
                                .foregroundColor(.white)
                        }
                        .frame(maxWidth: .infinity, minHeight: 52)
                    } else {
                        Text("PROCESS BATCH")
                            .font(.headline)
                            .foregroundColor(.white)
                            .frame(maxWidth: .infinity, minHeight: 52)
                    }
                }
                .background(Color.blue)
                .cornerRadius(26)
                .padding(.horizontal)
                .disabled(isProcessing)

                if !feedbackMessage.isEmpty {
                    Text(feedbackMessage)
                        .font(.callout)
                        .foregroundColor(feedbackMessage.contains("🎉") ? .green : .red)
                        .padding()
                        .multilineTextAlignment(.center)
                }

                // Batch Transactions History direct link
                NavigationLink(destination: BatchTransactionsView()) {
                    HStack {
                        Image(systemName: "clock.fill")
                        Text("View Batch History")
                            .fontWeight(.semibold)
                    }
                    .foregroundColor(.blue)
                    .padding()
                }

                Spacer()
            }
            .padding(.top)
        }
        .navigationTitle("Bulk Operations")
    }

    private func validateNumbers(_ text: String) {
        let list = text.components(separatedBy: ",").map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }.filter { !$0.isEmpty }
        var mtn = 0
        var airtel = 0
        var glo = 0
        var mobile9 = 0
        var invalid = 0

        for num in list {
            let clean = num.replacingOccurrences(of: " ", with: "")
            if clean.count >= 10 {
                var prefix = ""
                if clean.hasPrefix("+234") {
                    prefix = "0" + String(clean.dropFirst(4))
                } else if clean.hasPrefix("234") {
                    prefix = "0" + String(clean.dropFirst(3))
                } else {
                    prefix = clean
                }
                prefix = String(prefix.prefix(4))

                if ["0803", "0806", "0810", "0813", "0814", "0816", "0903", "0906", "0913"].contains(prefix) {
                    mtn += 1
                } else if ["0802", "0808", "0812", "0701", "0708", "0902", "0907", "0901", "0912"].contains(prefix) {
                    airtel += 1
                } else if ["0805", "0807", "0811", "0815", "0705", "0905", "0915"].contains(prefix) {
                    glo += 1
                } else if ["0809", "0817", "0818", "0908", "0909"].contains(prefix) {
                    mobile9 += 1
                } else {
                    invalid += 1
                }
            } else {
                invalid += 1
            }
        }

        let total = mtn + airtel + glo + mobile9
        validationSummary = "Live Validation: \(total) numbers verified (\(invalid) invalid)"
        validationDetails = "MTN: \(mtn) | Airtel: \(airtel) | Glo: \(glo) | 9mobile: \(mobile9)"
    }

    private func executeBatch() {
        let cleanRecipients = recipients.trimmingCharacters(in: .whitespacesAndNewlines)
        let cleanParam = paramValue.trimmingCharacters(in: .whitespacesAndNewlines)
        
        guard !cleanRecipients.isEmpty else {
            feedbackMessage = "Please input recipient numbers"
            return
        }
        guard !cleanParam.isEmpty else {
            feedbackMessage = "Please fill in parameter details"
            return
        }

        let list = cleanRecipients.components(separatedBy: ",").map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }.filter { !$0.isEmpty }
        totalCount = list.count
        successCount = 0
        isProcessing = true
        feedbackMessage = "Initiating batch job for \(totalCount) numbers..."

        // Perform sequential coroutine-like task processing
        executeSequentialRequest(list: list, index: 0)
    }

    private func executeSequentialRequest(list: [String], index: Int) {
        if index >= list.count {
            // Completed
            isProcessing = false
            feedbackMessage = "🎉 Batch completed! Successfully processed \(successCount)/\(totalCount) requests."
            recipients = ""
            paramValue = ""
            return
        }

        progressMessage = "PROCESSING \(index + 1)/\(list.count)..."
        let num = list[index]

        var params: [String: Any] = [
            "phone_number": num,
            "phone": num
        ]

        if serviceType == "sms" {
            params["message"] = paramValue
        } else {
            params["amount"] = paramValue
        }

        let endpoint = serviceType == "sms" ? "sms.php" : (serviceType == "data" ? "data.php" : "airtime.php")

        AppNetworkService.shared.request(endpoint, params: params) { (result: Result<[String: AnyCodableValue], Error>) in
            switch result {
            case .success(let dict):
                if dict["status"]?.stringValue == "success" {
                    self.successCount += 1
                }
            case .failure:
                break
            }

            // brief interval delay (300ms) before continuing next item in queue
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                self.executeSequentialRequest(list: list, index: index + 1)
            }
        }
    }
}
