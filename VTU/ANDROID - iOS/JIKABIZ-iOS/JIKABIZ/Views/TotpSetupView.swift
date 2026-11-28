import SwiftUI
import CoreImage.CIFilterBuiltins
import UIKit

@MainActor
struct TotpSetupView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @State private var email = ""
    @State private var emailCode = ""
    @State private var setupGrant = ""
    @State private var secret = ""
    @State private var otpAuthUri = ""
    @State private var totpCode = ""
    @State private var isLoading = false
    @State private var statusMessage: String?
    @State private var isError = false
    @State private var copiedSecret = false

    var onComplete: () -> Void
    var onLogout: () -> Void

    var body: some View {
        ScrollView {
            VStack(spacing: 18) {
                Image(systemName: "lock.rotation")
                    .resizable()
                    .scaledToFit()
                    .frame(width: 64, height: 64)
                    .foregroundColor(brandTheme.ink)
                    .padding(.top, 24)

                Text("Set Up Google Authenticator")
                    .font(.title2.bold())
                    .multilineTextAlignment(.center)

                Text("1. Verify your email address.\n2. Scan the QR code or copy the key into Google Authenticator.\n3. Enter the 6-digit code from your authenticator app.")
                    .font(.subheadline)
                    .foregroundColor(.secondary)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal)

                if let msg = statusMessage {
                    Text(msg)
                        .font(.caption)
                        .foregroundColor(isError ? .red : .green)
                        .multilineTextAlignment(.center)
                        .padding(.horizontal)
                }

                if secret.isEmpty {
                    VStack(spacing: 12) {
                        Button(action: sendEmailCode) {
                            Text(email.isEmpty ? "Send Email Verification Code" : "Send Code to \(email)")
                                .fontWeight(.semibold)
                                .frame(maxWidth: .infinity)
                        }
                        .padding()
                        .background(brandTheme.primary)
                        .foregroundColor(brandTheme.onPrimary)
                        .cornerRadius(12)
                        .disabled(isLoading)

                        TextField("6-digit email verification code", text: $emailCode)
                            .keyboardType(.numberPad)
                            .textFieldStyle(RoundedBorderTextFieldStyle())

                        Button(action: verifyEmailAndBegin) {
                            if isLoading {
                                ProgressView().progressViewStyle(CircularProgressViewStyle(tint: brandTheme.onPrimary))
                            } else {
                                Text("Verify Email & Generate Key")
                                    .fontWeight(.bold)
                                    .frame(maxWidth: .infinity)
                            }
                        }
                        .padding()
                        .background(emailCodeClean.count == 6 ? brandTheme.primary : Color.gray.opacity(0.5))
                        .foregroundColor(emailCodeClean.count == 6 ? brandTheme.onPrimary : brandTheme.onSurface)
                        .cornerRadius(12)
                        .disabled(isLoading || emailCodeClean.count != 6)
                    }
                    .padding(.horizontal)
                } else {
                    VStack(spacing: 14) {
                        if let qrImage = generateQRCode(from: otpAuthUri) {
                            Image(uiImage: qrImage)
                                .interpolation(.none)
                                .resizable()
                                .scaledToFit()
                                .frame(width: 180, height: 180)
                                .padding(10)
                                .background(Color.white)
                                .cornerRadius(12)
                        }

                        VStack(spacing: 6) {
                            Text("Setup Key (Time-based)")
                                .font(.caption)
                                .foregroundColor(.secondary)
                            Text(secret)
                                .font(.system(.body, design: .monospaced).bold())
                                .multilineTextAlignment(.center)
                                .textSelection(.enabled)
                                .padding(10)
                                .frame(maxWidth: .infinity)
                                .background(Color(.systemGray6))
                                .cornerRadius(10)
                        }

                        HStack(spacing: 10) {
                            Button(copiedSecret ? "Copied!" : "Copy Key") {
                                UIPasteboard.general.string = secret
                                copiedSecret = true
                            }
                            .buttonStyle(.bordered)

                            if let url = URL(string: otpAuthUri), !otpAuthUri.isEmpty {
                                Button("Open Authenticator") {
                                    UIApplication.shared.open(url)
                                }
                                .buttonStyle(.bordered)
                            }
                        }

                        TextField("6-digit Authenticator code", text: $totpCode)
                            .keyboardType(.numberPad)
                            .textFieldStyle(RoundedBorderTextFieldStyle())

                        Button(action: confirmTotp) {
                            if isLoading {
                                ProgressView().progressViewStyle(CircularProgressViewStyle(tint: brandTheme.onPrimary))
                            } else {
                                Text("Enable Google Authenticator")
                                    .fontWeight(.bold)
                                    .frame(maxWidth: .infinity)
                            }
                        }
                        .padding()
                        .background(totpCodeClean.count == 6 ? brandTheme.primary : Color.gray.opacity(0.5))
                        .foregroundColor(totpCodeClean.count == 6 ? brandTheme.onPrimary : brandTheme.onSurface)
                        .cornerRadius(12)
                        .disabled(isLoading || totpCodeClean.count != 6)
                    }
                    .padding(.horizontal)
                }

                Button("Log out") { onLogout() }
                    .font(.footnote)
                    .foregroundColor(.secondary)
                    .padding(.top, 8)
            }
            .padding()
        }
        .onAppear(perform: checkStatus)
    }

    private var emailCodeClean: String { emailCode.filter { $0.isNumber } }
    private var totpCodeClean: String { totpCode.filter { $0.isNumber } }

    private func checkStatus() {
        AppNetworkService.shared.requestObject("totp-setup.php", params: ["action": "status"]) { result in
            if case .success(let json) = result {
                OnboardingState.shared.syncFromPayload(json)
                email = bcString(json["email"])
                if bcInt(json["totp_enabled"]) == 1 || !OnboardingState.shared.needsTotp {
                    OnboardingState.shared.markTotpDone()
                    onComplete()
                }
            }
        }
    }

    private func sendEmailCode() {
        isLoading = true
        statusMessage = nil
        isError = false
        AppNetworkService.shared.requestObject("totp-setup.php", params: ["action": "send"]) { result in
            isLoading = false
            switch result {
            case .success(let json):
                OnboardingState.shared.syncFromPayload(json)
                if bcBool(json["already_enabled"]) {
                    OnboardingState.shared.markTotpDone()
                    onComplete()
                    return
                }
                let ok = bcString(json["status"]) == "success"
                isError = !ok
                statusMessage = bcString(json["message"]).isEmpty ? (ok ? "Verification code sent." : "Could not send verification code.") : bcString(json["message"])
            case .failure(let err):
                isError = true
                statusMessage = err.localizedDescription
            }
        }
    }

    private func verifyEmailAndBegin() {
        guard emailCodeClean.count == 6 else { return }
        isLoading = true
        statusMessage = nil
        isError = false
        AppNetworkService.shared.requestObject("totp-setup.php", params: [
            "action": "verify",
            "email_code": emailCodeClean
        ]) { result in
            switch result {
            case .success(let json):
                if bcBool(json["already_enabled"]) {
                    isLoading = false
                    OnboardingState.shared.markTotpDone()
                    onComplete()
                    return
                }
                let grant = bcString(json["setup_grant"])
                if bcString(json["status"]) == "success", !grant.isEmpty {
                    setupGrant = grant
                    beginTotp(grant: grant)
                } else {
                    isLoading = false
                    isError = true
                    statusMessage = bcString(json["message"]).isEmpty ? "Invalid verification code." : bcString(json["message"])
                }
            case .failure(let err):
                isLoading = false
                isError = true
                statusMessage = err.localizedDescription
            }
        }
    }

    private func beginTotp(grant: String) {
        AppNetworkService.shared.requestObject("totp-setup.php", params: [
            "action": "begin",
            "setup_grant": grant
        ]) { result in
            isLoading = false
            switch result {
            case .success(let json):
                if bcBool(json["already_enabled"]) {
                    OnboardingState.shared.markTotpDone()
                    onComplete()
                    return
                }
                let sec = bcString(json["secret"])
                if bcString(json["status"]) == "success", !sec.isEmpty {
                    secret = sec
                    otpAuthUri = bcString(json["uri"])
                    isError = false
                    statusMessage = bcString(json["message"]).isEmpty ? "Add this key to Google Authenticator." : bcString(json["message"])
                } else {
                    isError = true
                    statusMessage = bcString(json["message"]).isEmpty ? "Could not generate setup key." : bcString(json["message"])
                }
            case .failure(let err):
                isError = true
                statusMessage = err.localizedDescription
            }
        }
    }

    private func confirmTotp() {
        guard totpCodeClean.count == 6, !setupGrant.isEmpty else { return }
        isLoading = true
        statusMessage = nil
        isError = false
        AppNetworkService.shared.requestObject("totp-setup.php", params: [
            "action": "confirm",
            "setup_grant": setupGrant,
            "totp_code": totpCodeClean
        ]) { result in
            isLoading = false
            switch result {
            case .success(let json):
                OnboardingState.shared.syncFromPayload(json)
                if bcString(json["status"]) == "success" {
                    OnboardingState.shared.markTotpDone()
                    onComplete()
                } else {
                    isError = true
                    statusMessage = bcString(json["message"]).isEmpty ? "Authenticator code did not match." : bcString(json["message"])
                }
            case .failure(let err):
                isError = true
                statusMessage = err.localizedDescription
            }
        }
    }

    private func generateQRCode(from string: String) -> UIImage? {
        guard !string.isEmpty else { return nil }
        let context = CIContext()
        let filter = CIFilter.qrCodeGenerator()
        filter.message = Data(string.utf8)
        guard let outputImage = filter.outputImage else { return nil }
        let scaled = outputImage.transformed(by: CGAffineTransform(scaleX: 8, y: 8))
        guard let cgimg = context.createCGImage(scaled, from: scaled.extent) else { return nil }
        return UIImage(cgImage: cgimg)
    }
}
