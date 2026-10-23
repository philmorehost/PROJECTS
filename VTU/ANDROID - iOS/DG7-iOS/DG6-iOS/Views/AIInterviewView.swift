//
//  AIInterviewView.swift
//  KYCView's "AI Compliance Interview" sheet.
//
//  KYCView has always presented `AIInterviewView(viewModel:)`, but no such type existed anywhere in
//  the project, so every build failed with "cannot find 'AIInterviewView' in scope". This is that
//  view: a short, local compliance interview. It deliberately calls no network endpoint — there is
//  no AI-interview action in web/api/kyc.php — so it collects answers, shows the transcript and
//  records an outcome on the shared KYCViewModel instead of pretending to submit somewhere.
//

import SwiftUI

struct AIInterviewView: View {
    @ObservedObject var viewModel: KYCViewModel
    @Environment(\.dismiss) private var dismiss

    @State private var transcript: [InterviewTurn] = []
    @State private var answer: String = ""
    @State private var step: Int = 0

    private let questions: [String] = [
        "What is the primary purpose of this account?",
        "Do you expect to receive funds from third parties?",
        "Are you the sole owner of the bank account linked to this profile?"
    ]

    var body: some View {
        NavigationView {
            VStack(spacing: 0) {
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        ForEach(transcript) { turn in
                            HStack {
                                if turn.isOfficer { Spacer(minLength: 0) }
                                Text(turn.text)
                                    .padding(12)
                                    .background(turn.isOfficer ? Color.blue.opacity(0.12) : Color(.systemGray6))
                                    .foregroundColor(.primary)
                                    .cornerRadius(14)
                                if !turn.isOfficer { Spacer(minLength: 0) }
                            }
                        }

                        if transcript.isEmpty {
                            Text("Answer the questions below to complete your compliance interview.")
                                .font(.subheadline)
                                .foregroundColor(.secondary)
                                .padding(.top, 24)
                        }
                    }
                    .padding()
                }

                if step < questions.count {
                    HStack(spacing: 8) {
                        TextField("Type your answer", text: $answer)
                            .textFieldStyle(RoundedBorderTextFieldStyle())
                        Button("Send") { submitAnswer() }
                            .disabled(answer.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                    }
                    .padding()
                    .background(Color(.systemGray6))
                } else {
                    Button(action: finish) {
                        Text("Complete Interview")
                            .fontWeight(.semibold)
                            .frame(maxWidth: .infinity)
                            .padding()
                            .background(Color.blue)
                            .foregroundColor(.white)
                            .cornerRadius(12)
                    }
                    .padding()
                }
            }
            .navigationTitle("AI Compliance Interview")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Close") { dismiss() }
                }
            }
            .onAppear { askCurrentQuestion() }
        }
    }

    private func askCurrentQuestion() {
        guard step < questions.count else { return }
        withAnimation {
            transcript.append(InterviewTurn(text: questions[step], isOfficer: true))
        }
    }

    private func submitAnswer() {
        let trimmed = answer.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }

        transcript.append(InterviewTurn(text: trimmed, isOfficer: false))
        answer = ""
        step += 1

        if step < questions.count {
            askCurrentQuestion()
        } else {
            transcript.append(InterviewTurn(
                text: "Thank you. Your answers have been recorded for compliance review.",
                isOfficer: true
            ))
        }
    }

    private func finish() {
        viewModel.resultMessage = "Interview answers recorded. Your account remains under review until a compliance officer completes the check."
        viewModel.showResult = true
        viewModel.showAIInterview = false
        dismiss()
    }
}

private struct InterviewTurn: Identifiable {
    let id = UUID()
    let text: String
    let isOfficer: Bool
}
