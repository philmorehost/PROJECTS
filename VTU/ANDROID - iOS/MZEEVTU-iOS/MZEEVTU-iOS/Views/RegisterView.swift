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
@MainActor

struct RegisterView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @Environment(\.dismiss) private var dismiss

    @State private var username = ""
    @State private var password = ""
    @State private var confirmPassword = ""
    @State private var firstname = ""
    @State private var lastname = ""
    @State private var email = ""
    @State private var country: String = Locale.current.regionCode ?? "NG"
    @State private var phone = ""
    @State private var address = ""
    @State private var referral = ""

    private var countryOptions: [(code: String, name: String)] {
        let enLocale = Locale(identifier: "en_US")
        return Locale.isoRegionCodes
            .compactMap { code -> (String, String)? in
                guard let name = enLocale.localizedString(forRegionCode: code), !name.isEmpty else { return nil }
                return (code, name)
            }
            .sorted { $0.1 < $1.1 }
    }

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
                        Picker("Country", selection: $country) {
                            ForEach(countryOptions, id: \.code) { item in
                                Text("\(item.name) (\(item.code))").tag(item.code)
                            }
                        }
                        .pickerStyle(.menu)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        TextField("Phone number (e.g. 08012345678 or +447911123456)", text: $phone)
                            .keyboardType(.phonePad)
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
                                .progressViewStyle(CircularProgressViewStyle(tint: brandTheme.onPrimary))
                        } else {
                            Text("Create Account")
                                .fontWeight(.bold)
                                .frame(maxWidth: .infinity)
                        }
                    }
                    .padding()
                    .background(canSubmit ? brandTheme.primary : Color.gray.opacity(0.5))
                    .foregroundColor(canSubmit ? brandTheme.onPrimary : brandTheme.onSurface)
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
        let trimmedPhone = phone.trimmingCharacters(in: .whitespacesAndNewlines)
        let digits = trimmedPhone.filter { $0.isNumber }
        guard digits.count >= 7 && digits.count <= 15 else {
            errorMessage = "Enter a valid phone number (7–15 digits)."
            return
        }
        let normalizedPhone = trimmedPhone.hasPrefix("+") ? "+\(digits)" : digits

        isLoading = true
        let params: [String: Any] = [
            "user": username.lowercased(),
            "pass": password,
            "first": firstname,
            "last": lastname,
            "email": email.lowercased(),
            "country": country,
            "phone": normalizedPhone,
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

                // Only a server-issued revocable app session may establish app login.
                // Without one the caller returns to the normal password/email-OTP flow.
                let data = bcDict(json["data"])
                let key = bcString(data["app_token"])
                if !key.isEmpty {
                    AppNetworkService.shared.apiKey = key
                }
                let uname = bcString(data["username"])
                UserDefaults.standard.set(uname.isEmpty ? username : uname, forKey: "saved_username")
                // A brand-new account has no PIN. Setting this false is what makes the app entry
                // point show the mandatory PIN screen instead of the dashboard.
                AppNetworkService.shared.pinSet = false

                dismiss()
                // Do not open the authenticated dashboard without an issued session.
                if !key.isEmpty { onRegistered() }

            case .failure(let error):
                errorMessage = error.localizedDescription
            }
        }
    }
}
