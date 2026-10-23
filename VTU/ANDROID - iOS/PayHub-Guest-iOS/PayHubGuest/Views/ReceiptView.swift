import SwiftUI

struct ReceiptView: View {
    let reference: String
    @ObservedObject var viewModel: GuestViewModel
    let onDone: () -> Void

    var body: some View {
        ScrollView {
            HStack {
                Image(systemName: "xmark").onTapGesture { onDone() }
                Text("Receipt").font(.system(size: 18, weight: .bold)).padding(.leading, 12)
                Spacer()
            }
            .padding(.vertical, 16)

            switch viewModel.receiptState {
            case .idle, .polling:
                VStack(spacing: 12) {
                    ProgressView().tint(PHColor.primary)
                    Text("Confirming your payment…").font(.system(size: 15, weight: .bold)).foregroundColor(PHColor.text)
                    Text("This usually takes a few seconds.").font(.system(size: 12)).foregroundColor(PHColor.text2)
                }
                .padding(.vertical, 60)

            case .success(let order):
                // .id(statusLabel) forces a fresh view identity when the poll upgrades
                // Pending -> Success in place, so onAppear re-fires and re-saves the receipt
                // to history with its final settled status.
                ReceiptCard(order: order, viewModel: viewModel, statusLabel: "success", onDone: onDone)
                    .id("success")
            case .pending(let order):
                ReceiptCard(order: order, viewModel: viewModel, statusLabel: "pending", onDone: onDone)
                    .id("pending")
            case .failed(let message):
                VStack(spacing: 10) {
                    Image(systemName: "xmark.circle.fill").font(.system(size: 44)).foregroundColor(PHColor.error)
                    Text("We couldn't confirm this payment").font(.system(size: 15, weight: .bold)).foregroundColor(PHColor.text)
                    Text(message).font(.system(size: 13)).foregroundColor(PHColor.text2).multilineTextAlignment(.center)
                    Button("Back to Home") { onDone() }
                        .font(.system(size: 14, weight: .bold)).foregroundColor(.white)
                        .padding(.horizontal, 24).padding(.vertical, 12)
                        .background(PHColor.primary).cornerRadius(14)
                        .padding(.top, 12)
                }
                .padding(.vertical, 40)
            }
        }
        .padding(.horizontal, 20)
        .onAppear { viewModel.pollOrderStatus(reference: reference) }
    }
}

private struct ReceiptCard: View {
    let order: GuestOrderStatusResponse
    @ObservedObject var viewModel: GuestViewModel
    let statusLabel: String
    let onDone: () -> Void

    @State private var showEmailForm = false
    @State private var email = ""
    @State private var shareItems: [Any]? = nil
    @State private var showMailComposer = false
    @State private var pdfForEmail: URL? = nil

    private var receipt: GuestReceipt {
        let pending = viewModel.pendingTransaction
        return GuestReceipt(
            reference: order.ref ?? "",
            service: pending?.service ?? order.service ?? "",
            recipient: pending?.recipient ?? "",
            amountPaid: Double(order.amount ?? "") ?? 0,
            status: statusLabel,
            date: Date(),
            meterNumber: order.meterNumber,
            token: order.token,
            tokenUnit: order.tokenUnit
        )
    }

