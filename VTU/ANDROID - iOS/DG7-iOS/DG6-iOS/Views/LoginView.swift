import SwiftUI
import LocalAuthentication
@MainActor

struct LoginView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @State private var email = ""
    @State private var password = ""
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var showRegister = false
    @State private var showForgotPassword = false
    @State private var registrationEnabled = true
    @State private var passwordResetEnabled = true
    @State private var challenge: String?
    @State private var otp = ""
    @State private var canBiometricSignIn = false
    @State private var biometricPrompted = false

    var onLoginSuccess: () -> Void

    var body: some View {
        VStack(spacing: 20) {
            Spacer()

            Image(systemName: "bolt.circle.fill")
                .resizable()
                .scaledToFit()
                .frame(width: 80, height: 80)
                .foregroundColor(brandTheme.ink)

            Text("Digital Payments")
                .font(.largeTitle)
                .fontWeight(.bold)

            if let error = errorMessage {
                Text(error)
                    .foregroundColor(.red)
                    .font(.caption)
                    .multilineTextAlignment(.center)
            }

            if challenge != nil {
                Text("Enter the six-digit code sent to your email.")
                    .font(.subheadline)
                    .multilineTextAlignment(.center)
                TextField("Verification code", text: $otp)
                    .textFieldStyle(RoundedBorderTextFieldStyle())
                    .keyboardType(.numberPad)
                    .padding(.horizontal)
                Button(action: verifyOtp) {
                    if isLoading { ProgressView().progressViewStyle(CircularProgressViewStyle(tint: brandTheme.onPrimary)) }
                    else { Text("Verify and sign in").fontWeight(.bold).frame(maxWidth: .infinity) }
                }
                .padding().background(brandTheme.primary).foregroundColor(brandTheme.onPrimary).cornerRadius(12).padding(.horizontal)
                .disabled(isLoading || otp.count != 6 || !otp.allSatisfy({ $0.isNumber }))
                Button("Cancel and sign in again") {
                    challenge = nil
                    otp = ""
                    errorMessage = nil
                }.disabled(isLoading)
            } else {
                VStack(alignment: .leading, spacing: 12) {
                    TextField("Username", text: $email)
                        .textFieldStyle(RoundedBorderTextFieldStyle())
                        .autocapitalization(.none)
                    SecureField("Password", text: $password)
                        .textFieldStyle(RoundedBorderTextFieldStyle())
                    if passwordResetEnabled {
                        HStack {
                            Spacer()
                            Button(action: { showForgotPassword = true }) {
                                Text("Forgot Password?")
                                    .font(.caption)
                                    .fontWeight(.semibold)
                            }
                            .foregroundColor(brandTheme.ink)
                        }
                    }
                }
                .padding(.horizontal)

                Button(action: login) {
                    if isLoading { ProgressView().progressViewStyle(CircularProgressViewStyle(tint: brandTheme.onPrimary)) }
                    else { Text("Login").fontWeight(.bold).frame(maxWidth: .infinity) }
                }
                .padding().background(brandTheme.primary).foregroundColor(brandTheme.onPrimary).cornerRadius(12).padding(.horizontal)
                .disabled(isLoading || email.isEmpty || password.isEmpty)

                if canBiometricSignIn {
                    Button(action: biometricSignIn) {
                        Label("Sign in with Face ID / Touch ID", systemImage: "faceid")
                            .fontWeight(.semibold)
                            .frame(maxWidth: .infinity)
                    }
                    .padding()
                    .background(Color(.systemGray6))
                    .foregroundColor(brandTheme.ink)
                    .cornerRadius(12)
                    .padding(.horizontal)
                    .disabled(isLoading)
                }

                if registrationEnabled {
                    Button("Create an account") { showRegister = true }
                        .font(.subheadline)
                        .foregroundColor(brandTheme.ink)
                        .padding(.top, 4)
                }
            }

            Spacer()
            Spacer()
        }
        .padding()
        .onAppear {
            loadSiteFlags()
            checkBiometricAvailability()
        }
        .sheet(isPresented: $showRegister) {
            // Registering signs the user in, so hand control back to the app entry point, which
            // routes a PIN-less account to PinSetupView (the web's SecurityPIN.php step).
            RegisterView(onRegistered: { onLoginSuccess() })
        }
        .sheet(isPresented: $showForgotPassword) {
            ForgotPasswordSheetView(initialUser: email)
        }
    }

    func loadSiteFlags() {
        AppNetworkService.shared.requestObject("site-info.php", params: [:]) { result in
            if case .success(let body) = result,
               let data = body["data"] as? [String: Any],
               let services = data["services"] as? [String: Any] {
                if let regVal = services["registration"] {
                    registrationEnabled = parseServiceFlag(regVal)
                }
                if let resetVal = services["password_reset"] {
                    passwordResetEnabled = parseServiceFlag(resetVal)
                }
            }
        }
    }

    private func parseServiceFlag(_ value: Any) -> Bool {
        if let b = value as? Bool { return b }
        if let n = value as? NSNumber { return n.intValue != 0 }
        if let s = value as? String { return s != "0" && s.lowercased() != "false" }
        return true
    }

    func login() {
        isLoading = true
        errorMessage = nil

        let params: [String: Any] = [
            "user": email,
            "pass": password
        ]

        AppNetworkService.shared.request("login.php", params: params) { (result: Result<LoginResponse, Error>) in
            isLoading = false
            switch result {
            case .success(let response):
                if response.status == "otp_required", let token = response.challenge, !token.isEmpty {
                    challenge = token
                    otp = ""
                    password = ""
                } else {
                    finishLogin(response)
                }
            case .failure(let error):
                errorMessage = error.localizedDescription
            }
        }
    }

    func verifyOtp() {
        guard let challenge = challenge else { return }
        isLoading = true
        errorMessage = nil
        AppNetworkService.shared.request("login-otp.php", params: ["challenge": challenge, "otp": otp]) { (result: Result<LoginResponse, Error>) in
            isLoading = false
            switch result {
            case .success(let response): finishLogin(response)
            case .failure(let error): errorMessage = error.localizedDescription
            }
        }
    }

    func finishLogin(_ response: LoginResponse) {
        if response.status == "success", let data = response.data {
            guard let session = data.app_token, !session.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
                errorMessage = "App session was not issued. Please sign in again or update the server."
                return
            }
            AppNetworkService.shared.apiKey = session
            AppNetworkService.shared.pinSet = data.security_pin_set ?? false
            AppNetworkService.shared.pinRequired = data.pin_required ?? false
            UserDefaults.standard.set(data.username, forKey: "saved_username")
            var payload: [String: Any] = [
                "security_pin_set": data.security_pin_set ?? false,
                "pin_required": data.pin_required ?? false
            ]
            if let v = data.kyc_status { payload["kyc_status"] = v }
            if let v = data.kyc_enforced { payload["kyc_enforced"] = v }
            if let v = data.kyc_gate_locked { payload["kyc_gate_locked"] = v }
            if let v = data.kyc_required_on_register { payload["kyc_required_on_register"] = v }
            if let v = data.security_quest_required { payload["security_quest_required"] = v }
            if let v = data.security_quest_set { payload["security_quest_set"] = v }
            if let v = data.totp_required { payload["totp_required"] = v }
            if let v = data.totp_set { payload["totp_set"] = v }
            OnboardingState.shared.syncFromPayload(payload)
            if AppNetworkService.shared.biometricEnabled {
                AppNetworkService.shared.biometricSessionToken = session
                AppNetworkService.shared.biometricUsername = data.username
            }
            challenge = nil
            otp = ""
            onLoginSuccess()
        } else {
            errorMessage = response.message ?? "Login failed"
        }
    }

    private func checkBiometricAvailability() {
        guard AppNetworkService.shared.biometricEnabled,
              let token = AppNetworkService.shared.biometricSessionToken, !token.isEmpty else {
            canBiometricSignIn = false
            return
        }
        let ctx = LAContext()
        var err: NSError?
        canBiometricSignIn = ctx.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &err)
        if canBiometricSignIn && !biometricPrompted {
            biometricPrompted = true
            biometricSignIn()
        }
    }

    private func biometricSignIn() {
        guard let savedToken = AppNetworkService.shared.biometricSessionToken, !savedToken.isEmpty else {
            canBiometricSignIn = false
            return
        }
        let ctx = LAContext()
        ctx.evaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, localizedReason: "Sign in to your account") { success, _ in
            DispatchQueue.main.async {
                guard success else { return }
                isLoading = true
                errorMessage = nil
                AppNetworkService.shared.apiKey = savedToken
                AppNetworkService.shared.requestObject("profile.php", params: [:]) { result in
                    isLoading = false
                    switch result {
                    case .success(let json):
                        if bcString(json["status"]) == "success", let data = json["data"] as? [String: Any] {
                            OnboardingState.shared.syncFromPayload(data)
                            if let uname = data["username"] as? String, !uname.isEmpty {
                                UserDefaults.standard.set(uname, forKey: "saved_username")
                                AppNetworkService.shared.biometricUsername = uname
                            }
                            onLoginSuccess()
                        } else {
                            AppNetworkService.shared.apiKey = nil
                            AppNetworkService.shared.biometricSessionToken = nil
                            canBiometricSignIn = false
                            errorMessage = "Session expired. Please sign in with your password."
                        }
                    case .failure(let err):
                        let nsErr = err as NSError
                        if nsErr.domain == "RequiredSecuritySetup" {
                            // RequiredSetupView will handle it, or clear token if needed
                            return
                        }
                        AppNetworkService.shared.apiKey = nil
                        errorMessage = "Could not verify biometric session. Please sign in with your password."
                    }
                }
            }
        }
    }
}

