import SwiftUI

struct ServicesView: View {
    @ObservedObject var viewModel: GuestViewModel
    private let columns = [GridItem(.flexible(), spacing: 12), GridItem(.flexible(), spacing: 12)]

    var body: some View {
        ScrollView {
            Text("All Services").font(.system(size: 20, weight: .bold)).padding(.vertical, 20)
                .frame(maxWidth: .infinity, alignment: .leading)

            LazyVGrid(columns: columns, spacing: 12) {
                ForEach(GuestServiceCatalog.filterEnabled(viewModel.enabledServices)) { s in
                    VStack(alignment: .leading, spacing: 4) {
                        RoundedRectangle(cornerRadius: 14).fill(s.color).frame(width: 44, height: 44)
                            .overlay(Image(systemName: s.icon).foregroundColor(.white))
                        Text(s.title).font(.system(size: 14, weight: .bold)).foregroundColor(PHColor.text).padding(.top, 6)
                        Text(s.caption).font(.system(size: 11)).foregroundColor(PHColor.text2)
                    }
                    .padding(16)
                    .frame(maxWidth: .infinity, minHeight: 130, alignment: .topLeading)
                    .background(s.bg)
                    .cornerRadius(20)
                    .onTapGesture { viewModel.navigate(to: .purchase(s.key)) }
                }
            }
            .padding(.bottom, 100)
        }
        .padding(.horizontal, 20)
        .refreshable { await viewModel.refresh() }
    }
}
