package ke.everafter.couple.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * The wire shapes of `api/v1/index.php`, field for field.
 *
 * Every optional field has a default so an older server (or a newer one that
 * adds a field) never makes the app fail to parse. `ignoreUnknownKeys` in the
 * client covers the other direction.
 */

@Serializable
data class Envelope<T>(
    val ok: Boolean = false,
    val data: T? = null,
    val error: ApiError? = null,
)

@Serializable
data class ApiError(
    val code: String = "unknown",
    val message: String = "Something went wrong",
    @SerialName("retry_after") val retryAfter: Long? = null,
)

// ---------------------------------------------------------------- login

@Serializable
data class LoginData(
    val token: String,
    @SerialName("expires_at") val expiresAt: String? = null,
    val couple: CoupleBrief,
    val wedding: WeddingBrief? = null,
)

@Serializable
data class CoupleBrief(
    val id: Int,
    val name: String = "",
    @SerialName("first_name") val firstName: String = "",
    val email: String = "",
)

@Serializable
data class WeddingBrief(
    @SerialName("booking_id") val bookingId: Int = 0,
    val label: String? = null,
    val date: String? = null,
    @SerialName("days_to_go") val daysToGo: Int? = null,
    val status: String = "pending",
    @SerialName("has_booking") val hasBooking: Boolean = true,
)

// ---------------------------------------------------------------- me

@Serializable
data class MeData(
    val id: Int = 0,
    val name: String = "",
    val email: String = "",
    val phone: String = "",
    val city: String = "",
    val wedding: WeddingDetail? = null,
)

@Serializable
data class WeddingDetail(
    @SerialName("booking_id") val bookingId: Int = 0,
    val label: String? = null,
    val bride: String = "",
    val groom: String = "",
    val date: String? = null,
    @SerialName("days_to_go") val daysToGo: Int? = null,
    val status: String = "pending",
    val venue: String = "",
)

// ---------------------------------------------------------------- home

@Serializable
data class HomeData(
    val couple: String = "",
    val wedding: WeddingDetail? = null,
    @SerialName("has_booking") val hasBooking: Boolean = false,
    val checklist: ChecklistSummary = ChecklistSummary(),
    val guests: GuestCounts = GuestCounts(),
    val notifications: Unread = Unread(),
    val messages: Unread = Unread(),
)

@Serializable
data class Unread(val unread: Int = 0)

@Serializable
data class ChecklistSummary(
    val total: Int = 0,
    val done: Int = 0,
    val percent: Int = 0,
    val next: List<NextTask> = emptyList(),
)

@Serializable
data class NextTask(
    val id: Int = 0,
    val title: String = "",
    val phase: String = "",
    @SerialName("due_on") val dueOn: String? = null,
    val overdue: Boolean = false,
    @SerialName("days_left") val daysLeft: Int? = null,
)

// ---------------------------------------------------------------- checklist

@Serializable
data class ChecklistData(
    val progress: Progress = Progress(),
    val phases: List<ChecklistPhase> = emptyList(),
)

@Serializable
data class Progress(
    val total: Int = 0,
    val done: Int = 0,
    val percent: Int = 0,
)

@Serializable
data class ChecklistPhase(
    val name: String = "",
    val items: List<ChecklistItem> = emptyList(),
)

@Serializable
data class ChecklistItem(
    val id: Int = 0,
    val title: String = "",
    @SerialName("is_done") val isDone: Boolean = false,
    @SerialName("done_at") val doneAt: String? = null,
    @SerialName("due_on") val dueOn: String? = null,
    val overdue: Boolean = false,
    @SerialName("days_left") val daysLeft: Int? = null,
)

@Serializable
data class ToggleResult(
    val id: Int = 0,
    @SerialName("is_done") val isDone: Boolean = false,
    val progress: Progress = Progress(),
)

// ---------------------------------------------------------------- guests

@Serializable
data class GuestsData(
    val counts: GuestCounts = GuestCounts(),
    val offset: Int = 0,
    val limit: Int = 0,
    val guests: List<Guest> = emptyList(),
)

@Serializable
data class GuestCounts(
    val total: Int = 0,
    val yes: Int = 0,
    val no: Int = 0,
    val maybe: Int = 0,
    val pending: Int = 0,
)

