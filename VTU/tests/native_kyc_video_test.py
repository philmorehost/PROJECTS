"""Source/resource contracts, not a substitute for camera/device acceptance."""
from pathlib import Path
import re
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1] / 'ANDROID - iOS'
ANDROID = '{http://schemas.android.com/apk/res/android}'

class NativeKycVideo(unittest.TestCase):
    def test_all_three_brands(self):
        apps = sorted(ROOT.glob('*Android'))
        self.assertEqual(len(apps), 3)
        for app in apps:
            with self.subTest(app=app.name):
                sources = list((app / 'app/src/main/java').glob('**/KYCFragment.kt'))
                self.assertEqual(len(sources), 1)
                source = sources[0].read_text()
                for token in ['MediaStore.ACTION_VIDEO_CAPTURE', 'MediaStore.EXTRA_OUTPUT, uri',
                              'MediaStore.EXTRA_DURATION_LIMIT, 8', 'MediaStore.EXTRA_SIZE_LIMIT, 3L * 1024 * 1024',
                              'MediaStore.EXTRA_VIDEO_QUALITY, 0', 'ClipData.newRawUri',
                              'FLAG_GRANT_WRITE_URI_PERMISSION', 'RequestMultiplePermissions()',
                              'METADATA_KEY_DURATION', 'METADATA_KEY_MIMETYPE', 'METADATA_KEY_HAS_VIDEO', 'METADATA_KEY_HAS_AUDIO',
                              'duration in 1000L..9000L', 'file.length() !in 1..(3L * 1024 * 1024)',
                              'hasVideo && hasAudio && mime == "video/mp4"', 'videoIsValid(video)',
                              'addFormDataPart("liveliness_video", video.name', 'video.asRequestBody("video/mp4".toMediaType())',
                              'outState.putBoolean("kyc_video_ready", videoReady)', 'recordVideo.launch(intent)']:
                    self.assertIn(token, source)
                video = source[source.index('private var pendingVideo'):source.index('override fun onCreate(')]
                self.assertNotIn('ACTION_GET_CONTENT', video)
                self.assertNotIn('result.data', video, 'do not substitute an arbitrary returned/gallery URI')
                self.assertIn('file?.delete()', video)
                manifest = ET.parse(app / 'app/src/main/AndroidManifest.xml').getroot()
                self.assertIn('android.permission.RECORD_AUDIO', [e.get(ANDROID+'name') for e in manifest.findall('uses-permission')])
                layout = ET.parse(app / 'app/src/main/res/layout/fragment_kyc.xml').getroot()
                ids = {e.get(ANDROID+'id') for e in layout.iter()}
                self.assertTrue({'@+id/btn_record_video','@+id/tv_video_name','@+id/btn_upload_documents'} <= ids)
                provider = ET.parse(app / 'app/src/main/res/xml/provider_paths.xml').getroot()
                self.assertIn('kyc/', [e.get('path') for e in provider.findall('cache-path')])

if __name__ == '__main__':
    unittest.main()
