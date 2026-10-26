package ke.everafter.couple.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

/**
 * The palette is lifted straight from `admin/css/design-tokens.css`, so a
 * couple signing in here and opening the site in Chrome sees one product
 * rather than two. Values are duplicated (not read from the web) because the
 * app must paint before — and without — a network round trip.
 */
private val Brand50 = Color(0xFFFFF5F2)
private val Brand100 = Color(0xFFFFE8E0)
private val Brand300 = Color(0xFFF5A887)
private val Brand500 = Color(0xFFD97757)
private val Brand700 = Color(0xFFA8553A)
private val Brand900 = Color(0xFF6B3420)

private val Stone50 = Color(0xFFFAFAF8)
private val Stone100 = Color(0xFFF5F3EF)
private val Stone200 = Color(0xFFE8E4DC)
private val Stone300 = Color(0xFFD4CFC4)
private val Stone500 = Color(0xFF8A8377)
private val Stone700 = Color(0xFF4A463D)
private val Stone900 = Color(0xFF1F1D1A)

private val Success = Color(0xFF10B981)
private val SuccessInk = Color(0xFF047857)
private val WarningInk = Color(0xFFB45309)
private val Danger = Color(0xFFEF4444)
private val DangerInk = Color(0xFFB91C1C)

private val LightColors = lightColorScheme(
    primary = Brand700,
    onPrimary = Color.White,
    primaryContainer = Brand100,
    onPrimaryContainer = Brand900,
    secondary = Brand500,
    onSecondary = Color.White,
    secondaryContainer = Brand50,
    onSecondaryContainer = Brand900,
    tertiary = SuccessInk,
    onTertiary = Color.White,
    background = Stone50,
    onBackground = Stone900,
    surface = Color.White,
    onSurface = Stone900,
    surfaceVariant = Stone100,
    onSurfaceVariant = Stone700,
    outline = Stone300,
    outlineVariant = Stone200,
    error = Danger,
    onError = Color.White,
    errorContainer = Color(0xFFFEE2E2),
    onErrorContainer = DangerInk,
)

private val DarkColors = darkColorScheme(
    primary = Brand300,
    onPrimary = Brand900,
    primaryContainer = Brand900,
    onPrimaryContainer = Brand50,
    secondary = Brand500,
    onSecondary = Color.White,
    secondaryContainer = Color(0xFF3A2A22),
    onSecondaryContainer = Brand50,
    background = Color(0xFF141311),
    onBackground = Color(0xFFEDE9E2),
    surface = Color(0xFF1B1A17),
    onSurface = Color(0xFFEDE9E2),
    surfaceVariant = Color(0xFF2A2723),
    onSurfaceVariant = Stone300,
    outline = Stone500,
    outlineVariant = Color(0xFF3A3631),
    error = Color(0xFFF87171),
    onError = Color(0xFF1B1A17),
    errorContainer = Color(0xFF4A1D1D),
    onErrorContainer = Color(0xFFFECACA),
)

/**
 * Slightly tighter than Material's defaults, which are tuned for tablets.
 * Body text is 15sp rather than 14sp: the couple reading a checklist on a
 * Tecno at arm's length is the target, not a Pixel on a desk.
 */
private val AppTypography = Typography(
    displaySmall = TextStyle(fontSize = 34.sp, lineHeight = 40.sp, fontWeight = FontWeight.SemiBold),
    headlineMedium = TextStyle(fontSize = 26.sp, lineHeight = 32.sp, fontWeight = FontWeight.SemiBold),
    headlineSmall = TextStyle(fontSize = 22.sp, lineHeight = 28.sp, fontWeight = FontWeight.SemiBold),
    titleLarge = TextStyle(fontSize = 19.sp, lineHeight = 26.sp, fontWeight = FontWeight.SemiBold),
    titleMedium = TextStyle(fontSize = 16.sp, lineHeight = 22.sp, fontWeight = FontWeight.Medium),
    bodyLarge = TextStyle(fontSize = 15.sp, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, lineHeight = 20.sp),
    labelLarge = TextStyle(fontSize = 14.sp, lineHeight = 18.sp, fontWeight = FontWeight.Medium),
    labelMedium = TextStyle(fontSize = 12.sp, lineHeight = 16.sp, fontWeight = FontWeight.Medium),
)

@Composable
fun EverafterTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = if (darkTheme) DarkColors else LightColors,
        typography = AppTypography,
        content = content,
    )
}