@MainActor
struct ForgotPasswordSheetView: View {
    @Environment(\.presentationMode) var presentationMode
    @ObservedObject private var brandTheme = BrandTheme.shared
    @State private var userInput: String
    @State private var otp = ""
    @State private var newPassword = ""
    @State private var awaitingOtp = false
    @State private var isLoading = false
    @State private var statusMessage: String?
    @State private var isError = false

    init(initialUser: String) {
        _userInput = State(initialValue: initialUser)
    }

    var body: some View {
        NavigationView {
            VStack(spacing: 16) {
                Text(awaitingOtp
                     ? "Enter the 6-digit code sent to your email and your new password."
                     : "Enter your username or email to receive a 6-digit reset code.")
                    .font(.subheadline)
                    .foregroundColor(.secondary)
                    .multilineTextAlignment(.center)
                    .padding(.top, 8)

                if let msg = statusMessage {
                    Text(msg)
                        .font(.caption)
                        .foregroundColor(isError ? .red : .green)
                        .multilineTextAlignment(.center)
                }

                TextField("Username or email", text: $userInput)
                    .textFieldStyle(RoundedBorderTextFieldStyle())
                    .autocapitalization(.none)
                    .disabled(awaitingOtp || isLoading)

                if awaitingOtp {
                    TextField("6-digit verification code", text: $otp)
                        .textFieldStyle(RoundedBorderTextFieldStyle())
                        .keyboardType(.numberPad)
                    SecureField("New password (min 8 chars)", text: $newPassword)
                        .textFieldStyle(RoundedBorderTextFieldStyle())
                }

                Button(action: awaitingOtp ? confirmReset : requestCode) {
                    if isLoading {
                        ProgressView().progressViewStyle(CircularProgressViewStyle(tint: brandTheme.onPrimary))
                    } else {
                        Text(awaitingOtp ? "Reset Password" : "Send Reset Code")
                            .fontWeight(.bold)
                            .frame(maxWidth: .infinity)
                    }
                }
                .padding()
                .background(brandTheme.primary)
                .foregroundColor(brandTheme.onPrimary)
                .cornerRadius(12)
                .disabled(isLoading || userInput.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)

                Spacer()
            }
            .padding()
            .navigationTitle("Reset Password")
            .navigationBarItems(trailing: Button("Close") { presentationMode.wrappedValue.dismiss() })
        }
    }

