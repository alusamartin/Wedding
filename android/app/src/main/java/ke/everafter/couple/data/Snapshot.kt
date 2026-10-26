package ke.everafter.couple.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json

/**
 * The last thing this phone saw of the couple's wedding.
 *
 * One snapshot, three sections, and the moment it was written. It exists so the
 * app has something honest to paint while a 3G request is still in the air —
 * the alternative is a spinner, and a spinner over an empty screen tells a
 * couple nothing about their wedding.
 *
 * The sections are the screens that are worth reading on a bad connection:
 * home, the checklist, the guest list. Budget, vendor conversations and
 * notifications are left out on purpose — those are money and messages, where a
 * stale figure shown as current would be worse than "connect to continue".
 *
 * `savedAt` is when the phone last reached the server, not when the section was
 * first fetched; the banner says "as it was 2 h ago", and every section is
 * refreshed on every load, so they age together.
 */
@Serializable
data class CachedSnapshot(
    @SerialName("saved_at") val savedAt: Long = 0,
    val home: HomeData? = null,
    val checklist: ChecklistData? = null,
    val guests: GuestsData? = null,
) {
    /** True when there is something on disk worth painting. */
    val hasContent: Boolean
        get() = home != null || checklist != null || guests != null
}

/**
 * How the snapshot is turned into bytes and back.
 *
 * Kept apart from [CacheStore] — which needs Android's Keystore — so the merge
 * rule below can be unit-tested on a plain JVM. That rule is the part with a
 * bug in it if there ever is one: saving a checklist must not wipe the home
 * screen that was cached a second earlier.
 */
object SnapshotCodec {

    val json: Json = Json {
        ignoreUnknownKeys = true
        encodeDefaults = true
        explicitNulls = false
    }

    fun encode(snapshot: CachedSnapshot): String = json.encodeToString(snapshot)

    /** Null for anything unreadable — a corrupted cache must never crash a start. */
    fun decode(raw: String?): CachedSnapshot? {
        if (raw.isNullOrBlank()) return null
        return runCatching { json.decodeFromString<CachedSnapshot>(raw) }.getOrNull()
    }

    /**
     * Fold one freshly fetched section into the snapshot that is already there.
     *
     * A null argument means "this section was not reloaded", never "delete it":
     * a couple who opens only the checklist must still find yesterday's home
     * screen when they come back offline. `savedAt` moves to `now` because the
     * phone did reach the server.
     */
    fun merge(
        current: CachedSnapshot?,
        home: HomeData? = null,
        checklist: ChecklistData? = null,
        guests: GuestsData? = null,
        now: Long = System.currentTimeMillis(),
    ): CachedSnapshot = CachedSnapshot(
        savedAt = now,
        home = home ?: current?.home,
        checklist = checklist ?: current?.checklist,
        guests = guests ?: current?.guests,
    )
}
