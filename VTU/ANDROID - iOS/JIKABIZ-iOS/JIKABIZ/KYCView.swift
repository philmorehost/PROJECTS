//
//  KYCView.swift
//  JIKABIZ-iOS
//
//  KYC Verification View
//

import SwiftUI
import UIKit
import AVFoundation
import UniformTypeIdentifiers
@MainActor

struct KYCView: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @StateObject private var viewModel = KYCViewModel()
    @Environment(\.dismiss) var dismiss
    
    var body: some View {
        ScrollView {
            VStack(spacing: 24) {
                    // Status Card
                    KYCStatusCard(viewModel: viewModel)

                    // Traditional KYC Methods
                    TraditionalKYCSection(viewModel: viewModel)
                    
                    KYCLiveCaptureSection(onAccepted: { viewModel.loadKYCStatus() })

                    // AI Interview Section
                    AIInterviewSection(viewModel: viewModel)
                }
                .padding()
            }
            .navigationTitle("Identity Verification (KYC)")
            .navigationBarTitleDisplayMode(.inline)

            .onAppear {
                viewModel.loadKYCStatus()
            }
            .alert("Verification Result", isPresented: $viewModel.showResult) {
                Button("OK") { viewModel.showResult = false }
            } message: {
                Text(viewModel.resultMessage)
            }
    }
}
@MainActor

struct KYCStatusCard: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @ObservedObject var viewModel: KYCViewModel
    
    var body: some View {
        VStack(spacing: 16) {
            Image(systemName: statusIcon)
                .font(.system(size: 60))
                .foregroundColor(statusColor)
            
            Text(statusTitle)
                .font(.title2)
                .fontWeight(.bold)
            
            Text(statusMessage)
                .font(.subheadline)
                .foregroundColor(.secondary)
                .multilineTextAlignment(.center)
            
            if viewModel.kycStatus == 2 {
                Label("Fully Verified", systemImage: "checkmark.seal.fill")
                    .font(.caption)
                    .foregroundColor(.green)
            } else if viewModel.kycStatus == 1 {
                Label("Under Review", systemImage: "clock.fill")
                    .font(.caption)
                    .foregroundColor(.orange)
            }
        }
        .frame(maxWidth: .infinity)
        .padding()
        .background(Color(.systemGray6))
        .cornerRadius(20)
    }
    
    var statusIcon: String {
        switch viewModel.kycStatus {
        case 2: return "checkmark.seal.fill"
        case 1: return "clock.fill"
        default: return "shield.lefthalf.filled"
        }
    }
    
    var statusColor: Color {
        switch viewModel.kycStatus {
        case 2: return .green
        case 1: return .orange
        default: return .blue
        }
    }
    
    var statusTitle: String {
        switch viewModel.kycStatus {
        case 2: return "Fully Verified"
        case 1: return "Under Review"
        default: return "Unverified"
        }
    }
    
    var statusMessage: String {
        switch viewModel.kycStatus {
        case 2: return "Your identity has been confirmed. You have unrestricted access to all services."
        case 1: return "Your documents are being processed by our compliance team."
        default: return "Please complete the required steps below to secure your account."
        }
    }
}
@MainActor

struct TraditionalKYCSection: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @ObservedObject var viewModel: KYCViewModel
    
    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("Basic Verification")
                .font(.headline)
            
            VStack(spacing: 12) {
                if viewModel.bvnEnabled {
                    KYCInputField(
                        title: "Bank Verification Number (BVN)",
                        placeholder: "Enter 11-digit BVN",
                        value: $viewModel.bvnValue,
                        isSaved: viewModel.bvnSaved
                    )
                    KYCSubmitButton(
                        title: "Verify BVN",
                        isEnabled: viewModel.bvnValue.count == 11 && !viewModel.isLoading
                    ) {
                        viewModel.submitIdentity(type: "bvn", value: viewModel.bvnValue)
                    }
                }
                
                if viewModel.ninEnabled {
                    KYCInputField(
                        title: "National Identity Number (NIN)",
                        placeholder: "Enter 11-digit NIN",
                        value: $viewModel.ninValue,
                        isSaved: viewModel.ninSaved
                    )
                    KYCSubmitButton(
                        title: "Verify NIN",
                        isEnabled: viewModel.ninValue.count == 11 && !viewModel.isLoading
                    ) {
                        viewModel.submitIdentity(type: "nin", value: viewModel.ninValue)
                    }
                }

                if !viewModel.stillNeeded.isEmpty {
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Still outstanding")
                            .font(.caption)
                            .fontWeight(.semibold)
                            .foregroundColor(.secondary)
                        ForEach(viewModel.stillNeeded, id: \.self) { item in
                            Text("• " + item)
                                .font(.caption)
                                .foregroundColor(.secondary)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
        }
        .padding()
        .background(Color(.systemGray6))
        .cornerRadius(16)
    }
}
@MainActor

