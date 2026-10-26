package ke.everafter.couple.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext

/**
 * The snapshot cache, on disk, under the same Keystore key as the session.
 *
 * Encrypted rather than plain because this is a couple's wedding: names,
 * phone numbers, dietary notes, who declined. None of it is a credential, but
 * all of it is personal, and an app that already pays for a hardware-backed key
 * has no reason to leave it lying in a world-readable file.
 *
 * Everything fails soft. If the Keystore is unavailable (a broken OEM build, a
 * restored backup, a device with no secure lock screen) the cache turns itself
 * off: [load] answers null and [save] answers null, and the app behaves exactly
 * as it did before the cache existed. A convenience must never be the reason a
 * couple cannot open their wedding.
 */
class CacheStore(context: Context) {

    private val appContext = context.applicationContext

    /** Serialises read-modify-write so two parallel loads cannot lose a section. */
    private val mutex = Mutex()

    private val prefs: SharedPreferences? by lazy {
        runCatching {
            val masterKey = MasterKey.Builder(appContext)
                .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                .build()
            EncryptedSharedPreferences.create(
                appContext,
                PREFS_NAME,
                masterKey,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
            )
        }.getOrNull()
    }

    /** The snapshot on disk, or null when there is none (or it cannot be read). */
    suspend fun load(): CachedSnapshot? = withContext(Dispatchers.IO) {
        val store = prefs ?: return@withContext null
        val raw = runCatching { store.getString(KEY_SNAPSHOT, null) }.getOrNull()
        SnapshotCodec.decode(raw)
    }

    /**
     * Fold one section into the cache. Returns when it was written, or null if
     * the cache is unavailable — the caller uses that to decide whether the
     * "last synced" line it is about to show is true.
     */
    suspend fun save(
        home: HomeData? = null,
        checklist: ChecklistData? = null,
        guests: GuestsData? = null,
    ): Long? = withContext(Dispatchers.IO) {
        val store = prefs ?: return@withContext null
        mutex.withLock {
            val current = SnapshotCodec.decode(
                runCatching { store.getString(KEY_SNAPSHOT, null) }.getOrNull()
            )
            val next = SnapshotCodec.merge(current, home = home, checklist = checklist, guests = guests)
            val written = runCatching {
                store.edit().putString(KEY_SNAPSHOT, SnapshotCodec.encode(next)).commit()
            }.getOrDefault(false)
            if (written) next.savedAt else null
        }
    }

    /**
     * Throw the cache away. Called whenever the signed-in identity can change —
     * sign out, an expired token, a new sign-in, a different server — so the
     * next person to open the app can never be shown the last person's wedding.
     */
    suspend fun clear() = withContext(Dispatchers.IO) {
        val store = prefs ?: return@withContext
        mutex.withLock {
            runCatching { store.edit().remove(KEY_SNAPSHOT).commit() }
        }
        Unit
    }

    private companion object {
        const val PREFS_NAME = "everafter_cache"
        const val KEY_SNAPSHOT = "snapshot.v1"
    }
}
