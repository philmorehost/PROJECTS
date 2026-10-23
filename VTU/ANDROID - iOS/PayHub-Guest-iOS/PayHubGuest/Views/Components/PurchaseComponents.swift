import SwiftUI

struct FieldLabel: View {
    let text: String
    var body: some View {
        Text(text)
            .font(.system(size: 12, weight: .semibold))
            .foregroundColor(PHColor.text2)
            .padding(.top, 14)
            .padding(.bottom, 6)
            .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// Optional receipt-email field shared by every purchase form — checkout-init.php stores it
/// with the order and fulfill.php emails the receipt there after successful delivery.
struct EmailReceiptField: View {
    @Binding var email: String
    var body: some View {
        FieldLabel(text: "Email (optional — we'll send your receipt here)")
        TextField("you@example.com", text: $email)
            .keyboardType(.emailAddress)
            .textInputAutocapitalization(.never)
            .textFieldStyle(.roundedBorder)
    }
}

struct ChipRow<T: Hashable>: View {
    let options: [T]
    let labelOf: (T) -> String
    let selected: T?
    let onSelect: (T) -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(options, id: \.self) { opt in
                    let isSelected = opt == selected
                    Text(labelOf(opt))
                        .font(.system(size: 13, weight: .semibold))
                        .foregroundColor(isSelected ? .white : PHColor.primary)
                        .padding(.horizontal, 16)
                        .padding(.vertical, 10)
                        .background(isSelected ? PHColor.primary : PHColor.primaryLight)
                        .clipShape(Capsule())
                        .onTapGesture { onSelect(opt) }
                }
            }
        }
    }
}

struct AmountField: View {
    let label: String
    let amountChips: [Int]
    @Binding var amount: String

    var body: some View {
        FieldLabel(text: label)
        Text("₦\(amount.isEmpty ? "0" : amount)")
            .font(.system(size: 22, weight: .heavy))
            .foregroundColor(PHColor.text)
            .padding(.bottom, 10)
            .frame(maxWidth: .infinity, alignment: .leading)
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(amountChips, id: \.self) { value in
                    let isSelected = amount == String(value)
                    Text("₦\(value)")
                        .font(.system(size: 12, weight: .semibold))
                        .foregroundColor(isSelected ? .white : PHColor.primary)
                        .padding(.horizontal, 14)
                        .padding(.vertical, 8)
                        .background(isSelected ? PHColor.primary : PHColor.primaryLight)
                        .clipShape(Capsule())
                        .onTapGesture { amount = String(value) }
                }
            }
        }
        TextField("Or enter custom amount", text: $amount)
            .keyboardType(.numberPad)
            .textFieldStyle(.roundedBorder)
            .padding(.top, 10)
            .onChange(of: amount) { newValue in
                amount = newValue.filter { $0.isNumber }
            }
    }
}

struct SimpleDropdown<T: Hashable>: View {
    let label: String
    let options: [T]
    let labelOf: (T) -> String
    let selected: T?
    let placeholder: String
    let onSelect: (T) -> Void

    var body: some View {
        FieldLabel(text: label)
        Menu {
            ForEach(options, id: \.self) { opt in
                Button(labelOf(opt)) { onSelect(opt) }
            }
        } label: {
            HStack {
                Text(selected.map(labelOf) ?? placeholder)
                    .foregroundColor(selected == nil ? .secondary : PHColor.text)
                    .lineLimit(1)
                Spacer()
                Image(systemName: "chevron.down").foregroundColor(.secondary)
            }
            .padding(12)
            .background(RoundedRectangle(cornerRadius: 12).stroke(Color(.systemGray4)))
        }
    }
}

struct VerifyRow: View {
    @Binding var value: String
    let placeholder: String
    let verifying: Bool
    let onVerify: () -> Void

    var body: some View {
        HStack {
            TextField(placeholder, text: $value)
                .keyboardType(.numberPad)
                .textFieldStyle(.roundedBorder)
                .onChange(of: value) { newValue in
                    value = newValue.filter { $0.isNumber }
                }
            Button(action: onVerify) {
                Text(verifying ? "…" : "Verify")
                    .font(.system(size: 13, weight: .bold))
                    .foregroundColor(PHColor.primary)
                    .padding(.horizontal, 16)
                    .padding(.vertical, 14)
                    .background(PHColor.primaryLight)
                    .cornerRadius(12)
            }
            .disabled(verifying)
        }
    }
}

struct VerifiedCard: View {
    let name: String
    let sub: String?

    var body: some View {
        HStack(spacing: 10) {
            Circle().fill(PHColor.success).frame(width: 36, height: 36)
            VStack(alignment: .leading, spacing: 2) {
                Text(name).font(.system(size: 13, weight: .bold)).foregroundColor(PHColor.text)
                Text(sub ?? "Verified customer").font(.system(size: 11)).foregroundColor(PHColor.text2)
            }
            Spacer()
        }
        .padding(12)
        .background(PHColor.successBg)
        .cornerRadius(14)
    }
}

struct PayButton: View {
    let amount: Int
    let enabled: Bool
    let loading: Bool
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            Text(loading ? "Please wait…" : "Pay ₦\(amount)")
                .font(.system(size: 16, weight: .bold))
                .foregroundColor(.white)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 14)
        }
        .background(enabled && !loading ? PHColor.primary : Color.gray.opacity(0.4))
        .cornerRadius(16)
        .disabled(!enabled || loading)
        .padding(.top, 20)
    }
}
