import SwiftUI
import UIKit

struct MobileReceipt: Identifiable {
    let row: [String: Any]
    var id: String { row["reference"] as? String ?? "receipt" }
    static func number(_ value: Any?) -> Double? { if let n=value as? NSNumber { return n.doubleValue }; if let s=value as? String { return Double(s) }; return nil }
    var lines: [(String,String)] {
        let status=Int(Self.number(row["status"]) ?? 0)
        return [("Reference",id),("Status",status==1 ? "Completed" : status==2 ? "Pending" : "Failed"),
            ("Description",row["description"] as? String ?? ""),("Date",row["date"] as? String ?? ""),
            ("Amount","NGN \(String(format:"%.2f",Self.number(row["amount"]) ?? 0))"),
            ("Balance before","NGN \(String(format:"%.2f",Self.number(row["balance_before"]) ?? 0))"),
            ("Balance after","NGN \(String(format:"%.2f",Self.number(row["balance_after"]) ?? 0))")]
    }
}
struct MobileReceiptView: View {
    let receipt: MobileReceipt
    @State private var exportURL: URL?
    @State private var sharing=false
    @State private var error=""
    var body: some View {
        NavigationView {
            List {
                ForEach(Array(receipt.lines.enumerated()),id:\.offset) { _,line in VStack(alignment:.leading,spacing:6) { Text(line.0).font(.caption).foregroundColor(.secondary); Text(line.1).font(.body).textSelection(.enabled) } }
                Button("Share receipt as PDF") { export(pdf:true) }
                Button("Share receipt as image") { export(pdf:false) }
                if !error.isEmpty { Text(error).foregroundColor(.red) }
            }.navigationTitle("Transaction receipt")
        }.sheet(isPresented:$sharing) { if let url=exportURL { BCShareSheet(items:[url]) } }
    }
    private func export(pdf: Bool) {
        let size=CGSize(width:600,height:780)
        let draw={
            UIColor.white.setFill(); UIRectFill(CGRect(origin:.zero,size:size))
            let heading=[NSAttributedString.Key.font:UIFont.boldSystemFont(ofSize:26),.foregroundColor:UIColor.black]
            ("Transaction receipt" as NSString).draw(at:CGPoint(x:32,y:32),withAttributes:heading)
            var y:CGFloat=90
            for (key,value) in receipt.lines {
                (key as NSString).draw(at:CGPoint(x:32,y:y),withAttributes:[.font:UIFont.systemFont(ofSize:12),.foregroundColor:UIColor.darkGray])
                (value as NSString).draw(in:CGRect(x:32,y:y+20,width:536,height:60),withAttributes:[.font:UIFont.systemFont(ofSize:16),.foregroundColor:UIColor.black]); y+=88
            }
        }
        let url=FileManager.default.temporaryDirectory.appendingPathComponent("receipt-\(UUID().uuidString)."+(pdf ? "pdf":"png"))
        do {
            if pdf { let renderer=UIGraphicsPDFRenderer(bounds:CGRect(origin:.zero,size:size)); try renderer.writePDF(to:url) { context in context.beginPage(); draw() } }
            else { let image=UIGraphicsImageRenderer(size:size).image { _ in draw() }; guard let data=image.pngData() else { return }; try data.write(to:url) }
            exportURL=url; sharing=true
        } catch { self.error="Could not export this receipt. Please try again." }
    }
}