@Serializable
data class Guest(
    val id: Int = 0,
    val name: String = "",
    val phone: String = "",
    val relationship: String = "",
    val priority: String = "",
    val rsvp: String = "pending",
    @SerialName("rsvp_at") val rsvpAt: String? = null,
    val note: String = "",
    val dietary: String = "",
    val transport: Boolean = false,
    val accommodation: Boolean = false,
)

// ---------------------------------------------------------------- budget

@Serializable
data class BudgetData(
    val currency: String = "KES",
    @SerialName("package") val packageInfo: PackageInfo = PackageInfo(),
    val paid: PaidTotals = PaidTotals(),
    val balance: Double = 0.0,
    val pledged: Pledged = Pledged(),
    val vendors: VendorTotals = VendorTotals(),
    val plan: PlanInfo? = null,
    val next: NextDue? = null,
    val payments: List<Payment> = emptyList(),
)

@Serializable
data class PackageInfo(
    val label: String = "",
    val price: Double = 0.0,
)

@Serializable
data class PaidTotals(
    val total: Double = 0.0,
    val mpesa: Double = 0.0,
    val cash: Double = 0.0,
    val credit: Double = 0.0,
    val count: Int = 0,
    @SerialName("last_at") val lastAt: String? = null,
)

@Serializable
data class Pledged(
    val amount: Double = 0.0,
    val guests: Int = 0,
)

@Serializable
data class VendorTotals(
    val deals: Int = 0,
    val committed: Double = 0.0,
    val planned: Double = 0.0,
)

@Serializable
data class PlanInfo(
    val months: Int = 0,
    @SerialName("monthly_amount") val monthlyAmount: Double = 0.0,
    val done: Int = 0,
    val total: Int = 0,
    @SerialName("paid_into_plan") val paidIntoPlan: Double = 0.0,
    @SerialName("on_track") val onTrack: Boolean = false,
    @SerialName("next_due") val nextDue: String? = null,
    @SerialName("next_amount") val nextAmount: Double = 0.0,
)

@Serializable
data class NextDue(
    val kind: String = "",
    val label: String = "",
    val amount: Double = 0.0,
    @SerialName("due_on") val dueOn: String? = null,
    val overdue: Boolean = false,
)

@Serializable
data class Payment(
    val id: Int = 0,
    val amount: Double = 0.0,
    val method: String = "other",
    val reference: String = "",
    val on: String = "",
)

// ---------------------------------------------------------------- messages

@Serializable
data class MessagesData(
    val unread: Int = 0,
    val threads: List<ThreadSummary> = emptyList(),
)

@Serializable
data class ThreadSummary(
    @SerialName("deal_id") val dealId: Int = 0,
    val vendor: String = "",
    val category: String = "",
    val amount: Double = 0.0,
    val status: String = "pending",
    val unread: Int = 0,
    val messages: Int = 0,
    val last: LastMessage? = null,
    @SerialName("waiting_on") val waitingOn: String = "",
    @SerialName("waiting_for") val waitingFor: String? = null,
)

@Serializable
data class LastMessage(
    val body: String = "",
    val from: String = "vendor",
    val at: String = "",
)

@Serializable
data class ThreadData(
    val deal: DealSummary = DealSummary(),
    val messages: List<Message> = emptyList(),
    val unread: Int = 0,
)

@Serializable
data class DealSummary(
    @SerialName("deal_id") val dealId: Int = 0,
    val vendor: String = "",
    val category: String = "",
    val amount: Double = 0.0,
    val status: String = "pending",
    @SerialName("event_date") val eventDate: String? = null,
)

@Serializable
data class Message(
    val id: Int = 0,
    val from: String = "vendor",
    val role: String = "",
    val author: String = "",
    val body: String = "",
    val at: String = "",
)

@Serializable
data class SendResult(
    @SerialName("deal_id") val dealId: Int = 0,
    val message: Message? = null,
    val unread: Int = 0,
)

// ---------------------------------------------------------------- notifications

@Serializable
data class NotificationsData(
    val unread: Int = 0,
    val notifications: List<AppNotification> = emptyList(),
)

@Serializable
data class AppNotification(
    val id: Int = 0,
    val message: String = "",
    val link: String = "",
    @SerialName("is_read") val isRead: Boolean = false,
    val at: String = "",
)

@Serializable
data class PingData(
    val api: String = "",
    @SerialName("min_build") val minBuild: Int = 1,
    @SerialName("server_time") val serverTime: String = "",
    val timezone: String = "",
)
