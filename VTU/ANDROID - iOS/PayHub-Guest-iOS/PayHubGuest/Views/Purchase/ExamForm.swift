import SwiftUI

struct ExamForm: View {
    @ObservedObject var viewModel: GuestViewModel

    @State private var body_: String? = nil
    @State private var plan: ExamPlan? = nil
    @State private var email = ""

    private var plans: [ExamPlan] {
        body_.flatMap { viewModel.examPlans[$0] } ?? []
    }

    var body: some View {
        FieldLabel(text: "Exam Body")
        ChipRow(options: Array(viewModel.examPlans.keys).sorted(), labelOf: { $0 }, selected: body_) {
            body_ = $0; plan = nil
        }

        SimpleDropdown(
            label: "Exam Type",
            options: plans,
            labelOf: { "\($0.examType.replacingOccurrences(of: "_", with: " ").capitalized) — ₦\($0.amount)" },
            selected: plan,
            placeholder: body_ == nil ? "Select an exam body first" : "Select an exam type",
            onSelect: { plan = $0 }
        )

        FieldLabel(text: "Email (optional — we'll send your PIN here too)")
        TextField("you@example.com", text: $email)
            .keyboardType(.emailAddress)
            .textInputAutocapitalization(.never)
            .textFieldStyle(.roundedBorder)

        let amt = plan.flatMap { Double($0.amount) }.map { Int($0) } ?? 0
        let ready = body_ != nil && plan != nil
        PayButton(amount: amt, enabled: ready, loading: viewModel.checkoutState == .loading) {
            viewModel.startCheckout(
                service: "exam",
                recipient: body_ ?? "",
                fields: ["type": body_?.lowercased(), "quantity": plan?.examType, "email": email.isEmpty ? nil : email]
            )
        }
    }
}
