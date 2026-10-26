package ke.everafter.couple.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.everafter.couple.data.GuestCounts
import ke.everafter.couple.data.HomeData
import ke.everafter.couple.data.NextTask

/**
 * The screen a couple actually opens. It answers three questions in order:
 * how long have we got, what is next, and who has replied.
 */
@Composable
fun HomeScreen(
    state: UiState,
    onToggle: (id: Int, done: Boolean) -> Unit,
    onOpenChecklist: () -> Unit,
    onOpenGuests: () -> Unit,
    onOpenMessages: () -> Unit,
    onRetry: () -> Unit,
) {
    val home = state.home

    LazyColumn(
        modifier = Modifier.fillMaxWidth(),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(
            start = 20.dp, end = 20.dp, top = 12.dp, bottom = 28.dp,
        ),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item {
            Header(
                name = home?.couple?.substringBefore(' ') ?: state.auth?.name?.substringBefore(' ') ?: "",
                unread = home?.notifications?.unread ?: 0,
            )
        }

        if (state.offline) {
            item {
                OfflineBanner(
                    lastSyncedAt = state.lastSyncedAt,
                    onRetry = onRetry,
                    reason = state.offlineReason,
                )
            }
        }

        if (home != null && home.hasBooking && home.wedding != null) {
            item { CountdownCard(home) }
            item { ChecklistCard(home, onOpenChecklist) }
            item { NextUpCard(home.checklist.next, onToggle) }
            item { GuestsCard(home.guests, onOpenGuests) }
            if (home.messages.unread > 0) {
                item {
                    SectionCard(modifier = Modifier.clickable(onClick = onOpenMessages)) {
                        Text(
                            text = if (home.messages.unread == 1) {
                                "1 new message from a vendor"
                            } else {
                                "${home.messages.unread} new messages from vendors"
                            },
                            style = MaterialTheme.typography.bodyLarge,
                            color = MaterialTheme.colorScheme.onSurface,
                        )
                        Spacer(Modifier.height(4.dp))
                        Text(
                            text = "Open Messages to read and reply.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.primary,
                        )
                    }
                }
            }
        } else if (home != null) {
            item {
                EmptyState(
                    title = "No wedding on this account yet",
                    detail = "Once your planner confirms your booking, your countdown, checklist and guest list appear here.",
                )
            }
        }

        if (home == null && state.offline) {
            item {
                EmptyState(
                    title = "Nothing downloaded yet",
                    detail = "Connect to the internet once and your wedding is saved to this phone — after that the app opens without a signal.",
                )
            }
        }

        if (home == null && state.refreshing) {
            item { LoadingRow("Loading your wedding…") }
        }
    }
}

