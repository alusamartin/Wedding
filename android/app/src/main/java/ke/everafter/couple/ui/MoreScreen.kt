package ke.everafter.couple.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import ke.everafter.couple.BuildConfig

/** Who is signed in, where the app points, and the way out. */
@Composable
fun MoreScreen(
    state: UiState,
    onOpenGuests: () -> Unit,
    onChangeServer: (String) -> Unit,
    onSignOut: () -> Unit,
) {
    var showServerDialog by remember { mutableStateOf(false) }
    val me = state.auth
    val wedding = state.home?.wedding ?: me?.wedding

    LazyColumn(
        modifier = Modifier.fillMaxWidth(),
        contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = 12.dp, bottom = 28.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item {
            Text(
                text = "Account",
                style = MaterialTheme.typography.headlineSmall,
                color = MaterialTheme.colorScheme.onBackground,
            )
        }

        item {
            SectionCard(title = me?.name ?: "Signed in") {
                KeyValueRow("Email", me?.email ?: "—")
                if (!me?.phone.isNullOrBlank()) KeyValueRow("Phone", me!!.phone)
                if (!me?.city.isNullOrBlank()) KeyValueRow("Town", me!!.city)
            }
        }

        if (wedding != null) {
            item {
                SectionCard(title = "Your wedding") {
                    KeyValueRow("Couple", wedding.label ?: "—")
                    KeyValueRow("Date", prettyDate(wedding.date).ifBlank { "Not set" })
                    if (wedding.venue.isNotBlank()) KeyValueRow("Venue", wedding.venue)
                    KeyValueRow(
                        "Booking",
                        when (wedding.status) {
                            "confirm" -> "Confirmed"
                            "pending" -> "Pending"
                            else -> wedding.status.replaceFirstChar { it.uppercase() }
                        },
                    )
                }
            }
        }

        item {
            SectionCard(title = "Planning") {
                KeyValueRow("Guests", "${state.home?.guests?.total ?: 0} invited")
                TextButton(
                    onClick = onOpenGuests,
                    contentPadding = PaddingValues(horizontal = 0.dp, vertical = 4.dp),
                ) {
                    Text("Open guest list")
                }
            }
        }

        item {
            SectionCard(title = "This app") {
                KeyValueRow("Server", state.serverUrl)
                KeyValueRow("Version", BuildConfig.VERSION_NAME)
                Spacer(Modifier.height(4.dp))
                TextButton(
                    onClick = { showServerDialog = true },
                    contentPadding = PaddingValues(horizontal = 0.dp, vertical = 4.dp),
                ) {
                    Text("Change server")
                }
            }
        }

        item {
            OutlinedButton(
                onClick = onSignOut,
                shape = RoundedCornerShape(14.dp),
                modifier = Modifier
                    .fillMaxWidth()
                    .height(50.dp),
            ) {
                Text("Sign out")
            }
        }

        item {
            Text(
                text = "Signing out removes this phone's access. Your wedding data stays exactly where it is on the server.",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.outline,
            )
        }
    }

    if (showServerDialog) {
        ServerDialog(
            current = state.serverUrl,
            onDismiss = { showServerDialog = false },
            onSave = {
                showServerDialog = false
                onChangeServer(it)
            },
        )
    }
}
