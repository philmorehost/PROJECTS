import SwiftUI

struct CableForm: View {
    @ObservedObject var viewModel: GuestViewModel

    @State private var provider: String? = nil
    @State private var iucNumber = ""
    @State private var pkg: CablePlan? = nil
    @State private var email = ""

    private var plans: [CablePlan] {
        provider.flatMap { viewModel.cableProviders[$0] } ?? []
    }

    var body: some View {
        FieldLabel(text: "Select Provider")
        ChipRow(options: Array(viewModel.cableProviders.keys).sorted(), labelOf: { $0 }, selected: provider) {
            provider = $0; pkg = nil; viewModel.resetVerify()
        }

        FieldLabel(text: "Smartcard / IUC Number")
        VerifyRow(
            value: $iucNumber,
            placeholder: "e.g. 1234567890",
            verifying: viewModel.verifyState == .loading,
            onVerify: {
                if let provider, !iucNumber.isEmpty, let pkg {
                    viewModel.verifyCustomer(service: "cable", fields: ["type": provider.lowercased(), "iuc_number": iucNumber, "package": pkg.packageName])
                }
            }
        )
        .onChange(of: iucNumber) { _ in viewModel.resetVerify() }

        switch viewModel.verifyState {
        case .verified(let name, let address): VerifiedCard(name: name, sub: address)
        case .failed(let message): Text(message).font(.system(size: 12)).foregroundColor(PHColor.error).padding(.top, 6)
        default: EmptyView()
        }

        SimpleDropdown(
            label: "Choose a Package",
            options: plans,
            labelOf: { "\($0.packageName) — ₦\($0.amount)" },
            selected: pkg,
            placeholder: provider == nil ? "Select a provider first" : "Select a package",
            onSelect: { pkg = $0; viewModel.resetVerify() }
        )

        EmailReceiptField(email: $email)

        let amt = pkg.flatMap { Double($0.amount) }.map { Int($0) } ?? 0
        let ready = provider != nil && !iucNumber.isEmpty && pkg != nil
        PayButton(amount: amt, enabled: ready, loading: viewModel.checkoutState == .loading) {
            viewModel.startCheckout(
                service: "cable",
                recipient: iucNumber,
                fields: ["type": provider?.lowercased(), "iuc_number": iucNumber, "package": pkg?.packageName, "email": email.isEmpty ? nil : email]
            )
        }
    }
}
