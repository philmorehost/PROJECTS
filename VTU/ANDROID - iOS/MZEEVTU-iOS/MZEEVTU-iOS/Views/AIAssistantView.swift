import SwiftUI

struct Message: Identifiable, Equatable {
    let id = UUID()
    let text: String
    let isUser: Bool
    var isError: Bool = false
    let timestamp = Date()
}

let quickPrompts: [(label: String, prompt: String)] = [
    ("⚡ Airtime", "Buy MTN Airtime for 08061234567"),
    ("📶 Data", "Buy 2GB MTN Data for 08061234567"),
    ("💡 Electricity", "Pay Electricity Bill for meter 12345678901"),
    ("📺 Cable", "Renew GOtv Subscription for smartcard 1234567890"),
    ("🎲 Betting", "Fund my Bet9ja account with ID 12345678"),
    ("📝 Exam PIN", "Buy WAEC Result Checker PIN")
]

struct AIAssistantView: View {
    @Environment(\.presentationMode) var presentationMode
    @State private var messages: [Message] = [
        Message(text: "Hello! I am your AI VTU Assistant. How can I help you manage your VTU business today?", isUser: false)
    ]
    @State private var inputText: String = ""
    @State private var isLoading: Bool = false
    @State private var errorMessage: String? = nil

    var body: some View {
        NavigationView {
            ZStack {
                // Premium soft gradient background
                LinearGradient(gradient: Gradient(colors: [Color(hex: "F8FAFC"), Color(hex: "EEF2F6")]), startPoint: .top, endPoint: .bottom)
                    .edgesIgnoringSafeArea(.all)
                
                VStack(spacing: 0) {
                    // Chat Messages list
                    ScrollViewReader { proxy in
                        ScrollView {
                            VStack(spacing: 12) {
                                ForEach(messages) { message in
                                    ChatBubble(message: message)
                                        .id(message.id)
                                }
                                
                                if isLoading {
                                    HStack {
                                        ProgressView()
                                            .padding(12)
                                            .background(Color(hex: "E2E8F0"))
                                            .cornerRadius(16)
                                        Spacer()
                                    }
                                }
                            }
                            .padding()
                        }
                        .onChange(of: messages) { _ in
                            if let last = messages.last {
                                withAnimation {
                                    proxy.scrollTo(last.id, anchor: .bottom)
                                }
                            }
                        }
                    }

                    // Quick-prompt chips
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 8) {
                            ForEach(quickPrompts, id: \.label) { chip in
                                Button(action: { inputText = chip.prompt }) {
                                    Text(chip.label)
                                        .font(.system(size: 13, weight: .medium))
                                        .foregroundColor(Color(hex: "0D6EFD"))
                                        .padding(.horizontal, 12)
                                        .padding(.vertical, 8)
                                        .overlay(
                                            RoundedRectangle(cornerRadius: 16)
                                                .stroke(Color(hex: "0D6EFD"), lineWidth: 1)
                                        )
                                }
                            }
                        }
                        .padding(.horizontal)
                    }
                    .padding(.vertical, 8)
                    .background(Color.white.opacity(0.9))

                    // Input View
                    HStack(spacing: 12) {
                        TextField("Type a request (e.g. Buy 1GB MTN...)", text: $inputText)
                            .padding(12)
                            .background(Color(hex: "FFFFFF"))
                            .cornerRadius(24)
                            .overlay(
                                RoundedRectangle(cornerRadius: 24)
                                    .stroke(Color(hex: "E2E8F0"), lineWidth: 1)
                            )
                        
                        Button(action: sendMessage) {
                            Image(systemName: "paperplane.fill")
                                .font(.system(size: 20))
                                .foregroundColor(.white)
                                .padding(12)
                                .background(Color(hex: "0D6EFD"))
                                .clipShape(Circle())
                        }
                        .disabled(inputText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || isLoading)
                    }
                    .padding()
                    .background(Color.white.opacity(0.9))
                }
            }
            .navigationTitle("AI Assistant")
            .navigationBarTitleDisplayMode(.inline)
            .navigationBarItems(leading: Button(action: {
                presentationMode.wrappedValue.dismiss()
            }) {
                Image(systemName: "chevron.left")
                    .foregroundColor(Color(hex: "0D6EFD"))
                    .imageScale(.large)
            })
        }
    }

    private func sendMessage() {
        let trimmed = inputText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }
        
        let userMsg = Message(text: trimmed, isUser: true)
        messages.append(userMsg)
        inputText = ""
        isLoading = true
        errorMessage = nil
        
        AIService.shared.fetchAIResponse(prompt: trimmed) { result in
            isLoading = false
            switch result {
            case .success(let json):
                if json["status"] as? String == "success" {
                    let aiResponse = json["response"] as? String ?? "No response received"
                    messages.append(Message(text: aiResponse, isUser: false))
                } else {
                    let errMsg = json["message"] as? String ?? "Failed to get response"
                    messages.append(Message(text: "⚠️ Error: \(errMsg)", isUser: false, isError: true))
                }
            case .failure(let error):
                let errMsg = error.localizedDescription
                messages.append(Message(text: "⚠️ Connection failed: \(errMsg)", isUser: false, isError: true))
            }
        }
    }
}

struct ChatBubble: View {
    let message: Message

    var body: some View {
        HStack {
            if message.isUser {
                Spacer()
                Text(message.text)
                    .padding(12)
                    .background(Color(hex: "0D6EFD"))
                    .foregroundColor(.white)
                    .cornerRadius(16)
                    .lineLimit(nil)
            } else {
                Text(message.text)
                    .padding(12)
                    .background(message.isError ? Color(hex: "FEE2E2") : Color(hex: "F1F5F9"))
                    .foregroundColor(message.isError ? Color(hex: "B91C1C") : Color(hex: "1E293B"))
                    .cornerRadius(16)
                    .lineLimit(nil)
                Spacer()
            }
        }
    }
}

// SwiftUI hex extension for custom brand color palettes
extension Color {
    init(hex: String) {
        let hex = hex.trimmingCharacters(in: CharacterSet.alphanumerics.inverted)
        var int: UInt64 = 0
        Scanner(string: hex).scanHexInt64(&int)
        let a, r, g, b: UInt64
        switch hex.count {
        case 3: // RGB (12-bit)
            (a, r, g, b) = (255, (int >> 8) * 17, (int >> 4 & 0xF) * 17, (int & 0xF) * 17)
        case 6: // RGB (24-bit)
            (a, r, g, b) = (255, int >> 16, int >> 8 & 0xFF, int & 0xFF)
        case 8: // ARGB (32-bit)
            (a, r, g, b) = (int >> 24, int >> 16 & 0xFF, int >> 8 & 0xFF, int & 0xFF)
        default:
            (a, r, g, b) = (1, 1, 1, 1)
        }
        self.init(
            .sRGB,
            red: Double(r) / 255,
            green: Double(g) / 255,
            blue:  Double(b) / 255,
            opacity: Double(a) / 255
        )
    }
}
