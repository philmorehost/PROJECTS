import SwiftUI

private struct ServiceMeta {
    let title: String
    let subtitle: String
}

private let SERVICE_META: [String: ServiceMeta] = [
    "airtime": ServiceMeta(title: "Buy Airtime", subtitle: "Top up any network instantly"),
    "data": ServiceMeta(title: "Buy Data", subtitle: "SME, Shared, CG & Direct bundles"),
    "cable": ServiceMeta(title: "Cable TV", subtitle: "DStv, GOtv, Startimes & Showmax"),
    "electricity": ServiceMeta(title: "Electricity", subtitle: "Prepaid & postpaid tokens"),
    "exam": ServiceMeta(title: "Exam Pins", subtitle: "WAEC, NECO, NABTEB & JAMB"),
    "betting": ServiceMeta(title: "Betting", subtitle: "Fund your betting account"),
]

struct PurchaseView: View {
    let service: String
    @ObservedObject var viewModel: GuestViewModel

    private var meta: ServiceMeta {
        SERVICE_META[service] ?? ServiceMeta(title: service.capitalized, subtitle: "")
    }

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 14) {
                Circle().fill(Color.white.opacity(0.15)).frame(width: 36, height: 36)
                    .overlay(Image(systemName: "arrow.left").foregroundColor(.white))
                    .onTapGesture { viewModel.goBack() }
                VStack(alignment: .leading, spacing: 2) {
                    Text(meta.title).font(.system(size: 17, weight: .bold)).foregroundColor(.white)
                    Text(meta.subtitle).font(.system(size: 12)).foregroundColor(.white.opacity(0.85))
                }
                Spacer()
            }
            .padding(20)
            .background(LinearGradient(colors: [PHColor.primary, PHColor.primaryDark], startPoint: .leading, endPoint: .trailing))

            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    Group {
                        switch service {
                        case "airtime": AirtimeForm(viewModel: viewModel)
                        case "data": DataForm(viewModel: viewModel)
                        case "cable": CableForm(viewModel: viewModel)
                        case "electricity": ElectricityForm(viewModel: viewModel)
                        case "exam": ExamForm(viewModel: viewModel)
                        case "betting": BettingForm(viewModel: viewModel)
                        default: EmptyView()
                        }
                    }

                    if case .failed(let message) = viewModel.checkoutState {
                        Text(message).font(.system(size: 12)).foregroundColor(PHColor.error).padding(.top, 12)
                    }
                }
                .padding(.horizontal, 20)
                .padding(.bottom, 24)
            }
        }
        .onAppear {
            viewModel.loadCatalog(service)
            viewModel.resetVerify()
            viewModel.resetCheckout()
        }
        .onChange(of: viewModel.checkoutState) { state in
            if case .ready = state { viewModel.navigate(to: .checkout) }
        }
    }
}
