package ke.everafter.couple.ui

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathFillType
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.vector.addPathNodes
import androidx.compose.ui.unit.dp

/**
 * Two icons Compose's core set does not have: money and a chat bubble.
 *
 * Drawn here rather than by adding `material-icons-extended`, which is tens of
 * thousands of vectors the app would carry to use exactly two. Same 24dp
 * viewport as the Material icons beside them, so the navigation bar stays
 * evenly weighted.
 *
 * Both use an even-odd fill: the shapes punched out of the middle (the coin on
 * the note, the three dots in the bubble) are drawn as holes, not as
 * differently-coloured strokes.
 */
val FinanceIcon: ImageVector by lazy {
    ImageVector.Builder(
        name = "Finance",
        defaultWidth = 24.dp,
        defaultHeight = 24.dp,
        viewportWidth = 24f,
        viewportHeight = 24f,
    ).apply {
        addPath(
            pathData = addPathNodes(
                // The note.
                "M4 6h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z" +
                    // The coin in the middle.
                    "M12 9.4a2.6 2.6 0 1 0 0 5.2 2.6 2.6 0 0 0 0-5.2z" +
                    // The two printed marks either side.
                    "M6.6 11.2a0.8 0.8 0 1 0 0 1.6 0.8 0.8 0 0 0 0-1.6z" +
                    "M17.4 11.2a0.8 0.8 0 1 0 0 1.6 0.8 0.8 0 0 0 0-1.6z",
            ),
            fill = SolidColor(Color.Black),
            pathFillType = PathFillType.EvenOdd,
        )
    }.build()
}

val ChatIcon: ImageVector by lazy {
    ImageVector.Builder(
        name = "Chat",
        defaultWidth = 24.dp,
        defaultHeight = 24.dp,
        viewportWidth = 24f,
        viewportHeight = 24f,
    ).apply {
        addPath(
            pathData = addPathNodes(
                // The bubble, with its tail on the bottom left.
                "M5 3h14a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3h-5.4L8 20.6V16H5a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3z" +
                    // Three dots.
                    "M7.6 7.9a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2z" +
                    "M12 7.9a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2z" +
                    "M16.4 7.9a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2z",
            ),
            fill = SolidColor(Color.Black),
            pathFillType = PathFillType.EvenOdd,
        )
    }.build()
}
