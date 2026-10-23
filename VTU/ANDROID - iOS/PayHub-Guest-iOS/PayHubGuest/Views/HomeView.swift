import SwiftUI

private struct QuickAction: Identifiable {
    let id = UUID()
    let label: String
    let icon: String
    let color: Color
    let onClick: () -> Void
}

struct HomeView: View {
    @ObservedObject var viewModel: GuestViewModel

    @State private var selected: GuestReceipt? = nil

    private var actions: [QuickAction] {
        GuestServiceCatalog.filterEnabled(viewModel.enabledServices).map { s in
            QuickAction(label: s.shortLabel, icon: s.icon, color: s.color) { viewModel.navigate(to: .purchase(s.key)) }
        } + [
            QuickAction(label: "History", icon: "clock.arrow.circlepath", color: PHColor.primary) { viewModel.setTab(.history) },
        ]
    }

    private let columns = Array(repeating: GridItem(.flexible(), spacing: 8), count: 4)

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                HStack {
                    HStack(spacing: 10) {
                        RoundedRectangle(cornerRadius: 11).fill(PHColor.primary).frame(width: 36, height: 36)
                            .overlay(Image(systemName: "wallet.pass.fill").foregroundColor(.white).font(.system(size: 14)))
                        Text("PayHub").font(.system(size: 18, weight: .heavy))
                    }
                    Spacer()
                    Circle().fill(Color.white).frame(width: 40, height: 40)
                        .overlay(Image(systemName: "bell").foregroundColor(PHColor.text2))
                        .shadow(color: .black.opacity(0.08), radius: 6)
                }
                .padding(.top, 24)

                VStack(alignment: .leading, spacing: 6) {
                    Text("Buy Airtime, Data & Bills — Instantly")
                        .font(.system(size: 18, weight: .bold)).foregroundColor(.white)
                    Text("Pay once, get instant delivery. No sign up, no wallet needed.")
                        .font(.system(size: 12)).foregroundColor(.white.opacity(0.85))
                    HStack(spacing: 6) {
                        trustChip("⚡ Instant Delivery")
                        trustChip("🛡 Secured by PayHub")
                    }
                    .padding(.top, 6)
                }
                .padding(20)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(LinearGradient(colors: [PHColor.primary, PHColor.primaryDark], startPoint: .leading, endPoint: .trailing))
                .cornerRadius(24)

                LazyVGrid(columns: columns, spacing: 16) {
                    ForEach(actions) { action in
                        VStack(spacing: 6) {
                            RoundedRectangle(cornerRadius: 16).fill(action.color).frame(width: 52, height: 52)
                                .overlay(Image(systemName: action.icon).foregroundColor(.white))
                            Text(action.label).font(.system(size: 11, weight: .semibold)).foregroundColor(PHColor.text)
                        }
                        .onTapGesture(perform: action.onClick)
                    }
                }
                .padding(.top, 8)

                if !viewModel.transactionHistory.isEmpty {
                    HStack {
                        Text("Recent Transactions").font(.system(size: 15, weight: .bold)).foregroundColor(PHColor.text)
                        Spacer()
                        Text("See All")
                            .font(.system(size: 12, weight: .semibold))
                            .foregroundColor(PHColor.primary)
                            .onTapGesture { viewModel.setTab(.history) }
                    }
                    .padding(.top, 12)

                    VStack(spacing: 8) {
                        ForEach(Array(viewModel.transactionHistory.prefix(3))) { receipt in
                            RecentTransactionRow(receipt: receipt) { selected = receipt }
                        }
                    }
                }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 100)
        }
        .refreshable { await viewModel.refresh() }
        .onAppear { viewModel.refreshPendingHistory() }
        .sheet(item: $selected) { receipt in
            ReceiptDetailSheet(receipt: receipt) { selected = nil }
        }
    }

    private func trustChip(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 10, weight: .semibold))
            .foregroundColor(.white)
            .padding(.horizontal, 10)
            .padding(.vertical, 5)
            .background(Color.white.opacity(0.18))
            .clipShape(Capsule())
    }
}

private struct RecentTransactionRow: View {
    let receipt: GuestReceipt
    let onTap: () -> Void

    private var entry: GuestServiceEntry? {
        GuestServiceCatalog.all.first { $0.key == receipt.service }
    }

    private var statusColor: Color {
        switch receipt.status {
        case "success": return PHColor.success
        case "pending", "processing": return Color(hex: 0xF59E0B)
        default: return PHColor.error
        }
    }

    private var statusLabel: String {
        switch receipt.status {
        case "success": return "Successful"
        case "pending", "processing": return "Pending"
        default: return "Failed"
        }
    }

    var body: some View {
        HStack(spacing: 10) {
            RoundedRectangle(cornerRadius: 11)
                .fill(entry?.bg ?? PHColor.text2.opacity(0.12))
                .frame(width: 38, height: 38)
                .overlay(
                    Group {
                        if let entry { Image(systemName: entry.icon).font(.system(size: 14)).foregroundColor(entry.color) }
                    }
                )
            VStack(alignment: .leading, spacing: 2) {
                Text(entry?.title ?? receipt.service.capitalized).font(.system(size: 13, weight: .semibold)).foregroundColor(PHColor.text)
                Text(receipt.date.formatted(date: .abbreviated, time: .shortened)).font(.system(size: 11)).foregroundColor(PHColor.text2)
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 2) {
                Text(String(format: "₦%.0f", receipt.amountPaid)).font(.system(size: 13, weight: .bold)).foregroundColor(PHColor.text)
                Text(statusLabel).font(.system(size: 10, weight: .semibold)).foregroundColor(statusColor)
            }
        }
        .padding(12)
        .background(Color.white)
        .cornerRadius(14)
        .onTapGesture(perform: onTap)
    }
}
