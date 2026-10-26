package ke.everafter.couple.ui

import android.app.Application
import android.util.Log
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import ke.everafter.couple.data.ApiClient
import ke.everafter.couple.data.ApiException
import ke.everafter.couple.data.BudgetData
import ke.everafter.couple.data.CacheStore
import ke.everafter.couple.data.ChecklistData
import ke.everafter.couple.data.ChecklistSummary
import ke.everafter.couple.data.GuestsData
import ke.everafter.couple.data.HomeData
import ke.everafter.couple.data.MeData
import ke.everafter.couple.data.MessagesData
import ke.everafter.couple.data.NetworkState
import ke.everafter.couple.data.Progress
import ke.everafter.couple.data.SessionStore
import ke.everafter.couple.data.ThreadData
import ke.everafter.couple.data.Unread
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * One view model for the whole app, on purpose.
 *
 * The screens are four views of the same small object (this couple's wedding),
 * so a per-screen view model would mean four copies of the session, four ways
 * to sign out, and a cache that disagrees with itself after a tick. What the
 * screens get instead is one [UiState] they read and one set of actions they
 * call.
 */
class AppViewModel(app: Application) : AndroidViewModel(app) {

    private val store = SessionStore(app)
    private val cache = CacheStore(app)

    private val _state = MutableStateFlow(
        UiState(serverUrl = store.baseUrl, signedOut = store.token == null)
    )
    val state: StateFlow<UiState> = _state.asStateFlow()

    init {
        start()
    }

    /**
     * Paint from the last snapshot first, then ask the server.
     *
     * The order matters on a Kenyan mobile connection: a request can take ten
     * seconds, and a couple who opens the app at a vendor meeting should see
     * where their wedding stands immediately rather than a spinner. The cached
     * screen is not a lie either — the offline banner says how old it is, and
     * the network result replaces it the moment it lands.
     */
    private fun start() {
        if (store.token == null) {
            _state.update { it.copy(signedOut = true, loading = false) }
            return
        }
        viewModelScope.launch {
            val snapshot = cache.load()
            if (snapshot != null && snapshot.hasContent) {
                _state.update { current ->
                    current.copy(
                        loading = false,
                        home = snapshot.home ?: current.home,
                        checklist = snapshot.checklist ?: current.checklist,
                        guests = snapshot.guests ?: current.guests,
                        lastSyncedAt = snapshot.savedAt.takeIf { it > 0 } ?: current.lastSyncedAt,
                    )
                }
            }
            restore()
        }
    }

    // --------------------------------------------------------------- session

    private fun client(): ApiClient = ApiClient(_state.value.serverUrl, store.token)

    /** Re-open the app on an existing session without asking for a password. */
    private fun restore() {
        val token = store.token
        if (token == null) {
            _state.update { it.copy(signedOut = true, loading = false) }
            return
        }
        viewModelScope.launch {
            // With a snapshot on screen there is nothing to wait for: the
            // spinner belongs to the empty start, not to a refresh.
            _state.update { it.copy(loading = it.home == null, refreshing = it.home != null) }
            try {
                val me = client().me()
                _state.update {
                    it.copy(auth = me, signedOut = false, loading = false, refreshing = false, error = null)
                }
                loadHome()
            } catch (e: Throwable) {
                // A flaky connection must not throw away a valid session: the
                // couple keeps the app open and retries from the UI. An
                // expired one still ends at the sign-in screen.
                handle(e)
            }
        }
    }

    fun signIn(email: String, password: String) {
        if (_state.value.signingIn) return
        if (email.isBlank() || password.isBlank()) {
            _state.update { it.copy(error = "Enter your email and password.") }
            return
        }
        viewModelScope.launch {
            _state.update { it.copy(signingIn = true, error = null) }
            try {
                val api = ApiClient(_state.value.serverUrl)
                val data = api.login(
                    email = email,
                    password = password,
                    device = store.deviceName,
                    version = ke.everafter.couple.BuildConfig.VERSION_NAME,
                )
                // A different account may have used this phone; its cached
                // wedding must never appear under the new sign-in.
                cache.clear()
                store.token = data.token
                _state.update {
                    it.copy(
                        signingIn = false,
                        signedOut = false,
                        auth = MeData(
                            id = data.couple.id,
                            name = data.couple.name,
                            email = data.couple.email,
                        ),
                        error = null,
                    )
                }
                loadHome()
            } catch (e: Throwable) {
                // The screen says one calm sentence; the log keeps the reason,
                // or a failure that is not a server answer is undiagnosable on
                // a phone that is not plugged in.
                Log.e(TAG, "sign-in failed", e)
                _state.update { it.copy(signingIn = false, error = e.friendly()) }
            }
        }
    }

    fun signOut() {
        viewModelScope.launch {
            // Best effort: the local token is cleared even if the server is
            // unreachable, and the row stays revocable from the operator side.
            runCatching { client().logout() }
            store.clearToken()
            cache.clear()
            _state.value = UiState(serverUrl = _state.value.serverUrl, signedOut = true, loading = false)
        }
    }

    /** Point this install at a different server. Always ends signed out. */
    fun changeServer(raw: String) {
        val normalized = SessionStore.normalizeBaseUrl(raw)
        if (normalized == null) {
            _state.update { it.copy(error = "That does not look like a web address. Try https://your-domain.co.ke") }
            return
        }
        store.baseUrl = normalized
        store.clearToken()
        viewModelScope.launch { cache.clear() }
        _state.value = UiState(serverUrl = normalized, signedOut = true, loading = false)
    }

    // --------------------------------------------------------------- data

    /**
     * One request per section at a time.
     *
     * Two things ask for the home data on a cold start — the restore path and
     * the screen's first composition — and a tab reopened while its request is
     * still in the air asks again. Both are harmless to the server, but on a
     * metered connection a duplicate request is still a duplicate download, so
     * the second asks are dropped until the first answers.
     */
    private val inFlight = mutableSetOf<String>()

    private fun begin(key: String): Boolean = inFlight.add(key)

    private fun end(key: String) {
        inFlight.remove(key)
    }

    fun loadHome() {
        if (_state.value.signedOut || !begin("home")) return
        viewModelScope.launch {
            // Only the first load shows a placeholder; a refresh over data that
            // is already on screen stays silent.
            _state.update { it.copy(refreshing = it.home == null) }
            try {
                val home = client().home()
                val savedAt = cache.save(home = home)
                _state.update {
                    it.copy(
                        home = home,
                        refreshing = false,
                        offline = false,
                        error = null,
                        lastSyncedAt = savedAt ?: it.lastSyncedAt,
                    )
                }
            } catch (e: Throwable) {
                handle(e)
            } finally {
                end("home")
            }
        }
    }

    fun loadChecklist() {
        if (_state.value.signedOut || !begin("checklist")) return
        viewModelScope.launch {
            _state.update { it.copy(refreshing = it.checklist == null) }
            try {
                val checklist = client().checklist()
                val savedAt = cache.save(checklist = checklist)
                _state.update {
                    it.copy(
                        checklist = checklist,
                        refreshing = false,
                        offline = false,
                        error = null,
                        lastSyncedAt = savedAt ?: it.lastSyncedAt,
                    )
                }
            } catch (e: Throwable) {
                handle(e)
            } finally {
                end("checklist")
            }
        }
    }

    fun loadGuests(filter: String? = null) {
        if (_state.value.signedOut || !begin("guests")) return
        viewModelScope.launch {
            _state.update { it.copy(refreshing = it.guests == null, guestFilter = filter) }
            try {
                val guests = client().guests(rsvp = filter)
                // Only an unfiltered list is worth remembering: caching "only
                // the guests who said yes" would make the offline list a
                // partial one wearing the whole list's label.
                val savedAt = if (filter == null) cache.save(guests = guests) else null
                _state.update {
                    it.copy(
                        guests = guests,
                        refreshing = false,
                        offline = false,
                        error = null,
                        lastSyncedAt = savedAt ?: it.lastSyncedAt,
                    )
                }
            } catch (e: Throwable) {
                handle(e)
            } finally {
                end("guests")
            }
        }
    }

    /**
     * Tick or un-tick a checklist item.
     *
     * The row changes immediately — a checkbox that waits 400 ms for a round
     * trip feels broken — and is put back if the server refuses, so the screen
     * never shows a tick the database does not have.
     */
    fun toggleTask(id: Int, done: Boolean) {
        if (_state.value.toggling.contains(id)) return
        val before = _state.value.checklist

        _state.update { current ->
            current.copy(
                toggling = current.toggling + id,
                checklist = current.checklist?.withToggled(id, done),
            )
        }

        viewModelScope.launch {
            try {
                val result = client().toggleTask(id, done)
                _state.update { current ->
                    current.copy(
                        toggling = current.toggling - id,
                        offline = false,
                        checklist = current.checklist?.withProgress(result.progress),
                        home = current.home?.withProgress(result.progress),
                    )
                }
                // Keep the snapshot in step with the tick: a couple who ticks
                // something and then loses signal should reopen to the list
                // they just saw, not the one from before.
                _state.value.checklist?.let { cache.save(checklist = it) }
                // The "next up" list is server-computed; refresh it quietly.
                loadHome()
            } catch (e: Throwable) {
                _state.update { current ->
                    current.copy(toggling = current.toggling - id, checklist = before)
                }
                handle(e)
            }
        }
    }

    // --------------------------------------------------------------- money

    fun loadBudget() {
        if (_state.value.signedOut || !begin("budget")) return
        viewModelScope.launch {
            _state.update { it.copy(refreshing = it.budget == null) }
            try {
                val budget = client().budget()
                _state.update {
                    it.copy(budget = budget, refreshing = false, offline = false, error = null)
                }
            } catch (e: Throwable) {
                handle(e)
            } finally {
                end("budget")
            }
        }
    }

    // --------------------------------------------------------------- messages

    fun loadMessages() {
        if (_state.value.signedOut || !begin("messages")) return
        viewModelScope.launch {
            _state.update { it.copy(refreshing = it.messages == null) }
            try {
                val messages = client().messages()
                _state.update { current ->
                    current.copy(
                        messages = messages,
                        refreshing = false,
                        // The Home card reads its own copy of this number; keep
                        // it in step or it keeps saying "1 new" after reading.
                        home = current.home?.copy(messages = Unread(messages.unread)),
                        offline = false,
                    )
                }
            } catch (e: Throwable) {
                handle(e)
            } finally {
                end("messages")
            }
        }
    }

    /**
     * Open one conversation.
     *
     * The old thread is dropped before the request so the screen cannot show
     * the previous vendor's messages under this vendor's name while it loads.
     */
    fun openThread(dealId: Int) {
        if (_state.value.signedOut || dealId <= 0) return
        viewModelScope.launch {
            _state.update { it.copy(threadId = dealId, thread = null) }
            try {
                val thread = client().thread(dealId)
                _state.update { it.copy(thread = thread, offline = false) }
                // Reading the thread cleared the badge server-side; refresh the
                // list so the nav badge and the row agree with it.
                loadMessages()
            } catch (e: Throwable) {
                handle(e)
            }
        }
    }

    fun closeThread() = _state.update { it.copy(threadId = null, thread = null) }

    /**
     * Send a reply. The composer clears only once the server has the message:
     * losing a carefully typed paragraph to a dropped connection is the one
     * failure a chat screen must not have.
     */
    fun sendMessage(dealId: Int, text: String) {
        val body = text.trim()
        if (body.isEmpty() || _state.value.sending) return
        viewModelScope.launch {
            _state.update { it.copy(sending = true) }
            try {
                val result = client().sendMessage(dealId, body)
                _state.update { current ->
                    val thread = current.thread
                    val sent = result.message
                    if (thread != null && sent != null && thread.deal.dealId == dealId) {
                        current.copy(
                            sending = false,
                            sentCount = current.sentCount + 1,
                            thread = thread.copy(messages = thread.messages + sent),
                        )
                    } else {
                        current.copy(sending = false, sentCount = current.sentCount + 1)
                    }
                }
                loadMessages()
            } catch (e: Throwable) {
                _state.update { it.copy(sending = false) }
                handle(e)
            }
        }
    }

    fun dismissError() = _state.update { it.copy(error = null) }

    // --------------------------------------------------------------- helpers

    private fun handle(e: Throwable) {
        Log.w(TAG, "request failed", e)
        if (e is ApiException && e.status == 401) {
            store.clearToken()
            viewModelScope.launch { cache.clear() }
            _state.value = UiState(
                serverUrl = _state.value.serverUrl,
                signedOut = true,
                loading = false,
                error = "Your session expired. Please sign in again.",
            )
            return
        }
        // "network" is the client's own code for "the request never reached a
        // server". Any other failure means a server answered, so the offline
        // notice goes away either way.
        val networkError = e is ApiException && e.code == "network"
        if (!networkError) {
            _state.update {
                it.copy(refreshing = false, loading = false, offline = false, offlineReason = "", error = e.friendly())
            }
            return
        }

        // Two very different problems wear the same exception: no signal, or a
        // server that will not answer. Only the first is the couple's to fix.
        val online = NetworkState.isOnline(getApplication())
        _state.update {
            it.copy(
                refreshing = false,
                loading = false,
                offline = true,
                offlineReason = if (online) "Cannot reach your server" else "You are offline",
                error = if (online) {
                    "Your server did not answer. Check the address in More, or try again shortly."
                } else {
                    e.friendly()
                },
            )
        }
    }

    private fun Throwable.friendly(): String = when (this) {
        is ApiException -> message ?: "Something went wrong."
        else -> "Something went wrong. Please try again."
    }

    private companion object {
        const val TAG = "Everafter"
    }
}

/**
 * Everything the UI draws. One object so a recomposition can never show the
 * old couple's name above the new couple's checklist.
 */
data class UiState(
    val auth: MeData? = null,
    val signedOut: Boolean = false,
    val loading: Boolean = true,
    val signingIn: Boolean = false,
    val refreshing: Boolean = false,
    val home: HomeData? = null,
    val checklist: ChecklistData? = null,
    val guests: GuestsData? = null,
    val guestFilter: String? = null,
    val budget: BudgetData? = null,
    val messages: MessagesData? = null,
    /** The conversation on screen, if any (the thread route). */
    val threadId: Int? = null,
    val thread: ThreadData? = null,
    val sending: Boolean = false,
    /** How many replies have been delivered this session — the composer clears on a change. */
    val sentCount: Int = 0,
    /** The last request never reached a server; what is on screen came from the cache. */
    val offline: Boolean = false,
    /** Why — "You are offline" or "Cannot reach your server". */
    val offlineReason: String = "",
    /** When this phone last reached the server, for the offline banner. */
    val lastSyncedAt: Long? = null,
    val serverUrl: String = "",
    val toggling: Set<Int> = emptySet(),
    val error: String? = null,
)

// ---------------------------------------------------------------- list edits

private fun ChecklistData.withToggled(id: Int, done: Boolean): ChecklistData {
    var doneCount = 0
    val phases = phases.map { phase ->
        phase.copy(items = phase.items.map { item ->
            if (item.id == id) item.copy(isDone = done) else item
        })
    }
    phases.forEach { phase -> phase.items.forEach { if (it.isDone) doneCount++ } }
    val total = phases.sumOf { it.items.size }
    return copy(
        phases = phases,
        progress = Progress(
            total = total,
            done = doneCount,
            percent = if (total > 0) doneCount * 100 / total else 0,
        ),
    )
}

private fun ChecklistData.withProgress(progress: Progress): ChecklistData =
    copy(progress = progress)

private fun HomeData.withProgress(progress: Progress): HomeData =
    copy(
        checklist = ChecklistSummary(
            total = progress.total,
            done = progress.done,
            percent = progress.percent,
            next = checklist.next,
        )
    )
