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

## Store builds from GitHub (no Mac needed)

Two manual workflows under `.github/workflows/` build and upload from the cloud once the store credentials exist as
repository secrets (Settings → Secrets and variables → Actions). Nobody's Apple ID or Google password is involved —
only API keys the account owner generates and can revoke.

| Workflow | Secrets | Result |
|---|---|---|
| **iOS (TestFlight)** — `ios.yml`, macOS runner | `APPLE_TEAM_ID` · `ASC_KEY_ID` · `ASC_ISSUER_ID` · `ASC_KEY_P8` (the `.p8` file, base64) | archive signed by Xcode cloud-managed signing, uploaded to App Store Connect → TestFlight |
| **Android (Play)** — `android.yml` | `ANDROID_KEYSTORE_B64` · `ANDROID_KEYSTORE_PASSWORD` · `ANDROID_KEY_ALIAS` · `ANDROID_KEY_PASSWORD` · `PLAY_SERVICE_ACCOUNT_JSON` | signed `.aab`, uploaded to the chosen Play track |

Where the keys come from:

- **App Store Connect API key**: App Store Connect → Users and Access → Integrations → App Store Connect API → Team
  keys → "+" → name `B2B ops CI`, access **App Manager**. Download the `.p8` once (Apple never shows it again); the
  Key ID and Issuer ID are on the same page. Team ID: developer.apple.com → Membership.
- **Play service account**: Play Console → Setup → API access → create a Google Cloud service account, grant it
  "Release manager" on the app, create a JSON key. The upload keystore is the one under
  `%USERPROFILE%\.scmops\android\keys` (`base64 -w0 b2bops-upload.jks`).

Then Actions → pick the workflow → Run workflow with the next build / version number. The app record itself (name,
bundle id `sa.b2b.ops`, listing, privacy answers, review notes with a demo login) is created once in App Store
Connect / Play Console; after the first upload every further release is one workflow run plus "Submit for review".

## iOS release — how it is wired (2026-09-27)

| Piece | Value / where |
|---|---|
| App record | App Store Connect → "B2B ops", bundle id `sa.b2b.ops`, SKU `B2BOPS-IOS-001`, primary language Arabic |
| API key | "B2B ops CI", role **App Manager** (Key ID `G22VP6B72A`); file kept in `%USERPROFILE%\.scmops\apple\` — back it up |
| Signing | Apple Distribution certificate + App Store profile made by `tools/ios-signing.mjs` (valid to 2027-09-26); `.p12` and profile in the same folder and as GitHub secrets `IOS_DIST_P12_B64`, `IOS_DIST_P12_PASSWORD`, `IOS_PROFILE_B64`, `IOS_PROFILE_NAME` |
| Build | `.github/workflows/ios.yml` on **macos-26 / Xcode 26** (App Store Connect rejects older SDKs). Project uses Swift Package Manager — no CocoaPods |
| Listing | `mobile/store/listing.json` (ar-SA + en-US texts, urls, categories) and `mobile/store/{iphone-6.9,ipad-13}/*.png`; push with `node tools/asc.mjs listing sa.b2b.ops` |
| Privacy policy | public page `/privacy` (contact from `PRIVACY_CONTACT_EMAIL`) |

A new release: bump the build number → Actions → **iOS (TestFlight)** → Run workflow (`build_number` must increase) → the
build appears in TestFlight after Apple processes it (10–30 min) → attach it to the version and submit for review.
Why not cloud-managed signing: it needs an **Admin** API key; the App Manager key is enough with our own certificate.
Renew the certificate/profile before 2027-09-26 by re-running `tools/ios-signing.mjs` (delete `cert.id` first) and updating the four secrets.

## Driver phone tracking (native app)

The driver's phone reports its position during an ACTIVE trip only (`dispatched → returning`), after an in-app
disclosure and consent — next to the truck's own GPS (Wialon), so the dispatcher sees both and a mismatch.

| Piece | Where |
|---|---|
| Plugin | `@capacitor-community/background-geolocation` — Android foreground service (type `location`, persistent notification), iOS "Always" + `UIBackgroundModes: location` |
| Phone logic | `resources/js/composables/driverTracking.js` — polls `GET /delivery/tracking`, starts / stops the watcher, queues fixes in local storage (network gaps), sends batches to `POST /delivery/tracking/points` |
| Consent | `layout/DriverTrackingConsent.vue` (disclosure BEFORE the OS prompt — Play "prominent disclosure"), `POST /delivery/tracking/consent`; driver can withdraw from "رحلاتي" |
| Server | `app/Services/Delivery/PhoneTrackingService.php` — stores fixes only for a consented driver on an active trip (else answers `tracking:false`), validates (range, ≤24 h old, not future, accuracy ≤1 km), de-duplicates on (driver, time), keeps 30 days |
| Maps | trip map: 📱 marker + green phone trail + "phone away from the truck" (>1 km, both fresh) / "stopped reporting" (>10 min); fleet map: every open trip's phone + an alert list |

Honest limit: the OS stops tracking if the driver force-closes the app (swipes it away on Android, force-quits on
iOS). The dispatcher then sees "stopped reporting" while the truck GPS keeps working. Surviving a force-quit needs a
commercial native SDK (e.g. Transistorsoft, licence per app) — not included.

Store declarations: Google Play → App content → "Foreground service permissions" (location: tracking delivery trips,
with a short screen recording) and the data-safety form (precise location, collected, not shared). Apple → review notes
explain background location (see `mobile/store/listing.json` → review.notes) and the privacy labels include precise
location linked to the user.