struct KYCSubmitButton: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    let title: String
    let isEnabled: Bool
    let action: () -> Void
    
    var body: some View {
        Button(action: action) {
            Text(title)
                .fontWeight(.semibold)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 12)
                .background(isEnabled ? brandTheme.primary : Color.gray.opacity(0.4))
                .foregroundColor(isEnabled ? brandTheme.onPrimary : brandTheme.onSurface)
                .cornerRadius(10)
        }
        .disabled(!isEnabled)
    }
}
@MainActor

struct KYCInputField: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    let title: String
    let placeholder: String
    @Binding var value: String
    let isSaved: Bool
    
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text(title)
                    .font(.subheadline)
                    .fontWeight(.medium)
                Spacer()
                if isSaved {
                    Label("Saved", systemImage: "checkmark.circle.fill")
                        .font(.caption)
                        .foregroundColor(.green)
                }
            }
            
            TextField(placeholder, text: $value)
                .textFieldStyle(RoundedBorderTextFieldStyle())
                .keyboardType(.numberPad)
        }
        .padding()
        .background(Color(.systemBackground))
        .cornerRadius(12)
        .shadow(color: .black.opacity(0.05), radius: 4, x: 0, y: 2)
    }
}
@MainActor

struct AIInterviewSection: View {
    @ObservedObject private var brandTheme = BrandTheme.shared
    @ObservedObject var viewModel: KYCViewModel
    
    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("AI Compliance Interview")
                .font(.headline)
            
            Button(action: {
                viewModel.showAIInterview = true
            }) {
                HStack {
                    Image(systemName: "mic.fill")
                        .font(.title2)
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Titanium AI Interview")
                            .font(.headline)
                        Text("Complete your KYC by talking to our AI Compliance Officer. No forms required.")
                            .font(.caption)
                            .foregroundColor(brandTheme.onPrimary)
                    }
                    Spacer()
                    Image(systemName: "chevron.right")
                        .foregroundColor(brandTheme.onPrimary)
                }
                .padding()
                .background(
                    LinearGradient(
                        colors: [brandTheme.primary, brandTheme.primary],
                        startPoint: .leading,
                        endPoint: .trailing
                    )
                )
                .foregroundColor(brandTheme.onPrimary)
                .cornerRadius(16)
            }
        }
        .sheet(isPresented: $viewModel.showAIInterview) {
            AIInterviewView(viewModel: viewModel)
        }
    }
}

final class KYCViewModel: ObservableObject {
    @Published var kycStatus: Int = 0
    @Published var kycStatusName: String = "Unverified"
    @Published var bvnValue: String = ""
    @Published var ninValue: String = ""
    // Both numbers can be submitted on every platform; whether the vendor actually requires one is
    // decided server-side (bc_kyc_checks_pending) and reported back in `still_needed`.
    @Published var bvnEnabled: Bool = true
    @Published var ninEnabled: Bool = true
    @Published var bvnSaved: Bool = false
    @Published var ninSaved: Bool = false
    @Published var stillNeeded: [String] = []
    @Published var isLoading: Bool = false
    @Published var showResult: Bool = false
    @Published var resultMessage: String = ""
    @Published var showAIInterview: Bool = false
    
    func loadKYCStatus() {
        AppNetworkService.shared.requestObject("kyc.php", params: ["action": "status"]) { result in
            guard case .success(let json) = result else { return }
            self.kycStatus = bcInt(json["kyc_status"])
            let name = bcString(json["kyc_name"])
            if !name.isEmpty { self.kycStatusName = name }
            self.bvnSaved = bcBool(json["bvn_set"])
            self.ninSaved = bcBool(json["nin_set"])
            self.stillNeeded = (json["still_needed"] as? [String]) ?? []
        }
    }