    var body: some View {
        VStack(spacing: 12) {
            Image(systemName: "checkmark.circle.fill")
                .font(.system(size: 56))
                .foregroundColor(statusLabel == "success" ? PHColor.success : PHColor.primary)
            Text(String(format: "₦%.0f", receipt.amountPaid)).font(.system(size: 26, weight: .heavy)).foregroundColor(PHColor.text)
            Text(statusLabel == "success" ? "Payment Successful" : "Payment Pending").foregroundColor(PHColor.text2)
            if statusLabel == "success", let guestEmail = viewModel.pendingTransaction?.email {
                Text("📧 A copy of your receipt has been sent to \(guestEmail)")
                    .font(.system(size: 11))
                    .foregroundColor(PHColor.success)
            }

            VStack(spacing: 6) {
                receiptRow("Reference", receipt.reference)
                receiptRow("Service", receipt.service.capitalized)
                receiptRow("Recipient", receipt.recipient)
                if let token = receipt.token { receiptRow("Token", token) }
                receiptRow("Amount Paid", String(format: "₦%.0f", receipt.amountPaid))
                receiptRow("Payment Method", "PayHub Checkout")
            }
        }
        .padding(24)
        .background(Color.white)
        .cornerRadius(24)
        .onAppear { viewModel.saveReceipt(receipt) }

        HStack(spacing: 8) {
            actionButton("Download PDF") {
                if let url = ReceiptRenderer.savePdfToCache(receipt) { shareItems = [url] }
            }
            actionButton("Save Image") {
                if let url = ReceiptRenderer.saveImageToCache(receipt) { shareItems = [url] }
            }
        }
        .padding(.top, 16)
        HStack(spacing: 8) {
            actionButton(MailComposeView.canSendMail ? "Email Receipt" : "Share Receipt") {
                if MailComposeView.canSendMail {
                    showEmailForm.toggle()
                } else {
                    // No Mail account configured on this device — the composer can't even
                    // present, so fall back to the existing share sheet rather than a dead button.
                    if let url = ReceiptRenderer.savePdfToCache(receipt) { shareItems = [url] }
                }
            }
            actionButton("WhatsApp", bg: Color(hex: 0x25D366), fg: .white) {
                if let url = ReceiptRenderer.saveImageToCache(receipt) { shareItems = [url] }
            }
        }
        .padding(.top, 8)

        if showEmailForm {
            HStack {
                TextField("Enter your email", text: $email)
                    .keyboardType(.emailAddress)
                    .textInputAutocapitalization(.never)
                    .textFieldStyle(.roundedBorder)
                Button("Send") {
                    if let url = ReceiptRenderer.savePdfToCache(receipt) {
                        pdfForEmail = url
                        showMailComposer = true
                    }
                }
                .font(.system(size: 14, weight: .bold)).foregroundColor(.white)
                .padding(.horizontal, 16).padding(.vertical, 10)
                .background(PHColor.primary).cornerRadius(10)
            }
            .padding(.top, 12)
        }

        Button("Make Another Payment") { onDone() }
            .font(.system(size: 16, weight: .bold)).foregroundColor(.white)
            .frame(maxWidth: .infinity).padding(.vertical, 14)
            .background(PHColor.primary).cornerRadius(16)
            .padding(.top, 20)
            .padding(.bottom, 40)
            .sheet(isPresented: Binding(get: { shareItems != nil }, set: { if !$0 { shareItems = nil } })) {
                if let shareItems { ShareSheet(items: shareItems) }
            }
            .sheet(isPresented: $showMailComposer) {
                if let pdfForEmail {
                    MailComposeView(
                        recipient: email,
                        subject: "Your PayHub Guest Receipt",
                        body: "Thank you for using PayHub Guest!\n\nYour receipt for reference \(receipt.reference) is attached.",
                        attachmentURL: pdfForEmail,
                        onFinish: { showMailComposer = false }
                    )
                }
            }
    }

    private func receiptRow(_ label: String, _ value: String) -> some View {
        HStack {
            Text(label).font(.system(size: 13)).foregroundColor(PHColor.text2)
            Spacer()
            Text(value).font(.system(size: 13, weight: .semibold)).foregroundColor(PHColor.text)
        }
    }

    private func actionButton(_ label: String, bg: Color = Color(.systemGray6), fg: Color = PHColor.text, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(label).font(.system(size: 12, weight: .semibold)).foregroundColor(fg)
                .frame(maxWidth: .infinity).padding(.vertical, 12)
        }
        .background(bg)
        .cornerRadius(14)
    }
}
