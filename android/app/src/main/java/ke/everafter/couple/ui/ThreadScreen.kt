package ke.everafter.couple.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Send
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledIconButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.IconButtonDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.everafter.couple.data.DealSummary
import ke.everafter.couple.data.Message

/**
 * One conversation with one vendor.
 *
 * Two rules shape it. Your messages sit on the right, everything else on the
 * left with the sender named — a planner can speak in this thread too, and a
 * couple must be able to tell the planner's word from the vendor's. And the
 * reply box never clears until the server has the message, so a dropped
 * connection costs a retry, not the paragraph that was typed.
 */
@Composable
fun ThreadScreen(
    state: UiState,
    dealId: Int,
    onBack: () -> Unit,
    onSend: (String) -> Unit,
) {
    val thread = state.thread
    val listState = rememberLazyListState()
    var draft by rememberSaveable(dealId) { mutableStateOf("") }

    // Clear the composer only when a send has actually landed. Remembering the
    // count we started with matters: opening a thread that already holds your
    // messages must not wipe a draft typed while it loaded.
    var seenSends by remember { mutableIntStateOf(state.sentCount) }
    LaunchedEffect(state.sentCount) {
        if (state.sentCount != seenSends) {
            seenSends = state.sentCount
            draft = ""
        }
    }

    val messages = thread?.messages.orEmpty()
    LaunchedEffect(messages.size) {
        if (messages.isNotEmpty()) {
            listState.animateScrollToItem(messages.size - 1)
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .imePadding(),
    ) {
        ThreadHeader(deal = thread?.deal, fallbackId = dealId, onBack = onBack)
        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)

        Box(modifier = Modifier.weight(1f)) {
            when {
                // A thread that failed to load must say so instead of spinning
                // forever on a bus with no signal.
                thread == null && state.offline -> EmptyState(
                    title = state.offlineReason.ifBlank { "You are offline" },
                    detail = "This conversation needs a connection. Reopen it when you have one.",
                )

                thread == null -> Box(
                    modifier = Modifier.fillMaxSize(),
                    contentAlignment = Alignment.Center,
                ) {
                    CircularProgressIndicator(color = MaterialTheme.colorScheme.primary)
                }

                messages.isEmpty() -> EmptyState(
                    title = "No messages yet",
                    detail = "Ask about dates, numbers or what is included — the vendor sees it on their own ledger.",
                )

                else -> LazyColumn(
                    state = listState,
                    modifier = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(horizontal = 16.dp, vertical = 14.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    items(messages, key = { it.id }) { message -> Bubble(message) }
                }
            }
        }

        Composer(
            draft = draft,
            onDraftChange = { draft = it.take(2000) },
            sending = state.sending,
            onSend = { onSend(draft) },
        )
    }
}

@Composable
private fun ThreadHeader(deal: DealSummary?, fallbackId: Int, onBack: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(start = 4.dp, end = 16.dp, top = 6.dp, bottom = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(onClick = onBack) {
            Icon(
                imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                contentDescription = "Back to messages",
                tint = MaterialTheme.colorScheme.onBackground,
            )
        }
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = deal?.vendor?.ifBlank { "Conversation" } ?: "Conversation",
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onBackground,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = deal?.let {
                    listOf(it.category, money(it.amount), statusLabel(it.status))
                        .filter { part -> part.isNotBlank() }
                        .joinToString(" · ")
                } ?: "Deal $fallbackId",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun Bubble(message: Message) {
    val mine = message.from == "you"
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start,
    ) {
        Column(
            modifier = Modifier.widthIn(max = 300.dp),
            horizontalAlignment = if (mine) Alignment.End else Alignment.Start,
        ) {
            if (!mine && message.role == "planner") {
                Text(
                    text = message.author.ifBlank { "Your planner" },
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(start = 6.dp, bottom = 3.dp),
                )
            }
            Box(
                modifier = Modifier
                    .background(
                        color = if (mine) MaterialTheme.colorScheme.primary
                        else MaterialTheme.colorScheme.surfaceVariant,
                        shape = if (mine) {
                            RoundedCornerShape(18.dp, 18.dp, 6.dp, 18.dp)
                        } else {
                            RoundedCornerShape(18.dp, 18.dp, 18.dp, 6.dp)
                        },
                    )
                    .padding(horizontal = 14.dp, vertical = 10.dp),
            ) {
                Text(
                    text = message.body,
                    style = MaterialTheme.typography.bodyLarge,
                    color = if (mine) MaterialTheme.colorScheme.onPrimary
                    else MaterialTheme.colorScheme.onSurface,
                )
            }
            Text(
                text = chatStamp(message.at),
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.outline,
                modifier = Modifier.padding(horizontal = 6.dp, vertical = 3.dp),
            )
        }
    }
}

@Composable
private fun Composer(
    draft: String,
    onDraftChange: (String) -> Unit,
    sending: Boolean,
    onSend: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(MaterialTheme.colorScheme.surface)
            .navigationBarsPadding()
            .padding(horizontal = 12.dp, vertical = 8.dp),
        verticalAlignment = Alignment.Bottom,
    ) {
        OutlinedTextField(
            value = draft,
            onValueChange = onDraftChange,
            modifier = Modifier.weight(1f),
            placeholder = { Text("Write a message…") },
            maxLines = 4,
            shape = RoundedCornerShape(20.dp),
            colors = OutlinedTextFieldDefaults.colors(
                focusedBorderColor = MaterialTheme.colorScheme.primary,
                unfocusedBorderColor = MaterialTheme.colorScheme.outlineVariant,
                focusedContainerColor = MaterialTheme.colorScheme.background,
                unfocusedContainerColor = MaterialTheme.colorScheme.background,
            ),
        )
        FilledIconButton(
            onClick = onSend,
            enabled = draft.isNotBlank() && !sending,
            modifier = Modifier.padding(start = 6.dp, bottom = 4.dp),
            colors = IconButtonDefaults.filledIconButtonColors(
                containerColor = MaterialTheme.colorScheme.primary,
                contentColor = MaterialTheme.colorScheme.onPrimary,
                disabledContainerColor = MaterialTheme.colorScheme.surfaceVariant,
                disabledContentColor = MaterialTheme.colorScheme.outline,
            ),
        ) {
            if (sending) {
                CircularProgressIndicator(
                    modifier = Modifier.size(18.dp),
                    strokeWidth = 2.dp,
                    color = MaterialTheme.colorScheme.onPrimary,
                )
            } else {
                Icon(Icons.Default.Send, contentDescription = "Send")
            }
        }
    }
}
