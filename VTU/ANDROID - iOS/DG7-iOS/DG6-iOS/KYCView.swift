//
//  KYCView.swift
//  DG7-iOS
//
//  KYC Verification View
//

import SwiftUI

struct KYCView: View {
    @StateObject private var viewModel = KYCViewModel()
    @Environment(\.dismiss) var dismiss
    
    var body: some View {
        ScrollView {
            VStack(spacing: 24) {
                    // Status Card
                    KYCStatusCard(viewModel: viewModel)

                    // Traditional KYC Methods
                    TraditionalKYCSection(viewModel: viewModel)
                    
                    // AI Interview Section
                    AIInterviewSection(viewModel: viewModel)
                }
                .padding()
            }
            .navigationTitle("Identity Verification (KYC)")
            .navigationBarTitleDisplayMode(.inline)

            .onAppear {
                viewModel.loadKYCStatus()
            }
            .alert("Verification Result", isPresented: $viewModel.showResult) {
                Button("OK") { viewModel.showResult = false }
            } message: {
                Text(viewModel.resultMessage)
            }
    }
}

struct KYCStatusCard: View {
    @ObservedObject var viewModel: KYCViewModel
    
    var body: some View {
        VStack(spacing: 16) {
            Image(systemName: statusIcon)
                .font(.system(size: 60))
                .foregroundColor(statusColor)
            
            Text(statusTitle)
                .font(.title2)
                .fontWeight(.bold)
            
            Text(statusMessage)
                .font(.subheadline)
                .foregroundColor(.secondary)
                .multilineTextAlignment(.center)
            
            if viewModel.kycStatus == 2 {
                Label("Fully Verified", systemImage: "checkmark.seal.fill")
                    .font(.caption)
                    .foregroundColor(.green)
            } else if viewModel.kycStatus == 1 {
                Label("Under Review", systemImage: "clock.fill")
                    .font(.caption)
                    .foregroundColor(.orange)
            }
        }
        .frame(maxWidth: .infinity)
        .padding()
        .background(Color(.systemGray6))
        .cornerRadius(20)
    }
    
    var statusIcon: String {
        switch viewModel.kycStatus {
        case 2: return "checkmark.seal.fill"
        case 1: return "clock.fill"
        default: return "shield.lefthalf.filled"
        }
    }
    
    var statusColor: Color {
        switch viewModel.kycStatus {
        case 2: return .green
        case 1: return .orange
        default: return .blue
        }
    }
    
    var statusTitle: String {
        switch viewModel.kycStatus {
        case 2: return "Fully Verified"
        case 1: return "Under Review"
        default: return "Unverified"
        }
    }
    
    var statusMessage: String {
        switch viewModel.kycStatus {
        case 2: return "Your identity has been confirmed. You have unrestricted access to all services."
        case 1: return "Your documents are being processed by our compliance team."
        default: return "Please complete the required steps below to secure your account."
        }
    }
}

struct TraditionalKYCSection: View {
    @ObservedObject var viewModel: KYCViewModel
    
    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("Basic Verification")
                .font(.headline)
            
            VStack(spacing: 12) {
                if viewModel.bvnEnabled {
                    KYCInputField(
                        title: "Bank Verification Number (BVN)",
                        placeholder: "Enter 11-digit BVN",
                        value: $viewModel.bvnValue,
                        isSaved: viewModel.bvnSaved
                    )
                    KYCSubmitButton(
                        title: "Verify BVN",
                        isEnabled: viewModel.bvnValue.count == 11 && !viewModel.isLoading
                    ) {
                        viewModel.submitIdentity(type: "bvn", value: viewModel.bvnValue)
                    }
                }
                
                if viewModel.ninEnabled {
                    KYCInputField(
                        title: "National Identity Number (NIN)",
                        placeholder: "Enter 11-digit NIN",
                        value: $viewModel.ninValue,
                        isSaved: viewModel.ninSaved
                    )
                    KYCSubmitButton(
                        title: "Verify NIN",
                        isEnabled: viewModel.ninValue.count == 11 && !viewModel.isLoading
                    ) {
                        viewModel.submitIdentity(type: "nin", value: viewModel.ninValue)
                    }
                }

                if !viewModel.stillNeeded.isEmpty {
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Still outstanding")
                            .font(.caption)
                            .fontWeight(.semibold)
                            .foregroundColor(.secondary)
                        ForEach(viewModel.stillNeeded, id: \.self) { item in
                            Text("â€¢ " + item)
                                .font(.caption)
                                .foregroundColor(.secondary)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
        }
        .padding()
        .background(Color(.systemGray6))
        .cornerRadius(16)
    }
}

struct KYCSubmitButton: View {
    let title: String
    let isEnabled: Bool
    let action: () -> Void
    
