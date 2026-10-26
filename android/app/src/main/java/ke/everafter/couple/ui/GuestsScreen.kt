package ke.everafter.couple.ui

import androidx.compose.foundation.background
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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.everafter.couple.data.Guest
import ke.everafter.couple.data.GuestCounts

/**
 * Who is coming. Read-only in v1: an RSVP is the guest's own answer through
 * their link, so letting the couple tick "yes" on someone's behalf would make
 * the numbers lie.
 */
@Composable
fun GuestsScreen(
    state: UiState,
    onFilter: (String?) -> Unit,
    onBack: (() -> Unit)? = null,
    onRetry: () -> Unit = {},
) {
    val data = state.guests

    LazyColumn(
        modifier = Modifier.fillMaxWidth(),
        contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = 12.dp, bottom = 28.dp),
    ) {
        item {
            Column {
                // Guests is a pushed destination now (reached from Home or
                // More), so it carries its own way back.
                if (onBack != null) {
                    IconButton(
                        onClick = onBack,
                        modifier = Modifier.padding(bottom = 2.dp),
                    ) {
                        Icon(
                            imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                            contentDescription = "Back",
                            tint = MaterialTheme.colorScheme.onBackground,
                        )
                    }
                }
                Text(
                    text = "Guests",
                    style = MaterialTheme.typography.headlineSmall,
                    color = MaterialTheme.colorScheme.onBackground,
                )
                Spacer(Modifier.height(4.dp))
                Text(
                    text = "Answers arrive as guests use their own RSVP link.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                if (state.offline) {
                    Spacer(Modifier.height(12.dp))
                    OfflineBanner(
                        lastSyncedAt = state.lastSyncedAt,
                        onRetry = onRetry,
                        reason = state.offlineReason,
                    )
                }
                Spacer(Modifier.height(14.dp))
                Filters(selected = state.guestFilter, onFilter = onFilter)
                Spacer(Modifier.height(14.dp))
                if (data != null) {
                    CountsStrip(data.counts)
                    Spacer(Modifier.height(10.dp))
                }
            }
        }

        if (data == null) {
            item {
                when {
                    state.refreshing -> EmptyState("Loading your guest list…", "One moment.")
                    state.offline -> EmptyState(
                        title = "Not downloaded yet",
                        detail = "Connect once and the guest list is saved on this phone, answers and all.",
                    )
                    else -> EmptyState("No guest list yet", "Add guests on the website and they appear here with their answers.")
                }
            }
        } else if (data.guests.isEmpty()) {
            item {
                EmptyState(
                    title = "Nobody here",
                    detail = if (state.guestFilter == null) {
                        "Your guest list is empty. Add guests on the website to start inviting."
                    } else {
                        "No guest has given this answer yet."
                    },
                )
            }
        } else {
            items(data.guests, key = { it.id }) { guest ->
                GuestRow(guest)
            }
        }
    }
}

@Composable
private fun Filters(selected: String?, onFilter: (String?) -> Unit) {
    val options = listOf<Pair<String?, String>>(
        null to "All",
        "yes" to "Coming",
        "pending" to "Waiting",
        "no" to "Declined",
    )
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        options.forEach { (value, label) ->
            FilterChip(
                selected = selected == value,
                onClick = { onFilter(value) },
                label = { Text(label, style = MaterialTheme.typography.labelMedium) },
                shape = RoundedCornerShape(12.dp),
                colors = FilterChipDefaults.filterChipColors(
                    selectedContainerColor = MaterialTheme.colorScheme.primaryContainer,
                    selectedLabelColor = MaterialTheme.colorScheme.onPrimaryContainer,
                ),
            )
        }
    }
}

@Composable
private fun CountsStrip(counts: GuestCounts) {
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        StatPill("${counts.total}", "Invited")
        StatPill("${counts.yes}", "Coming", highlighted = true)
        StatPill("${counts.pending}", "Waiting")
    }
}

@Composable
private fun GuestRow(guest: Guest) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(42.dp)
                .background(MaterialTheme.colorScheme.primaryContainer, CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                text = guest.name.trim().take(1).uppercase().ifBlank { "?" },
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onPrimaryContainer,
                fontWeight = FontWeight.SemiBold,
            )
        }

        Column(
            modifier = Modifier
                .weight(1f)
                .padding(start = 12.dp),
        ) {
            Text(
                text = guest.name.ifBlank { "Unnamed guest" },
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurface,
            )
            val details = listOfNotNull(
                guest.dietary.takeIf { it.isNotBlank() }?.let { "Dietary: $it" },
                "Needs transport".takeIf { guest.transport },
                "Needs accommodation".takeIf { guest.accommodation },
            )
            if (details.isNotEmpty()) {
                Spacer(Modifier.height(2.dp))
                Text(
                    text = details.joinToString(" · "),
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        RsvpBadge(guest.rsvp)
    }
}

@Composable
private fun RsvpBadge(rsvp: String) {
    val (background, foreground) = when (rsvp.lowercase()) {
        "yes" -> MaterialTheme.colorScheme.primaryContainer to MaterialTheme.colorScheme.onPrimaryContainer
        "no" -> MaterialTheme.colorScheme.errorContainer to MaterialTheme.colorScheme.onErrorContainer
        "maybe" -> MaterialTheme.colorScheme.surfaceVariant to MaterialTheme.colorScheme.onSurfaceVariant
        else -> MaterialTheme.colorScheme.surfaceVariant to MaterialTheme.colorScheme.onSurfaceVariant
    }
    Box(
        modifier = Modifier
            .background(background, RoundedCornerShape(10.dp))
            .padding(horizontal = 10.dp, vertical = 5.dp),
    ) {
        Text(
            text = rsvpLabel(rsvp),
            style = MaterialTheme.typography.labelMedium,
            color = foreground,
        )
    }
}
