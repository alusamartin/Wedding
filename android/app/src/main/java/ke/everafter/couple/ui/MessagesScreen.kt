package ke.everafter.couple.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ke.everafter.couple.data.ThreadSummary

/**
 * Every vendor conversation on this wedding, the one that moved most recently
 * first.
 *
 * The row is built to be read at a glance: who, what it costs, the last thing
 * said, and whether the ball is in the couple's court. That last part is the
 * whole reason this screen exists — a couple should not have to open five
 * threads to find the one waiting on them.
 */
@Composable
fun MessagesScreen(
    state: UiState,
    onOpen: (Int) -> Unit,
) {
    val data = state.messages

    LazyColumn(
        modifier = Modifier.fillMaxWidth(),
        contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = 12.dp, bottom = 28.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item {
            Column(modifier = Modifier.padding(bottom = 6.dp)) {
                Text(
                    text = "Messages",
                    style = MaterialTheme.typography.headlineSmall,
                    color = MaterialTheme.colorScheme.onBackground,
                )
                Spacer(Modifier.height(4.dp))
                Text(
                    text = "Talk to the vendors you have deals with.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        when {
            data == null && state.refreshing -> item {
                EmptyState("Loading your conversations…", "One moment.")
            }

            data == null && state.offline -> item {
                EmptyState(
                    title = state.offlineReason.ifBlank { "You are offline" },
                    detail = "Replies need a connection. Messages waiting on you will be here when you are back.",
                )
            }

            data == null -> item {
                EmptyState(
                    title = "No conversations yet",
                    detail = "When your planner sends an enquiry to a vendor, the thread appears here.",
                )
            }

            data.threads.isEmpty() -> item {
                EmptyState(
                    title = "No vendor deals yet",
                    detail = "Your conversations start with the first enquiry your planner sends.",
                )
            }

            else -> items(data.threads, key = { it.dealId }) { thread ->
                ThreadRow(thread = thread, onClick = { onOpen(thread.dealId) })
            }
        }

        item { BottomSpacer() }
    }
}

@Composable
private fun ThreadRow(thread: ThreadSummary, onClick: () -> Unit) {
    val yourMove = thread.waitingOn == "you"

    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        shape = RoundedCornerShape(18.dp),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Row(
            modifier = Modifier.padding(14.dp),
            verticalAlignment = Alignment.Top,
        ) {
            Box(
                modifier = Modifier
                    .size(44.dp)
                    .background(MaterialTheme.colorScheme.primaryContainer, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    text = thread.vendor.trim().take(1).uppercase().ifBlank { "V" },
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = MaterialTheme.colorScheme.onPrimaryContainer,
                )
            }

            Column(
                modifier = Modifier
                    .weight(1f)
                    .padding(start = 12.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        text = thread.vendor.ifBlank { "Vendor" },
                        style = MaterialTheme.typography.bodyLarge,
                        fontWeight = FontWeight.SemiBold,
                        color = MaterialTheme.colorScheme.onSurface,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f),
                    )
                    Text(
                        text = listStamp(thread.last?.at),
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(start = 8.dp),
                    )
                }

                Spacer(Modifier.height(2.dp))
                Text(
                    text = listOf(thread.category, money(thread.amount), statusLabel(thread.status))
                        .filter { it.isNotBlank() }
                        .joinToString(" · "),
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )

                Spacer(Modifier.height(6.dp))
                Text(
                    text = thread.last?.body?.takeIf { it.isNotBlank() }
                        ?: "No messages yet — start the conversation.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = if (thread.last == null) MaterialTheme.colorScheme.onSurfaceVariant
                    else MaterialTheme.colorScheme.onSurface,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )

                if (yourMove || thread.unread > 0) {
                    Spacer(Modifier.height(8.dp))
                    Row(
                        horizontalArrangement = Arrangement.spacedBy(6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        if (yourMove) {
                            Pill(
                                text = "Your reply is due",
                                background = MaterialTheme.colorScheme.primaryContainer,
                                foreground = MaterialTheme.colorScheme.onPrimaryContainer,
                            )
                        }
                        if (thread.unread > 0) {
                            Pill(
                                text = if (thread.unread == 1) "1 new" else "${thread.unread} new",
                                background = MaterialTheme.colorScheme.secondaryContainer,
                                foreground = MaterialTheme.colorScheme.onSecondaryContainer,
                            )
                        }
                        if (thread.waitingFor != null && !yourMove) {
                            Text(
                                text = "waiting ${thread.waitingFor}",
                                style = MaterialTheme.typography.labelMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun Pill(text: String, background: androidx.compose.ui.graphics.Color, foreground: androidx.compose.ui.graphics.Color) {
    Box(
        modifier = Modifier
            .background(background, RoundedCornerShape(10.dp))
            .padding(horizontal = 9.dp, vertical = 4.dp),
    ) {
        Text(text = text, style = MaterialTheme.typography.labelMedium, color = foreground)
    }
}
