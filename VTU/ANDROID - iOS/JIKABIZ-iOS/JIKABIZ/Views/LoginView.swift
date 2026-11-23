import SwiftUI
@MainActor

struct LoginView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @State private var email = ""
    @State private var password = ""
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var showRegister = false
    @State private var challenge: String?
    @State private var otp = ""

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
                }
                .padding(.horizontal)

                Button(action: login) {
                    if isLoading { ProgressView().progressViewStyle(CircularProgressViewStyle(tint: brandTheme.onPrimary)) }
                    else { Text("Login").fontWeight(.bold).frame(maxWidth: .infinity) }
                }
                .padding().background(brandTheme.primary).foregroundColor(brandTheme.onPrimary).cornerRadius(12).padding(.horizontal)
                .disabled(isLoading || email.isEmpty || password.isEmpty)

                Button("Create an account") { showRegister = true }
                    .font(.subheadline)
                    .padding(.top, 4)
            }

            Spacer()
            Spacer()
        }
        .padding()
        .sheet(isPresented: $showRegister) {
            // Registering signs the user in, so hand control back to the app entry point, which
            // routes a PIN-less account to PinSetupView (the web's SecurityPIN.php step).
            RegisterView(onRegistered: { onLoginSuccess() })
        }
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
            challenge = nil
            otp = ""
            onLoginSuccess()
        } else {
            errorMessage = response.message ?? "Login failed"
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
}
