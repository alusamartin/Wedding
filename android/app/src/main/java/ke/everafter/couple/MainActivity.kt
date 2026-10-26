package ke.everafter.couple

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import ke.everafter.couple.ui.AppViewModel
import ke.everafter.couple.ui.BrandMark
import ke.everafter.couple.ui.BudgetScreen
import ke.everafter.couple.ui.ChatIcon
import ke.everafter.couple.ui.ChecklistScreen
import ke.everafter.couple.ui.FinanceIcon
import ke.everafter.couple.ui.GuestsScreen
import ke.everafter.couple.ui.HomeScreen
import ke.everafter.couple.ui.LoginScreen
import ke.everafter.couple.ui.MessagesScreen
import ke.everafter.couple.ui.MoreScreen
import ke.everafter.couple.ui.ThreadScreen
import ke.everafter.couple.ui.UiState
import ke.everafter.couple.ui.theme.EverafterTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            EverafterTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background,
                ) {
                    val vm: AppViewModel = viewModel()
                    val state by vm.state.collectAsStateWithLifecycle()
                    Root(state = state, vm = vm)
                }
            }
        }
    }
}

@Composable
private fun Root(state: UiState, vm: AppViewModel) {
    val snackbar = remember { SnackbarHostState() }

    // A session that expires mid-use must say so, but the sign-in screen has
    // its own inline banner and does not need the same sentence twice.
    LaunchedEffect(state.error) {
        val message = state.error
        if (message != null && !state.signedOut && !state.loading) {
            snackbar.showSnackbar(message)
            vm.dismissError()
        }
    }

    when {
        state.loading -> SplashScreen()

        state.signedOut -> LoginScreen(
            state = state,
            onSignIn = vm::signIn,
            onChangeServer = vm::changeServer,
        )

        else -> MainScaffold(state = state, vm = vm, snackbar = snackbar)
    }
}

@Composable
private fun SplashScreen() {
    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        BrandMark(
            modifier = Modifier.size(72.dp),
            tint = MaterialTheme.colorScheme.primary,
        )
    }
}

private data class Tab(
    val route: String,
    val label: String,
    val icon: ImageVector,
    val badge: Boolean = false,
)

/**
 * Five destinations, which is the most a bottom bar can hold before the
 * labels start competing for space on a 360 dp phone.
 *
 * Guests is deliberately not one of them: the guest summary already sits on
 * Home, so tapping that card (or the row in More) opens the list a single tap
 * deeper, while Budget and Messages — the screens a couple checks weekly as
 * money and vendors become the real work — get their own permanent place.
 */
private val TABS = listOf(
    Tab("home", "Home", Icons.Default.Home),
    Tab("checklist", "Checklist", Icons.Default.CheckCircle),
    Tab("budget", "Money", FinanceIcon),
    Tab("messages", "Messages", ChatIcon, badge = true),
    Tab("more", "More", Icons.Default.MoreVert),
)

@Composable
private fun MainScaffold(state: UiState, vm: AppViewModel, snackbar: SnackbarHostState) {
    val nav = rememberNavController()
    val backStack by nav.currentBackStackEntryAsState()
    val current = backStack?.destination?.route

    // A conversation needs the full height: the composer sits where the tab
    // bar would be, and one keyboard above another is a cramped screen.
    val fullScreen = current?.startsWith("thread/") == true
    val unread = state.messages?.unread ?: state.home?.messages?.unread ?: 0

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        snackbarHost = { SnackbarHost(snackbar) },
        bottomBar = {
            if (!fullScreen) {
                NavigationBar(
                    containerColor = MaterialTheme.colorScheme.surface,
                    tonalElevation = 0.dp,
                ) {
                    TABS.forEach { tab ->
                        NavigationBarItem(
                            selected = current == tab.route,
                            onClick = {
                                if (current != tab.route) {
                                    nav.navigate(tab.route) {
                                        popUpTo(nav.graph.findStartDestination().id) { saveState = true }
                                        launchSingleTop = true
                                        restoreState = true
                                    }
                                }
                            },
                            icon = {
                                val icon = @Composable {
                                    Icon(tab.icon, contentDescription = tab.label)
                                }
                                if (tab.badge && unread > 0) {
                                    BadgedBox(badge = {
                                        Badge { Text(if (unread > 9) "9+" else unread.toString()) }
                                    }) { icon() }
                                } else {
                                    icon()
                                }
                            },
                            label = { Text(tab.label, style = MaterialTheme.typography.labelMedium) },
                            colors = NavigationBarItemDefaults.colors(
                                selectedIconColor = MaterialTheme.colorScheme.primary,
                                selectedTextColor = MaterialTheme.colorScheme.primary,
                                indicatorColor = MaterialTheme.colorScheme.primaryContainer,
                            ),
                        )
                    }
                }
            }
        },
    ) { padding ->
        NavHost(
            navController = nav,
            startDestination = "home",
            modifier = Modifier.padding(padding),
        ) {
            composable("home") {
                LaunchedEffect(Unit) { vm.loadHome() }
                HomeScreen(
                    state = state,
                    onToggle = vm::toggleTask,
                    onOpenChecklist = { nav.navigate("checklist") },
                    onOpenGuests = { nav.navigate("guests") },
                    onOpenMessages = { nav.navigate("messages") },
                    onRetry = vm::loadHome,
                )
            }
            composable("checklist") {
                LaunchedEffect(Unit) { vm.loadChecklist() }
                ChecklistScreen(
                    state = state,
                    onToggle = vm::toggleTask,
                    onRetry = vm::loadChecklist,
                )
            }
            composable("guests") {
                LaunchedEffect(Unit) { vm.loadGuests(state.guestFilter) }
                GuestsScreen(
                    state = state,
                    onFilter = vm::loadGuests,
                    onBack = { nav.popBackStack() },
                    onRetry = { vm.loadGuests(state.guestFilter) },
                )
            }
            composable("budget") {
                LaunchedEffect(Unit) { vm.loadBudget() }
                BudgetScreen(state = state)
            }
            composable("messages") {
                LaunchedEffect(Unit) { vm.loadMessages() }
                MessagesScreen(
                    state = state,
                    onOpen = { dealId -> nav.navigate("thread/$dealId") },
                )
            }
            composable("thread/{dealId}") { entry ->
                val dealId = entry.arguments?.getString("dealId")?.toIntOrNull() ?: 0
                LaunchedEffect(dealId) { vm.openThread(dealId) }
                ThreadScreen(
                    state = state,
                    dealId = dealId,
                    onBack = {
                        nav.popBackStack()
                        vm.closeThread()
                    },
                    onSend = { text -> vm.sendMessage(dealId, text) },
                )
            }
            composable("more") {
                MoreScreen(
                    state = state,
                    onOpenGuests = { nav.navigate("guests") },
                    onChangeServer = vm::changeServer,
                    onSignOut = vm::signOut,
                )
            }
        }
    }
}
