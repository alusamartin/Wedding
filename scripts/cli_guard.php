<?php
declare(strict_types=1);

/**
 * Hard stop for web access to anything in scripts/.
 *
 * Every file in this directory is a CLI tool: some back up the database, some
 * send SMS or email, some prune rows, and demo_reset.php drops and rebuilds the
 * whole schema. The project root IS the web root on cPanel-style hosting, so
 * under Apache alone `GET /scripts/rsvp_reminders.php` would have run the job
 * as an anonymous visitor and texted real guests.
 *
 * .htaccess denies the directory, but that only protects Apache with
 * AllowOverride on. The PHP built-in server, Nginx, and any host with
 * AllowOverride None would still reach these files — which is exactly how the
 * hole was found. This is the layer that cannot be configured away.
 *
 * Included first by every runnable script in this directory; safe from the
 * library files too (a library loaded by a CLI script still sees PHP_SAPI
 * 'cli'), so the guard cannot be side-stepped by changing the entry point.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden — scripts/ is CLI-only.\n";
    exit(1);
}
