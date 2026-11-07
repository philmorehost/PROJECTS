import SwiftUI
import Speech
import AVFoundation

struct AIParsedResponse: Codable {
    let success: Bool?
    let error: String?
    let intent: [String: String]?
    let response: String?
    let needs_confirmation: Bool?
}

struct AIChatView: View {
    @State private var prompt: String = ""
    @State private var chatHistory: [ChatMessage] = []
    @State private var isLoading: Bool = false
    @State private var showingConfirmation = false
    @State private var pendingIntent: [String: String]?
    
    @Environment(\.presentationMode) var presentationMode
    
    // Speech Recognition properties
    @State private var isRecording = false
    private let speechRecognizer = SFSpeechRecognizer(locale: Locale(identifier: "en-US"))
    @State private var recognitionRequest: SFSpeechAudioBufferRecognitionRequest?
    @State private var recognitionTask: SFSpeechRecognitionTask?
    private let audioEngine = AVAudioEngine()
    
    struct ChatMessage: Identifiable {
        let id = UUID()
        let isUser: Bool
        let text: String
    }
    
    var body: some View {
        NavigationView {
            VStack {
                ScrollViewReader { proxy in
                    ScrollView {
                        VStack(spacing: 12) {
                            ForEach(chatHistory) { message in
                                HStack {
                                    if message.isUser { Spacer() }
                                    Text(message.text)
                                        .padding(12)
                                        .background(message.isUser ? Color.blue : Color(UIColor.secondarySystemBackground))
                                        .foregroundColor(message.isUser ? .white : .primary)
                                        .cornerRadius(16)
                                    if !message.isUser { Spacer() }
                                }
                                .padding(.horizontal)
                                .id(message.id)
                            }
                        }
                        .padding(.vertical)
                    }
                    .onChange(of: chatHistory.count) { _ in
                        withAnimation {
                            if let last = chatHistory.last {
                                proxy.scrollTo(last.id, anchor: .bottom)
                            }
                        }
                    }
                }
                
                if isLoading {
                    ProgressView("AI is thinking...")
                        .padding()
                }

                HStack {
                    TextField(isRecording ? "Listening..." : "Ask me anything...", text: $prompt)
                        .padding(12)
                        .background(Color(UIColor.systemGray6))
                        .cornerRadius(20)
                        .disabled(isLoading)
                    
                    Button(action: toggleRecording) {
                        Image(systemName: isRecording ? "mic.fill" : "mic")
                            .foregroundColor(.white)
                            .padding(12)
                            .background(isRecording ? Color.red : Color.blue)
                            .clipShape(Circle())
                    }
                    .disabled(isLoading)
                    
                    Button(action: sendMessage) {
                        Image(systemName: "paperplane.fill")
                            .foregroundColor(.white)
                            .padding()
                            .background(prompt.isEmpty ? Color.gray : Color.blue)
                            .clipShape(Circle())
                    }
                    .disabled(prompt.isEmpty || isLoading)
                }
                .padding()
            }
            .navigationTitle("AI Assistant")
            .navigationBarTitleDisplayMode(.inline)
            .navigationBarItems(trailing: Button("Close") {
                if isRecording { stopRecording() }
                presentationMode.wrappedValue.dismiss()
            })
            .onAppear {
                appendLog(isUser: false, text: "Hello! I am your AI Assistant. You can use your voice or type to perform transactions.")
                SFSpeechRecognizer.requestAuthorization { _ in }
            }
            .alert(isPresented: $showingConfirmation) {
                Alert(
                    title: Text("Confirm Transaction"),
                    message: Text(getTransactionSummary()),
                    primaryButton: .default(Text("Proceed")) {
                        if let intent = pendingIntent { executeTransaction(intent) }
                    },
                    secondaryButton: .cancel()
                )
            }
        }
    }
    
    func appendLog(isUser: Bool, text: String) {
        chatHistory.append(ChatMessage(isUser: isUser, text: text))
    }

