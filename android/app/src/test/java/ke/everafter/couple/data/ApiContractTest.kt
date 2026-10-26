package ke.everafter.couple.data

import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Assume.assumeTrue
import org.junit.Test
import java.net.InetSocketAddress
import java.net.Socket

/**
 * The one test that proves the app and the server still agree.
 *
 * Compiling proves the DTOs parse *something*; this proves they parse what
 * `api/v1/index.php` actually sends — field names, nullability, the envelope,
 * the `is_done`/`rsvp` spellings. It runs against a local server (XAMPP plus
 * `php -S 127.0.0.1:8099 -t .` from the project root) and is skipped, not
 * failed, when nothing is listening, so a machine with only the Android SDK
 * can still run the unit-test task.
 *
 * Override the target with: -Dwpms.api=https://staging.example.co.ke/api/v1/
 */
class ApiContractTest {

    private val base = System.getProperty("wpms.api") ?: "http://127.0.0.1:8099/api/v1/"

    @Test
    fun `sign in, read every screen, tick a task, sign out`() = runBlocking {
        assumeTrue("no local API at $base — skipping", reachable(base))

        val anonymous = ApiClient(base)

        val ping = anonymous.ping()
        assertEquals("1.0", ping.api)

        val login = anonymous.login(
            email = "brian.wafula@gmail.com",
            password = "Test@1234",
            device = "JVM contract test",
            version = "test",
        )
        assertTrue("token should carry the app prefix", login.token.startsWith("wpmsapp_"))
        assertTrue("couple name should not be empty", login.couple.name.isNotBlank())

        val api = ApiClient(base, login.token)

        val me = api.me()
        assertNotNull("the seeded couple has a wedding", me.wedding)

        val home = api.home()
        assertTrue("home reports a booking", home.hasBooking)
        assertNotNull("home has a wedding", home.wedding)
        assertEquals(
            "checklist totals must agree between home and the checklist endpoint",
            api.checklist().progress.total,
            home.checklist.total,
        )

        val checklist = api.checklist()
        assertTrue("the Kenyan template is seeded", checklist.phases.isNotEmpty())
        assertTrue("phases carry items", checklist.phases.all { it.items.isNotEmpty() })

        val guests = api.guests(limit = 2)
        assertTrue("limit is honoured", guests.guests.size <= 2)
        assertTrue("counts are present", guests.counts.total >= guests.guests.size)

        val notifications = api.notifications(limit = 5)
        assertTrue("the API never returns more than asked", notifications.notifications.size <= 5)

        // ---- money -------
        val budget = api.budget()
        assertTrue("the seeded wedding has a priced package", budget.packageInfo.price > 0)
        assertEquals(
            "balance must be the package price less everything paid",
            budget.packageInfo.price - budget.paid.total,
            budget.balance,
            0.01,
        )
        assertTrue("the seeded wedding has payments on the ledger", budget.payments.isNotEmpty())
        assertTrue("payment methods are named, not ids", budget.payments.all { it.method.isNotBlank() })
        assertNotNull("a wedding with a balance knows what is due next", budget.next)

        // ---- vendor conversations ----
        val messages = api.messages()
        assertTrue("the seeded wedding has vendor deals", messages.threads.isNotEmpty())
        assertTrue("unread is never negative", messages.unread >= 0)
        assertTrue(
            "every thread says whose move it is",
            messages.threads.all { it.waitingOn == "you" || it.waitingOn == "vendor" },
        )

        val deal = messages.threads.first()
        val thread = api.thread(deal.dealId)
        assertEquals("the thread is the deal that was asked for", deal.dealId, thread.deal.dealId)
        assertTrue(
            "every message says who wrote it and when",
            thread.messages.all { (it.from == "you" || it.from == "vendor") && it.at.isNotBlank() },
        )
        assertEquals(
            "opening a thread clears that deal's unread count for this device",
            0,
            api.messages().threads.first { it.dealId == deal.dealId }.unread,
        )

        // Sending is the one write that cannot be undone by the test, so it is
        // opt-in: run with -Dwpms.api.writes=true against a scratch database.
        if (System.getProperty("wpms.api.writes") == "true") {
            val sent = api.sendMessage(deal.dealId, "Contract test — please ignore")
            assertEquals(deal.dealId, sent.dealId)
            assertEquals("a message you sent is yours", "you", sent.message?.from)
            assertEquals(
                "the reply lands in the thread that was open",
                true,
                api.thread(deal.dealId).messages.any { it.id == sent.message?.id },
            )
        }

        // ---- the offline snapshot ----
        // What the cache stores is these three payloads. If the codec lost a
        // field, the app would reopen offline with a screen that is subtly
        // wrong — so the round trip is checked against real server JSON, not
        // hand-written fixtures.
        val snapshot = CachedSnapshot(
            savedAt = 1_700_000_000_000L,
            home = home,
            checklist = checklist,
            guests = api.guests(),
        )
        assertEquals(
            "a cached wedding must come back off disk exactly as it arrived",
            snapshot,
            SnapshotCodec.decode(SnapshotCodec.encode(snapshot)),
        )

        // Ticking twice must be an answer, not a 404: a retried request or a
        // doubled tap is normal on a mobile connection.
        val item = checklist.phases.first().items.first()
        val first = api.toggleTask(item.id, true)
        assertEquals(true, first.isDone)
        val again = api.toggleTask(item.id, true)
        assertEquals(true, again.isDone)
        assertEquals("a no-op tick must not change progress", first.progress.done, again.progress.done)

        // Leave the seed as it was found.
        api.toggleTask(item.id, item.isDone)

        val out = api.logout()
        assertTrue(out.signedOut)

        // The revoked bearer must now be refused, with a code the UI branches on.
        val refused = runCatching { api.me() }.exceptionOrNull()
        assertTrue(refused is ApiException)
        assertEquals(401, (refused as ApiException).status)
    }

    private fun reachable(url: String): Boolean = try {
        val authority = url.substringAfter("://").substringBefore('/')
        val host = authority.substringBefore(':')
        val port = authority.substringAfter(':', "").toIntOrNull()
            ?: if (url.startsWith("https")) 443 else 80
        Socket().use { it.connect(InetSocketAddress(host, port), 400) }
        true
    } catch (e: Exception) {
        false
    }
}
