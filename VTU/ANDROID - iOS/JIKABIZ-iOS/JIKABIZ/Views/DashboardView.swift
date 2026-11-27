import SwiftUI
import WebKit
import LocalAuthentication

struct MobileService: Identifiable {
    let id: String
    let title: String
    let group: String
}
struct MobileWallet: Identifiable {
    var id: String { code }
    let code: String
    let balance: String
}
@MainActor
struct DashboardView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @ObservedObject private var complianceGate = ComplianceGateState.shared
    @EnvironmentObject var session: SessionManager
    @Environment(\.scenePhase) private var scenePhase
    @State private var name = ""
    @State private var wallets: [MobileWallet] = []
    @State private var services: [MobileService] = []
    @State private var selectedCurrency = "NGN"
    @State private var balanceVisible = true
    @State private var recent: [[String: Any]] = []
    @State private var error = ""
    @State private var portal: MobileService?
    @State private var receipt: MobileReceipt?
    @State private var showNotifications = false
    @State private var locked = UserDefaults.standard.bool(forKey: "mobile_biometric_lock")
    private var orange: Color { brandTheme.ink }

    var body: some View {
        ZStack {
            TabView {
                NavigationView {
                    ScrollView {
                        VStack(spacing: 18) {
                            hero
                            HStack(spacing: 8) {
                                shortcut("Crypto", "bitcoinsign.circle", "crypto")
                                shortcut("Virtual card", "creditcard", "cards")
                                shortcut("Gift card", "gift", "giftcards")
                                NavigationLink(destination: serviceList(group: "VTU")) { tile("VTU", "square.grid.2x2") }
                            }
                            HStack(spacing: 8) {
                                NavigationLink(destination: IntlTransferView()) { tile("Send Abroad", "globe") }
                                NavigationLink(destination: TuitionView()) { tile("Tuition", "graduationcap") }
                                NavigationLink(destination: EsimView()) { tile("eSIM", "simcard") }
                                NavigationLink(destination: PosRequestView()) { tile("POS Device", "creditcard.and.123") }
                            }
                            transactionCard
                            if !error.isEmpty { Text(error).font(.caption).foregroundColor(.orange).padding() }
                        }.padding(16)
                    }
                    .background(brandTheme.background.ignoresSafeArea())
                    .navigationBarHidden(true)
                    .refreshable { reload() }
                }.tabItem { Label("Home", systemImage: "house.fill") }
                NavigationView { serviceList(group: nil).navigationTitle("All services") }
                    .tabItem { Label("Services", systemImage: "square.grid.2x2") }
                NavigationView { TransactionHistoryView() }
                    .tabItem { Label("History", systemImage: "clock") }
                NavigationView { account }
                    .tabItem { Label("Account", systemImage: "person.crop.circle") }
            }.accentColor(orange)
            if locked {
                Color.black.ignoresSafeArea()
                VStack(spacing: 24) {
                    Image(systemName: "faceid").font(.system(size: 54)).foregroundColor(orange)
                    Text("Unlock your account").foregroundColor(.white).font(.title2.bold())
                    Button("Unlock securely") { authenticate(enable: false) }.buttonStyle(.borderedProminent)
                    Button("Sign out") { session.logout() }
                }
            }
        }
        .sheet(item: $portal, onDismiss: { reload() }) { service in MobilePortalView(service: service) }
        .sheet(item: $receipt) { item in MobileReceiptView(receipt: item) }
        .sheet(isPresented: $showNotifications) { ComplianceNotificationsSheet() }
        .onAppear { reload(); ComplianceGateState.shared.refresh(); if locked { authenticate(enable: false) } }
        .onChange(of: scenePhase) { phase in
            if phase == .background && UserDefaults.standard.bool(forKey: "mobile_biometric_lock") { locked = true }
            if phase == .active && locked { authenticate(enable: false) }
        }
    }
    private var hero: some View {
        VStack(alignment: .leading, spacing: 20) {
            HStack {
                Image(systemName: "person.crop.circle.fill").font(.title)
                Text(name.isEmpty ? "Welcome back" : "Hello, \(name)").font(.headline)
                Spacer()
                Button { showNotifications = true } label: {
                    ZStack(alignment: .topTrailing) {
                        Image(systemName: "bell").padding(12).background(brandTheme.primary).clipShape(Circle())
                        if complianceGate.unreadNotifications > 0 {
                            Circle().fill(Color.red).frame(width: 10, height: 10).offset(x: -2, y: 2)
                        }
                    }
                }.accessibilityLabel("Account and notifications")
            }
            HStack {
                Button { balanceVisible.toggle() } label: { Label("Wallet balance", systemImage: balanceVisible ? "eye" : "eye.slash").font(.caption) }
                Spacer()
                Menu {
                    ForEach(wallets) { wallet in Button(wallet.code) { selectedCurrency = wallet.code } }
                    Button("Live currency conversion") { open("fx") }
                } label: { HStack { Text(selectedCurrency); Image(systemName: "chevron.down") }.font(.caption.bold()).padding(10).background(brandTheme.primary).clipShape(Capsule()) }
                .accessibilityLabel("Switch existing currency wallet")
            }
            Text(balanceText).font(.system(size: 39, weight: .bold, design: .rounded)).minimumScaleFactor(0.5).lineLimit(1).padding(.vertical, 8)
            if balanceVisible && selectedCurrency == "NGN" && recent.count > 1 {
                MobileBalanceLine(values: recent.reversed().compactMap { MobileReceipt.number($0["balance_after"]) })
                    .stroke(brandTheme.onPrimary, style: StrokeStyle(lineWidth: 2.5, lineCap: .round))
                    .frame(height: 62).accessibilityLabel("Recent recorded naira balance trend")
            } else { Text("Your money. Your next move.").font(.caption).frame(height: 40) }
            HStack {
                NavigationLink(destination: serviceList(group: "Send")) { quick("Send", "arrow.up.right") }
                Spacer()
                Button { open("invoice") } label: { quick("Receive", "arrow.down.left") }
                Spacer()
                Button { open("fund") } label: { quick("Top up", "plus") }
                Spacer()
                NavigationLink(destination: serviceList(group: nil)) { quick("More", "ellipsis") }
            }
        }
        .foregroundColor(brandTheme.onPrimary).tint(brandTheme.onPrimary).padding(24)
        .background(brandTheme.primary)
        .cornerRadius(32)
    }
    private var balanceText: String {
        if !balanceVisible { return "••••••" }
        guard let wallet = wallets.first(where: { $0.code == selectedCurrency }), let value = Double(wallet.balance) else { return "Unavailable" }
        let fmt = NumberFormatter(); fmt.numberStyle = .decimal; fmt.minimumFractionDigits = 2; fmt.maximumFractionDigits = 2
        return "\(selectedCurrency) \(fmt.string(from: NSNumber(value: value)) ?? wallet.balance)"
    }
    private func quick(_ title: String, _ icon: String) -> some View {
        VStack(spacing: 8) { Image(systemName: icon).font(.title2).frame(width: 52, height: 52).background(brandTheme.primary).clipShape(Circle()); Text(title).font(.caption) }
    }
    private func tile(_ title: String, _ icon: String) -> some View {
        VStack(spacing: 14) { Image(systemName: icon).font(.title2).foregroundColor(orange); Text(title).font(.system(size: 11, weight: .medium)).foregroundColor(brandTheme.onSurface).lineLimit(1).minimumScaleFactor(0.7) }
            .frame(maxWidth: .infinity).frame(height: 102).background(brandTheme.surface).cornerRadius(20)
    }
    private func shortcut(_ title: String, _ icon: String, _ slug: String) -> some View {
        Button { open(slug) } label: { tile(title, icon) }
    }
    private var transactionCard: some View {
        VStack(alignment: .leading, spacing: 16) {
            HStack { Text("Transaction history").font(.headline); Spacer(); NavigationLink("See all", destination: TransactionHistoryView()).font(.caption) }
            if recent.isEmpty { Text("No recent transactions to display.").font(.caption).foregroundColor(.secondary).padding(.vertical, 30) }
            ForEach(Array(recent.enumerated()), id: \.offset) { _, row in
                Button { receipt = MobileReceipt(row: row) } label: {
                    HStack {
                        Image(systemName: "arrow.up.arrow.down").padding(10).background(Color.orange.opacity(0.1)).cornerRadius(12)
                        VStack(alignment: .leading, spacing: 4) { Text(row["description"] as? String ?? "Transaction").font(.subheadline).lineLimit(1); Text(row["date"] as? String ?? "").font(.caption2).foregroundColor(.secondary) }
                        Spacer()
                        Text("₦\(String(format: "%.2f", MobileReceipt.number(row["amount"]) ?? 0))").font(.subheadline.bold())
                    }.foregroundColor(brandTheme.onSurface)
                }
            }
        }.padding(22).background(brandTheme.surface).cornerRadius(28)
    }
    private func serviceList(group: String?) -> some View {
        List {
            if services.isEmpty { Text("Services unavailable. Refresh Home to retry.") }
            ForEach(Array(Set(services.filter { group == nil || $0.group == group }.map { $0.group })).sorted(), id: \.self) { category in
                Section(header: Text(category)) {
                    ForEach(services.filter { $0.group == category }) { service in
                        if service.id == "airtime" { NavigationLink(service.title, destination: AirtimeView()) }
                        else if service.id == "data" { NavigationLink(service.title, destination: DataView()) }
                        else if service.id == "electric" || service.id == "cable" { NavigationLink(service.title, destination: BillPaymentView(serviceType: service.id)) }
                        else if service.id == "kyc" { NavigationLink(service.title, destination: KYCView()) }
                        else if service.id == "cards" { NavigationLink(service.title, destination: VirtualCardView()) }
                        else if service.id == "international" { NavigationLink(service.title, destination: IntlTransferView()) }
                        else if service.id == "tuition" { NavigationLink(service.title, destination: TuitionView()) }
                        else if service.id == "esim" { NavigationLink(service.title, destination: EsimView()) }
                        else if service.id == "pos" { NavigationLink(service.title, destination: PosRequestView()) }
                        else { Button(service.title) { portal = service } }
                    }
                }
            }
        }.navigationTitle(group ?? "Services")
    }
    private var account: some View {
        List {
            Section(header: Text("Your account")) {
                NavigationLink("Identity verification", destination: KYCView())
                Button("Referral growth") { open("referral") }
                Button("Rewards and coins") { open("coins") }
                Button("Points history") { open("points") }
                Button("Update PIN and security") { open("pin") }
                Button("Account settings and support chat") { open("settings") }
                NavigationLink("AI assistant", destination: AIChatView())
            }
            Section(header: Text("Device security")) {
                Button(UserDefaults.standard.bool(forKey: "mobile_biometric_lock") ? "Disable biometric lock" : "Enable biometric lock") { authenticate(enable: true) }
            }
            Button("Sign out", role: .destructive) { session.logout() }
        }.navigationTitle("Account")
    }
    private func open(_ slug: String) {
        guard let item = services.first(where: { $0.id == slug }) else { error = "This service is not available on your platform yet."; return }
        portal = item
    }
    private func reload() {
        AppNetworkService.shared.requestObject("mobile-home.php", params: [:]) { result in
            switch result {
            case .success(let body):
                guard body["status"] as? String == "success" else { wallets = []; services = []; error = body["message"] as? String ?? "Account data unavailable"; return }
                name = body["name"] as? String ?? ""; error = ""
                wallets = (body["wallets"] as? [[String: Any]] ?? []).compactMap { row in guard let code=row["code"] as? String, let balance=row["balance"] as? String else { return nil }; return MobileWallet(code: code, balance: balance) }
                services = (body["services"] as? [[String: String]] ?? []).compactMap { row in guard let id=row["id"], let title=row["title"], let group=row["group"] else { return nil }; return MobileService(id: id, title: title, group: group) }
                if !wallets.contains(where: { $0.code == selectedCurrency }) { selectedCurrency = wallets.first?.code ?? "NGN" }
            case .failure: error = "Unable to refresh. Check your connection."; wallets = []
            }
        }
        AppNetworkService.shared.requestObject("transactions.php", params: ["limit": 5]) { result in
            if case .success(let body) = result, body["status"] as? String == "success" { recent = body["data"] as? [[String: Any]] ?? [] }
        }
    }
    private func authenticate(enable: Bool) {
        let context=LAContext(); var authError: NSError?
        guard context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &authError) else { error="Biometric authentication is unavailable or not enrolled on this device."; return }
        context.evaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, localizedReason: "Protect access to your wallet") { success, _ in
            DispatchQueue.main.async { if success { if enable { UserDefaults.standard.set(!UserDefaults.standard.bool(forKey: "mobile_biometric_lock"), forKey: "mobile_biometric_lock") }; locked = false } }
        }
    }
}
struct MobileBalanceLine: Shape {
    let values: [Double]
    func path(in rect: CGRect) -> Path {
        var p=Path(); guard values.count>1, let low=values.min(), let high=values.max() else { return p }
        for (i,value) in values.enumerated() {
            let point=CGPoint(x: rect.width*CGFloat(i)/CGFloat(values.count-1), y: rect.height*(1-CGFloat((value-low)/max(high-low,1))))
            if i==0 { p.move(to: point) } else { p.addLine(to: point) }
        }; return p
    }
}
@MainActor
struct MobilePortalView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    let service: MobileService
    @Environment(\.dismiss) private var dismiss
    @State private var token: String?
    @State private var message="Opening secure service…"
    var body: some View {
        NavigationView {
            Group { if let token=token { MobilePortalBrowser(token: token, service: service.id) } else { Text(message).padding() } }
                .navigationTitle(service.title).navigationBarTitleDisplayMode(.inline)
                .toolbar { ToolbarItem(placement: .navigationBarTrailing) { Button("Done") { dismiss() } } }
        }.onAppear {
            AppNetworkService.shared.requestObject("mobile-home.php", params: ["action":"handoff","service":service.id]) { result in
                if case .success(let body)=result, body["status"] as? String == "success", let value=body["token"] as? String, !value.isEmpty { token=value }
                else { message="This service could not be opened. Close and try again." }
            }
        }
    }
}
struct MobilePortalBrowser: UIViewRepresentable {
    let token: String
    let service: String
    func makeCoordinator() -> Coordinator { Coordinator() }
    func makeUIView(context: Context) -> WKWebView {
        let config=WKWebViewConfiguration(); config.websiteDataStore = .nonPersistent()
        let view=WKWebView(frame: .zero, configuration: config); view.navigationDelegate=context.coordinator
        guard let base=URL(string: AppNetworkService.shared.mobileOrigin), base.scheme=="https", let url=URL(string:"web/AppPortal.php",relativeTo:base) else { return view }
        var request=URLRequest(url:url); request.httpMethod="POST"
        var parts=URLComponents(); parts.queryItems=[URLQueryItem(name:"token",value:token),URLQueryItem(name:"service",value:service)]
        request.httpBody=parts.percentEncodedQuery?.data(using:.utf8); request.setValue("application/x-www-form-urlencoded",forHTTPHeaderField:"Content-Type")
        view.load(request); return view
    }
    func updateUIView(_ uiView: WKWebView, context: Context) {}
    class Coordinator: NSObject, WKNavigationDelegate {
        func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy)->Void) {
            guard let url=navigationAction.request.url, let base=URL(string:AppNetworkService.shared.mobileOrigin) else { decisionHandler(.cancel); return }
            if url.scheme=="https" && url.host==base.host && url.port==base.port { decisionHandler(.allow); return }
            if url.scheme=="https" || url.scheme=="mailto" { UIApplication.shared.open(url) }
            decisionHandler(.cancel)
        }
    }
}

