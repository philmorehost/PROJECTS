"""iOS capture source contracts; native build/device acceptance is separate."""
from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[1]
class IOSKycCapture(unittest.TestCase):
    def test_three_brand_capture_and_transport(self):
        views = sorted((ROOT / 'ANDROID - iOS').glob('*iOS/**/KYCView.swift'))
        self.assertEqual(len(views), 3)
        for path in views:
            with self.subTest(app=str(path)):
                source = path.read_text()
                for token in ['KYCLiveCaptureSection(onAccepted:', 'picker.sourceType = .camera', 'picker.videoMaximumDuration = 8',
                              'picker.videoQuality = .typeLow', 'AVCaptureDevice.requestAccess', 'permission(.audio)',
                              'availableMediaTypes(for: .camera)', 'jpegData(compressionQuality: 0.78)',
                              '512 * 1024', '960 / max(size.width, size.height)', 'AVAssetExportPreset640x480',
                              'exporter.outputFileType = .mp4', 'size <= 3 * 1024 * 1024',
                              'asset.tracks(withMediaType: .audio)', 'seconds <= 9', 'Discard captures',
                              'removeItem(at: source)', 'removeItem(at: output)', 'copyItem(at: source, to: owned)']:
                    self.assertIn(token, source)
                self.assertNotIn('.photoLibrary', source)
                self.assertNotIn('UIImageWriteToSavedPhotosAlbum', source)
                capture = source[source.index('// Camera capture is manual review evidence'):]
                self.assertNotIn('print(', capture)
        networks = sorted((ROOT / 'ANDROID - iOS').glob('*iOS/**/AppNetworkService.swift'))
        self.assertEqual(len(networks), 3)
        for path in networks:
            source = path.read_text().split('// No redirects:')[1]
            for token in ['completionHandler(nil)', '.ephemeral', 'Bearer \\(token)', 'web/api/kyc.php',
                          'upload_document', 'app_token', 'liveliness_video', 'accepted_media',
                          'finishTasksAndInvalidate()', 'inspectRequiredSetup(data)', 'http.statusCode',
                          'photo.map', '3 * 1024 * 1024']:
                self.assertIn(token, source)
            self.assertNotIn('print(', source)
        for path in (ROOT / 'ANDROID - iOS').glob('*iOS/project.yml'):
            self.assertIn('INFOPLIST_KEY_NSMicrophoneUsageDescription:', path.read_text())
        for edition in ['SAAS', 'NON-SAAS']:
            self.assertIn('"accepted_media" => $uploaded', (ROOT / ('DGV7.0-' + edition) / 'web/api/kyc.php').read_text())
if __name__ == '__main__': unittest.main()
