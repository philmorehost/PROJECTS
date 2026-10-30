import SwiftUI
import Foundation

// ─── Brand Colors ─────────────────────────────────────────────────────────────
extension Color {
    static let appDark      = Color(hex: "#0F172A")
    static let appCard      = Color(hex: "#1E293B")
    static let appBorder    = Color(hex: "#334155")
    static let appBlue      = Color(hex: "#2563EB")
    static let appIndigo    = Color(hex: "#4F46E5")
    static let appGreen     = Color(hex: "#10B981")
    static let appAmber     = Color(hex: "#F59E0B")
    static let appRed       = Color(hex: "#EF4444")
    static let textPrimary  = Color(hex: "#F1F5F9")
    static let textSecondary = Color(hex: "#94A3B8")

    init(hex: String) {
        let hex    = hex.trimmingCharacters(in: CharacterSet.alphanumerics.inverted)
        var int    = UInt64()
        Scanner(string: hex).scanHexInt64(&int)
        let r, g, b: UInt64
        (r, g, b) = ((int >> 16) & 0xFF, (int >> 8) & 0xFF, int & 0xFF)
        self.init(.sRGB, red: Double(r)/255, green: Double(g)/255, blue: Double(b)/255)
    }
}

// ─── Models ───────────────────────────────────────────────────────────────────
struct AuthState {
    var token: String
    var role: String
    var userName: String
    var schoolUrl: String
}

// ─── Root Navigation ──────────────────────────────────────────────────────────
struct ContentView: View {
    @State private var auth: AuthState? = nil

    var body: some View {
        if let auth = auth {
            MainTabView(auth: auth, onLogout: { self.auth = nil })
        } else {
            LoginView(onLogin: { self.auth = $0 })
        }
    }
}

// ─── Login View ───────────────────────────────────────────────────────────────
struct LoginView: View {
    var onLogin: (AuthState) -> Void

    @State private var schoolUrl = "https://schoolapp.ng"
    @State private var email     = ""
    @State private var password  = ""
    @State private var role      = "student"
    @State private var error: String? = nil
    @State private var isLoading = false

    let roles = ["student", "teacher", "parent"]

    var body: some View {
        ZStack {
            LinearGradient(
                colors: [Color(hex: "#0B0F19"), Color.appCard, Color(hex: "#0B0F19")],
                startPoint: .top, endPoint: .bottom
            ).ignoresSafeArea()

            ScrollView {
                VStack(spacing: 22) {
                    Spacer(minLength: 50)

                    // Logo
                    ZStack {
                        LinearGradient(colors: [Color.appBlue, Color.appIndigo], startPoint: .topLeading, endPoint: .bottomTrailing)
                            .frame(width: 80, height: 80)
                            .cornerRadius(22)
                        Image(systemName: "graduationcap.fill")
                            .font(.system(size: 38))
                            .foregroundColor(.white)
                    }

                    VStack(spacing: 4) {
                        Text("School Portal")
                            .font(.system(size: 28, weight: .heavy))
                            .foregroundColor(.textPrimary)
                        Text("Multi-tenant School Management")
                            .font(.system(size: 13))
                            .foregroundColor(.textSecondary)
                    }

                    // Error banner
                    if let err = error {
                        HStack {
                            Image(systemName: "exclamationmark.triangle.fill").foregroundColor(.appRed).font(.system(size: 14))
                            Text(err).font(.system(size: 13)).foregroundColor(.appRed)
                        }
                        .padding(12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Color.appRed.opacity(0.12))
                        .cornerRadius(12)
                    }

                    // Form Card
                    VStack(spacing: 14) {
                        // School URL
                        AppTextField(label: "School Portal URL", text: $schoolUrl, icon: "globe", keyboardType: .URL)

                        // Email
                        AppTextField(label: "Email / Username", text: $email, icon: "person", keyboardType: .emailAddress)

                        // Password
                        AppSecureField(label: "Password", text: $password)

                        // Role selector
                        VStack(alignment: .leading, spacing: 6) {
                            Text("Sign in as").font(.system(size: 12)).foregroundColor(.textSecondary)
                            HStack(spacing: 8) {
                                ForEach(roles, id: \.self) { r in
                                    let selected = role == r
                                    Button(action: { role = r }) {
                                        Text(r.capitalized)
                                            .font(.system(size: 12, weight: selected ? .bold : .regular))
                                            .foregroundColor(selected ? .appBlue : .textSecondary)
                                            .frame(maxWidth: .infinity)
                                            .padding(.vertical, 10)
                                            .background(selected ? Color.appBlue.opacity(0.15) : Color.clear)
                                            .cornerRadius(10)
                                            .overlay(
                                                RoundedRectangle(cornerRadius: 10)
                                                    .stroke(selected ? Color.appBlue : Color.appBorder, lineWidth: 1)
                                            )
                                    }
                                }
                            }
                        }

                        // Login button
                        if isLoading {
                            ProgressView().tint(Color.appBlue)
                        } else {
                            Button(action: performLogin) {
                                HStack {
                                    Image(systemName: "arrow.right.circle.fill")
                                    Text("Sign In").fontWeight(.bold)
                                }
                                .foregroundColor(.white)
                                .frame(maxWidth: .infinity)
                                .padding(.vertical, 14)
                                .background(LinearGradient(colors: [Color.appBlue, Color.appIndigo], startPoint: .leading, endPoint: .trailing))
                                .cornerRadius(14)
                            }
                        }
                    }
                    .padding(20)
                    .background(Color.appCard)
                    .cornerRadius(20)
                    .overlay(RoundedRectangle(cornerRadius: 20).stroke(Color.appBorder, lineWidth: 1))

                    Spacer(minLength: 30)
                }
                .padding(.horizontal, 22)
            }
        }
    }

