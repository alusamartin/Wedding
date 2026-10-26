import org.gradle.api.tasks.testing.Test
import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    id("org.jetbrains.kotlin.plugin.serialization")
}

/**
 * The install this APK talks to. Left empty by default so an unstamped build
 * cannot ship pointing at a domain that does not exist; the release build
 * passes the real one:
 *
 *   ./gradlew assembleRelease -PapiBase=https://your-domain.co.ke
 *
 * The value is only a DEFAULT — the app's Settings screen can override it, and
 * stores the override in the phone's encrypted preferences.
 */
val apiBase: String = (project.findProperty("apiBase") as String?) ?: ""

/**
 * Release signing.
 *
 * The key is `android/keystore/everafter-release.jks` and its password lives in
 * `android/keystore.properties`. BOTH are gitignored: a signing key in a
 * repository is a signing key anybody can publish an update with, and the
 * password in the same commit would make the key's password moot.
 *
 * A clone without them still builds — it produces an unsigned APK, which is
 * exactly what CI (and anyone just compiling) should get. Only a machine that
 * holds the key can produce something a phone will install as an update.
 */
val keystoreFile = rootProject.file("keystore.properties")
val keystoreProps = Properties().apply {
    if (keystoreFile.exists()) {
        keystoreFile.inputStream().use { load(it) }
    }
}
val hasSigningKey = keystoreProps.getProperty("storeFile") != null

