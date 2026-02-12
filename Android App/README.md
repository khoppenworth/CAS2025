# CAS2025 Android App

This is a **native Android app** that wraps the CAS2025 portal in a secure `WebView`.
It is intended for rapid field testing on user phones.

## Features

- Native onboarding screen to enter:
  - CAS server URL
- Optional in-app update checker that reads `mobile-app-update.json` from your server
- Stores values locally with `SharedPreferences`
- Launches a dedicated `WebViewActivity` with:
  - JavaScript + DOM storage enabled
  - in-app back navigation
  - refresh button
- Appends a mobile parameter when opening the portal:
  - `mobile=1`

## Build an APK (Android Studio)

Use these steps when preparing a test APK for installation on phones.

1. Open Android Studio (Hedgehog or newer).
2. Select **Open** on the welcome screen and choose the `Android App/` folder.
3. If prompted to trust the project, click **Trust Project**.
4. Wait for the first **Gradle Sync** to complete:
   - Android Studio may prompt you to install missing components (Android SDK Platform, Build-Tools, or Kotlin plugin updates).
   - Accept the prompts so the project can finish syncing.
   - Confirm sync succeeded by checking for **"Gradle sync finished"** in the status bar.
5. Choose a build variant:
   - Open **Build Variants** tool window.
   - Ensure module `app` is set to `debug`.
6. Build the debug APK:
   - Menu: **Build → Build Bundle(s) / APK(s) → Build APK(s)**.
   - Wait for **BUILD SUCCESSFUL** in the Build output.
7. Locate the generated APK:
   - Android Studio notification: click **locate** when build completes.
   - Manual path: `Android App/app/build/outputs/apk/debug/app-debug.apk`.
8. Install on a phone for testing:
   - Copy `app-debug.apk` to the device and install, or
   - Use ADB: `adb install -r app-debug.apk`.
   - If Android blocks install, enable **Install unknown apps** for the file manager/browser used.

### Recommended pre-release checks (for testers)

- Open the app and confirm the setup screen loads.
- Enter a valid CAS URL.
- Verify the portal opens in-app and refresh/back navigation works.

## CLI build (optional)

After opening once in Android Studio, run:

```bash
cd "Android App"
./gradlew assembleDebug
```

## Prototype notes

- `network_security_config.xml` allows HTTP only for `localhost` and `10.0.2.2` to support emulator/local testing.
- All other traffic requires HTTPS.


## APK update automation (post-install)

The app now includes a **Check for Updates** button on the setup screen.

- It fetches update metadata from:
  - `<SERVER_URL>/mobile-app-update.json`
- If `latestVersionCode` is greater than the installed app version code, the app prompts the user to download the APK.
- Tapping **Download APK** opens the provided APK URL in the system browser.

Expected update manifest format:

```json
{
  "latestVersionCode": 2,
  "apkUrl": "https://your-domain.example/downloads/cas2025-app-v2.apk",
  "notes": "Optional release notes shown in the update prompt"
}
```

> Note: for side-loaded apps, Android still requires user confirmation for installing an APK update.
> Fully silent updates are only available to device-owner/enterprise-managed deployments.
