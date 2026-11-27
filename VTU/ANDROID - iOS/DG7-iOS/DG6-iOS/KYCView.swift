//
//  KYCView.swift
//  DG7-iOS
//
//  KYC Verification View
//

import SwiftUI
import UIKit
import CoreImage
import AVFoundation
import UniformTypeIdentifiers
import Vision

@MainActor
struct KYCView: View {
    var isOverlayLocked: Bool = false
    @ObservedObject private var brandTheme = BrandTheme.shared
    @ObservedObject private var complianceGate = ComplianceGateState.shared
    @EnvironmentObject var session: SessionManager
    @StateObject private var viewModel = KYCViewModel()
    @Environment(\.dismiss) var dismiss
    @State private var showFormsWhilePending = false
    @State private var showNotifications = false

    var body: some View {
        ScrollView {
            VStack(spacing: 20) {
                if isOverlayLocked {
                    VStack(alignment: .leading, spacing: 10) {
                        Text("Verification Required to Unlock App")
                            .font(.headline)
                            .foregroundColor(.white)
                        Text(viewModel.kycStatus == 1
                             ? "Your submission passed automated checks and is awaiting compliance review."
                             : "Wallet, transfers, and purchases stay locked until your identity verification is approved.")
                            .font(.caption)
                            .foregroundColor(.white.opacity(0.85))
                        HStack(spacing: 10) {
                            Button("Refresh Status") {
                                viewModel.loadKYCStatus()
                                ComplianceGateState.shared.refresh()
                            }
                            .buttonStyle(.borderedProminent)
                            Button("Alerts") { showNotifications = true }
                                .buttonStyle(.bordered)
                                .foregroundColor(.white)
                            Spacer()
                            Button("Sign Out") { session.logout() }
                                .buttonStyle(.bordered)
                                .foregroundColor(.white)
                        }
                    }
                    .padding()
                    .background(Color(red: 0.06, green: 0.09, blue: 0.16))
                    .cornerRadius(16)
                }

                // Status Card
                KYCStatusCard(viewModel: viewModel)

                // Rejection Reason Card
                if viewModel.kycStatus == 3 && !viewModel.rejectReason.isEmpty {
                    VStack(alignment: .leading, spacing: 6) {
                        Text("Submission Rejected — Action Required")
                            .font(.subheadline.bold())
                            .foregroundColor(.red)
                        Text(viewModel.rejectReason)
                            .font(.caption)
                            .foregroundColor(.primary)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding()
                    .background(Color.red.opacity(0.1))
                    .cornerRadius(14)
                }

                // AI Advisory Badge
                if viewModel.aiRating > 0 {
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Automated AI Screening: \(viewModel.aiRating)/100 (Grade \(viewModel.aiGrade))")
                            .font(.caption.bold())
                            .foregroundColor(.blue)
                        if !viewModel.aiReason.isEmpty {
                            Text(viewModel.aiReason)
                                .font(.caption)
                                .foregroundColor(.secondary)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding()
                    .background(Color.blue.opacity(0.08))
                    .cornerRadius(12)
                }

                // Pending Human Review Lock Card
                let showPendingLock = (viewModel.kycStatus == 1 && viewModel.stillNeeded.isEmpty)
                if showPendingLock {
                    VStack(alignment: .leading, spacing: 10) {
                        Text("Verification Under Compliance Review")
                            .font(.headline)
                            .foregroundColor(.orange)
                        Text("Your required checks are complete and awaiting compliance review. You will be notified as soon as your account is approved.")
                            .font(.caption)
                            .foregroundColor(.secondary)
                        HStack(spacing: 10) {
                            Button("Check Approval Status") {
                                viewModel.loadKYCStatus()
                                ComplianceGateState.shared.refresh()
                            }
                            .buttonStyle(.borderedProminent)
                            Button(showFormsWhilePending ? "Hide Forms" : "Update Files") {
                                showFormsWhilePending.toggle()
                            }
                            .buttonStyle(.bordered)
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding()
                    .background(Color.orange.opacity(0.12))
                    .cornerRadius(16)
                }

                if !showPendingLock || showFormsWhilePending {
                    // Traditional KYC Methods
                    TraditionalKYCSection(viewModel: viewModel)

                    // Guided ID + Proof of Address + Auto Face Selfie
                    KYCDocumentUploadSection(onAccepted: { viewModel.loadKYCStatus() })

                    // Camera Capture (Photo + Live Audio/Video)
                    KYCLiveCaptureSection(onAccepted: { viewModel.loadKYCStatus() })

                    // AI Interview Section
                    AIInterviewSection(viewModel: viewModel)
                }
            }
            .padding()
        }
        .navigationTitle("Identity Verification (KYC)")
        .navigationBarTitleDisplayMode(.inline)
        .sheet(isPresented: $showNotifications) {
            ComplianceNotificationsSheet()
        }
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
            } else if viewModel.kycStatus == 3 {
                Label("Rejected — Resubmit Required", systemImage: "exclamationmark.triangle.fill")
                    .font(.caption)
                    .foregroundColor(.red)
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
        case 3: return "exclamationmark.triangle.fill"
        default: return "shield.lefthalf.filled"
        }
    }

    var statusColor: Color {
        switch viewModel.kycStatus {
        case 2: return .green
        case 1: return .orange
        case 3: return .red
        default: return .blue
        }
    }

    var statusTitle: String {
        switch viewModel.kycStatus {
        case 2: return "Fully Verified"
        case 1: return "Under Review"
        case 3: return "Verification Rejected"
        default: return "Unverified"
        }
    }

    var statusMessage: String {
        switch viewModel.kycStatus {
        case 2: return "Your identity has been confirmed. You have unrestricted access to all services."
        case 1: return "Your documents are being processed by our compliance team."
        case 3: return "Please review the feedback below and upload corrected documents."
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
struct KYCDocumentUploadSection: View {
    let onAccepted: () -> Void
    @ObservedObject private var brandTheme = BrandTheme.shared
    @State private var selectedDocType = "National ID"
    private let docTypes = ["National ID", "NIN", "Passport", "Driver's Licence", "Voter's Card", "BVN"]
    @State private var govtIdFile: (data: Data, filename: String, mime: String)?
    @State private var poaFile: (data: Data, filename: String, mime: String)?
    @State private var autoSelfieData: Data?
    @State private var pickingKind: Int = 0 // 1 = govt_id, 2 = poa
    @State private var showFileImporter = false
    @State private var showAutoSelfie = false
    @State private var busy = false
    @State private var statusMessage = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Identity Documents & Auto Face Selfie")
                .font(.headline)

            Picker("Document Type", selection: $selectedDocType) {
                ForEach(docTypes, id: \.self) { Text($0) }
            }
            .pickerStyle(.menu)

            HStack {
                Button("Select Government ID") {
                    pickingKind = 1
                    showFileImporter = true
                }
                .buttonStyle(.bordered)
                Spacer()
                Text(govtIdFile?.filename ?? "None")
                    .font(.caption)
                    .foregroundColor(.secondary)
                    .lineLimit(1)
            }

            HStack {
                Button("Select Proof of Address") {
                    pickingKind = 2
                    showFileImporter = true
                }
                .buttonStyle(.bordered)
                Spacer()
                Text(poaFile?.filename ?? "Utility bill / PDF")
                    .font(.caption)
                    .foregroundColor(.secondary)
                    .lineLimit(1)
            }

            HStack {
                Button("Auto Face-Framed Selfie") {
                    showAutoSelfie = true
                }
                .buttonStyle(.bordered)
                Spacer()
                Text(autoSelfieData != nil ? "Ready (\(autoSelfieData!.count / 1024) KB)" : "Optional here")
                    .font(.caption)
                    .foregroundColor(.secondary)
            }

            Button(busy ? "Submitting..." : "Upload Documents") {
                submitDocuments()
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 10)
            .background((govtIdFile != nil || poaFile != nil || autoSelfieData != nil) && !busy ? brandTheme.primary : Color.gray.opacity(0.35))
            .foregroundColor((govtIdFile != nil || poaFile != nil || autoSelfieData != nil) && !busy ? brandTheme.onPrimary : brandTheme.onSurface)
            .cornerRadius(10)
            .disabled(busy || (govtIdFile == nil && poaFile == nil && autoSelfieData == nil))

            if !statusMessage.isEmpty {
                Text(statusMessage)
                    .font(.caption)
                    .foregroundColor(.secondary)
            }
        }
        .padding()
        .background(Color(.systemGray6))
        .cornerRadius(16)
        .fileImporter(
            isPresented: $showFileImporter,
            allowedContentTypes: [.pdf, .image],
            allowsMultipleSelection: false
        ) { result in
            guard case .success(let urls) = result, let url = urls.first else { return }
            let accessed = url.startAccessingSecurityScopedResource()
            defer { if accessed { url.stopAccessingSecurityScopedResource() } }
            guard let data = try? Data(contentsOf: url), data.count >= 256, data.count <= 5 * 1024 * 1024 else {
                statusMessage = "Selected file must be between 256 bytes and 5 MB."
                return
            }
            let ext = url.pathExtension.lowercased()
            let mime = ext == "pdf" ? "application/pdf" : "image/jpeg"
            let payload = (data, url.lastPathComponent, mime)
            if pickingKind == 1 { govtIdFile = payload } else { poaFile = payload }
            statusMessage = "\(url.lastPathComponent) ready to upload."
        }
        .sheet(isPresented: $showAutoSelfie) {
            KYCAutoFaceSelfieSheet { jpegData in
                showAutoSelfie = false
                if let jpegData = jpegData {
                    autoSelfieData = jpegData
                    statusMessage = "Auto-captured selfie ready (\(jpegData.count / 1024) KB)."
                }
            }
        }
    }

    private func submitDocuments() {
        busy = true
        statusMessage = "Scanning & uploading..."
        AppNetworkService.shared.uploadKYCDocuments(
            docType: selectedDocType,
            govtId: govtIdFile,
            proofOfAddress: poaFile,
            photo: autoSelfieData,
            video: nil
        ) { result in
            busy = false
            switch result {
            case .success(let desc):
                govtIdFile = nil
                poaFile = nil
                autoSelfieData = nil
                statusMessage = desc
                onAccepted()
            case .failure(let err):
                statusMessage = err.localizedDescription
            }
        }
    }
}

private struct KYCAutoFaceSelfieSheet: View {
    let onFinish: (Data?) -> Void
    @State private var statusText = "Center your face inside the oval — auto-capturing..."
    @State private var triggerManual = false

    var body: some View {
        NavigationView {
            ZStack {
                KYCAutoFaceCameraRepresentable(statusText: $statusText, triggerManual: $triggerManual, onFinish: onFinish)
                    .ignoresSafeArea()
                Ellipse()
                    .stroke(Color.cyan, lineWidth: 3)
                    .frame(width: 240, height: 310)
                VStack {
                    Text(statusText)
                        .font(.caption.bold())
                        .padding(10)
                        .background(Color.black.opacity(0.65))
                        .foregroundColor(.white)
                        .cornerRadius(10)
                        .padding(.top, 16)
                    Spacer()
                    Button("Capture Now") {
                        triggerManual = true
                    }
                    .buttonStyle(.borderedProminent)
                    .padding(.bottom, 30)
                }
            }
            .navigationTitle("Auto Face Selfie")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Cancel") { onFinish(nil) }
                }
            }
        }
    }
}

private struct KYCAutoFaceCameraRepresentable: UIViewControllerRepresentable {
    @Binding var statusText: String
    @Binding var triggerManual: Bool
    let onFinish: (Data?) -> Void

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    func makeUIViewController(context: Context) -> UIViewController {
        let vc = UIViewController()
        context.coordinator.setupCamera(in: vc.view)
        return vc
    }

    func updateUIViewController(_ uiViewController: UIViewController, context: Context) {
        if triggerManual {
            context.coordinator.captureLatestFrame()
        }
    }

    final class Coordinator: NSObject, AVCaptureVideoDataOutputSampleBufferDelegate {
        let parent: KYCAutoFaceCameraRepresentable
        private let session = AVCaptureSession()
        private let queue = DispatchQueue(label: "kyc.autofaceselfie")
        private var stableCount = 0
        private var finished = false
        private var latestPixelBuffer: CVPixelBuffer?

        init(_ parent: KYCAutoFaceCameraRepresentable) {
            self.parent = parent
        }

        func setupCamera(in view: UIView) {
            guard let device = AVCaptureDevice.default(.builtInWideAngleCamera, for: .video, position: .front),
                  let input = try? AVCaptureDeviceInput(device: device),
                  session.canAddInput(input) else {
                DispatchQueue.main.async { self.parent.statusText = "Front camera unavailable." }
                return
            }
            session.beginConfiguration()
            session.sessionPreset = .vga640x480
            session.addInput(input)
            let output = AVCaptureVideoDataOutput()
            output.alwaysDiscardsLateVideoFrames = true
            output.setSampleBufferDelegate(self, queue: queue)
            if session.canAddOutput(output) { session.addOutput(output) }
            session.commitConfiguration()

            let preview = AVCaptureVideoPreviewLayer(session: session)
            preview.videoGravity = .resizeAspectFill
            preview.frame = UIScreen.main.bounds
            view.layer.addSublayer(preview)
            queue.async { self.session.startRunning() }
        }

        func captureLatestFrame() {
            guard !finished, let pb = latestPixelBuffer else { return }
            exportPixelBuffer(pb)
        }

        func captureOutput(_ output: AVCaptureOutput, didOutput sampleBuffer: CMSampleBuffer, from connection: AVCaptureConnection) {
            guard !finished, let pb = CMSampleBufferGetImageBuffer(sampleBuffer) else { return }
            latestPixelBuffer = pb
            let req = VNDetectFaceRectanglesRequest()
            let handler = VNImageRequestHandler(cvPixelBuffer: pb, orientation: .leftMirrored, options: [:])
            try? handler.perform([req])
            if let faces = req.results, !faces.isEmpty {
                stableCount += 1
                DispatchQueue.main.async {
                    self.parent.statusText = "Face aligned — hold still (\(self.stableCount)/3)..."
                }
                if stableCount >= 3 {
                    exportPixelBuffer(pb)
                }
            } else {
                stableCount = 0
                DispatchQueue.main.async {
                    self.parent.statusText = "Center your face inside the oval..."
                }
            }
        }

        private func exportPixelBuffer(_ pb: CVPixelBuffer) {
            guard !finished else { return }
            finished = true
            session.stopRunning()
            let ci = CIImage(cvPixelBuffer: pb).oriented(.right)
            let ctx = CIContext()
            guard let cg = ctx.createCGImage(ci, from: ci.extent) else {
                DispatchQueue.main.async { self.parent.onFinish(nil) }
                return
            }
            let img = UIImage(cgImage: cg)
            let jpeg = img.jpegData(compressionQuality: 0.78)
            DispatchQueue.main.async { self.parent.onFinish(jpeg) }
        }

        deinit {
            if session.isRunning { session.stopRunning() }
        }
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
    @Published var rejectReason: String = ""
    @Published var aiRating: Int = 0
    @Published var aiGrade: String = ""
    @Published var aiReason: String = ""
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
            self.rejectReason = bcString(json["kyc_reject_reason"])
            self.aiRating = bcInt(json["ai_rating"])
            self.aiGrade = bcString(json["ai_grade"])
            self.aiReason = bcString(json["ai_reason"])
            DispatchQueue.main.async {
                let enforced = (json["kyc_enforced"] as? Bool) ?? false
                let gateLocked = (json["kyc_gate_locked"] as? Bool) ?? (enforced && self.kycStatus != 2)
                ComplianceGateState.shared.locked = gateLocked
            }
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
            Text("Use your camera. For video, look at the camera, say \"HI\" clearly, then turn your head slowly. Record 1–8 seconds. No gallery uploads.").font(.caption)
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