    /// Submits a BVN or NIN through `kyc.php` action=submit_bvn_nin.
    /// The server verifies it with the vendor's provider and answers `status` = success/failed.
    func submitIdentity(type: String, value: String) {
        let cleaned = value.filter { $0.isNumber }
        guard cleaned.count >= 10 else {
            resultMessage = "Enter a valid \(type.uppercased()) (at least 10 digits)."
            showResult = true
            return
        }

        isLoading = true
        AppNetworkService.shared.requestObject(
            "kyc.php",
            params: ["action": "submit_bvn_nin", "type": type, "value": cleaned]
        ) { result in
            self.isLoading = false
            switch result {
            case .success(let json):
                let status = bcString(json["status"])
                let desc = bcString(json["desc"])
                self.resultMessage = desc.isEmpty
                    ? (status == "success" ? "\(type.uppercased()) verified." : "Verification failed.")
                    : desc
                self.showResult = true
                self.loadKYCStatus()
            case .failure(let error):
                self.resultMessage = error.localizedDescription
                self.showResult = true
            }
        }
    }
}
// Camera capture is manual review evidence, not biometric liveness attestation.
private enum KYCCameraKind: String, Identifiable { case photo, video; var id: String { rawValue } }
@MainActor
private struct KYCLiveCaptureSection: View {
    let onAccepted: () -> Void
    @State private var camera: KYCCameraKind?
    @State private var photo: Data?
    @State private var video: Data?
    @State private var busy = false
    @State private var message = "Captures are submitted for manual review, not automatic verification."
    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Live photo and video").font(.headline)
            Text("Use your camera. For video, say your full name and today’s date, then turn your head slowly. Record 1–8 seconds. No gallery uploads.").font(.caption)
            Button(photo == nil ? "Take live photo" : "Retake live photo") { open(.photo) }.disabled(busy)
            if let data = photo, let image = UIImage(data: data) {
                Image(uiImage: image).resizable().scaledToFit().frame(maxHeight: 180)
                Text("Photo: \(data.count / 1024) KB").font(.caption)
            }
            Button(video == nil ? "Record live video" : "Retake live video") { open(.video) }.disabled(busy)
            if let data = video { Text("Video ready: \(data.count / 1024) KB (maximum 3 MB)").font(.caption) }
            Button("Submit captures for review") { submit() }.disabled(busy || (photo == nil && video == nil))
            Button("Discard captures") { photo = nil; video = nil; message = "Unsubmitted captures discarded." }.disabled(busy || (photo == nil && video == nil))
            if busy { ProgressView("Processing…") }
            Text(message).font(.caption).accessibilityIdentifier("kycCaptureStatus")
        }
        .padding().background(Color.secondary.opacity(0.08)).cornerRadius(12)
        .sheet(item: $camera) { kind in
            KYCLiveCamera(kind: kind) { image, url, error in
                camera = nil
                if let error = error { busy = false; message = error; return }
                if let image = image {
                    let size = image.size
                    guard min(size.width, size.height) >= 160 else { busy = false; message = "Photo too small. Please retake."; return }
                    let ratio = min(1, 960 / max(size.width, size.height))
                    let format = UIGraphicsImageRendererFormat(); format.scale = 1
                    let target = CGSize(width: size.width * ratio, height: size.height * ratio)
                    let renderer = UIGraphicsImageRenderer(size: target, format: format)
                    let normalized = renderer.image { _ in image.draw(in: CGRect(origin: .zero, size: target)) }
                    if let jpeg = normalized.jpegData(compressionQuality: 0.78), jpeg.count <= 512 * 1024 {
                        photo = jpeg; message = "Photo ready. Review it before submitting."
                    } else { message = "Photo exceeds 512 KB. Please retake." }
                    busy = false
                } else if let url = url {
                    Task { do { video = try await compressVideo(url); message = "Video ready for submission." }
                        catch { message = error.localizedDescription }; busy = false }
                } else { busy = false; message = "Capture cancelled. Previous captures, if any, were retained." }
            }.interactiveDismissDisabled(true)
        }
    }
    private func open(_ kind: KYCCameraKind) {
        guard UIImagePickerController.isSourceTypeAvailable(.camera) else { message = "A live camera is unavailable. Use a camera-enabled device or the secure website."; return }
        let wanted = kind == .video ? UTType.movie.identifier : UTType.image.identifier
        guard UIImagePickerController.availableMediaTypes(for: .camera)?.contains(wanted) == true else { message = "This camera does not support the requested capture format."; return }
        busy = true
        Task {
            let cameraAllowed = await permission(.video)
            let audioAllowed = kind == .video ? await permission(.audio) : true
            guard cameraAllowed && audioAllowed else { busy = false; message = "Allow camera/microphone access in Settings to capture live evidence. No gallery fallback is offered."; return }
            camera = kind
        }
    }
    private func permission(_ type: AVMediaType) async -> Bool {
        if AVCaptureDevice.authorizationStatus(for: type) == .authorized { return true }
        return await withCheckedContinuation { continuation in AVCaptureDevice.requestAccess(for: type) { continuation.resume(returning: $0) } }
    }
    private func compressVideo(_ source: URL) async throws -> Data {
        defer { try? FileManager.default.removeItem(at: source) }
        let asset = AVURLAsset(url: source)
        let duration = CMTimeGetSeconds(asset.duration)
        guard duration.isFinite, duration >= 1, duration <= 9,
              !asset.tracks(withMediaType: .video).isEmpty, !asset.tracks(withMediaType: .audio).isEmpty else {
            throw captureError("Record 1–8 seconds of video with audio, then try again.")
        }
        guard let exporter = AVAssetExportSession(asset: asset, presetName: AVAssetExportPreset640x480), exporter.supportedFileTypes.contains(.mp4) else { throw captureError("This recording cannot be compressed to MP4. Please retake.") }
        let output = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString).appendingPathExtension("mp4")
        defer { try? FileManager.default.removeItem(at: output) }
        exporter.outputURL = output; exporter.outputFileType = .mp4; exporter.shouldOptimizeForNetworkUse = true
        await withCheckedContinuation { (continuation: CheckedContinuation<Void, Never>) in exporter.exportAsynchronously { continuation.resume() } }
        guard exporter.status == .completed else { throw captureError("Video compression failed. Please retake.") }
        let size = try output.resourceValues(forKeys: [.fileSizeKey]).fileSize ?? 0
        guard size > 0, size <= 3 * 1024 * 1024 else { throw captureError("Compressed video exceeds 3 MB. Record a shorter video.") }
        let result = AVURLAsset(url: output)
        let seconds = CMTimeGetSeconds(result.duration)
        guard seconds.isFinite, seconds >= 1, seconds <= 9, !result.tracks(withMediaType: .audio).isEmpty else { throw captureError("Exported video is invalid. Please retake.") }
        return try Data(contentsOf: output)
    }
    private func captureError(_ message: String) -> NSError { NSError(domain: "KYCCapture", code: 1, userInfo: [NSLocalizedDescriptionKey: message]) }
    private func submit() {
        busy = true
        AppNetworkService.shared.uploadKYCCaptures(photo: photo, video: video) { result in
            busy = false
            switch result {
            case .success(let description): photo = nil; video = nil; message = description; onAccepted()
            case .failure(let error): message = error.localizedDescription
            }
        }
    }
}
private struct KYCLiveCamera: UIViewControllerRepresentable {
    let kind: KYCCameraKind
    let finished: (UIImage?, URL?, String?) -> Void
    func makeCoordinator() -> Coordinator { Coordinator(self) }
    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker = UIImagePickerController()
        picker.sourceType = .camera
        picker.mediaTypes = [kind == .video ? UTType.movie.identifier : UTType.image.identifier]
        if UIImagePickerController.isCameraDeviceAvailable(.front) { picker.cameraDevice = .front }
        picker.cameraCaptureMode = kind == .video ? .video : .photo
        picker.videoMaximumDuration = 8; picker.videoQuality = .typeLow
        picker.delegate = context.coordinator
        return picker
    }
    func updateUIViewController(_ uiViewController: UIImagePickerController, context: Context) {}
    final class Coordinator: NSObject, UINavigationControllerDelegate, UIImagePickerControllerDelegate {
        let parent: KYCLiveCamera
        init(_ parent: KYCLiveCamera) { self.parent = parent }
        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) { parent.finished(nil, nil, nil) }
        func imagePickerController(_ picker: UIImagePickerController, didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            if parent.kind == .photo { parent.finished(info[.originalImage] as? UIImage, nil, nil); return }
            guard let source = info[.mediaURL] as? URL else { parent.finished(nil, nil, "Recording was not returned. Please retry."); return }
            let owned = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString).appendingPathExtension("mov")
            do { try FileManager.default.copyItem(at: source, to: owned); parent.finished(nil, owned, nil) }
            catch { try? FileManager.default.removeItem(at: owned); parent.finished(nil, nil, "Could not retain the recording for compression.") }
        }
    }
}
