import SwiftUI

struct AirtimeForm: View {
    @ObservedObject var viewModel: GuestViewModel

    @State private var network: String? = nil
    @State private var userPicked = false
    @State private var phone = ""
    @State private var amount = ""
    @State private var email = ""

    var body: some View {
        FieldLabel(text: "Select Network")
        ChipRow(options: viewModel.airtimeNetworks.map { $0.code }, labelOf: { $0.uppercased() }, selected: network) {
            network = $0; userPicked = true
        }

        FieldLabel(text: "Phone Number")
        TextField("080X XXX XXXX", text: $phone)
            .keyboardType(.phonePad)
            .textFieldStyle(.roundedBorder)
            .onChange(of: phone) { newValue in
                phone = String(newValue.filter(\.isNumber).prefix(11))
                if phone.count == 11 { viewModel.detectNetwork(phone: phone) }
            }

        AmountField(label: "Amount", amountChips: [100, 200, 500, 1000], amount: $amount)

        EmailReceiptField(email: $email)

        let amt = Int(amount) ?? 0
        let ready = network != nil && phone.count == 11 && amt > 0
        PayButton(amount: amt, enabled: ready, loading: viewModel.checkoutState == .loading) {
            viewModel.startCheckout(
                service: "airtime",
                recipient: phone,
                fields: ["network": network, "phone_number": phone, "amount": amt, "email": email.isEmpty ? nil : email]
            )
        }
        .onChange(of: viewModel.detectedNetwork) { detected in
            if !userPicked, let detected { network = detected.lowercased() }
        }
    }
}