    private func requestCode() {
        isLoading = true
        statusMessage = nil
        isError = false
        let trimmed = userInput.trimmingCharacters(in: .whitespacesAndNewlines)
        AppNetworkService.shared.requestObject("forgot-password.php", params: ["user": trimmed]) { result in
            isLoading = false
            switch result {
            case .success(let body):
                let status = body["status"] as? String ?? ""
                let msg = body["message"] as? String ?? "Verification code sent."
                statusMessage = msg
                if status == "otp_required" || status == "success" {
                    awaitingOtp = true
                    isError = false
                } else {
                    isError = true
                }
            case .failure(let error):
                isError = true
                statusMessage = error.localizedDescription
            }
        }
    }

    private func confirmReset() {
        let code = otp.trimmingCharacters(in: .whitespacesAndNewlines)
        guard code.count == 6, newPassword.count >= 8 else {
            isError = true
            statusMessage = "Enter a 6-digit code and a password of at least 8 characters."
            return
        }
        isLoading = true
        statusMessage = nil
        isError = false
        let trimmed = userInput.trimmingCharacters(in: .whitespacesAndNewlines)
        AppNetworkService.shared.requestObject("forgot-password.php", params: [
            "user": trimmed,
            "otp": code,
            "new_password": newPassword
        ]) { result in
            isLoading = false
            switch result {
            case .success(let body):
                let status = body["status"] as? String ?? ""
                let msg = body["message"] as? String ?? "Done"
                statusMessage = msg
                isError = status != "success"
            case .failure(let error):
                isError = true
                statusMessage = error.localizedDescription
            }
        }
    }
}

struct LoginResponse: Codable {
    let status: String
    let message: String?
    let challenge: String?
    let data: UserData?
}

struct UserData: Codable {
    let username: String
    let app_token: String?
    // Optional so an older API build that does not send these still decodes.
    let security_pin_set: Bool?
    let pin_required: Bool?
    let kyc_status: Int?
    let kyc_enforced: Bool?
    let kyc_gate_locked: Bool?
    let kyc_required_on_register: Bool?
    let security_quest_required: Bool?
    let security_quest_set: Bool?
    let totp_required: Bool?
    let totp_set: Bool?
}
