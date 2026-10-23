import SwiftUI
import MessageUI

/// Real native email delivery for "Email Receipt" (previously this button just regenerated a PDF
/// and reopened the OS share sheet — mislabeled, since nothing was ever actually emailed). Wraps
/// MFMailComposeViewController so sending goes through the user's own configured Mail account —
/// no backend dependency, and no new guest-api endpoint that would let an anonymous client
/// trigger server-sent email to arbitrary addresses.
struct MailComposeView: UIViewControllerRepresentable {
    let recipient: String
    let subject: String
    let body: String
    let attachmentURL: URL?
    let onFinish: (() -> Void)?

    func makeUIViewController(context: Context) -> MFMailComposeViewController {
        let vc = MFMailComposeViewController()
        vc.mailComposeDelegate = context.coordinator
        if !recipient.isEmpty {
            vc.setToRecipients([recipient])
        }
        vc.setSubject(subject)
        vc.setMessageBody(body, isHTML: false)
        if let attachmentURL, let data = try? Data(contentsOf: attachmentURL) {
            vc.addAttachmentData(data, mimeType: "application/pdf", fileName: attachmentURL.lastPathComponent)
        }
        return vc
    }

    func updateUIViewController(_ uiViewController: MFMailComposeViewController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(onFinish: onFinish) }

    final class Coordinator: NSObject, MFMailComposeViewControllerDelegate {
        let onFinish: (() -> Void)?
        init(onFinish: (() -> Void)?) { self.onFinish = onFinish }

        func mailComposeController(_ controller: MFMailComposeViewController, didFinishWith result: MFMailComposeResult, error: Error?) {
            controller.dismiss(animated: true) { self.onFinish?() }
        }
    }

    /// Devices with no Mail account configured can't present MFMailComposeViewController at all —
    /// callers must check this first and fall back to the existing share-sheet behavior instead.
    static var canSendMail: Bool { MFMailComposeViewController.canSendMail() }
}
