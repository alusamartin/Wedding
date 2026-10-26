package ke.everafter.couple.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The cache's one piece of real logic: folding a freshly fetched section into
 * the snapshot that is already on disk.
 *
 * The failure this guards against is subtle and silent — a tap that reloads the
 * guest list must not delete the home screen cached five minutes earlier, or a
 * couple opening the app offline finds half a wedding. The storage layer around
 * this (EncryptedSharedPreferences) needs a device; the rule does not.
 */
class SnapshotCodecTest {

    private fun home(couple: String = "Brian & Faith") = HomeData(
        couple = couple,
        wedding = WeddingDetail(bookingId = 200, label = couple, daysToGo = 54),
        hasBooking = true,
        checklist = ChecklistSummary(total = 10, done = 3, percent = 30),
        guests = GuestCounts(total = 40, yes = 12, pending = 20),
    )

    private fun checklist() = ChecklistData(
        progress = Progress(total = 2, done = 1, percent = 50),
        phases = listOf(
            ChecklistPhase(
                name = "Legal & Church",
                items = listOf(ChecklistItem(id = 7, title = "Book the church", isDone = true)),
            ),
        ),
    )

    private fun guests() = GuestsData(
        counts = GuestCounts(total = 2, yes = 1),
        guests = listOf(Guest(id = 11, name = "Achieng Otieno", rsvp = "yes", dietary = "No pork")),
    )

    @Test
    fun `saving one section keeps the others`() {
        val afterHome = SnapshotCodec.merge(null, home = home(), now = 1_000)
        val afterGuests = SnapshotCodec.merge(afterHome, guests = guests(), now = 2_000)

        assertEquals("the home screen must survive a guest-list refresh", home(), afterGuests.home)
        assertEquals(guests(), afterGuests.guests)
        assertNull("nothing cached a checklist yet", afterGuests.checklist)
    }

    @Test
    fun `saving a section replaces only that section`() {
        val first = SnapshotCodec.merge(null, home = home("Brian & Faith"), checklist = checklist(), now = 1_000)
        val second = SnapshotCodec.merge(first, home = home("Brian & Faith Wafula"), now = 2_000)

        assertEquals("Brian & Faith Wafula", second.home?.couple)
        assertEquals("the checklist is untouched", checklist(), second.checklist)
    }

    @Test
    fun `the timestamp moves to the newest write`() {
        val first = SnapshotCodec.merge(null, home = home(), now = 1_000)
        assertEquals(1_000L, first.savedAt)
        val second = SnapshotCodec.merge(first, guests = guests(), now = 9_000)
        assertEquals(9_000L, second.savedAt)
    }

    @Test
    fun `an empty snapshot says it has nothing to paint`() {
        assertFalse(SnapshotCodec.merge(null, now = 500).hasContent)
        assertTrue(SnapshotCodec.merge(null, home = home(), now = 500).hasContent)
    }

    @Test
    fun `everything survives a trip through json`() {
        val snapshot = SnapshotCodec.merge(null, home = home(), checklist = checklist(), guests = guests(), now = 42)

        val restored = SnapshotCodec.decode(SnapshotCodec.encode(snapshot))

        assertEquals(snapshot, restored)
    }

    @Test
    fun `a corrupted or missing cache is not an exception`() {
        assertNull(SnapshotCodec.decode(null))
        assertNull(SnapshotCodec.decode(""))
        assertNull(SnapshotCodec.decode("{not json"))
        assertNull(SnapshotCodec.decode("[1,2,3]"))
    }

    @Test
    fun `a cache written by another build still loads`() {
        // Unknown fields are ignored and missing ones fall back to their
        // defaults, so a snapshot from a build that had a section this one
        // does not (or vice versa) still opens instead of being discarded.
        val raw = """
            {"saved_at":7,"home":{"couple":"Brian & Faith","has_booking":true},"budget":{"balance":5000}}
        """.trimIndent()

        val decoded = SnapshotCodec.decode(raw)

        assertEquals("Brian & Faith", decoded?.home?.couple)
        assertTrue(decoded?.home?.hasBooking == true)
        assertEquals(7L, decoded?.savedAt)
    }
}