struct ComplianceNotificationsSheet: View {
    @Environment(\.dismiss) private var dismiss
    @State private var items: [[String: Any]] = []
    @State private var loading = true

    var body: some View {
        NavigationView {
            List {
                if loading {
                    HStack { Spacer(); ProgressView(); Spacer() }
                } else if items.isEmpty {
                    Text("No compliance or account alerts yet.")
                        .foregroundColor(.secondary)
                } else {
                    ForEach(Array(items.enumerated()), id: \.offset) { _, item in
                        VStack(alignment: .leading, spacing: 4) {
                            Text(item["title"] as? String ?? "Notification")
                                .font(.headline)
                            Text(item["message"] as? String ?? "")
                                .font(.subheadline)
                                .foregroundColor(.secondary)
                            if let ts = item["created_at"] as? String, !ts.isEmpty {
                                Text(ts).font(.caption2).foregroundColor(.secondary)
                            }
                        }
                        .padding(.vertical, 4)
                    }
                }
            }
            .navigationTitle("Notifications")
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Close") { dismiss() }
                }
                ToolbarItem(placement: .navigationBarTrailing) {
                    NavigationLink("KYC") { KYCView() }
                }
            }
            .onAppear { loadNotifications() }
        }
    }

    private func loadNotifications() {
        loading = true
        AppNetworkService.shared.requestObject("kyc.php", params: ["action": "notifications", "mark_read": "1"]) { result in
            loading = false
            if case .success(let json) = result, let list = json["items"] as? [[String: Any]] {
                items = list
                ComplianceGateState.shared.unreadNotifications = 0
            }
        }
    }
}