    func performLogin() {
        guard !email.isEmpty, !password.isEmpty else {
            error = "Please fill in all fields."; return
        }
        isLoading = true; error = nil
        guard let url = URL(string: "\(schoolUrl)/api/v1/auth.php") else {
            error = "Invalid portal URL."; isLoading = false; return
        }
        var req = URLRequest(url: url)
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.httpBody = try? JSONSerialization.data(withJSONObject: ["email": email, "password": password, "role": role])

        URLSession.shared.dataTask(with: req) { data, resp, err in
            DispatchQueue.main.async {
                isLoading = false
                if let err = err { error = err.localizedDescription; return }
                guard let data = data, (resp as? HTTPURLResponse)?.statusCode == 200 else {
                    error = "Authentication failed. Check your credentials."; return
                }
                if let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                   let token = json["token"] as? String {
                    onLogin(AuthState(
                        token: token,
                        role: role,
                        userName: json["name"] as? String ?? email,
                        schoolUrl: schoolUrl
                    ))
                } else {
                    error = "Failed to parse server response."
                }
            }
        }.resume()
    }
}

// ─── Reusable Field Components ────────────────────────────────────────────────
struct AppTextField: View {
    var label: String
    @Binding var text: String
    var icon: String
    var keyboardType: UIKeyboardType = .default

    var body: some View {
        VStack(alignment: .leading, spacing: 5) {
            Text(label).font(.system(size: 12)).foregroundColor(.textSecondary)
            HStack {
                Image(systemName: icon).font(.system(size: 14)).foregroundColor(.textSecondary)
                TextField("", text: $text)
                    .foregroundColor(.textPrimary)
                    .keyboardType(keyboardType)
                    .autocapitalization(.none)
                    .disableAutocorrection(true)
            }
            .padding(.horizontal, 14).padding(.vertical, 12)
            .background(Color.white.opacity(0.05))
            .cornerRadius(12)
            .overlay(RoundedRectangle(cornerRadius: 12).stroke(Color.appBorder, lineWidth: 1))
        }
    }
}

struct AppSecureField: View {
    var label: String
    @Binding var text: String

    var body: some View {
        VStack(alignment: .leading, spacing: 5) {
            Text(label).font(.system(size: 12)).foregroundColor(.textSecondary)
            HStack {
                Image(systemName: "lock").font(.system(size: 14)).foregroundColor(.textSecondary)
                SecureField("", text: $text).foregroundColor(.textPrimary)
            }
            .padding(.horizontal, 14).padding(.vertical, 12)
            .background(Color.white.opacity(0.05))
            .cornerRadius(12)
            .overlay(RoundedRectangle(cornerRadius: 12).stroke(Color.appBorder, lineWidth: 1))
        }
    }
}

// ─── Main Tab View ─────────────────────────────────────────────────────────────
struct MainTabView: View {
    var auth: AuthState
    var onLogout: () -> Void