    func sendMessage() {
        if isRecording { stopRecording() }
        let userMsg = prompt.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !userMsg.isEmpty else { return }
        
        appendLog(isUser: true, text: userMsg)
        prompt = ""
        isLoading = true
        
        let params: [String: Any] = ["voice_text": userMsg]
        
        AppNetworkService.shared.requestJSON("api/app-backend/ai-intent-parser.php", params: params) { (result: Result<AIParsedResponse, Error>) in
            DispatchQueue.main.async {
                self.isLoading = false
                switch result {
                case .success(let response):
                    if response.success == true {
                        if let intent = response.intent, let service = intent["service"] {
                            self.pendingIntent = intent
                            if response.needs_confirmation == false {
                                self.executeTransaction(intent)
                            } else {
                                self.showingConfirmation = true
                            }
                        } else if let aiResponse = response.response {
                            self.appendLog(isUser: false, text: aiResponse)
                        }
                    } else {
                        self.appendLog(isUser: false, text: "Error: \(response.error ?? "Unknown error")")
                    }
                case .failure(let error):
                    self.appendLog(isUser: false, text: "Network Error: \(error.localizedDescription)")
                }
            }
        }
    }
    
    func getTransactionSummary() -> String {
        guard let intent = pendingIntent else { return "" }
        let service = intent["service"] ?? ""
        let amount = intent["amount"] ?? "0"
        let phone = intent["phone"] ?? ""
        let network = intent["network"] ?? ""
        return "Purchase \(network) \(service) of ₦\(amount) for \(phone)?"
    }

    func executeTransaction(_ intent: [String: String]) {
        self.isLoading = true
        let service = intent["service"] ?? ""
        let endpoint = service + ".php" // Correct extension
        
        AppNetworkService.shared.request(endpoint, params: intent) { (result: Result<APIResponse, Error>) in
            DispatchQueue.main.async {
                self.isLoading = false
                self.isLoading = false
                switch result {
                case .success(let response):
                    let status = response.status ?? ""
                    let msg = response.desc ?? response.message ?? "Processed"
                    
                    if status == "pending" {
                        self.appendLog(isUser: false, text: "AI Result: ⏳ \(msg) (Processing...)")
                    } else if status == "success" {
                        self.appendLog(isUser: false, text: "AI Result: ✅ \(msg)")
                    } else {
                        self.appendLog(isUser: false, text: "AI Result: \(msg)")
                    }
                case .failure(let error):
                    self.appendLog(isUser: false, text: "Execution Error: \(error.localizedDescription)")
                }
            }
        }
    }

    // Speech logic remains mostly the same but updated for better integration
    func toggleRecording() {
        if isRecording {
            stopRecording()
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) {
                if !prompt.isEmpty { sendMessage() }
            }
        } else {
            do { try startRecording() } catch { print(error) }
        }
    }
    
    func startRecording() throws {
        recognitionTask?.cancel()
        self.recognitionTask = nil
        let audioSession = AVAudioSession.sharedInstance()
        try audioSession.setCategory(.record, mode: .measurement, options: .duckOthers)
        try audioSession.setActive(true, options: .notifyOthersOnDeactivation)
        let inputNode = audioEngine.inputNode
        recognitionRequest = SFSpeechAudioBufferRecognitionRequest()
        guard let recognitionRequest = recognitionRequest else { return }
        recognitionRequest.shouldReportPartialResults = true
        recognitionTask = speechRecognizer?.recognitionTask(with: recognitionRequest) { result, error in
            if let result = result { self.prompt = result.bestTranscription.formattedString }
            if error != nil || result?.isFinal == true {
                self.audioEngine.stop()
                inputNode.removeTap(onBus: 0)
                self.isRecording = false
            }
        }
        let recordingFormat = inputNode.outputFormat(forBus: 0)
        inputNode.installTap(onBus: 0, bufferSize: 1024, format: recordingFormat) { buffer, _ in
            self.recognitionRequest?.append(buffer)
        }
        audioEngine.prepare()
        try audioEngine.start()
        isRecording = true
    }
    
    func stopRecording() {
        audioEngine.stop()
        recognitionRequest?.endAudio()
        isRecording = false
    }
}
