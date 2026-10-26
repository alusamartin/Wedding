package ke.everafter.couple.ui

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * The formatting the Budget and Messages screens rely on.
 *
 * These are pure JVM functions with no Compose in them, so they can be checked
 * on a machine with no emulator — which is the only kind of machine this app
 * has been built on so far. Money is the part worth pinning down: a balance
 * shown as "KSh 245,000.00" is merely ugly, but one that loses a decimal place
 * is wrong.
 */
class FormatTest {

    @Test
    fun `whole shillings print without cents`() {
        assertEquals("KSh 245,000", money(245_000.0))
        assertEquals("KSh 0", money(0.0))
        assertEquals("KSh 1,000,000", money(1_000_000.0))
    }

    @Test
    fun `real cents are kept`() {
        assertEquals("KSh 1,234.50", money(1234.50))
        assertEquals("KSh 50.05", money(50.05))
    }

    @Test
    fun `a deal status reads in the couple's terms`() {
        assertEquals("Confirmed", statusLabel("confirmed"))
        assertEquals("Completed", statusLabel("completed"))
        assertEquals("Cancelled", statusLabel("cancelled"))
        assertEquals("Awaiting reply", statusLabel("pending"))
    }

    @Test
    fun `a payment method is named, not a slug`() {
        assertEquals("M-Pesa", methodLabel("mpesa"))
        assertEquals("Cash", methodLabel("cash"))
        assertEquals("Credit", methodLabel("credit"))
        assertEquals("Payment", methodLabel("other"))
    }

    @Test
    fun `a message from today shows only its time`() {
        val today = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())
        assertEquals("09:41", listStamp("$today 09:41:07"))
        assertEquals("09:41", chatStamp("$today 09:41:07"))
    }

    @Test
    fun `an older message carries its day`() {
        // The year is added only when the message is from a previous one, so
        // the assertion is on the day and the time, not on the exact string.
        val stamp = chatStamp("2026-03-01 14:05:00")
        assertTrue(stamp, stamp.contains("1 Mar"))
        assertTrue(stamp, stamp.contains("14:05"))
        assertTrue(listStamp("2026-03-01 14:05:00").contains("1 Mar"))
    }

    @Test
    fun `the offline banner says how old the copy is`() {
        val now = 1_000_000_000_000L
        assertEquals("a moment ago", agoLabel(now - 20_000, now))
        assertEquals("7 min ago", agoLabel(now - 7 * 60_000, now))
        assertEquals("3 h ago", agoLabel(now - 3 * 3_600_000, now))
        assertEquals("yesterday", agoLabel(now - 30 * 3_600_000, now))
        assertEquals("4 days ago", agoLabel(now - 4 * 24 * 3_600_000, now))
        assertEquals("earlier", agoLabel(null, now))
        assertEquals("earlier", agoLabel(0L, now))
    }

    @Test
    fun `missing or broken timestamps never crash a row`() {
        assertEquals("", chatStamp(null))
        assertEquals("", listStamp(null))
        assertEquals("not a date", chatStamp("not a date"))
    }
}
