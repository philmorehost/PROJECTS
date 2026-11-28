import SwiftUI

struct SecurityQuestBankItem: Identifiable, Hashable {
    let id: Int
    let quest: String
}

@MainActor
struct SecurityQuestSetupView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @State private var questions: [SecurityQuestBankItem] = []
    @State private var selectedQuestId: Int = 0
    @State private var answer = ""
    @State private var isLoading = true
    @State private var isSubmitting = false
    @State private var errorMessage: String?

    var onComplete: () -> Void
    var onLogout: () -> Void

    var body: some View {
        VStack(spacing: 20) {
            Spacer()

            Image(systemName: "questionmark.shield.fill")
                .resizable()
                .scaledToFit()
                .frame(width: 68, height: 68)
                .foregroundColor(brandTheme.ink)

            Text("Set Security Question")
                .font(.title2.bold())
                .multilineTextAlignment(.center)

            Text("Choose a security question and memorable answer (3–20 characters) to protect your account and confirm sensitive actions.")
                .font(.subheadline)
                .foregroundColor(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal)

            if let error = errorMessage {
                Text(error)
                    .foregroundColor(.red)
                    .font(.caption)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal)
            }

            if isLoading {
                ProgressView("Loading questions...")
                    .padding()
            } else {
                VStack(alignment: .leading, spacing: 12) {
                    if !questions.isEmpty {
                        Picker("Security Question", selection: $selectedQuestId) {
                            ForEach(questions) { q in
                                Text(q.quest).tag(q.id)
                            }
                        }
                        .pickerStyle(.menu)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(10)
                        .background(Color(.systemGray6))
                        .cornerRadius(10)
                    }

                    TextField("Your Answer (3–20 characters)", text: $answer)
                        .textFieldStyle(RoundedBorderTextFieldStyle())
                        .autocapitalization(.none)
                }
                .padding(.horizontal)

                Button(action: submit) {
                    if isSubmitting {
                        ProgressView()
                            .progressViewStyle(CircularProgressViewStyle(tint: brandTheme.onPrimary))
                    } else {
                        Text("Save Security Question")
                            .fontWeight(.bold)
                            .frame(maxWidth: .infinity)
                    }
                }
                .padding()
                .background(canSubmit ? brandTheme.primary : Color.gray.opacity(0.5))
                .foregroundColor(canSubmit ? brandTheme.onPrimary : brandTheme.onSurface)
                .cornerRadius(12)
                .padding(.horizontal)
                .disabled(!canSubmit || isSubmitting)
            }

            Button("Log out") { onLogout() }
                .font(.footnote)
                .foregroundColor(.secondary)

            Spacer()
            Spacer()
        }
        .padding()
        .onAppear(perform: loadQuestions)
    }

    private var canSubmit: Bool {
        let trimmed = answer.trimmingCharacters(in: .whitespacesAndNewlines)
        return selectedQuestId > 0 && trimmed.count >= 3 && trimmed.count <= 20
    }

    private func loadQuestions() {
        isLoading = true
        errorMessage = nil
        AppNetworkService.shared.requestObject("security-quest.php", params: ["action": "get"]) { result in
            isLoading = false
            switch result {
            case .success(let json):
                let enabled = json["enabled"] as? Bool ?? true
                let hasQuest = bcBool(json["has_quest"])
                if !enabled {
                    OnboardingState.shared.securityQuestRequired = false
                    UserDefaults.standard.set(false, forKey: "onb_sq_required")
                    OnboardingState.shared.markSecurityQuestDone()
                    onComplete()
                    return
                }
                if hasQuest {
                    OnboardingState.shared.markSecurityQuestDone()
                    onComplete()
                    return
                }
                let bank = (json["quest_bank"] as? [[String: Any]] ?? []).compactMap { item -> SecurityQuestBankItem? in
                    let id = bcInt(item["id"])
                    let quest = bcString(item["quest"])
                    guard id > 0, !quest.isEmpty else { return nil }
                    return SecurityQuestBankItem(id: id, quest: quest)
                }
                questions = bank
                if selectedQuestId == 0, let first = bank.first {
                    selectedQuestId = first.id
                }
                if bank.isEmpty {
                    errorMessage = bcString(json["desc"]).isEmpty ? "Security questions are unavailable." : bcString(json["desc"])
                }
            case .failure(let err):
                errorMessage = err.localizedDescription
            }
        }
    }

    private func submit() {
        let trimmed = answer.trimmingCharacters(in: .whitespacesAndNewlines)
        guard selectedQuestId > 0, trimmed.count >= 3, trimmed.count <= 20 else {
            errorMessage = "Choose a question and enter an answer between 3 and 20 characters."
            return
        }
        isSubmitting = true
        errorMessage = nil
        AppNetworkService.shared.requestObject("security-quest.php", params: [
            "action": "set",
            "quest_id": "\(selectedQuestId)",
            "answer": trimmed
        ]) { result in
            isSubmitting = false
            switch result {
            case .success(let json):
                if bcString(json["status"]) == "success" {
                    OnboardingState.shared.markSecurityQuestDone()
                    onComplete()
                } else {
                    let desc = bcString(json["desc"])
                    errorMessage = desc.isEmpty ? "Could not save security question." : desc
                }
            case .failure(let err):
                errorMessage = err.localizedDescription
            }
        }
    }
}
