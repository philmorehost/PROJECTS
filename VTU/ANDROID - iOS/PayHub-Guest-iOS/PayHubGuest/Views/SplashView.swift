import SwiftUI

struct SplashView: View {
    let onContinue: () -> Void

    var body: some View {
        ZStack {
            LinearGradient(colors: [PHColor.primary, PHColor.primaryDark], startPoint: .top, endPoint: .bottom)
                .ignoresSafeArea()
                .onTapGesture { onContinue() }

            VStack(spacing: 6) {
                RoundedRectangle(cornerRadius: 22)
                    .fill(Color.white.opacity(0.15))
                    .frame(width: 76, height: 76)
                    .overlay(Image(systemName: "wallet.pass.fill").font(.system(size: 32)).foregroundColor(.white))
                    .padding(.bottom, 12)
                Text("PayHub").font(.system(size: 32, weight: .heavy)).foregroundColor(.white)
                Text("Instant top-ups, no login needed.")
                    .font(.system(size: 14))
                    .foregroundColor(.white.opacity(0.85))
                    .padding(.bottom, 26)
                ProgressView().tint(.white)
                Text("Tap anywhere to continue")
                    .font(.system(size: 11))
                    .foregroundColor(.white.opacity(0.6))
                    .padding(.top, 20)
            }
        }
        .task {
            try? await Task.sleep(nanoseconds: 1_400_000_000)
            onContinue()
        }
    }
}
