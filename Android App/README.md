# CAS2025 Android App

This is a **native Android app** that wraps the CAS2025 portal in a secure `WebView`.
It is intended for rapid field testing on user phones.

## Features

- Native onboarding screen to enter:
  - CAS server URL
  - test user code
- Stores values locally with `SharedPreferences`
- Launches a dedicated `WebViewActivity` with:
  - JavaScript + DOM storage enabled
  - in-app back navigation
  - refresh button
- Appends mobile parameters when opening the portal:
  - `mobile=1`
  - `userCode=<encoded value>`

## Build an APK (Android Studio)

1. Open Android Studio (Hedgehog or newer).
2. Click **Open** and select `Android App/`.
3. Let Gradle sync.
4. Build debug APK:
   - **Build → Build Bundle(s)/APK(s) → Build APK(s)**
5. APK output path:
   - `Android App/app/build/outputs/apk/debug/app-debug.apk`

## CLI build (optional)

After opening once in Android Studio, run:

```bash
cd "Android App"
./gradlew assembleDebug
```

## Prototype notes

- `network_security_config.xml` allows HTTP only for `localhost` and `10.0.2.2` to support emulator/local testing.
- All other traffic requires HTTPS.
