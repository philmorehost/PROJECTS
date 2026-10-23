import SwiftUI

struct BettingForm: View {
    @ObservedObject var viewModel: GuestViewModel

    @State private var provider: BettingProvider? = nil
    @State private var customerId = ""
    @State private var amount = ""
    @State private var email = ""

    var body: some View {
        SimpleDropdown(
            label: "Select Bookmaker",
            options: viewModel.bettingProviders,
            labelOf: { $0.providerName },
            selected: provider,
            placeholder: "Choose your bookmaker",
            onSelect: { provider = $0; viewModel.resetVerify() }
        )

        FieldLabel(text: "Customer / Account ID")
        VerifyRow(
            value: $customerId,
            placeholder: "e.g. 1234567890",
            verifying: viewModel.verifyState == .loading,
            onVerify: {
                if let provider, !customerId.isEmpty {
                    viewModel.verifyCustomer(service: "betting", fields: ["provider": provider.providerCode, "customer_id": customerId])
                }
            }
        )
        .onChange(of: customerId) { _ in viewModel.resetVerify() }

        switch viewModel.verifyState {
        case .verified(let name, let address): VerifiedCard(name: name, sub: address)
        case .failed(let message): Text(message).font(.system(size: 12)).foregroundColor(PHColor.error).padding(.top, 6)
        default: EmptyView()
        }

        AmountField(label: "Amount", amountChips: [500, 1000, 2000, 5000], amount: $amount)

        EmailReceiptField(email: $email)

        let amt = Int(amount) ?? 0
        let ready = provider != nil && !customerId.isEmpty && amt > 0
        PayButton(amount: amt, enabled: ready, loading: viewModel.checkoutState == .loading) {
            viewModel.startCheckout(
                service: "betting",
                recipient: customerId,
                fields: ["provider": provider?.providerCode, "customer_id": customerId, "amount": amt, "email": email.isEmpty ? nil : email]
            )
        }
    }
}
