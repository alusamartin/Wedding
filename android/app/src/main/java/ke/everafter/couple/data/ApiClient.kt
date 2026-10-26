package ke.everafter.couple.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.util.concurrent.TimeUnit

/**
 * A failure the UI can explain. `code` is the server's own stable string
 * (`bad_credentials`, `token_expired`, `network`, …) so screens can branch on
 * it without matching prose, and `status` is 0 when the request never left
 * the phone.
 */
class ApiException(
    val code: String,
    message: String,
    val status: Int = 0,
    val retryAfter: Long? = null,
) : Exception(message)

@Serializable
private data class LoginRequest(
    val email: String,
    val password: String,
    val device: String,
    val version: String,
)

@Serializable
private data class ToggleRequest(val id: Int, val done: Boolean)

@Serializable
private data class ReadRequest(val id: Int)

@Serializable
private data class SendMessageRequest(
    @SerialName("deal_id") val dealId: Int,
    val body: String,
)

@Serializable
data class SignOutResult(@SerialName("signed_out") val signedOut: Boolean = false)

@Serializable
data class ReadResult(val marked: Int = 0, val unread: Int = 0)

/**
 * The one door to the server.
 *
 * Deliberately small: plain OkHttp plus kotlinx.serialization, no Retrofit, no
 * DI graph, no image loader. Every kilobyte here is one the couple downloads
 * on a phone plan, and there is exactly one endpoint family to talk to.
 */
class ApiClient(
    private val baseUrl: String,
    private val token: String? = null,
) {

    internal val json = Json {
        ignoreUnknownKeys = true
        explicitNulls = false
        coerceInputValues = true
    }

    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(25, TimeUnit.SECONDS)
        .callTimeout(40, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    // ------------------------------------------------------------ endpoints

    suspend fun ping(): PingData = call("GET", "ping")

    suspend fun login(email: String, password: String, device: String, version: String): LoginData =
        call(
            "POST", "auth/login",
            body = json.encodeToString(LoginRequest(email.trim().lowercase(), password, device, version)),
        )

    suspend fun logout(): SignOutResult = call("POST", "auth/logout", body = "{}")

    suspend fun me(): MeData = call("GET", "me")

    suspend fun home(): HomeData = call("GET", "home")

    suspend fun checklist(): ChecklistData = call("GET", "checklist")

    suspend fun toggleTask(id: Int, done: Boolean): ToggleResult =
        call("POST", "checklist/toggle", body = json.encodeToString(ToggleRequest(id, done)))

    suspend fun guests(rsvp: String? = null, limit: Int = 100, offset: Int = 0): GuestsData {
        val query = buildMap {
            put("limit", limit.toString())
            put("offset", offset.toString())
            if (!rsvp.isNullOrBlank()) put("rsvp", rsvp)
        }
        return call("GET", "guests", query = query)
    }

    suspend fun budget(): BudgetData = call("GET", "budget")

    suspend fun messages(): MessagesData = call("GET", "messages")

    /**
     * One vendor conversation. Reading it on the server is what stamps the
     * vendor's messages read, exactly as opening the row on the website does —
     * so the app never needs a separate "mark read" call.
     */
    suspend fun thread(dealId: Int): ThreadData =
        call("GET", "messages/thread", query = mapOf("deal_id" to dealId.toString()))

    suspend fun sendMessage(dealId: Int, text: String): SendResult =
        call(
            "POST", "messages/send",
            body = json.encodeToString(SendMessageRequest(dealId, text)),
        )

    suspend fun notifications(limit: Int = 50): NotificationsData =
        call("GET", "notifications", query = mapOf("limit" to limit.toString()))

    /** Mark one notification read, or every one when `id` is null. */
    suspend fun markNotificationRead(id: Int? = null): ReadResult =
        call(
            "POST", "notifications/read",
            body = json.encodeToString(ReadRequest(id ?: 0)),
        )

    // ------------------------------------------------------------ transport

    /**
     * The server routes on `?r=<route>`, not on path segments — one PHP file
     * serves every endpoint, so Apache needs no rewrite rules and the API works
     * in a subfolder install exactly as it does at the domain root.
     *
     *   https://domain/api/v1/?r=checklist&limit=100
     */
    private fun buildUrl(path: String, query: Map<String, String>): HttpUrl {
        val parsed = baseUrl.trimEnd('/').toHttpUrlOrNull()
            ?: throw ApiException("bad_server", "That server address is not a valid URL.", 0)
        return parsed.newBuilder().apply {
            addQueryParameter("r", path)
            query.forEach { (key, value) -> addQueryParameter(key, value) }
        }.build()
    }

    /**
     * One request, one envelope, one retry.
     *
     * The retry exists because a Kenyan mobile connection drops mid-request
     * far more often than a server misbehaves; a second attempt on a fresh
     * socket fixes most of those, and it is safe here because every GET is
     * read-only and the two POSTs are idempotent (tick/un-tick, mark read).
     */
    private suspend inline fun <reified T> call(
        method: String,
        path: String,
        body: String? = null,
        query: Map<String, String> = emptyMap(),
    ): T {
        val request = Request.Builder()
            .url(buildUrl(path, query))
            .header("Accept", "application/json")
            .header("User-Agent", "Everafter-Android")
            .apply { token?.let { header("Authorization", "Bearer $it") } }
            .apply {
                if (method == "POST") {
                    post((body ?: "{}").toRequestBody(JSON_MEDIA))
                } else {
                    get()
                }
            }
            .build()

        val (code, text) = exchangeWithRetry(request)

        val envelope = try {
            json.decodeFromString<Envelope<T>>(text)
        } catch (e: Exception) {
            throw ApiException(
                "bad_response",
                "The server sent something unexpected (HTTP $code). Check the server address in Settings.",
                code,
            )
        }

        if (code !in 200..299 || !envelope.ok) {
            val error = envelope.error
            throw ApiException(
                error?.code ?: "http_$code",
                error?.message ?: "The request failed (HTTP $code).",
                code,
                error?.retryAfter,
            )
        }
        return envelope.data ?: throw ApiException("empty", "The server returned no data.", code)
    }

    /**
     * One call, on the IO dispatcher, returning status and body together.
     *
     * Reading `body.string()` is network I/O — it pulls the bytes off the
     * socket — so it MUST happen here, inside [Dispatchers.IO], and not in the
     * caller after this returns. Done the other way round it goes unnoticed on
     * a JVM test (no StrictMode) and throws NetworkOnMainThreadException on a
     * phone, on every single request.
     */
    private suspend fun exchange(request: Request): Pair<Int, String> = withContext(Dispatchers.IO) {
        client.newCall(request).execute().use { res ->
            res.code to res.body?.string().orEmpty()
        }
    }

    /**
     * The same call, retried once. A Kenyan mobile connection drops mid-request
     * far more often than a server misbehaves, and every request here is safe to
     * repeat: the GETs are reads, and the POSTs (tick a task, mark read, send a
     * message, sign in) are idempotent or answer the same on a second attempt.
     */
    private suspend fun exchangeWithRetry(request: Request): Pair<Int, String> = try {
        exchange(request)
    } catch (first: IOException) {
        try {
            exchange(request)
        } catch (second: IOException) {
            throw ApiException("network", "No connection. Check your internet and try again.", 0)
        }
    }

    private companion object {
        val JSON_MEDIA = "application/json; charset=utf-8".toMediaType()
    }
}
