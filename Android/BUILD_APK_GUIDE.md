# Building APK File - Step by Step Guide

## Method 1: Using Android Studio (Recommended)

### Prerequisites
- Android Studio installed
- JDK 17 or higher
- Android SDK installed

### Steps:

1. **Open Project in Android Studio**
   - Open Android Studio
   - Click "Open" and navigate to `Android/android` folder
   - Wait for Gradle sync to complete

2. **Sync Gradle Files**
   - Click "Sync Project with Gradle Files" (elephant icon) if prompted
   - Wait for all dependencies to download (including Printer-ktx)

3. **Build Release APK**
   - Go to menu: **Build → Generate Signed Bundle / APK**
   - Select **APK** (not Android App Bundle)
   - Click **Next**

4. **Select Keystore**
   - The app is configured with keystore files:
     - Release: `Android/release.keystore` (password: `password`)
     - Upload: `Android/upload.keystore` (password: `password`)
   - Click **Create new...** if keystores don't exist, or **Choose existing...** if they do
   - Enter password: `password`
   - Key alias: `release`
   - Key password: `password`

5. **Select Build Variant**
   - Build variant: **normalRelease** (for release APK)
   - Or **normalDebug** (for debug APK - no signing needed)
   - Click **Finish**

6. **Locate APK**
   - After build completes, click **locate** link
   - APK will be at: `Android/android/app/build/outputs/apk/normal/release/app-normal-release.apk`

---

## Method 2: Using Command Line (Gradle)

### Prerequisites
- Java JDK 17+ installed
- Android SDK installed (or use Android Studio's SDK)

### Steps:

1. **Open Terminal/Command Prompt**
   - Navigate to `Android/android` directory

2. **Build Release APK**
   ```bash
   # Windows
   gradlew.bat assembleNormalRelease

   # Linux/Mac
   ./gradlew assembleNormalRelease
   ```

3. **Build Debug APK** (faster, no signing)
   ```bash
   # Windows
   gradlew.bat assembleNormalDebug

   # Linux/Mac
   ./gradlew assembleNormalDebug
   ```

4. **Locate APK**
   - Release APK: `Android/android/app/build/outputs/apk/normal/release/app-normal-release.apk`
   - Debug APK: `Android/android/app/build/outputs/apk/normal/debug/app-normal-debug.apk`

---

## Method 3: Using Android Studio Build Menu

1. **Open Project** in Android Studio
2. **Select Build Variant**
   - Bottom left: Click "Build Variants" tab
   - Select `normalRelease` for release APK
   - Or `normalDebug` for debug APK
3. **Build APK**
   - Menu: **Build → Build Bundle(s) / APK(s) → Build APK(s)**
   - Wait for build to complete
   - Click "locate" when notification appears

---

## Build Variants Available

- **normalDebug** - Debug APK (no signing, faster build, includes debug features)
- **normalRelease** - Release APK (signed, optimized, production-ready)
- **normalUpload** - Upload APK (alternative signing config)

---

## Troubleshooting

### Error: "Keystore file not found"
- The keystore files should be at `Android/release.keystore` and `Android/upload.keystore`
- If missing, create new keystore:
  ```bash
  keytool -genkey -v -keystore release.keystore -alias release -keyalg RSA -keysize 2048 -validity 10000
  ```
- Or use Android Studio's "Create new..." option

### Error: "SDK location not found"
- Set ANDROID_HOME environment variable:
  - Windows: `set ANDROID_HOME=C:\Users\YourName\AppData\Local\Android\Sdk`
  - Linux/Mac: `export ANDROID_HOME=$HOME/Android/Sdk`

### Error: "Gradle sync failed"
- Check internet connection (needs to download dependencies)
- Ensure JDK 17+ is installed
- Try: **File → Invalidate Caches / Restart**

### Build takes too long
- First build downloads all dependencies (including Printer-ktx)
- Subsequent builds are faster
- Use debug variant for faster builds during development

---

## APK File Size

- **Debug APK**: ~15-25 MB (includes debug symbols)
- **Release APK**: ~8-15 MB (optimized, minified)

---

## Installing APK

1. **Enable Unknown Sources** on Android device:
   - Settings → Security → Unknown Sources (enable)

2. **Transfer APK** to device:
   - Via USB: Copy APK to device
   - Via email/cloud: Send APK to yourself

3. **Install**:
   - Open APK file on device
   - Tap "Install"
   - Grant permissions when prompted

---

## Quick Build Commands Summary

```bash
# Navigate to project
cd Android/android

# Build release APK
gradlew.bat assembleNormalRelease    # Windows
./gradlew assembleNormalRelease      # Linux/Mac

# Build debug APK (faster)
gradlew.bat assembleNormalDebug      # Windows
./gradlew assembleNormalDebug        # Linux/Mac

# Clean build (if having issues)
gradlew.bat clean assembleNormalRelease
```

---

## Notes

- **Release APK** is signed and ready for distribution
- **Debug APK** is for testing only (not for production)
- First build may take 5-10 minutes (downloading dependencies)
- Ensure you have internet connection for first build
- Printer-ktx library will be downloaded automatically during build
