import SwiftUI

struct ElectricityForm: View {
    @ObservedObject var viewModel: GuestViewModel

    @State private var disco: GuestViewModel.ElectricProvider? = nil
    @State private var meterType: String? = nil
    @State private var meterNumber = ""
    @State private var amount = ""
    @State private var email = ""

    var body: some View {
        SimpleDropdown(
            label: "Select Disco",
            options: viewModel.electricProviders,
            labelOf: { $0.label },
            selected: disco,
            placeholder: "Choose your electricity provider",
            onSelect: { disco = $0; viewModel.resetVerify() }
        )

        FieldLabel(text: "Meter Type")
        ChipRow(options: ["prepaid", "postpaid"], labelOf: { $0.capitalized }, selected: meterType) { meterType = $0 }

        FieldLabel(text: "Meter Number")
        VerifyRow(
            value: $meterNumber,
            placeholder: "e.g. 04512378965",
            verifying: viewModel.verifyState == .loading,
            onVerify: {
                if let disco, let meterType, !meterNumber.isEmpty {
                    viewModel.verifyCustomer(service: "electricity", fields: ["provider": disco.code, "type": meterType, "meter_number": meterNumber])
                }
            }
        )
        .onChange(of: meterNumber) { _ in viewModel.resetVerify() }

        switch viewModel.verifyState {
        case .verified(let name, let address): VerifiedCard(name: name, sub: address)
        case .failed(let message): Text(message).font(.system(size: 12)).foregroundColor(PHColor.error).padding(.top, 6)
        default: EmptyView()
        }

        AmountField(label: "Amount", amountChips: [1000, 2000, 5000, 10000], amount: $amount)

        EmailReceiptField(email: $email)

        let amt = Int(amount) ?? 0
        let ready = disco != nil && meterType != nil && !meterNumber.isEmpty && amt > 0
        PayButton(amount: amt, enabled: ready, loading: viewModel.checkoutState == .loading) {
            viewModel.startCheckout(
                service: "electricity",
                recipient: meterNumber,
                fields: ["provider": disco?.code, "type": meterType, "meter_number": meterNumber, "amount": amt, "email": email.isEmpty ? nil : email]
            )
        }
    }
}
