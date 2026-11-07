import SwiftUI
import Foundation
import WebKit

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
                                    Button(action: { role = r }) {
                                        Text(r.capitalized)
                                            .font(.system(size: 12, weight: role == r ? .bold : .regular))
                                            .foregroundColor(role == r ? .appBlue : .textSecondary)
                                            .frame(maxWidth: .infinity)
                                            .padding(.vertical, 10)
                                            .background(role == r ? Color.appBlue.opacity(0.15) : Color.clear)
                                            .cornerRadius(10)
                                            .overlay(
                                                RoundedRectangle(cornerRadius: 10)
                                                    .stroke(role == r ? Color.appBlue : Color.appBorder, lineWidth: 1)
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

            FeesTabView(auth: auth)
                .tabItem { Label("Fees", systemImage: "creditcard.fill") }

            NoticesTabView(auth: auth)
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
    @State private var showAiTutor = false
    @State private var showTimetable = false
    @State private var showReportCard = false

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
                        QuickActionTile(emoji: "📊", label: "Results", color: Color.appBlue) {
                            // Switches tab naturally via user, or we can just leave it as is if it doesn't navigate.
                        }
                        QuickActionTile(emoji: "💳", label: "Pay Fees", color: Color.appGreen)
                        QuickActionTile(emoji: "📣", label: "Notices", color: Color.appAmber)
                        QuickActionTile(emoji: "📖", label: "Timetable", color: Color.appIndigo) {
                            showTimetable = true
                        }
                        QuickActionTile(emoji: "📄", label: "Report Card", color: Color(hex: "#8B5CF6")) {
                            showReportCard = true
                        }
                        QuickActionTile(emoji: "🎓", label: "AI Tutor", color: Color(hex: "#06B6D4")) {
                            showAiTutor = true
                        }
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
        .sheet(isPresented: $showAiTutor) {
            AiTutorView(auth: auth)
        }
        .sheet(isPresented: $showTimetable) {
            PortalWebScreen(auth: auth, page: "academics", title: "Timetable")
        }
        .sheet(isPresented: $showReportCard) {
            PortalWebScreen(auth: auth, page: "evaluation", title: "Report Card")
        }
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
    var action: () -> Void = {}
    var body: some View {
        Button(action: action) {
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
}

struct AiTutorView: View {
    var auth: AuthState
    @Environment(\.presentationMode) var presentationMode

    @State private var queryText = ""
    @State private var messages: [(text: String, isUser: Bool)] = [
        ("Hello! I am your AI Study Buddy. How can I help you with your classes today?", false)
    ]
    @State private var isSending = false

    var body: some View {
        NavigationView {
            ZStack {
                Color.appDark.ignoresSafeArea()
                VStack(spacing: 0) {
                    ScrollViewReader { proxy in
                        ScrollView {
                            LazyVStack(spacing: 12) {
                                ForEach(0..<messages.count, id: \.self) { idx in
                                    HStack {
                                        if messages[idx].isUser {
                                            Spacer()
                                            Text(messages[idx].text)
                                                .font(.system(size: 13))
                                                .foregroundColor(.white)
                                                .padding(12)
                                                .background(Color.appBlue)
                                                .cornerRadius(12)
                                                .padding(.leading, 50)
                                        } else {
                                            Text(messages[idx].text)
                                                .font(.system(size: 13))
                                                .foregroundColor(.white)
                                                .padding(12)
                                                .background(Color.appCard)
                                                .cornerRadius(12)
                                                .overlay(
                                                    RoundedRectangle(cornerRadius: 12)
                                                        .stroke(Color.appBorder, lineWidth: 1)
                                                )
                                                .padding(.trailing, 50)
                                            Spacer()
                                        }
                                    }
                                    .id(idx)
                                }
                            }
                            .padding(16)
                        }
                        .onChange(of: messages.count) { _ in
                            if let lastIdx = messages.indices.last {
                                withAnimation {
                                    proxy.scrollTo(lastIdx, anchor: .bottom)
                                }
                            }
                        }
                    }

                    if isSending {
                        ProgressView()
                            .progressViewStyle(CircularProgressViewStyle(tint: Color.appBlue))
                            .padding(.vertical, 8)
                    }

                    Divider().background(Color.appBorder)

                    HStack(spacing: 10) {
                        TextField("Ask a question...", text: $queryText)
                            .padding(12)
                            .background(Color.appCard)
                            .cornerRadius(10)
                            .foregroundColor(.white)
                            .font(.system(size: 13))

                        Button(action: sendMessage) {
                            Text("Send")
                                .font(.system(size: 12, weight: .bold))
                                .foregroundColor(.white)
                                .padding(.horizontal, 16)
                                .padding(.vertical, 10)
                                .background(queryText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || isSending ? Color.appBlue.opacity(0.5) : Color.appBlue)
                                .cornerRadius(10)
                        }
                        .disabled(queryText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || isSending)
                    }
                    .padding(12)
                    .background(Color.appDark)
                }
            }
            .navigationBarTitleDisplayMode(.inline)
            .navigationTitle("🎓 AI Study Buddy")
            .navigationBarItems(trailing: Button("Close") {
                presentationMode.wrappedValue.dismiss()
            }.foregroundColor(.textSecondary))
        }
        .preferredColorScheme(.dark)
    }

    func sendMessage() {
        let trimmed = queryText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }

        messages.append((text: trimmed, isUser: true))
        queryText = ""
        isSending = true

        guard let url = URL(string: "\(auth.schoolUrl)/api/v1/ai.php") else {
            messages.append((text: "Invalid school URL configuration.", isUser: false))
            isSending = false
            return
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(auth.token)", forHTTPHeaderField: "Authorization")

        let payload: [String: Any] = [
            "action": "chat_study_buddy",
            "query": trimmed,
            "context_material": "Student: \(auth.userName)"
        ]

        request.httpBody = try? JSONSerialization.data(withJSONObject: payload)

        URLSession.shared.dataTask(with: request) { data, response, error in
            DispatchQueue.main.async {
                isSending = false
                if let error = error {
                    messages.append((text: "Error: \(error.localizedDescription)", isUser: false))
                    return
                }
                guard let data = data else {
                    messages.append((text: "No response received.", isUser: false))
                    return
                }
                do {
                    if let json = try JSONSerialization.jsonObject(with: data) as? [String: Any],
                       let answer = json["answer"] as? String {
                        messages.append((text: answer, isUser: false))
                    } else {
                        messages.append((text: "Invalid response format.", isUser: false))
                    }
                } catch {
                    messages.append((text: "Error parsing server response.", isUser: false))
                }
            }
        }.resume()
    }
}

// ─── Results Tab ──────────────────────────────────────────────────────────────
struct ResultsTabView: View {
    var auth: AuthState
    var body: some View {
        PortalWebView(url: URL(string: "\(auth.schoolUrl)/dashboard.php?page=evaluation&jwt_login=\(auth.token)")!)
            .ignoresSafeArea(edges: .top)
    }
}

// ─── Fees Tab ─────────────────────────────────────────────────────────────────
struct FeesTabView: View {
    var auth: AuthState
    var body: some View {
        PortalWebView(url: URL(string: "\(auth.schoolUrl)/dashboard.php?page=billing&jwt_login=\(auth.token)")!)
            .ignoresSafeArea(edges: .top)
    }
}

// ─── Notices Tab ──────────────────────────────────────────────────────────────
struct NoticesTabView: View {
    var auth: AuthState
    var body: some View {
        PortalWebView(url: URL(string: "\(auth.schoolUrl)/dashboard.php?page=communications&jwt_login=\(auth.token)")!)
            .ignoresSafeArea(edges: .top)
    }
}

// ─── Portal Web View Integration ──────────────────────────────────────────────
struct PortalWebView: UIViewRepresentable {
    let url: URL

    func makeUIView(context: Context) -> WKWebView {
        return WKWebView()
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {
        let request = URLRequest(url: url)
        uiView.load(request)
    }
}

struct PortalWebScreen: View {
    var auth: AuthState
    var page: String
    var title: String
    
    @Environment(\.presentationMode) var presentationMode

    var body: some View {
        NavigationView {
            PortalWebView(url: URL(string: "\(auth.schoolUrl)/dashboard.php?page=\(page)&jwt_login=\(auth.token)")!)
                .ignoresSafeArea(edges: .bottom)
                .navigationBarTitleDisplayMode(.inline)
                .navigationTitle(title)
                .navigationBarItems(trailing: Button("Close") {
                    presentationMode.wrappedValue.dismiss()
                }.foregroundColor(.appBlue))
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
