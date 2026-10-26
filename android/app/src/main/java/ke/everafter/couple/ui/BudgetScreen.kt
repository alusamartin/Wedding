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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.everafter.couple.data.BudgetData
import ke.everafter.couple.data.Payment
import ke.everafter.couple.data.PlanInfo

/**
 * The money screen: what has been paid, what is left, and what is due next.
 *
 * It answers the question a couple actually asks ("how much more, and by
 * when?") before it shows a ledger. The figures are the website's own — the
 * package price from the wedding category, payments from the liquidation
 * ledger, the plan from the installment plan — so a couple comparing the phone
 * to the site never sees two different balances.
 */
@Composable
fun BudgetScreen(state: UiState) {
    val budget = state.budget

    LazyColumn(
        modifier = Modifier.fillMaxWidth(),
        contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = 12.dp, bottom = 28.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item {
            Column {
                Text(
                    text = "Money",
                    style = MaterialTheme.typography.headlineSmall,
                    color = MaterialTheme.colorScheme.onBackground,
                )
                Spacer(Modifier.height(4.dp))
                Text(
                    text = "Payments recorded by your planner, and what is next.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        if (budget == null) {
            item {
                when {
                    state.refreshing -> EmptyState("Loading your budget…", "One moment.")
                    // Money is the one thing this app will not show from a
                    // stale copy: a balance that is quietly a day old is worse
                    // than an honest "connect to see it".
                    state.offline -> EmptyState(
                        title = state.offlineReason.ifBlank { "You are offline" },
                        detail = "Payments need a connection. Your checklist and guest list are saved on this phone and still work.",
                    )
                    else -> EmptyState(
                        title = "No payment record yet",
                        detail = "Once your planner records a deposit or sets your package, your budget appears here.",
                    )
                }
            }
            return@LazyColumn
        }

        item { BalanceCard(budget) }
        if (budget.next != null) item { NextPaymentCard(budget) }
        if (budget.plan != null) item { PlanCard(budget.plan) }
        item { CommitmentsCard(budget) }

        item {
            Text(
                text = "Payments",
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onSurface,
                modifier = Modifier.padding(top = 4.dp),
            )
        }

        if (budget.payments.isEmpty()) {
            item {
                EmptyState(
                    title = "Nothing paid yet",
                    detail = "Deposits show up here as soon as they are recorded — M-Pesa, cash or credit.",
                )
            }
        } else {
            item {
                SectionCard {
                    budget.payments.forEachIndexed { index, payment ->
                        if (index > 0) Spacer(Modifier.height(10.dp))
                        PaymentRow(payment)
                    }
                }
            }
        }

        item { BottomSpacer() }
    }
}

/** The headline: how much is still owed on the package. */
@Composable
private fun BalanceCard(budget: BudgetData) {
    val price = budget.packageInfo.price
    val priced = price > 0
    val ratio = if (priced) (budget.paid.total / price).coerceIn(0.0, 1.0).toFloat() else 0f
    val settled = priced && budget.balance <= 0.0

    SectionCard {
        Text(
            text = when {
                !priced -> "Recorded so far"
                settled -> "Fully paid"
                else -> "Balance due"
            },
            style = MaterialTheme.typography.labelLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(4.dp))
        Text(
            text = when {
                !priced -> money(budget.paid.total)
                settled -> money(budget.paid.total)
                else -> money(budget.balance)
            },
            style = MaterialTheme.typography.headlineMedium,
            fontWeight = FontWeight.Bold,
            color = when {
                settled -> MaterialTheme.colorScheme.tertiary
                priced -> MaterialTheme.colorScheme.primary
                else -> MaterialTheme.colorScheme.onSurface
            },
        )
        if (!priced) {
            Spacer(Modifier.height(6.dp))
            Text(
                text = "Your package has not been priced yet, so there is no balance to show.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        if (priced && !settled && budget.pledged.amount > 0) {
            Spacer(Modifier.height(4.dp))
            Text(
                text = "Harambee pledges of ${money(budget.pledged.amount)} are counted separately.",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        if (priced) {
            Spacer(Modifier.height(14.dp))
            LinearProgressIndicator(
                progress = { ratio },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(10.dp),
                trackColor = MaterialTheme.colorScheme.surfaceVariant,
                color = MaterialTheme.colorScheme.tertiary,
                strokeCap = StrokeCap.Round,
            )
        }
        Spacer(Modifier.height(12.dp))

        KeyValueRow("Package", budget.packageInfo.label.ifBlank { "Not set" })
        KeyValueRow("Package price", if (price > 0) money(price) else "Not priced")
        KeyValueRow("Paid to date", money(budget.paid.total))
        if (budget.paid.mpesa > 0) KeyValueRow("of which M-Pesa", money(budget.paid.mpesa))
        if (budget.paid.cash > 0) KeyValueRow("of which cash", money(budget.paid.cash))
        if (budget.paid.credit > 0) KeyValueRow("of which credit", money(budget.paid.credit))
    }
}

/** One figure, one date: the thing the couple has to act on. */
@Composable
private fun NextPaymentCard(budget: BudgetData) {
    val next = budget.next ?: return
    val tone = if (next.overdue) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary

    SectionCard {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.Top,
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = next.label.ifBlank { "Next payment" },
                    style = MaterialTheme.typography.labelLarge,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(4.dp))
                Text(
                    text = money(next.amount),
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.Bold,
                    color = tone,
                )
                Spacer(Modifier.height(4.dp))
                Text(
                    text = when {
                        next.overdue -> "Due now — this is past its date"
                        next.dueOn.isNullOrBlank() -> "No date set yet"
                        else -> "Due ${prettyDate(next.dueOn)}"
                    },
                    style = MaterialTheme.typography.bodyMedium,
                    color = if (next.overdue) MaterialTheme.colorScheme.error
                    else MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Box(
                modifier = Modifier
                    .background(
                        color = if (next.overdue) MaterialTheme.colorScheme.errorContainer
                        else MaterialTheme.colorScheme.primaryContainer,
                        shape = RoundedCornerShape(10.dp),
                    )
                    .padding(horizontal = 10.dp, vertical = 5.dp),
            ) {
                Text(
                    text = if (next.kind == "installment") "Plan" else "Final",
                    style = MaterialTheme.typography.labelMedium,
                    color = if (next.overdue) MaterialTheme.colorScheme.onErrorContainer
                    else MaterialTheme.colorScheme.onPrimaryContainer,
                )
            }
        }
    }
}

/** The month-by-month plan, when the couple set one on the website. */
@Composable
private fun PlanCard(plan: PlanInfo) {
    val ratio = if (plan.total > 0) (plan.done.toFloat() / plan.total).coerceIn(0f, 1f) else 0f
    SectionCard(title = "Your installment plan") {
        Text(
            text = "${plan.total} × ${money(plan.monthlyAmount)} a month",
            style = MaterialTheme.typography.bodyLarge,
            color = MaterialTheme.colorScheme.onSurface,
        )
        Spacer(Modifier.height(10.dp))
        LinearProgressIndicator(
            progress = { ratio },
            modifier = Modifier
                .fillMaxWidth()
                .height(8.dp),
            trackColor = MaterialTheme.colorScheme.surfaceVariant,
            color = MaterialTheme.colorScheme.primary,
            strokeCap = StrokeCap.Round,
        )
        Spacer(Modifier.height(10.dp))
        Text(
            text = if (plan.done >= plan.total) {
                "All ${plan.total} installments are paid."
            } else {
                "${plan.done} of ${plan.total} paid · ${money(plan.paidIntoPlan)} in so far"
            },
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        if (plan.done < plan.total && !plan.onTrack) {
            Spacer(Modifier.height(6.dp))
            Text(
                text = "You are behind this plan's pace — a catch-up payment puts it back on track.",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.error,
            )
        }
    }
}

/** Vendor commitments and harambee: the other two numbers a budget needs. */
@Composable
private fun CommitmentsCard(budget: BudgetData) {
    val vendors = budget.vendors
    SectionCard(title = "Committed to vendors") {
        Text(
            text = money(vendors.committed),
            style = MaterialTheme.typography.titleLarge,
            color = MaterialTheme.colorScheme.onSurface,
        )
        Spacer(Modifier.height(2.dp))
        Text(
            text = "confirmed with ${vendors.deals} ${if (vendors.deals == 1) "vendor" else "vendors"}",
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        if (vendors.planned > 0) {
            Spacer(Modifier.height(10.dp))
            KeyValueRow("Still awaiting a reply", money(vendors.planned))
        }
        if (budget.pledged.amount > 0) {
            Spacer(Modifier.height(10.dp))
            KeyValueRow(
                "Harambee pledged (${budget.pledged.guests} ${if (budget.pledged.guests == 1) "guest" else "guests"})",
                money(budget.pledged.amount),
            )
        }
    }
}

@Composable
private fun PaymentRow(payment: Payment) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = methodLabel(payment.method),
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurface,
            )
            val detail = listOfNotNull(
                payment.on.takeIf { it.isNotBlank() }?.let { prettyDate(it) },
                payment.reference.takeIf { it.isNotBlank() },
            ).joinToString(" · ")
            if (detail.isNotBlank()) {
                Text(
                    text = detail,
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        Text(
            text = money(payment.amount),
            style = MaterialTheme.typography.bodyLarge,
            fontWeight = FontWeight.SemiBold,
            color = MaterialTheme.colorScheme.tertiary,
        )
    }
}
