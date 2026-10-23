import UIKit

/// Renders a receipt as a simple, readable PDF or PNG for the "no stored history" guest flow —
/// this is the guest's only durable copy of a transaction, so both formats share one drawing
/// routine to keep them consistent. Mirrors the Android app's util/ReceiptRenderer.kt.
enum ReceiptRenderer {

    private static let pageSize = CGSize(width: 360, height: 500)

    private static func draw(in context: CGContext, rect: CGRect, receipt: GuestReceipt) {
        UIColor.white.setFill()
        context.fill(rect)

        let brand: [NSAttributedString.Key: Any] = [.font: UIFont.boldSystemFont(ofSize: 20), .foregroundColor: UIColor(hex: 0x0D6EFD)]
        let title: [NSAttributedString.Key: Any] = [.font: UIFont.boldSystemFont(ofSize: 24), .foregroundColor: UIColor(hex: 0x1E293B)]
        let success: [NSAttributedString.Key: Any] = [.font: UIFont.boldSystemFont(ofSize: 16), .foregroundColor: UIColor(hex: 0x22C55E)]
        let label: [NSAttributedString.Key: Any] = [.font: UIFont.systemFont(ofSize: 12), .foregroundColor: UIColor(hex: 0x64748B)]
        let value: [NSAttributedString.Key: Any] = [.font: UIFont.boldSystemFont(ofSize: 12), .foregroundColor: UIColor(hex: 0x1E293B)]

        var y: CGFloat = 24
        "PayHub".draw(at: CGPoint(x: 20, y: y), withAttributes: brand)
        y += 32
        (receipt.status == "success" ? "✓ Payment Successful" : "Payment \(receipt.status)").draw(at: CGPoint(x: 20, y: y), withAttributes: success)
        y += 28
        String(format: "₦%.0f", receipt.amountPaid).draw(at: CGPoint(x: 20, y: y), withAttributes: title)
        y += 40

        let dateFormatter = DateFormatter()
        dateFormatter.dateFormat = "dd MMM yyyy, h:mm a"

        var rows: [(String, String)] = [
            ("Reference", receipt.reference),
            ("Service", receipt.service.capitalized),
            ("Recipient", receipt.recipient),
            ("Amount Paid", String(format: "₦%.0f", receipt.amountPaid)),
            ("Date & Time", dateFormatter.string(from: receipt.date)),
            ("Payment Method", "PayHub Checkout"),
        ]
        if let token = receipt.token { rows.append(("Token", token)) }
        if let meterNumber = receipt.meterNumber { rows.append(("Meter Number", meterNumber)) }

        for (l, v) in rows {
            l.draw(at: CGPoint(x: 20, y: y), withAttributes: label)
            v.draw(at: CGPoint(x: 150, y: y), withAttributes: value)
            y += 24
        }

        y += 20
        "Thank you for using PayHub".draw(at: CGPoint(x: 20, y: y), withAttributes: label)
    }

    static func renderImage(_ receipt: GuestReceipt) -> UIImage {
        let renderer = UIGraphicsImageRenderer(size: pageSize)
        return renderer.image { ctx in draw(in: ctx.cgContext, rect: CGRect(origin: .zero, size: pageSize), receipt: receipt) }
    }

    static func saveImageToCache(_ receipt: GuestReceipt) -> URL? {
        let dir = FileManager.default.temporaryDirectory.appendingPathComponent("receipts", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        let file = dir.appendingPathComponent("receipt_\(receipt.reference).png")
        guard let data = renderImage(receipt).pngData() else { return nil }
        try? data.write(to: file)
        return file
    }

    static func savePdfToCache(_ receipt: GuestReceipt) -> URL? {
        let dir = FileManager.default.temporaryDirectory.appendingPathComponent("receipts", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        let file = dir.appendingPathComponent("receipt_\(receipt.reference).pdf")

        let renderer = UIGraphicsPDFRenderer(bounds: CGRect(origin: .zero, size: pageSize))
        do {
            try renderer.writePDF(to: file) { ctx in
                ctx.beginPage()
                draw(in: ctx.cgContext, rect: CGRect(origin: .zero, size: pageSize), receipt: receipt)
            }
            return file
        } catch {
            return nil
        }
    }
}

private extension UIColor {
    convenience init(hex: UInt32) {
        self.init(
            red: CGFloat((hex >> 16) & 0xFF) / 255,
            green: CGFloat((hex >> 8) & 0xFF) / 255,
            blue: CGFloat(hex & 0xFF) / 255,
            alpha: 1
        )
    }
}
