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
    @State private var infoMessage: String?
    @State private var registrationToken: String?
    @State private var registrationOtp = ""
    @State private var loginChallenge: String?
    @State private var loginOtp = ""

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
                    if let info = infoMessage {
                        Text(info)
                            .foregroundColor(brandTheme.ink)
                            .font(.caption)
                            .multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }

                    if let challenge = loginChallenge {
                        Text("Sign-In Verification")
                            .font(.headline)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        Text("Account created! Enter the 6-digit login code sent to your email.")
                            .font(.subheadline)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        TextField("6-digit code", text: $loginOtp)
                            .keyboardType(.numberPad)
                            .textFieldStyle(RoundedBorderTextFieldStyle())

                        Button(action: { verifyLoginOtp(challenge: challenge) }) {
                            if isLoading {
                                ProgressView()
                                    .progressViewStyle(CircularProgressViewStyle(tint: brandTheme.onPrimary))
                            } else {
                                Text("Verify and Sign In")
                                    .fontWeight(.bold)
                                    .frame(maxWidth: .infinity)
                            }
                        }
                        .padding()
                        .background(loginOtp.count == 6 ? brandTheme.primary : Color.gray.opacity(0.5))
                        .foregroundColor(loginOtp.count == 6 ? brandTheme.onPrimary : brandTheme.onSurface)
                        .cornerRadius(12)
                        .disabled(isLoading || loginOtp.count != 6 || !loginOtp.allSatisfy({ $0.isNumber }))
                    } else if let token = registrationToken {
                        Text("Verify Your Email")
                            .font(.headline)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        Text(infoMessage ?? "Enter the 6-digit verification code sent to \(email).")
                            .font(.subheadline)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        TextField("6-digit verification code", text: $registrationOtp)
                            .keyboardType(.numberPad)
                            .textFieldStyle(RoundedBorderTextFieldStyle())

                        Button(action: { verifyRegistrationOtp(token: token) }) {
                            if isLoading {
                                ProgressView()
                                    .progressViewStyle(CircularProgressViewStyle(tint: brandTheme.onPrimary))
                            } else {
                                Text("Verify")
                                    .fontWeight(.bold)
                                    .frame(maxWidth: .infinity)
                            }
                        }
                        .padding()
                        .background(registrationOtp.count == 6 ? brandTheme.primary : Color.gray.opacity(0.5))
                        .foregroundColor(registrationOtp.count == 6 ? brandTheme.onPrimary : brandTheme.onSurface)
                        .cornerRadius(12)
                        .disabled(isLoading || registrationOtp.count != 6 || !registrationOtp.allSatisfy({ $0.isNumber }))

                        HStack {
                            Button("Resend Code") {
                                resendRegistrationOtp(token: token)
                            }
                            .disabled(isLoading)
                            Spacer()
                            Button("Edit Details") {
                                registrationToken = nil
                                registrationOtp = ""
                                errorMessage = nil
                                infoMessage = nil
                            }
                            .disabled(isLoading)
                        }
                        .font(.subheadline)
                        .foregroundColor(brandTheme.ink)
                    } else {
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
        infoMessage = nil

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
                let status = bcString(json["status"]).lowercased()
                if status == "otp_required" {
                    let token = bcString(json["registration_token"])
                    guard !token.isEmpty else {
                        errorMessage = "Verification token missing. Please try again."
                        return
                    }
                    registrationToken = token
                    registrationOtp = ""
                    let msg = bcString(json["message"])
                    infoMessage = msg.isEmpty ? "Enter the 6-digit verification code sent to your email." : msg
                    return
                }
                guard status == "success" else {
                    let msg = bcString(json["message"])
                    errorMessage = msg.isEmpty ? "Registration failed." : msg
                    return
                }
                completeRegistration(json)

            case .failure(let error):
                errorMessage = error.localizedDescription
            }
        }
    }

    private func verifyRegistrationOtp(token: String) {
        errorMessage = nil
        infoMessage = nil
        let cleanOtp = registrationOtp.filter { $0.isNumber }
        guard cleanOtp.count == 6 else {
            errorMessage = "Enter the 6-digit verification code."
            return
        }
        isLoading = true
        AppNetworkService.shared.requestObject("register.php", params: [
            "registration_token": token,
            "otp": cleanOtp
        ]) { result in
            isLoading = false
            switch result {
            case .success(let json):
                let status = bcString(json["status"]).lowercased()
                guard status == "success" else {
                    let msg = bcString(json["message"])
                    errorMessage = msg.isEmpty ? "Verification failed." : msg
                    return
                }
                completeRegistration(json)
            case .failure(let error):
                errorMessage = error.localizedDescription
            }
        }
    }

    private func resendRegistrationOtp(token: String) {
        errorMessage = nil
        infoMessage = nil
        isLoading = true
        AppNetworkService.shared.requestObject("register-otp.php", params: [
            "registration_token": token
        ]) { result in
            isLoading = false
            switch result {
            case .success(let json):
                let status = bcString(json["status"]).lowercased()
                let msg = bcString(json["message"])
                if status == "success" {
                    infoMessage = msg.isEmpty ? "A new verification code has been sent." : msg
                } else {
                    errorMessage = msg.isEmpty ? "Could not resend the code." : msg
                }
            case .failure(let error):
                errorMessage = error.localizedDescription
            }
        }
    }

    private func completeRegistration(_ json: [String: Any]) {
        // Only a server-issued revocable app session may establish app login.
        // Without one the caller signs in via login.php (or returns to password/email-OTP flow).
        let data = bcDict(json["data"])
        let key = bcString(data["app_token"])
        if !key.isEmpty {
            AppNetworkService.shared.apiKey = key
        }
        let uname = bcString(data["username"])
        UserDefaults.standard.set(uname.isEmpty ? username.lowercased() : uname, forKey: "saved_username")
        // A brand-new account has no PIN. Setting this false is what makes the app entry
        // point show the mandatory PIN screen instead of the dashboard.
        AppNetworkService.shared.pinSet = bcBool(data["security_pin_set"])
        OnboardingState.shared.syncFromPayload(data)
        OnboardingState.shared.markBiometricPromptNeeded(true)

        if !key.isEmpty {
            dismiss()
            if !key.isEmpty { onRegistered() }
            return
        }
        autoLogin(username: uname.isEmpty ? username.lowercased() : uname)
    }

    private func autoLogin(username: String) {
        isLoading = true
        AppNetworkService.shared.requestObject("login.php", params: [
            "user": username,
            "pass": password
        ]) { result in
            isLoading = false
            switch result {
            case .success(let json):
                let status = bcString(json["status"]).lowercased()
                if status == "otp_required" {
                    let challenge = bcString(json["challenge"])
                    if !challenge.isEmpty {
                        registrationToken = nil
                        loginChallenge = challenge
                        loginOtp = ""
                        infoMessage = "Account created! Enter the 6-digit login code sent to your email."
                        return
                    }
                }
                if status == "success" {
                    finishAutoLogin(json)
                } else {
                    registrationToken = nil
                    infoMessage = "Account created! Please sign in."
                }
            case .failure:
                registrationToken = nil
                infoMessage = "Account created! Please sign in."
            }
        }
    }

    private func verifyLoginOtp(challenge: String) {
        errorMessage = nil
        let cleanOtp = loginOtp.filter { $0.isNumber }
        guard cleanOtp.count == 6 else {
            errorMessage = "Enter the 6-digit code."
            return
        }
        isLoading = true
        AppNetworkService.shared.requestObject("login-otp.php", params: [
            "challenge": challenge,
            "otp": cleanOtp
        ]) { result in
            isLoading = false
            switch result {
            case .success(let json):
                if bcString(json["status"]).lowercased() == "success" {
                    finishAutoLogin(json)
                } else {
                    let msg = bcString(json["message"])
                    errorMessage = msg.isEmpty ? "Verification failed." : msg
                }
            case .failure(let error):
                errorMessage = error.localizedDescription
            }
        }
    }

    private func finishAutoLogin(_ json: [String: Any]) {
        let data = bcDict(json["data"])
        let key = bcString(data["app_token"])
        guard !key.isEmpty else {
            infoMessage = "Account created! Please sign in."
            return
        }
        AppNetworkService.shared.apiKey = key
        AppNetworkService.shared.pinSet = bcBool(data["security_pin_set"])
        AppNetworkService.shared.pinRequired = bcBool(data["pin_required"])
        OnboardingState.shared.syncFromPayload(data)
        OnboardingState.shared.markBiometricPromptNeeded(true)
        let uname = bcString(data["username"])
        if !uname.isEmpty {
            UserDefaults.standard.set(uname, forKey: "saved_username")
        }
        dismiss()
        if !key.isEmpty { onRegistered() }
    }
}
