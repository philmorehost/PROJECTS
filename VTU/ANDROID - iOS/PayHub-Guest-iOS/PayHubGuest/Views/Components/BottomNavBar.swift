import SwiftUI

enum GuestTab: CaseIterable, Hashable {
    case home, services, history, support

    var icon: String {
        switch self {
        case .home: return "house.fill"
        case .services: return "square.grid.2x2.fill"
        case .history: return "clock.arrow.circlepath"
        case .support: return "headphones"
        }
    }

    var screen: GuestViewModel.Screen {
        switch self {
        case .home: return .home
        case .services: return .services
        case .history: return .history
        case .support: return .support
        }
    }
}

/// Dark bar background + gradient bubble colors, matching scratch/payhub-guest-app-mockup.html
/// exactly (#0b0b0f bar, #8b5cf6 -> #3b82f6 bubble) — intentionally not theme-driven colors.
private let NavBarBg = Color(hex: 0x0B0B0F)
private let BubbleStart = Color(hex: 0x8B5CF6)
private let BubbleEnd = Color(hex: 0x3B82F6)
private let BubbleGlow = Color(hex: 0x6366F1)

/// Wraps the dark bar and the floating bubble in one GeometryReader so both agree on the same
/// x-coordinate for the active tab — the SwiftUI analog of the Android rebuild's BoxWithConstraints.
/// Use this (not BottomNavBar directly) from ContentView.swift.
struct GuestBottomNavArea: View {
    let current: GuestTab
    let onSelect: (GuestTab) -> Void

    var body: some View {
        GeometryReader { geo in
            let tabIndex = CGFloat(GuestTab.allCases.firstIndex(of: current) ?? 0)
            let tabCount = CGFloat(GuestTab.allCases.count)
            let notchX = geo.size.width * (tabIndex + 0.5) / tabCount

            ZStack(alignment: .topLeading) {
                BottomNavBar(current: current, notchX: notchX, onSelect: onSelect)
                GuestNavBubble(current: current, bubbleX: notchX)
            }
            .animation(.interpolatingSpring(mass: 1, stiffness: 180, damping: 12), value: current)
        }
        .frame(height: 62)
    }
}

/// The dark floating pill with an animated circular notch punched into its top edge at the
/// active tab's x-position. Reproduces the mockup's `mask-image: radial-gradient(...)` notch
/// using `.blendMode(.destinationOut)` inside a `.compositingGroup()` — the standard SwiftUI
/// idiom for "punch a hole in one shape using another," and safer here than a custom Shape with
/// Path boolean subtraction, which needs newer iOS SDK features this project can't verify
/// against locally (no Xcode/simulator in this environment).
private struct BottomNavBar: View {
    let current: GuestTab
    let notchX: CGFloat
    let onSelect: (GuestTab) -> Void

    var body: some View {
        ZStack {
            RoundedRectangle(cornerRadius: 31)
                .fill(NavBarBg)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            Circle()
                .fill(Color.black)
                .frame(width: 60, height: 60)
                .position(x: notchX, y: -6)
                .blendMode(.destinationOut)
        }
        .compositingGroup()
        .frame(height: 62)
        .shadow(color: Color.black.opacity(0.4), radius: 14, y: 6)
        .overlay(
            HStack(spacing: 0) {
                ForEach(GuestTab.allCases, id: \.self) { tab in
                    Button(action: { onSelect(tab) }) {
                        // The active tab's icon is hidden here (mockup: opacity:0) since the
                        // floating bubble shows it instead — kept in layout for stable spacing.
                        Image(systemName: tab.icon)
                            .foregroundColor(.white)
                            .opacity(tab == current ? 0 : 1)
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    }
                }
            }
        )
    }
}

/// The floating gradient bubble showing the active tab's icon. Must be rendered as a SIBLING of
/// BottomNavBar (same parent ZStack in GuestBottomNavArea), never nested inside it — otherwise
/// the bar's own notch-punch compositing would clip the bubble too, exactly the bug the mockup's
/// own CSS comment warns about for `.nav-bubble`. Decorative only: `allowsHitTesting(false)`
/// mirrors the mockup's `pointer-events:none` (taps pass through to the real tab button beneath).
private struct GuestNavBubble: View {
    let current: GuestTab
    let bubbleX: CGFloat

    var body: some View {
        Circle()
            .fill(LinearGradient(colors: [BubbleStart, BubbleEnd], startPoint: .topLeading, endPoint: .bottomTrailing))
            .frame(width: 50, height: 50)
            .overlay(Circle().stroke(PHColor.bg, lineWidth: 5))
            .overlay(Image(systemName: current.icon).foregroundColor(.white))
            .shadow(color: BubbleGlow.opacity(0.65), radius: 11, y: 5)
            .position(x: bubbleX, y: -10)
            .allowsHitTesting(false)
    }
}

/// Thin decorative bar under the floating nav, matching the mockup's iOS-style home indicator.
struct GuestHomeIndicator: View {
    var body: some View {
        RoundedRectangle(cornerRadius: 2)
            .fill(Color.black.opacity(0.25))
            .frame(width: 120, height: 4)
    }
}