android {
    namespace = "ke.everafter.couple"
    compileSdk = 35

    defaultConfig {
        applicationId = "ke.everafter.couple"
        minSdk = 24
        targetSdk = 35
        versionCode = 1
        versionName = "1.0.0"
        buildConfigField("String", "API_BASE_URL", "\"$apiBase\"")
        resourceConfigurations += listOf("en")
    }

    signingConfigs {
        if (hasSigningKey) {
            create("release") {
                storeFile = rootProject.file(keystoreProps.getProperty("storeFile"))
                storePassword = keystoreProps.getProperty("storePassword")
                keyAlias = keystoreProps.getProperty("keyAlias")
                keyPassword = keystoreProps.getProperty("keyPassword")
                // minSdk is 24 — the first release where the v2 scheme is
                // always verified — so the older JAR signature adds bytes and
                // protects nobody. AGP omits it either way; this says so.
                enableV1Signing = false
                enableV2Signing = true
            }
        }
    }

    buildTypes {
        debug {
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
        }
        release {
            // Light: R8 removes everything the app does not touch, which is
            // most of Compose's unused surface and every unused Material icon.
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            // Null when this machine has no key: the APK is then unsigned and
            // a phone will refuse it, which is the honest outcome.
            signingConfig = signingConfigs.findByName("release")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    packaging {
        resources.excludes += setOf("/META-INF/{AL2.0,LGPL2.1}", "/META-INF/DEPENDENCIES")
    }
}

/*
 * THE RELEASE GATE.
 *
 * `assembleRelease` and `bundleRelease` will not produce an APK until
 * `scripts/release_preflight.php` passes: it rebuilds an isolated database, walks
 * every page and write path as every role with scripts/simulate_roles.php, runs
 * the browser flows a person performs, then the full PHPUnit suite.
 *
 * Every defect that reached an Android build so far was invisible from a debug
 * run — an app-login table the seeder never created, a navigation badge whose
 * markup the simulator still asserted against, a request read on the main
 * thread — and this is the run that finds them. It costs a few minutes; a broken
 * APK on a couple's phone costs more.
 *
 * The gate's LAST step installs the signed APK on the attached device, signs in
 * against the seeded database and opens every core screen. That step needs the
 * APK to exist, so it runs the other way round from the rest: steps 1-4 before
 * packaging (above, with --skip-device), step 5 after it, via `releaseDeviceCheck`
 * — which is why `assembleRelease` is finalized by it below. No device attached
 * (or no adb) is reported as a skip, never as a pass.
 *
 *   -PphpBin=/path/to/php    interpreter to use (default: $PHP_BINARY, else php)
 *   -PpreflightArgs=…       extra arguments, e.g. "--keep-db --port=8138"
 *   -PskipPreflight=true    break glass: build anyway, and say so loudly
 *   -PskipDeviceCheck=true  skip only the on-device step
 *   -PrequireDevice=true    no device attached fails the build instead of skipping
 */
val skipPreflight = (project.findProperty("skipPreflight") as String?)?.toBoolean() ?: false
val phpBinary = (project.findProperty("phpBin") as String?)
    ?: System.getenv("PHP_BINARY")
    ?: "php"
val preflightArgs = ((project.findProperty("preflightArgs") as String?) ?: "")
    .split(" ")
    .filter { it.isNotBlank() }

val releasePreflight = tasks.register<Exec>("releasePreflight") {
    group = "verification"
    description = "Release gate: seeded database, every role simulated, full PHPUnit suite."
    // The PHP application is the Gradle project's parent directory.
    workingDir = rootProject.projectDir.parentFile
    // Steps 1-4. Step 5 needs the APK, so it is deferred to releaseDeviceCheck
    // below — running it here as well would judge the PREVIOUS build and pay
    // for the install and the sign-in twice.
    commandLine(listOf(phpBinary, "scripts/release_preflight.php", "--skip-device") + preflightArgs)
    onlyIf { !skipPreflight }
}

val releasePreflightBypassed = tasks.register("releasePreflightBypassed") {
    group = "verification"
    description = "Records that the release gate was skipped on purpose."
    doLast {
        logger.warn("RELEASE GATE BYPASSED (-PskipPreflight=true): no role simulation and no " +
            "PHPUnit ran. This APK has not been verified.")
    }
}

/*
 * Hook the tasks that actually produce a shippable artifact. `preReleaseBuild`
 * is not present in every AGP version, and a dependency on a task that is not
 * there fails the build for the wrong reason; `configureEach` also catches the
 * release tasks AGP registers later in the configuration.
 */
val gatedTasks = setOf("assembleRelease", "bundleRelease", "packageRelease")
tasks.configureEach {
    if (name in gatedTasks) {
        dependsOn(if (skipPreflight) releasePreflightBypassed else releasePreflight)
    }
}

/*
 * Step 5, on the APK this build just produced.
 *
 * `assembleRelease` is only FINALIZED by it, never dependent on it: the check
 * installs the signed APK, so it has to run after packaging, and a task cannot
 * depend on the task that produces its own input. Gradle runs finalizers after
 * the finalized task finishes — and skips them entirely when the gate above
 * fails first, which is the behaviour we want.
 *
 * The port is taken from -PapiBase when that points somewhere local, so the app
 * that was stamped for this machine is the app the seeded server answers.
 */
val skipDeviceCheck = (project.findProperty("skipDeviceCheck") as String?)?.toBoolean() ?: false
val requireDevice = (project.findProperty("requireDevice") as String?)?.toBoolean() ?: false
val devicePort = Regex(":\\d{2,5}").find(apiBase)?.value?.trimStart(':')?.toIntOrNull() ?: 8137

val releaseDeviceCheck = tasks.register<Exec>("releaseDeviceCheck") {
    group = "verification"
    description = "Release gate step 5: install the signed APK on the device, sign in, open every screen."
    workingDir = rootProject.projectDir.parentFile
    val args = mutableListOf(
        "--only=device",
        "--port=$devicePort",
        "--api-base=http://10.0.2.2:$devicePort",
        "--apk=android/app/build/outputs/apk/release/app-release.apk",
    )
    if (requireDevice) {
        args += "--require-device"
    }
    commandLine(listOf(phpBinary, "scripts/release_preflight.php") + args)
    onlyIf { !skipPreflight && !skipDeviceCheck }
}

// `tasks.named` would fail here: AGP registers assembleRelease later in the
// configuration, so the hook has to be reactive, exactly like the gate's own
// dependency above.
tasks.configureEach {
    if (name == "assembleRelease") {
        finalizedBy(releaseDeviceCheck)
    }
}

// The contract test is run by hand against a real server. Gradle does not pass
// the command line's -D flags through to the test JVM, so they are forwarded
// here: -Dwpms.api=https://… -Dwpms.api.writes=true
// Both are documented in ApiContractTest's own docblock.
tasks.withType<Test>().configureEach {
    listOf("wpms.api", "wpms.api.writes").forEach { key ->
        val value = System.getProperty(key) ?: providers.gradleProperty(key).orNull
        if (value != null) {
            systemProperty(key, value)
        }
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2024.12.01")
    implementation(composeBom)

    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.activity:activity-compose:1.9.3")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.7")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.7")
    implementation("androidx.navigation:navigation-compose:2.8.5")

    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")

    // EncryptedSharedPreferences — the bearer lives in the Android Keystore,
    // never in plain SharedPreferences and never in a file the app can read raw.
    implementation("androidx.security:security-crypto:1.1.0-alpha06")

    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.7.3")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")

    debugImplementation("androidx.compose.ui:ui-tooling")

    // JVM-only: the contract test talks to a locally running API and never
    // ships in the APK.
    testImplementation("junit:junit:4.13.2")
}