    var body: some View {
        Button(action: action) {
            Text(title)
                .fontWeight(.semibold)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 12)
                .background(isEnabled ? Color.blue : Color.gray.opacity(0.4))
                .foregroundColor(.white)
                .cornerRadius(10)
        }
        .disabled(!isEnabled)
    }
}

struct KYCInputField: View {
    let title: String
    let placeholder: String
    @Binding var value: String
    let isSaved: Bool
    
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text(title)
                    .font(.subheadline)
                    .fontWeight(.medium)
                Spacer()
                if isSaved {
                    Label("Saved", systemImage: "checkmark.circle.fill")
                        .font(.caption)
                        .foregroundColor(.green)
                }
            }
            
            TextField(placeholder, text: $value)
                .textFieldStyle(RoundedBorderTextFieldStyle())
                .keyboardType(.numberPad)
        }
        .padding()
        .background(Color(.systemBackground))
        .cornerRadius(12)
        .shadow(color: .black.opacity(0.05), radius: 4, x: 0, y: 2)
    }
}

struct AIInterviewSection: View {
    @ObservedObject var viewModel: KYCViewModel
    
    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("AI Compliance Interview")
                .font(.headline)
            
            Button(action: {
                viewModel.showAIInterview = true
            }) {
                HStack {
                    Image(systemName: "mic.fill")
                        .font(.title2)
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Titanium AI Interview")
                            .font(.headline)
                        Text("Complete your KYC by talking to our AI Compliance Officer. No forms required.")
                            .font(.caption)
                            .foregroundColor(.secondary)
                    }
                    Spacer()
                    Image(systemName: "chevron.right")
                        .foregroundColor(.secondary)
                }
                .padding()
                .background(
                    LinearGradient(
                        colors: [Color.blue, Color.purple],
                        startPoint: .leading,
                        endPoint: .trailing
                    )
                )
                .foregroundColor(.white)
                .cornerRadius(16)
            }
        }
        .sheet(isPresented: $viewModel.showAIInterview) {
            AIInterviewView(viewModel: viewModel)
        }
    }
}

struct KYCViewModel: ObservableObject {
    @Published var kycStatus: Int = 0
    @Published var kycStatusName: String = "Unverified"
    @Published var bvnValue: String = ""
    @Published var ninValue: String = ""
    // Both numbers can be submitted on every platform; whether the vendor actually requires one is
    // decided server-side (bc_kyc_checks_pending) and reported back in `still_needed`.
    @Published var bvnEnabled: Bool = true
    @Published var ninEnabled: Bool = true
    @Published var bvnSaved: Bool = false
    @Published var ninSaved: Bool = false
    @Published var stillNeeded: [String] = []
    @Published var isLoading: Bool = false
    @Published var showResult: Bool = false
    @Published var resultMessage: String = ""
    @Published var showAIInterview: Bool = false
    
    func loadKYCStatus() {
        AppNetworkService.shared.requestObject("kyc.php", params: ["action": "status"]) { result in
            guard case .success(let json) = result else { return }
            self.kycStatus = bcInt(json["kyc_status"])
            let name = bcString(json["kyc_name"])
            if !name.isEmpty { self.kycStatusName = name }
            self.bvnSaved = bcBool(json["bvn_set"])
            self.ninSaved = bcBool(json["nin_set"])
            self.stillNeeded = (json["still_needed"] as? [String]) ?? []
        }
    }

    /// Submits a BVN or NIN through `kyc.php` action=submit_bvn_nin.
    /// The server verifies it with the vendor's provider and answers `status` = success/failed.
    func submitIdentity(type: String, value: String) {
        let cleaned = value.filter { $0.isNumber }
        guard cleaned.count >= 10 else {
            resultMessage = "Enter a valid \(type.uppercased()) (at least 10 digits)."
            showResult = true
            return
        }

        isLoading = true
        AppNetworkService.shared.requestObject(
            "kyc.php",
            params: ["action": "submit_bvn_nin", "type": type, "value": cleaned]
        ) { result in
            self.isLoading = false
            switch result {
            case .success(let json):
                let status = bcString(json["status"])
                let desc = bcString(json["desc"])
                self.resultMessage = desc.isEmpty
                    ? (status == "success" ? "\(type.uppercased()) verified." : "Verification failed.")
                    : desc
                self.showResult = true
                self.loadKYCStatus()
            case .failure(let error):
                self.resultMessage = error.localizedDescription
                self.showResult = true
            }
        }
    }
}