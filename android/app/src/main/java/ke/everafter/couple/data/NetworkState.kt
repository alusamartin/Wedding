package ke.everafter.couple.data

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities

/**
 * Does this phone have a usable network right now?
 *
 * Asked only when a request has already failed, to tell two different problems
 * apart for the couple: *there is no signal* (a matatu, a basement, an empty
 * bundle) and *your server is not answering* (a wrong address typed into
 * Settings, a host that is down, a certificate that expired). Both leave the app
 * on cached data, but only one of them is the couple's to fix, and the banner
 * should not blame their connection for the operator's server.
 *
 * Deliberately optimistic: when the answer is unknown it says "connected", so a
 * missing capability on an unusual OEM build never turns into a false "you are
 * offline" while requests are in fact working.
 */
object NetworkState {

    fun isOnline(context: Context): Boolean {
        val manager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
            ?: return true
        val network = manager.activeNetwork ?: return false
        val capabilities = manager.getNetworkCapabilities(network) ?: return true
        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }
}
