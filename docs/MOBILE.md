# Native apps (Android / iOS)

The App Store / Google Play apps are the SAME Vue client, packaged with [Capacitor](https://capacitorjs.com) into a
native shell that talks to the server named in `mobile/.env` (`VITE_API_ORIGIN`). One code base: every screen, rule and
fix lands in the web, the Android app and the iOS app at once; the phone layout (`docs/FRONTEND.md` → Phone layout) is
what the apps show.

| Piece | Where |
|---|---|
| App id / name / native settings | `capacitor.config.json` (`sa.b2b.ops`, "B2B ops", dark status bar, splash) |
| Web bundle for the apps | `vite.mobile.config.js` → `mobile/index.html` + `mobile/.env` → `mobile/www/` (git-ignored, built on demand) |
| Native behaviour (status bar, splash, back button, camera permission) | `resources/js/composables/native.js` — no-ops in the browser |
| Android project | `android/` (Gradle; release signing from a keystore OUTSIDE the repo) |
| iOS project | `ios/App/` (Xcode; needs a Mac) |
| Icons & splash sources | `mobile/assets/` from `tools/mobile-assets.mjs`; generated into both projects by `npm run mobile:assets` |

Inside the shells the router uses hash history (`#/dash`), the API base is absolute, the camera asks for the OS
permission before the scanner opens, the Android back button closes the open drawer / sheet or goes back, and the app
minimises on a home screen. Everything else is untouched.

## Build

```bash
npm run mobile:build      # web bundle → mobile/www  (reads mobile/.env)
npx cap sync              # copy it + plugins into android/ and ios/
```

**Android** (portable toolchain on this machine: JDK 21 (Capacitor 8 requires it) + SDK under `%USERPROFILE%\.scmops\android`; on another machine
install Android Studio or the command-line tools and set `ANDROID_HOME` / `JAVA_HOME`):

```bash
cd android
./gradlew assembleDebug                         # app/build/outputs/apk/debug/app-debug.apk  — install on a phone to test
KEYSTORE_PROPERTIES=/path/keystore.properties ./gradlew bundleRelease   # app/build/outputs/bundle/release/app-release.aab — upload to Play
```

Release signing reads `keystore.properties` (`storeFile, storePassword, keyAlias, keyPassword`). The upload key lives in
`%USERPROFILE%\.scmops\android\keys\` on this machine — **back it up**; Play uses it to verify every future upload.
Without the file the release build is unsigned (it never falls back to the debug key).

**iOS**: open `ios/App/App.xcworkspace` in Xcode on a Mac (`npx cap open ios` after `pod install` in `ios/App`), set the
team under Signing & Capabilities, then Product → Archive → Distribute to App Store Connect. No Mac? Use a cloud macOS
builder (Codemagic, Bitrise, GitHub Actions `macos` runner) with the same repository.

## Publish

1. **Google Play**: a Play Console developer account (one-time fee, the company's Google account). Create the app
   ("B2B ops", package `sa.b2b.ops`), upload the `.aab` to Internal testing first, add the testers' Gmail addresses,
   then promote to Production. Required listing items: description, 2+ phone screenshots, 512×512 icon
   (`mobile/assets/icon.png`), feature graphic 1024×500, privacy policy URL, data-safety form (login credentials,
   location for delivery proof, camera for scanning — nothing sold or shared).
2. **App Store**: an Apple Developer Program membership (yearly fee, the company's Apple ID). In App Store Connect
   create the app with bundle id `sa.b2b.ops`, upload the archive from Xcode, fill the listing (screenshots per device
   size, privacy nutrition labels: identifiers, location, user content). Provide a demo account for the review team —
   the app is for company staff, so the review notes must say so ("B2B ops is an internal operations tool; sign in with
   the demo account …"). Apps that are a plain website wrapper get rejected (guideline 4.2); this one has native
   camera scanning, location on delivery proof and offline-safe navigation — mention them in the review notes.
3. **Versions**: bump `versionCode` / `versionName` in `android/app/build.gradle` and the build/version in Xcode for each
   store upload. The web bundle inside the app only changes when you rebuild and re-upload — the server can change any time.

## Not done yet

- Push notifications (needs Firebase for Android and APNs for iOS) — the in-app bell works over the API.
- Offline mode: the app needs the server; screens show the normal "cannot reach the server" error offline.
- iOS build and store submission need a Mac (or a cloud macOS builder) and the Apple membership; both are on your side.
