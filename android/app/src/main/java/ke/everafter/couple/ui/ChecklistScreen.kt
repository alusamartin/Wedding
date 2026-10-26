package ke.everafter.couple.ui

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import ke.everafter.couple.data.ChecklistItem
import ke.everafter.couple.data.ChecklistPhase
import ke.everafter.couple.data.Progress

/**
 * The Kenyan planning checklist, phase by phase, with each item's real date
 * worked out on the server from the wedding date. Phases are sticky headers so
 * a couple scrolling to "Legal & Church" always knows where they are.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun ChecklistScreen(
    state: UiState,
    onToggle: (id: Int, done: Boolean) -> Unit,
    onRetry: () -> Unit,
) {
    val data = state.checklist

    if (data == null) {
        if (state.refreshing) {
            EmptyState("Loading your checklist…", "This only takes a moment.")
        } else if (state.offline) {
            EmptyState(
                title = "Not downloaded yet",
                detail = "Connect once and the checklist is saved on this phone, ready to read when you have no signal. Ticking needs a connection.",
            )
        } else {
            EmptyState("No checklist yet", "Your planner sets it up when your booking is confirmed.")
        }
        return
    }

    LazyColumn(
        modifier = Modifier.fillMaxWidth(),
        contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = 12.dp, bottom = 28.dp),
    ) {
        item {
            Column {
                Text(
                    text = "Checklist",
                    style = MaterialTheme.typography.headlineSmall,
                    color = MaterialTheme.colorScheme.onBackground,
                )
                Spacer(Modifier.height(12.dp))
                if (state.offline) {
                    OfflineBanner(
                        lastSyncedAt = state.lastSyncedAt,
                        onRetry = onRetry,
                        reason = state.offlineReason,
                    )
                    Spacer(Modifier.height(12.dp))
                }
                ProgressHeader(data.progress)
                Spacer(Modifier.height(16.dp))
            }
        }

        data.phases.forEach { phase ->
            stickyHeader(key = "phase-${phase.name}") {
                PhaseHeader(phase)
            }
            items(phase.items, key = { it.id }) { item ->
                ChecklistRow(
                    item = item,
                    enabled = !state.toggling.contains(item.id),
                    onToggle = { done -> onToggle(item.id, done) },
                )
            }
        }

        item {
            Spacer(Modifier.height(8.dp))
            Text(
                text = "Tick what is done — your planner sees the same list update.",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.outline,
                modifier = Modifier.padding(top = 8.dp),
            )
        }
    }
}

@Composable
private fun ProgressHeader(progress: Progress) {
    SectionCard {
        Row(verticalAlignment = Alignment.Bottom) {
            Text(
                text = "${progress.percent}%",
                style = MaterialTheme.typography.headlineSmall,
                color = MaterialTheme.colorScheme.primary,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.height(0.dp))
            Text(
                text = "   ${progress.done} of ${progress.total} milestones done",
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
            strokeCap = StrokeCap.Round,
        )
    }
}

@Composable
private fun PhaseHeader(phase: ChecklistPhase) {
    val done = phase.items.count { it.isDone }
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(MaterialTheme.colorScheme.background)
            .padding(top = 18.dp, bottom = 8.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            text = phase.name,
            style = MaterialTheme.typography.titleMedium,
            color = MaterialTheme.colorScheme.onBackground,
            fontWeight = FontWeight.SemiBold,
        )
        Text(
            text = "$done/${phase.items.size}",
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun ChecklistRow(
    item: ChecklistItem,
    enabled: Boolean,
    onToggle: (Boolean) -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 2.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Checkbox(
            checked = item.isDone,
            enabled = enabled,
            onCheckedChange = onToggle,
            colors = CheckboxDefaults.colors(
                checkedColor = MaterialTheme.colorScheme.primary,
                uncheckedColor = MaterialTheme.colorScheme.outline,
            ),
        )
        Column(
            modifier = Modifier
                .padding(top = 12.dp, end = 8.dp)
                .fillMaxWidth(),
        ) {
            Text(
                text = item.title,
                style = MaterialTheme.typography.bodyLarge,
                color = if (item.isDone) MaterialTheme.colorScheme.onSurfaceVariant
                else MaterialTheme.colorScheme.onSurface,
                textDecoration = if (item.isDone) TextDecoration.LineThrough else null,
            )
            if (!item.isDone) {
                Spacer(Modifier.height(2.dp))
                Text(
                    text = dueLabel(item.dueOn, item.daysLeft, item.overdue),
                    style = MaterialTheme.typography.labelMedium,
                    color = if (item.overdue) MaterialTheme.colorScheme.error
                    else MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Spacer(Modifier.height(8.dp))
            Box_divider()
        }
    }
}

/** A hairline between rows without pulling in HorizontalDivider styling. */
@Composable
private fun Box_divider() {
    Spacer(
        modifier = Modifier
            .fillMaxWidth()
            .height(1.dp)
            .background(MaterialTheme.colorScheme.outlineVariant)
    )
}