    var body: some View {
        TabView {
            HomeTabView(auth: auth, onLogout: onLogout)
                .tabItem { Label("Home", systemImage: "house.fill") }

            ResultsTabView(auth: auth)
                .tabItem { Label("Results", systemImage: "chart.bar.doc.horizontal.fill") }

            FeesTabView()
                .tabItem { Label("Fees", systemImage: "creditcard.fill") }

            NoticesTabView()
                .tabItem { Label("Notices", systemImage: "bell.fill") }
        }
        .accentColor(Color.appBlue)
        .onAppear {
            let appearance = UITabBarAppearance()
            appearance.configureWithOpaqueBackground()
            appearance.backgroundColor = UIColor(Color.appCard)
            UITabBar.appearance().standardAppearance = appearance
            UITabBar.appearance().scrollEdgeAppearance = appearance
        }
    }
}

// ─── Home Tab ─────────────────────────────────────────────────────────────────
struct HomeTabView: View {
    var auth: AuthState
    var onLogout: () -> Void

    @State private var studentName = ""
    @State private var className   = "Loading..."
    @State private var admNo       = "..."

    var body: some View {
        ZStack {
            Color.appDark.ignoresSafeArea()
            ScrollView {
                VStack(spacing: 18) {
                    // Welcome Banner
                    ZStack(alignment: .topLeading) {
                        LinearGradient(colors: [Color.appBlue, Color.appIndigo], startPoint: .topLeading, endPoint: .bottomTrailing)
                            .cornerRadius(22)
                        VStack(alignment: .leading, spacing: 14) {
                            HStack {
                                ZStack {
                                    Circle().fill(Color.white.opacity(0.2)).frame(width: 52, height: 52)
                                    Text(String(auth.userName.prefix(1)).uppercased())
                                        .font(.system(size: 22, weight: .black))
                                        .foregroundColor(.white)
                                }
                                VStack(alignment: .leading) {
                                    Text("Welcome back,").font(.system(size: 12)).foregroundColor(.white.opacity(0.8))
                                    Text(studentName.isEmpty ? auth.userName : studentName)
                                        .font(.system(size: 18, weight: .bold)).foregroundColor(.white)
                                }
                                Spacer()
                            }
                            HStack(spacing: 20) {
                                InfoPill(label: "Class", value: className)
                                InfoPill(label: "Adm No", value: admNo)
                                InfoPill(label: "Role", value: auth.role.capitalized)
                            }
                        }
                        .padding(20)
                    }
                    .frame(height: 155)

                    // Quick Actions Grid
                    Text("Quick Actions").font(.system(size: 13, weight: .semibold)).foregroundColor(.textSecondary).frame(maxWidth: .infinity, alignment: .leading)
                    LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
                        QuickActionTile(emoji: "📊", label: "Results", color: Color.appBlue)
                        QuickActionTile(emoji: "💳", label: "Pay Fees", color: Color.appGreen)
                        QuickActionTile(emoji: "📣", label: "Notices", color: Color.appAmber)
                        QuickActionTile(emoji: "📖", label: "Timetable", color: Color.appIndigo)
                        QuickActionTile(emoji: "📄", label: "Report Card", color: Color(hex: "#8B5CF6"))
                        QuickActionTile(emoji: "🎓", label: "AI Tutor", color: Color(hex: "#06B6D4"))
                    }

                    // Sign Out
                    Button(action: onLogout) {
                        HStack {
                            Image(systemName: "rectangle.portrait.and.arrow.right").font(.system(size: 15))
                            Text("Sign Out").fontWeight(.semibold)
                        }
                        .foregroundColor(Color.appRed)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                        .background(Color.appRed.opacity(0.1))
                        .cornerRadius(14)
                        .overlay(RoundedRectangle(cornerRadius: 14).stroke(Color.appRed.opacity(0.4), lineWidth: 1))
                    }
                }
                .padding(20)
            }
        }
        .navigationTitle("Dashboard")
        .onAppear { fetchProfile() }
    }

    func fetchProfile() {
        guard let url = URL(string: "\(auth.schoolUrl)/api/v1/student.php") else { return }
        var req = URLRequest(url: url)
        req.setValue("Bearer \(auth.token)", forHTTPHeaderField: "Authorization")
        URLSession.shared.dataTask(with: req) { data, _, _ in
            guard let data = data,
                  let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let d = json["data"] as? [String: Any] else { return }
            let profile  = d["profile"]  as? [String: Any]
            let academic = d["academic"] as? [String: Any]
            DispatchQueue.main.async {
                let first = profile?["firstname"] as? String ?? ""
                let last  = profile?["lastname"]  as? String ?? ""
                studentName = "\(first) \(last)".trimmingCharacters(in: .whitespaces)
                className   = academic?["class_name"] as? String ?? "N/A"
                admNo       = profile?["admission_number"] as? String ?? "N/A"
            }
        }.resume()
    }
}

