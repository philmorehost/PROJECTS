import SwiftUI

/// Full receipt details for a history entry, opened by tapping a transaction on the Home
/// "Recent Transactions" list or the History tab. Shares reuse the exact same PDF/PNG renderer
/// as the post-purchase Receipt screen, so a shared history receipt is indistinguishable from
/// one shared right after payment.
struct ReceiptDetailSheet: View {
    let receipt: GuestReceipt
    let onDismiss: () -> Void

    @State private var shareItems: [Any]? = nil

    private var entry: GuestServiceEntry? {
        GuestServiceCatalog.all.first { $0.key == receipt.service }
    }

    private var statusLabel: String {
        switch receipt.status {
        case "success": return "Successful"
        case "pending", "processing": return "Pending"
        default: return "Failed"
        }
    }

    private var statusColor: Color {
        switch receipt.status {
        case "success": return PHColor.success
        case "pending", "processing": return Color(hex: 0xF59E0B)
        default: return PHColor.error
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            HStack {
                Text("Transaction Details").font(.system(size: 16, weight: .bold)).foregroundColor(PHColor.text)
                Spacer()
                Image(systemName: "xmark")
                    .foregroundColor(PHColor.text2)
                    .onTapGesture { onDismiss() }
            }
            .padding(.bottom, 16)

            Image(systemName: receipt.status == "success" ? "checkmark.circle.fill" : "clock.fill")
                .font(.system(size: 48))
                .foregroundColor(statusColor)
            Text(String(format: "₦%.0f", receipt.amountPaid))
                .font(.system(size: 24, weight: .heavy)).foregroundColor(PHColor.text)
                .padding(.top, 8)
            Text(statusLabel)
                .font(.system(size: 11, weight: .semibold))
                .foregroundColor(statusColor)
                .padding(.horizontal, 10).padding(.vertical, 4)
                .background(statusColor.opacity(0.12))
                .clipShape(Capsule())
                .padding(.top, 4)
                .padding(.bottom, 16)

            VStack(spacing: 10) {
                detailRow("Service", entry?.title ?? receipt.service.capitalized)
                detailRow("Recipient", receipt.recipient.isEmpty ? "—" : receipt.recipient)
                detailRow("Reference", receipt.reference)
                if let token = receipt.token { detailRow("Token", token) }
                if let unit = receipt.tokenUnit { detailRow("Units", unit) }
                detailRow("Date", receipt.date.formatted(date: .abbreviated, time: .shortened))
                detailRow("Payment Method", "PayHub Checkout")
            }

            HStack(spacing: 8) {
                shareButton("Share PDF") {
                    if let url = ReceiptRenderer.savePdfToCache(receipt) { shareItems = [url] }
                }
                shareButton("Share Image", bg: PHColor.primary, fg: .white) {
                    if let url = ReceiptRenderer.saveImageToCache(receipt) { shareItems = [url] }
                }
            }
            .padding(.top, 20)

            Spacer()
        }
        .padding(24)
        .sheet(isPresented: Binding(get: { shareItems != nil }, set: { if !$0 { shareItems = nil } })) {
            if let shareItems { ShareSheet(items: shareItems) }
        }
    }

    private func detailRow(_ label: String, _ value: String) -> some View {
        HStack {
            Text(label).font(.system(size: 13)).foregroundColor(PHColor.text2)
            Spacer()
            Text(value).font(.system(size: 13, weight: .semibold)).foregroundColor(PHColor.text)
        }
    }

    private func shareButton(_ label: String, bg: Color = Color(.systemGray6), fg: Color = PHColor.text, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(label).font(.system(size: 12, weight: .semibold)).foregroundColor(fg)
                .frame(maxWidth: .infinity).padding(.vertical, 12)
        }
        .background(bg)
        .cornerRadius(14)
    }
}