@Composable
private fun Header(name: String, unread: Int) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(top = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Column {
            Text(
                text = if (name.isBlank()) "Karibu" else "Karibu, $name",
                style = MaterialTheme.typography.headlineSmall,
                color = MaterialTheme.colorScheme.onBackground,
            )
            Text(
                text = "Here is where your wedding stands today.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        if (unread > 0) {
            BadgedBox(badge = {
                Badge { Text(if (unread > 9) "9+" else unread.toString()) }
            }) {
                Icon(
                    imageVector = Icons.Default.Notifications,
                    contentDescription = "Notifications",
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

@Composable
private fun CountdownCard(home: HomeData) {
    val wedding = home.wedding ?: return
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .background(
                brush = Brush.linearGradient(
                    listOf(
                        MaterialTheme.colorScheme.primary,
                        MaterialTheme.colorScheme.secondary,
                    )
                ),
                shape = RoundedCornerShape(24.dp),
            )
            .padding(horizontal = 22.dp, vertical = 24.dp),
    ) {
        Column {
            Text(
                text = wedding.label ?: home.couple,
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onPrimary.copy(alpha = 0.9f),
            )
            Spacer(Modifier.height(10.dp))
            Row(verticalAlignment = Alignment.Bottom) {
                Text(
                    text = countdownHeadline(wedding.daysToGo),
                    style = MaterialTheme.typography.displaySmall,
                    fontWeight = FontWeight.Bold,
                    color = MaterialTheme.colorScheme.onPrimary,
                )
                Spacer(Modifier.size(8.dp))
                Text(
                    text = countdownCaption(wedding.daysToGo),
                    style = MaterialTheme.typography.bodyLarge,
                    color = MaterialTheme.colorScheme.onPrimary.copy(alpha = 0.9f),
                    modifier = Modifier.padding(bottom = 6.dp),
                )
            }
            Spacer(Modifier.height(10.dp))
            val place = listOfNotNull(
                prettyDate(wedding.date).ifBlank { null },
                wedding.venue.ifBlank { null },
            ).joinToString(" · ")
            if (place.isNotBlank()) {
                Text(
                    text = place,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onPrimary.copy(alpha = 0.85f),
                )
            }
        }
    }
}

@Composable
private fun ChecklistCard(home: HomeData, onOpen: () -> Unit) {
    val progress = home.checklist
    SectionCard(title = "Planning progress", modifier = Modifier.clickable(onClick = onOpen)) {
        Row(verticalAlignment = Alignment.Bottom) {
            Text(
                text = "${progress.percent}%",
                style = MaterialTheme.typography.headlineSmall,
                color = MaterialTheme.colorScheme.primary,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.size(8.dp))
            Text(
                text = "${progress.done} of ${progress.total} done",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(bottom = 3.dp),
            )
        }
        Spacer(Modifier.height(10.dp))
        LinearProgressIndicator(
            progress = { progress.percent / 100f },
            modifier = Modifier
                .fillMaxWidth()
                .height(8.dp),
            trackColor = MaterialTheme.colorScheme.surfaceVariant,
            strokeCap = androidx.compose.ui.graphics.StrokeCap.Round,
        )
    }
}

@Composable
private fun NextUpCard(next: List<NextTask>, onToggle: (Int, Boolean) -> Unit) {
    SectionCard(title = "Next up") {
        if (next.isEmpty()) {
            Text(
                text = "Nothing overdue. Open the checklist to see what is coming.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            return@SectionCard
        }
        next.forEachIndexed { index, task ->
            if (index > 0) {
                Spacer(Modifier.height(4.dp))
            }
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.Top,
            ) {
                Checkbox(
                    checked = false,
                    onCheckedChange = { checked -> onToggle(task.id, checked) },
                    colors = CheckboxDefaults.colors(
                        uncheckedColor = MaterialTheme.colorScheme.outline,
                        checkedColor = MaterialTheme.colorScheme.primary,
                    ),
                )
                Column(modifier = Modifier.padding(top = 12.dp)) {
                    Text(
                        text = task.title,
                        style = MaterialTheme.typography.bodyLarge,
                        color = MaterialTheme.colorScheme.onSurface,
                    )
                    Spacer(Modifier.height(2.dp))
                    Text(
                        text = dueLabel(task.dueOn, task.daysLeft, task.overdue),
                        style = MaterialTheme.typography.labelMedium,
                        color = if (task.overdue) MaterialTheme.colorScheme.error
                        else MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

@Composable
private fun GuestsCard(counts: GuestCounts, onOpen: () -> Unit) {
    SectionCard(title = "Guests", modifier = Modifier.clickable(onClick = onOpen)) {
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            StatPill("${counts.yes}", "Coming", highlighted = true)
            StatPill("${counts.pending}", "Waiting")
            StatPill("${counts.no}", "Declined")
        }
        Spacer(Modifier.height(10.dp))
        Text(
            text = "${counts.total} invited in total · tap to see the list",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun LoadingRow(message: String) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 24.dp),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(10.dp)
                .background(MaterialTheme.colorScheme.primary, CircleShape)
        )
        Spacer(Modifier.size(10.dp))
        Text(
            text = message,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}