struct InfoPill: View {
    var label: String
    var value: String
    var body: some View {
        VStack(spacing: 2) {
            Text(label).font(.system(size: 9)).foregroundColor(.white.opacity(0.7))
            Text(value).font(.system(size: 11, weight: .bold)).foregroundColor(.white)
        }
    }
}

struct QuickActionTile: View {
    var emoji: String
    var label: String
    var color: Color
    var body: some View {
        VStack(spacing: 8) {
            Text(emoji).font(.system(size: 26))
            Text(label).font(.system(size: 11, weight: .semibold)).foregroundColor(color).multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 16)
        .background(color.opacity(0.1))
        .cornerRadius(16)
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(color.opacity(0.3), lineWidth: 1))
    }
}

// ─── Results Tab ──────────────────────────────────────────────────────────────
struct ResultsTabView: View {
    var auth: AuthState
    var body: some View {
        ZStack {
            Color.appDark.ignoresSafeArea()
            VStack(spacing: 18) {
                VStack(alignment: .leading, spacing: 4) {
                    Text("My Results").font(.system(size: 26, weight: .heavy)).foregroundColor(.textPrimary)
                    Text("View your academic performance").font(.system(size: 13)).foregroundColor(.textSecondary)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 20).padding(.top, 20)

                CardView {
                    VStack(spacing: 12) {
                        Text("📄").font(.system(size: 40))
                        Text("Result Portal").font(.system(size: 16, weight: .bold)).foregroundColor(.textPrimary)
                        Text("Your report cards and academic results are\navailable in the full portal.")
                            .font(.system(size: 13)).foregroundColor(.textSecondary).multilineTextAlignment(.center)
                    }
                    .frame(maxWidth: .infinity).padding(20)
                }
                Spacer()
            }
        }
    }
}

// ─── Fees Tab ─────────────────────────────────────────────────────────────────
struct FeesTabView: View {
    var body: some View {
        ZStack {
            Color.appDark.ignoresSafeArea()
            VStack(spacing: 18) {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Fees & Payments").font(.system(size: 26, weight: .heavy)).foregroundColor(.textPrimary)
                    Text("Manage school fee payments").font(.system(size: 13)).foregroundColor(.textSecondary)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 20).padding(.top, 20)

                CardView {
                    VStack(spacing: 12) {
                        Text("💳").font(.system(size: 40))
                        Text("Fee Portal").font(.system(size: 16, weight: .bold)).foregroundColor(.textPrimary)
                        Text("View outstanding bills and payment history\nin the full web portal.")
                            .font(.system(size: 13)).foregroundColor(.textSecondary).multilineTextAlignment(.center)
                    }
                    .frame(maxWidth: .infinity).padding(20)
                }
                Spacer()
            }
        }
    }
}

// ─── Notices Tab ──────────────────────────────────────────────────────────────
struct NoticesTabView: View {
    var body: some View {
        ZStack {
            Color.appDark.ignoresSafeArea()
            VStack(spacing: 18) {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Notice Board").font(.system(size: 26, weight: .heavy)).foregroundColor(.textPrimary)
                    Text("School announcements").font(.system(size: 13)).foregroundColor(.textSecondary)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 20).padding(.top, 20)

                CardView {
                    VStack(spacing: 12) {
                        Text("🔔").font(.system(size: 40))
                        Text("No Announcements").font(.system(size: 16, weight: .bold)).foregroundColor(.textPrimary)
                        Text("No announcements at this time. Check back later.")
                            .font(.system(size: 13)).foregroundColor(.textSecondary).multilineTextAlignment(.center)
                    }
                    .frame(maxWidth: .infinity).padding(20)
                }
                Spacer()
            }
        }
    }
}

// ─── Reusable Card ────────────────────────────────────────────────────────────
struct CardView<Content: View>: View {
    @ViewBuilder let content: Content
    var body: some View {
        content
            .background(Color.appCard)
            .cornerRadius(20)
            .overlay(RoundedRectangle(cornerRadius: 20).stroke(Color.appBorder, lineWidth: 1))
            .padding(.horizontal, 20)
    }
}
