import SwiftUI

private struct Faq: Identifiable {
    let id = UUID()
    let q: String
    let a: String
}

private let FAQS: [Faq] = [
    Faq(q: "Do I need an account?", a: "No — PayHub lets you pay for airtime, data and bills instantly as a guest. No registration required."),
    Faq(q: "Is my payment secure?", a: "Yes, all payments are processed securely through PayHub's checkout."),
    Faq(q: "What if my data doesn't deliver?", a: "Contact our support team with your transaction reference and we'll resolve it immediately."),
]

struct SupportView: View {
    var supportInfo: GuestSupportInfo? = nil

    // Falls back to placeholder contact info only if site-info.php hasn't returned real values yet.
    private var whatsappPhone: String {
        let phone = supportInfo?.phone ?? ""
        return phone.isEmpty ? "2348000000000" : phone
    }
    private var supportEmail: String {
        let email = supportInfo?.email ?? ""
        return email.isEmpty ? "support@payhub.com.ng" : email
    }

    var body: some View {
        ScrollView {
            Text("Support").font(.system(size: 20, weight: .bold)).padding(.vertical, 20)
                .frame(maxWidth: .infinity, alignment: .leading)

            VStack(alignment: .leading, spacing: 12) {
                Text("Need help?").font(.system(size: 15, weight: .bold))
                Button {
                    if let url = URL(string: "https://wa.me/\(whatsappPhone)") {
                        UIApplication.shared.open(url)
                    }
                } label: {
                    Text("Chat on WhatsApp").font(.system(size: 14, weight: .bold)).foregroundColor(.white)
                        .frame(maxWidth: .infinity).padding(.vertical, 14)
                }
                .background(Color(hex: 0x25D366)).cornerRadius(14)

                Button {
                    if let url = URL(string: "mailto:\(supportEmail)") {
                        UIApplication.shared.open(url)
                    }
                } label: {
                    Label("Email Support", systemImage: "envelope")
                        .font(.system(size: 14, weight: .bold)).foregroundColor(PHColor.text)
                        .frame(maxWidth: .infinity).padding(.vertical, 14)
                }
                .background(Color(.systemGray6)).cornerRadius(14)
            }
            .padding(16)
            .background(Color.white)
            .cornerRadius(18)

            VStack(alignment: .leading, spacing: 4) {
                Text("Frequently Asked Questions").font(.system(size: 15, weight: .bold)).padding(.bottom, 4)
                ForEach(FAQS) { faq in FaqRow(faq: faq) }
            }
            .padding(16)
            .background(Color.white)
            .cornerRadius(18)
            .padding(.top, 16)
            .padding(.bottom, 100)
        }
        .padding(.horizontal, 20)
    }
}

private struct FaqRow: View {
    let faq: Faq
    @State private var expanded = false

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(faq.q).font(.system(size: 13, weight: .semibold)).foregroundColor(PHColor.text)
                Spacer()
                Image(systemName: "chevron.down").foregroundColor(PHColor.text2)
            }
            if expanded {
                Text(faq.a).font(.system(size: 12)).foregroundColor(PHColor.text2)
            }
        }
        .padding(.vertical, 8)
        .contentShape(Rectangle())
        .onTapGesture { expanded.toggle() }
    }
}
