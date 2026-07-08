# Java 11+ Installation Instructions

## Problem
The Android Gradle Plugin 7.4.2 requires Java 11 or higher, but your system currently has Java 8 installed.

## Solution: Install Java 11 or Higher

### Option 1: Install Eclipse Temurin (Adoptium) - Recommended

1. Visit: https://adoptium.net/
2. Download Java 11 LTS or Java 17 LTS (recommended)
3. Run the installer
4. After installation, set JAVA_HOME:
   ```powershell
   # For Java 11 (adjust path if different)
   [System.Environment]::SetEnvironmentVariable('JAVA_HOME', 'C:\Program Files\Eclipse Adoptium\jdk-11.x.x-hotspot', 'User')
   
   # Or for Java 17
   [System.Environment]::SetEnvironmentVariable('JAVA_HOME', 'C:\Program Files\Eclipse Adoptium\jdk-17.x.x-hotspot', 'User')
   ```
5. Restart your terminal/PowerShell
6. Verify: `java -version` should show Java 11 or higher

### Option 2: Install Oracle JDK

1. Visit: https://www.oracle.com/java/technologies/downloads/
2. Download Java 11 or Java 17
3. Install and set JAVA_HOME as shown above

### Option 3: Use Chocolatey (if installed)

```powershell
choco install openjdk11
# or
choco install openjdk17
```

### After Installation

1. Verify Java version:
   ```powershell
   java -version
   ```

2. Set JAVA_HOME (if not set automatically):
   ```powershell
   $env:JAVA_HOME = "C:\Program Files\Eclipse Adoptium\jdk-17.x.x-hotspot"
   ```

3. Try building again:
   ```powershell
   ./gradlew assembleRelease
   ```

## Alternative: Downgrade Android Gradle Plugin (Not Recommended)

If you cannot install Java 11+, you can downgrade the Android Gradle Plugin to version 4.2.2 (last version supporting Java 8), but this may cause compatibility issues.

To downgrade, edit `build.gradle` and change:
```gradle
classpath 'com.android.tools.build:gradle:7.4.2'
```
to:
```gradle
classpath 'com.android.tools.build:gradle:4.2.2'
```

You may also need to downgrade Gradle wrapper version in `gradle/wrapper/gradle-wrapper.properties` to a compatible version (e.g., Gradle 6.7.1).
