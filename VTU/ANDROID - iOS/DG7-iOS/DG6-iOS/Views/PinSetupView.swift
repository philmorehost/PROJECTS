//
//  PinSetupView.swift
//  The iOS equivalent of `web/SecurityPIN.php`.
//
//  The website cannot be reached past the dashboard while the account has no transaction PIN:
//  `web/Register.php` ends with `header("Location: SecurityPIN.php")` and `web/Dashboard.php`
//  bounces PIN-less accounts back there. The iOS app had neither a registration screen nor a PIN
//  screen, so an iOS sign-up could never satisfy the gate every purchase API enforces.
//
//  This screen is deliberately not dismissible from the dashboard: it either sets the PIN or logs
//  the user out, exactly like the web page.
//

import SwiftUI

struct PinSetupView: View {
    @State private var pin = ""
    @State private var confirmPin = ""
    @State private var isLoading = false
    @State private var errorMessage: String?

    /// Called after the server confirms the PIN was stored.
    var onComplete: () -> Void
    /// Called when the user chooses to log out instead of setting a PIN.
    var onLogout: () -> Void

    var body: some View {
        VStack(spacing: 20) {
            Spacer()

            Image(systemName: "lock.shield.fill")
                .resizable()
                .scaledToFit()
                .frame(width: 70, height: 70)
                .foregroundColor(.blue)

            Text("Set your transaction PIN")
                .font(.title2)
                .fontWeight(.bold)
                .multilineTextAlignment(.center)

            Text("Your account needs a 4-digit transaction PIN before you can buy, withdraw or transfer. This is the same step the website asks for right after sign-up.")
                .font(.subheadline)
                .foregroundColor(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal)

            if let error = errorMessage {
                Text(error)
                    .foregroundColor(.red)
                    .font(.caption)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal)
            }

            VStack(spacing: 12) {
                SecureField("4-digit PIN", text: $pin)
                    .keyboardType(.numberPad)
                    .textFieldStyle(RoundedBorderTextFieldStyle())
                SecureField("Confirm PIN", text: $confirmPin)
                    .keyboardType(.numberPad)
                    .textFieldStyle(RoundedBorderTextFieldStyle())
            }
            .padding(.horizontal)

            Button(action: setPin) {
                if isLoading {
                    ProgressView()
                        .progressViewStyle(CircularProgressViewStyle(tint: .white))
                } else {
                    Text("Set PIN")
                        .fontWeight(.bold)
                        .frame(maxWidth: .infinity)
                }
            }
            .padding()
            .background(isValid ? Color.blue : Color.gray.opacity(0.5))
            .foregroundColor(.white)
            .cornerRadius(12)
            .padding(.horizontal)
            .disabled(!isValid || isLoading)

            Button("Log out") { onLogout() }
                .font(.footnote)
                .foregroundColor(.secondary)

            Spacer()
            Spacer()
        }
        .padding()
    }

    private var isValid: Bool {
        pin.count == 4 && pin.allSatisfy { $0.isNumber } && pin == confirmPin
    }

    private func setPin() {
        errorMessage = nil

        guard pin.count == 4, pin.allSatisfy({ $0.isNumber }) else {
            errorMessage = "PIN must be exactly 4 digits."
            return
        }
        guard pin == confirmPin else {
            errorMessage = "PINs do not match."
            return
        }

        isLoading = true
        AppNetworkService.shared.requestObject("set-pin.php", params: ["pin": pin]) { result in
            isLoading = false
            switch result {
            case .success(let json):
                let status = bcString(json["status"])
                if status == "success" {
                    // Trust the server's own answer when it sends one; a successful call is enough
                    // otherwise because set-pin.php only reports success after writing the PIN.
                    AppNetworkService.shared.pinSet = bcBool(json["security_pin_set"]) || status == "success"
                    onComplete()
                } else {
                    let desc = bcString(json["desc"])
                    errorMessage = desc.isEmpty ? "Could not save your PIN. Please try again." : desc
                }
            case .failure(let error):
                errorMessage = error.localizedDescription
            }
        }
    }
}
