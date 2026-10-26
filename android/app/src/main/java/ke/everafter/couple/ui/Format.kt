package ke.everafter.couple.ui

import java.text.NumberFormat
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

/**
 * Formatting helpers.
 *
 * Kept on SimpleDateFormat rather than java.time on purpose: java.time needs
 * API 26 (or core-library desugaring, which costs method count and build time),
 * and `minSdk` is 24 — an Android 7 phone in Nairobi is still a real device.
 */
private val ISO = SimpleDateFormat("yyyy-MM-dd", Locale.US)
private val PRETTY = SimpleDateFormat("d MMM yyyy", Locale.UK)

/** "2026-12-19" → "19 Dec 2026". Returns the input when it cannot be read. */
fun prettyDate(iso: String?): String {
    if (iso.isNullOrBlank()) return ""
    return try {
        val parsed = ISO.parse(iso) ?: return iso
        PRETTY.format(parsed)
    } catch (e: Exception) {
        iso
    }
}

/**
 * The one line under a checklist item: how long is left, or how late it is.
 *
 * Silence would be worse than a number here — a couple scrolling the list needs
 * to know whether "Final headcount" is due in three days or was due last month.
 */
fun dueLabel(dueOn: String?, daysLeft: Int?, overdue: Boolean): String {
    if (dueOn.isNullOrBlank()) return "No deadline"
    val date = prettyDate(dueOn)
    return when {
        daysLeft == null -> "Due $date"
        daysLeft < 0 -> "Overdue by ${-daysLeft} ${if (-daysLeft == 1) "day" else "days"}"
        daysLeft == 0 -> "Due today"
        daysLeft == 1 -> "Due tomorrow"
        overdue -> "Overdue · due $date"
        else -> "Due $date · ${daysLeft} days left"
    }
}

/** The countdown's own wording, which is the app's loudest sentence. */
fun countdownHeadline(days: Int?): String = when {
    days == null -> "Date not set"
    days > 1 -> "$days"
    days == 1 -> "1"
    days == 0 -> "Today"
    days == -1 -> "Yesterday"
    else -> "${-days} ago"
}

fun countdownCaption(days: Int?): String = when {
    days == null -> "Agree a date with your planner"
    days > 1 -> "days to go"
    days == 1 -> "day to go"
    days == 0 -> "is the day"
    else -> "since the wedding"
}

/**
 * Kenyan shillings, the way a statement prints them: "KSh 245,000".
 *
 * Cents appear only when the figure actually has them — a wedding budget is
 * quoted in whole shillings, and "KSh 245,000.00" on every row is noise. A
 * stray 0.50 from a partial M-Pesa deposit still shows, because rounding money
 * away is how disagreements start.
 */
private val MONEY = NumberFormat.getNumberInstance(Locale.US).apply {
    maximumFractionDigits = 0
}

private val MONEY_CENTS = NumberFormat.getNumberInstance(Locale.US).apply {
    minimumFractionDigits = 2
    maximumFractionDigits = 2
}

fun money(amount: Double): String {
    val hasCents = amount % 1.0 != 0.0
    val text = if (hasCents) MONEY_CENTS.format(amount) else MONEY.format(amount)
    return "KSh $text"
}

/** The long name of a payment method, for the ledger rows. */
fun methodLabel(method: String): String = when (method.lowercase()) {
    "mpesa" -> "M-Pesa"
    "cash" -> "Cash"
    "credit" -> "Credit"
    else -> "Payment"
}

/** A vendor deal's status in the couple's terms, not the database's. */
fun statusLabel(status: String): String = when (status.lowercase()) {
    "confirmed" -> "Confirmed"
    "completed" -> "Completed"
    "cancelled" -> "Cancelled"
    else -> "Awaiting reply"
}

/** "yes" → "Coming", "no" → "Declined", and so on. */
/**
 * How long ago this phone last reached the server — the offline banner's one
 * fact. Deliberately coarse: "2 h ago" is what a couple needs to decide
 * whether to trust the screen; the exact minute is not.
 */
fun agoLabel(millis: Long?, now: Long = System.currentTimeMillis()): String {
    if (millis == null || millis <= 0L) return "earlier"
    val minutes = ((now - millis) / 60_000L).toInt().coerceAtLeast(0)
    return when {
        minutes < 1 -> "a moment ago"
        minutes < 60 -> "$minutes min ago"
        minutes < 60 * 24 -> "${minutes / 60} h ago"
        minutes < 60 * 48 -> "yesterday"
        else -> "${minutes / (60 * 24)} days ago"
    }
}

// ---------------------------------------------------------------- messages

/** MySQL datetime ("2026-10-26 09:48:41") → a moment the app can compare. */
private val SQL_TIME = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
private val CLOCK = SimpleDateFormat("HH:mm", Locale.UK)
private val DAY_MONTH = SimpleDateFormat("d MMM", Locale.UK)
private val DAY_MONTH_YEAR = SimpleDateFormat("d MMM yyyy", Locale.UK)

private fun parseSql(raw: String?): java.util.Date? {
    if (raw.isNullOrBlank()) return null
    return try {
        SQL_TIME.parse(raw)
    } catch (e: Exception) {
        null
    }
}

/**
 * "1 Mar" — with the year once the message is from a previous one, because a
 * couple who started enquiring eighteen months out would otherwise read last
 * year's reply as this year's.
 */
private fun dayMonth(date: java.util.Date): String {
    val thisYear = Calendar.getInstance().get(Calendar.YEAR)
    val thatYear = Calendar.getInstance().apply { time = date }.get(Calendar.YEAR)
    return if (thisYear == thatYear) DAY_MONTH.format(date) else DAY_MONTH_YEAR.format(date)
}

private fun midnight(date: java.util.Date): Long =
    Calendar.getInstance().apply {
        time = date
        set(Calendar.HOUR_OF_DAY, 0)
        set(Calendar.MINUTE, 0)
        set(Calendar.SECOND, 0)
        set(Calendar.MILLISECOND, 0)
    }.timeInMillis

/**
 * The stamp on a message bubble: the time if it was today, otherwise the day
 * as well. A conversation with a vendor spans weeks, so "09:48" alone would be
 * ambiguous the moment it scrolls back.
 */
fun chatStamp(raw: String?): String {
    val date = parseSql(raw) ?: return raw.orEmpty()
    val days = ((midnight(java.util.Date()) - midnight(date)) / 86_400_000L).toInt()
    return when {
        days <= 0 -> CLOCK.format(date)
        days == 1 -> "Yesterday " + CLOCK.format(date)
        else -> dayMonth(date) + " " + CLOCK.format(date)
    }
}

/** The stamp in the conversation list: compact, relative, one glance. */
fun listStamp(raw: String?): String {
    val date = parseSql(raw) ?: return ""
    val days = ((midnight(java.util.Date()) - midnight(date)) / 86_400_000L).toInt()
    return when {
        days <= 0 -> CLOCK.format(date)
        days == 1 -> "Yesterday"
        days < 7 -> "$days days ago"
        else -> dayMonth(date)
    }
}

fun rsvpLabel(rsvp: String): String = when (rsvp.lowercase()) {
    "yes" -> "Coming"
    "no" -> "Declined"
    "maybe" -> "Maybe"
    else -> "Waiting"
}
