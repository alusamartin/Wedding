package ke.everafter.couple.data

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import ke.everafter.couple.BuildConfig
import okhttp3.HttpUrl.Companion.toHttpUrl

/**
 * Where the phone keeps its sign-in.
 *
 * The bearer is a 60-day credential that can read a couple's wedding, so it
 * does not belong in plain SharedPreferences (readable on a rooted phone, in
 * an ADB backup, or by anything that can read the app's data directory).
 * EncryptedSharedPreferences keeps the key in the Android Keystore, which is
 * hardware-backed on most phones: the value is unreadable even if the prefs
 * file itself is copied off the device.
 */
class SessionStore(context: Context) {

    private val appContext = context.applicationContext

    private val prefs by lazy {
        val masterKey = MasterKey.Builder(appContext)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        EncryptedSharedPreferences.create(
            appContext,
            "everafter_session",
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    }

    /** The current bearer, or null when this phone is signed out. */
    var token: String?
        get() = prefs.getString(KEY_TOKEN, null)?.takeIf { it.isNotBlank() }
        set(value) {
            prefs.edit().apply {
                if (value.isNullOrBlank()) remove(KEY_TOKEN) else putString(KEY_TOKEN, value)
            }.apply()
        }

    /**
     * The install this app talks to, always ending in `/api/v1/`.
     *
     * The build's own default is used until someone changes it in Settings,
     * so a stamped APK works with no setup at all, while a tester can point
     * the same APK at a staging server.
     */
    var baseUrl: String
        get() {
            val stored = prefs.getString(KEY_BASE, null)
            if (!stored.isNullOrBlank()) return stored
            return normalizeBaseUrl(BuildConfig.API_BASE_URL) ?: ""
        }
        set(value) {
            val normalized = normalizeBaseUrl(value)
            prefs.edit().apply {
                if (normalized == null) remove(KEY_BASE) else putString(KEY_BASE, normalized)
            }.apply()
        }

    /** The name this device shows in the operator's device list. */
    val deviceName: String
        get() = "${android.os.Build.MANUFACTURER.orEmpty().trim()} ${android.os.Build.MODEL.orEmpty().trim()}".trim()
            .ifBlank { "Android" }

    fun clearToken() {
        prefs.edit().remove(KEY_TOKEN).apply()
    }

    companion object {
        private const val KEY_TOKEN = "token"
        private const val KEY_BASE = "base_url"

        /**
         * Accept what a human actually types — `mydomain.co.ke`,
         * `https://mydomain.co.ke/`, or the full API path — and return the
         * one form the client expects. Null when it cannot be an address.
         *
         * A missing scheme becomes https on purpose: this app must never
         * silently fall back to plain HTTP for a real domain.
         */
        fun normalizeBaseUrl(input: String?): String? {
            var value = input?.trim().orEmpty()
            if (value.isEmpty()) return null

            if (!value.startsWith("http://", ignoreCase = true) &&
                !value.startsWith("https://", ignoreCase = true)
            ) {
                value = "https://$value"
            }
            value = value.trimEnd('/')

            if (!value.endsWith("/api/v1", ignoreCase = true)) {
                value = "$value/api/v1"
            }

            val parsed = runCatching { value.toHttpUrl() }.getOrNull() ?: return null
            if (parsed.host.isBlank() || !parsed.host.contains('.')) {
                // localhost and 10.0.2.2 are the only dotless hosts we accept,
                // so a typo like "mydomain" fails here instead of at sign-in.
                val host = parsed.host
                if (host != "localhost" && host != "127.0.0.1" && host != "10.0.2.2") return null
            }
            return parsed.toString().trimEnd('/') + "/"
        }
    }
}

