import SwiftUI

struct DataForm: View {
    @ObservedObject var viewModel: GuestViewModel

    @State private var network: String? = nil
    @State private var userPicked = false
    @State private var phone = ""
    @State private var dataType: String? = nil
    @State private var plan: DataPlan? = nil
    @State private var email = ""

    private var networkPlans: [DataPlan] {
        network.flatMap { viewModel.dataNetworks[$0] } ?? []
    }
    private var dataTypes: [String] {
        Array(Set(networkPlans.map { $0.dataTypeCode })).sorted()
    }
    private var filteredPlans: [DataPlan] {
        networkPlans.filter { $0.dataTypeCode == dataType }
    }

    var body: some View {
        FieldLabel(text: "Select Network")
        ChipRow(options: Array(viewModel.dataNetworks.keys).sorted(), labelOf: { $0 }, selected: network) {
            network = $0; userPicked = true; dataType = nil; plan = nil
        }

        FieldLabel(text: "Phone Number")
        TextField("080X XXX XXXX", text: $phone)
            .keyboardType(.phonePad)
            .textFieldStyle(.roundedBorder)
            .onChange(of: phone) { newValue in
                phone = String(newValue.filter(\.isNumber).prefix(11))
                if phone.count == 11 { viewModel.detectNetwork(phone: phone) }
            }

        FieldLabel(text: "Data Type")
        ChipRow(options: dataTypes, labelOf: { $0.replacingOccurrences(of: "-", with: " ").capitalized }, selected: dataType) {
            dataType = $0; plan = nil
        }

        SimpleDropdown(
            label: "Choose a Plan",
            options: filteredPlans,
            labelOf: { "\($0.productName) — ₦\($0.amount) (\($0.duration))" },
            selected: plan,
            placeholder: dataType == nil ? "Select a data type first" : "Select a plan",
            onSelect: { plan = $0 }
        )

        EmailReceiptField(email: $email)

        let amt = plan.flatMap { Double($0.amount) }.map { Int($0) } ?? 0
        let ready = network != nil && phone.count == 11 && plan != nil
        PayButton(amount: amt, enabled: ready, loading: viewModel.checkoutState == .loading) {
            viewModel.startCheckout(
                service: "data",
                recipient: phone,
                fields: [
                    "network": network?.lowercased(),
                    "phone_number": phone,
                    "type": plan?.dataTypeCode,
                    "quantity": plan?.productCode,
                    "email": email.isEmpty ? nil : email,
                ]
            )
        }
        .onChange(of: viewModel.detectedNetwork) { detected in
            if !userPicked, let detected { network = detected.uppercased() }
        }
    }
}
