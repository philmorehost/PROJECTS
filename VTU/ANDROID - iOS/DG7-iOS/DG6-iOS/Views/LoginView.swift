import SwiftUI

struct LoginView: View {
    @State private var email = ""
    @State private var password = ""
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var showRegister = false

    var onLoginSuccess: () -> Void

    var body: some View {
        VStack(spacing: 20) {
            Spacer()

            Image(systemName: "bolt.circle.fill")
                .resizable()
                .scaledToFit()
                .frame(width: 80, height: 80)
                .foregroundColor(.blue)

            Text("Digital Payments")
                .font(.largeTitle)
                .fontWeight(.bold)

            if let error = errorMessage {
                Text(error)
                    .foregroundColor(.red)
                    .font(.caption)
                    .multilineTextAlignment(.center)
            }

            VStack(alignment: .leading, spacing: 12) {
                TextField("Username", text: $email)
                    .textFieldStyle(RoundedBorderTextFieldStyle())
                    .autocapitalization(.none)

                SecureField("Password", text: $password)
                    .textFieldStyle(RoundedBorderTextFieldStyle())
            }
            .padding(.horizontal)

            Button(action: login) {
                if isLoading {
                    ProgressView()
                        .progressViewStyle(CircularProgressViewStyle(tint: .white))
                } else {
                    Text("Login")
                        .fontWeight(.bold)
                        .frame(maxWidth: .infinity)
                }
            }
            .padding()
            .background(Color.blue)
            .foregroundColor(.white)
            .cornerRadius(12)
            .padding(.horizontal)
            .disabled(isLoading || email.isEmpty || password.isEmpty)

            Button("Create an account") { showRegister = true }
                .font(.subheadline)
                .padding(.top, 4)

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
                if response.status == "success", let data = response.data {
                    AppNetworkService.shared.apiKey = data.api_key
                    // Authoritative: login.php reports `security_pin_set` through bc_user_has_pin(),
                    // so an account with a legacy-only PIN is not sent to the PIN screen again.
                    AppNetworkService.shared.pinSet = data.security_pin_set ?? false
                    AppNetworkService.shared.pinRequired = data.pin_required ?? false
                    // Save username for display
                    UserDefaults.standard.set(data.username, forKey: "saved_username")
                    onLoginSuccess()
                } else {
                    errorMessage = response.message ?? "Login failed"
                }
            case .failure(let error):
                errorMessage = error.localizedDescription
            }
        }
    }
}

struct LoginResponse: Codable {
    let status: String
    let message: String?
    let data: UserData?
}

struct UserData: Codable {
    let username: String
    let api_key: String
    // Optional so an older API build that does not send these still decodes.
    let security_pin_set: Bool?
    let pin_required: Bool?
}
