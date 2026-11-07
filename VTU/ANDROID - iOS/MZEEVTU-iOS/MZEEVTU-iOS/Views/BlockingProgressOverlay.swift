import SwiftUI

/// Full-screen, non-interactive processing overlay for purchase/processing operations.
///
/// Covers the entire screen with a dim layer and a centered card (spinner + status message)
/// whenever `isVisible` is true. The overlay blocks all interaction underneath (so the user
/// can't tap "Buy" twice) and is dismissed automatically when the operation resolves.
///
/// Usage:
///   .blockingProgress(isVisible: isProcessing, message: "Processing your purchase...")
struct BlockingProgressOverlay: ViewModifier {
    let isVisible: Bool
    var message: String = "Processing..."

    func body(content: Content) -> some View {
        content
            .overlay(
                ZStack {
                    if isVisible {
                        // Dim, full-screen layer that also swallows taps.
                        Color.black.opacity(0.45)
                            .ignoresSafeArea()
                            .transition(.opacity)

                        // Centered status card.
                        VStack(spacing: 14) {
                            ProgressView()
                                .progressViewStyle(CircularProgressViewStyle(tint: Color.accentColor))
                                .scaleEffect(1.5)
                                .padding(.top, 4)

                            Text(message)
                                .font(.headline)
                                .foregroundColor(.primary)

                            Text("Please wait, do not close the app")
                                .font(.caption)
                                .foregroundColor(.secondary)
                                .padding(.bottom, 4)
                        }
                        .padding(32)
                        .background(
                            RoundedRectangle(cornerRadius: 20, style: .continuous)
                                .fill(Color(.systemBackground))
                        )
                        .shadow(color: .black.opacity(0.25), radius: 24, x: 0, y: 12)
                        .padding(32)
                        .transition(.opacity)
                    }
                }
                .animation(.easeInOut(duration: 0.2), value: isVisible)
                .allowsHitTesting(isVisible)
            )
    }
}

extension View {
    /// Shows a full-screen blocking "Processing…" overlay while `isVisible` is true.
    func blockingProgress(isVisible: Bool, message: String = "Processing...") -> some View {
        self.modifier(BlockingProgressOverlay(isVisible: isVisible, message: message))
    }
}
