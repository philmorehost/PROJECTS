//
//  RegisterView.swift
//  The iOS equivalent of `web/Register.php`, which the app has never had.
//
//  LoginView only ever offered login, so an iOS user could not create an account at all. This form
//  posts the exact fields `web/api/register.php` validates (user / pass / first / last / email /
//  phone / address / referral) and, because register.php returns the new account's api_key, signs
//  the user straight in — the same behaviour as the website, which sets `$_SESSION["user_session"]`
//  and redirects. The caller then hits the mandatory PIN gate in the App entry point.
//

import SwiftUI

struct RegisterView: View {
    @Environment(\.dismiss) private var dismiss

    @State private var username = ""
    @State private var password = ""
    @State private var confirmPassword = ""
    @State private var firstname = ""
    @State private var lastname = ""
    @State private var email = ""
    @State private var phone = ""
    @State private var address = ""
    @State private var referral = ""

    @State private var isLoading = false
    @State private var errorMessage: String?

    /// Called once registration (and the auto-login) succeeded.
    var onRegistered: () -> Void

    var body: some View {
        NavigationView {
            ScrollView {
                VStack(spacing: 14) {
                    if let error = errorMessage {
                        Text(error)
                            .foregroundColor(.red)
                            .font(.caption)
                            .multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }

                    Group {
                        TextField("Username (min 6 characters)", text: $username)
                            .autocapitalization(.none)
                            .disableAutocorrection(true)
                        TextField("First name", text: $firstname)
                        TextField("Last name", text: $lastname)
                        TextField("Email address", text: $email)
                            .keyboardType(.emailAddress)
                            .autocapitalization(.none)
                            .disableAutocorrection(true)
                        TextField("Phone number (11 digits)", text: $phone)
                            .keyboardType(.numberPad)
                        TextField("Home address", text: $address)
                        TextField("Referral username (optional)", text: $referral)
                            .autocapitalization(.none)
                            .disableAutocorrection(true)
                        SecureField("Password", text: $password)
                        SecureField("Confirm password", text: $confirmPassword)
                    }
                    .textFieldStyle(RoundedBorderTextFieldStyle())

                    Button(action: register) {
                        if isLoading {
                            ProgressView()
                                .progressViewStyle(CircularProgressViewStyle(tint: .white))
                        } else {
                            Text("Create Account")
                                .fontWeight(.bold)
                                .frame(maxWidth: .infinity)
                        }
                    }
                    .padding()
                    .background(canSubmit ? Color.blue : Color.gray.opacity(0.5))
                    .foregroundColor(.white)
                    .cornerRadius(12)
                    .disabled(!canSubmit || isLoading)
                }
                .padding()
            }
            .navigationTitle("Create Account")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Cancel") { dismiss() }
                }
            }
        }
    }

    private var canSubmit: Bool {
        !username.isEmpty && !password.isEmpty && !firstname.isEmpty &&
            !lastname.isEmpty && !email.isEmpty && !phone.isEmpty
    }

    private func register() {
        errorMessage = nil

        // Mirror the server's own rules so the failure is explained before a round trip.
        guard username.count >= 6 else {
            errorMessage = "Username must be at least 6 characters."
            return
        }
        guard password == confirmPassword else {
            errorMessage = "Passwords do not match."
            return
        }
        let digits = phone.filter { $0.isNumber }
        guard digits.count == 11 else {
            errorMessage = "Phone number must be 11 digits."
            return
        }

        isLoading = true
        let params: [String: Any] = [
            "user": username.lowercased(),
            "pass": password,
            "first": firstname,
            "last": lastname,
            "email": email.lowercased(),
            "phone": digits,
            "address": address,
            "referral": referral.lowercased()
        ]

        AppNetworkService.shared.requestObject("register.php", params: params) { result in
            isLoading = false
            switch result {
            case .success(let json):
                let status = bcString(json["status"])
                guard status == "success" else {
                    let msg = bcString(json["message"])
                    errorMessage = msg.isEmpty ? "Registration failed." : msg
                    return
                }

                // register.php hands back the new account's api_key, so log in immediately — the
                // website does the same with $_SESSION["user_session"].
                let data = bcDict(json["data"])
                let key = bcString(data["api_key"])
                if !key.isEmpty {
                    AppNetworkService.shared.apiKey = key
                }
                let uname = bcString(data["username"])
                UserDefaults.standard.set(uname.isEmpty ? username : uname, forKey: "saved_username")
                // A brand-new account has no PIN. Setting this false is what makes the app entry
                // point show the mandatory PIN screen instead of the dashboard.
                AppNetworkService.shared.pinSet = false

                dismiss()
                onRegistered()

            case .failure(let error):
                errorMessage = error.localizedDescription
            }
        }
    }
}
