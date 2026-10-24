<?php
/**
 * Shared app shell for every admin page: sidebar, top bar, and the opening
 * <main>. include/footer.php closes the </main>; pages that ship their own
 * footer leave the browser to close it, exactly as before.
 *
 * Which links a session sees is unchanged (vendors get their own three pages,
 * couples get their own, staff everything else). What changed is the shape:
 * links are grouped by the job they do, the group holding the current page is
 * open by default and marked aria-current, and the whole menu is searchable.
 * Badge counts keep the queries they always had, plus one for every role: the
 * deal conversations waiting for this reader (VendorThread::unread_total),
 * shown on whichever ledger that role opens.
 */
$sidebar_user = ($users_profile instanceof Users) ? $users_profile : null;

$shell_is_vendor = $sidebar_user ? is_vendor() : false;
$shell_staff_id  = (int) ($_SESSION['id'] ?? 0);
$shell_brand_href = $sidebar_user
    ? (($shell_is_vendor && vendor_profile_of($shell_staff_id) !== null) ? 'vendor_home.php' : 'dashboard.php')
    : 'client.php';

if (!$sidebar_user) {
    $shell_role      = 'Couple account';
    $shell_name      = (string) ($_SESSION['user_email'] ?? 'Client');
    $shell_initial   = strtoupper(substr($shell_name !== '' ? $shell_name : 'C', 0, 1));
    $shell_avatar    = '';
    $shell_profile   = 'client.php';
} else {
    $shell_role = $shell_is_vendor
        ? 'Vendor account'
        : ((function_exists('is_super_admin') && is_super_admin()) ? 'Platform owner' : 'Wedding planner');
    $shell_name    = trim(((string) ($sidebar_user->firstname ?? '')) . ' ' . ((string) ($sidebar_user->lastname ?? '')));
    if ($shell_name === '') {
        $shell_name = (string) ($sidebar_user->email ?? 'Account');
    }
    $shell_initial = strtoupper(substr($shell_name, 0, 1));
    $shell_avatar  = (string) ($sidebar_user->profile_picture_picture() ?? '');
    $shell_profile = 'users_profile.php';
}

/*
 * Badge counts. Only computed for staff who are not vendors: a couple and a
 * vendor have no business being told how many weddings are near capacity, and
 * each count is a query this shell should not run for them on every page.
 */
$headroomCount     = 0;
$approvalsWaiting  = 0;
$jobWatchTrouble   = 0;
$proposalCount     = 0;
if ($sidebar_user && !$shell_is_vendor) {
    $headroomCount    = PackageCapacity::watch_count();
    $approvalsWaiting = McpApprovals::pending_count();
    // Proposals a planner still owes an answer to (v2.30), scoped exactly like
    // the queue page it opens: their own plus the unclaimed ones.
    $proposalCount    = Proposal::open_count(planner_scope_planner_id());
    if (function_exists('is_super_admin') && is_super_admin()) {
        $jobWatchTrouble = JobWatch::troubled_count();
    }
}

/*
 * Unread deal conversations (v2.25) — the one badge every role gets, because
 * every role is a party to the thread. It is scoped exactly like the ledger it
 * sits on: a planner counts the deals on weddings they may open, a vendor the
 * deals on their listings, a couple the deals on their wedding. A couple has no
 * deal ledger in the menu, so this also resolves which wedding the new "Vendor
 * enquiries" entry opens — the count and the link then always describe the same
 * messages, and a couple who has not booked yet gets no entry and no badge.
 */
$threadUnread        = 0;
$threadDeal          = 0;
$coupleThreadHref    = '';
$coupleThreadBooking = 0;
try {
    $sidebar_db = $GLOBALS['db'] ?? null;
    if (!$sidebar_user && $sidebar_db instanceof Database) {
        $coupleThreadBooking = (int) $sidebar_db->fetchValue(
            "SELECT booking_id FROM tblweddingbook WHERE user_id = :me
              ORDER BY (wedding_date IS NULL OR wedding_date < CURDATE()), wedding_date ASC, booking_id DESC
              LIMIT 1",
            [':me' => (int) ($_SESSION['id'] ?? 0)]
        );
        if ($coupleThreadBooking > 0) {
            $coupleThreadHref = 'client_vendors.php?booking_id=' . $coupleThreadBooking
                . '&user_id=' . (int) ($_SESSION['id'] ?? 0);
        }
    }
    $threadUnread = VendorThread::unread_total(null, (int) ($_SESSION['id'] ?? 0), $coupleThreadBooking);
    if ($threadUnread > 0) {
        // The badge opens the message that has waited longest, not the newest.
        $threadDeal = VendorThread::oldest_unread(null, (int) ($_SESSION['id'] ?? 0), $coupleThreadBooking);
    }
} catch (Throwable $e) {
    $threadUnread = 0;
    $threadDeal   = 0;
}

/*
 * Where the badged entry points. With messages waiting it goes straight at the
 * oldest thread (vendor_thread_open.php, which authorises again and returns to
 * the ledger with that panel open); with nothing waiting it is the ledger list
 * itself. The couple's ledger is their own wedding's marketplace, never a bare
 * file name — a couple with no booking gets no entry at all.
 */
if ($shell_is_vendor) {
    $threadLedgerHref = 'vendor_home.php';
} elseif ($sidebar_user !== null) {
    $threadLedgerHref = 'vendor_enquiries.php';
} else {
    $threadLedgerHref = $coupleThreadHref;
}
$threadHref = $threadDeal > 0 ? VendorThread::opener_url($threadDeal) : $threadLedgerHref;
$threadTitle = match (true) {
    $shell_is_vendor        => 'unread messages from couples and planners',
    $sidebar_user !== null  => "unread messages on your couples' vendor deals",
    default                 => 'unread messages from your vendors',
};
if ($threadUnread > 0 && $threadDeal > 0) {
    $threadTitle .= ' — click opens the oldest waiting message';
}

/*
 * The menu. Keys are section labels in the order a planner works through a
 * wedding: today's work, the couples, the suppliers, the money, then the
 * publishing tools and the account settings. Each item is
 * [href, icon, label, optional badge => [count, tone, title]].
 *
 * Icons are deliberately limited to names that exist in Material Design Icons
 * 2.x, the oldest build any admin page loads, so an entry draws on whichever
 * CDN stylesheet its page pulls in (2.1.19 on most, 7.4.47 on the newer
 * dashboards). Every admin page loads one; no icon font is bundled locally.
 */
$nav_sections = [];

if (!$sidebar_user) {
    $couple_links = [
        ['client.php', 'calendar-multiple-check', 'My Bookings'],
    ];
    if ($coupleThreadHref !== '') {
        $couple_links[] = [$threadHref, 'store-outline', 'Vendor enquiries',
            ['count' => $threadUnread, 'tone' => 'warn', 'title' => $threadTitle]];
    }
    /*
     * The couple's vendor brief (v2.28). Listed only when we know which wedding
     * it is for — the page is booking-scoped, and a link without the wedding
     * would be refused by the same ownership rule it exists to respect.
     */
    if ($coupleThreadBooking > 0) {
        /*
         * The couple's proposal (v2.30). Listed only when their wedding actually
         * has one: the page is useless before a planner has priced anything, and
         * an entry that opens an empty page is worse than no entry. A wedding
         * confirmed before v2.30 simply never gets one.
         */
        $coupleProposal = Proposal::installed() ? Proposal::find_by_booking($coupleThreadBooking) : null;
        if ($coupleProposal !== null) {
            $couple_links[] = [
                'client_proposal.php?booking_id=' . $coupleThreadBooking
                    . '&user_id=' . (int) ($_SESSION['id'] ?? 0),
                'file-document-edit-outline',
                'My proposal',
            ];
        }
        $couple_links[] = [
            'client_vendor_preferences.php?booking_id=' . $coupleThreadBooking
                . '&user_id=' . (int) ($_SESSION['id'] ?? 0),
            'palette-swatch',
            'Taste & budget',
        ];
        /*
         * The couple's vendor plan (v2.29): every vendor chosen for their
         * wedding and one total. Booking-scoped like the brief above, so it is
         * listed only once the session knows which wedding it belongs to.
         */
        $couple_links[] = [
            'client_vendor_plan.php?booking_id=' . $coupleThreadBooking
                . '&user_id=' . (int) ($_SESSION['id'] ?? 0),
            'clipboard-text-outline',
            'Vendor plan &amp; total',
        ];
    }
    $couple_links[] = ['api_tokens.php', 'robot', 'AI Assistant'];
    $nav_sections = [
        'My wedding' => $couple_links,
    ];
} elseif ($shell_is_vendor) {
    $nav_sections = [
        'My business' => [
            [$threadHref, 'calendar-multiple-check', 'My Bookings',
                ['count' => $threadUnread, 'tone' => 'warn', 'title' => $threadTitle]],
            ['vendor_services.php', 'format-list-bulleted', 'My Services'],
            ['vendor_packages.php', 'package-variant-closed', 'My Packages'],
            ['vendor_profile.php', 'store', 'My Profile'],
        ],
        'Discover' => [
            ['vendors.php', 'cart-outline', 'Marketplace'],
        ],
    ];
} else {
    $nav_sections = [
        'Today' => [
            ['dashboard.php', 'view-dashboard', 'Dashboard'],
            ['task_all_calendar.php', 'calendar-text', 'Task Calendar'],
        ],
        'Weddings' => [
            ['proposals.php', 'file-document-edit-outline', 'Proposals', ['count' => $proposalCount, 'tone' => 'warn',
                'title' => 'couples waiting for a priced proposal']],
            ['client.php', 'account-multiple', 'Clients'],
            ['headroom.php', 'gauge', 'Capacity Headroom', ['count' => $headroomCount, 'tone' => 'warn',
                'title' => "weddings close to a booked package's guest capacity"]],
        ],
        'Vendors' => [
            ['vendors.php', 'cart-outline', 'Vendor Marketplace'],
            [$threadHref, 'email-outline', 'Vendor Enquiries',
                ['count' => $threadUnread, 'tone' => 'warn', 'title' => $threadTitle]],
        ],
        'Revenue' => [
            ['revenue.php', 'chart-line', 'Revenue'],
            ['subscription.php', 'crown', 'Subscription Plans'],
            ['subscription_plans.php', 'tune', 'Plan Definitions', null, 'super'],
            ['mpesa_ledger.php', 'cellphone', 'M-Pesa Ledger'],
        ],
        'Content & comms' => [
            ['service_list.php', 'verified', 'Services'],
            ['blog_events.php', 'comment-text', 'Blogs & Events'],
            ['photos_view.php', 'image-multiple', 'Gallery'],
            ['photos_add.php', 'upload-multiple', 'Upload Photos'],
            ['sms_center.php', 'message-text', 'SMS Center'],
        ],
        'Team & automation' => [
            ['users.php', 'account-card-details', 'User Management'],
            ['login_activity.php', 'lock-outline', 'Login Security'],
            ['api_tokens.php', 'robot', 'AI Assistant'],
            ['mcp_approvals.php', 'check-circle-outline', 'AI Approvals', ['count' => $approvalsWaiting, 'tone' => 'warn',
                'title' => 'money or message actions waiting for your decision']],
            ['ops_pulse.php', 'chart-donut-variant', 'Business Pulse', null, 'super'],
            ['job_watch.php', 'timer-sand', 'Job Health', ['count' => $jobWatchTrouble, 'tone' => 'danger',
                'title' => 'scheduled jobs that have gone quiet or are failing'], 'super'],
        ],
    ];
}

// A "super" flag on an item means the link is for the SaaS operator only
// (access_level 2), the same gate the old menu used.
$shell_is_super = function_exists('is_super_admin') && is_super_admin();
if (!$shell_is_super) {
    foreach ($nav_sections as $section_label => $section_links) {
        foreach ($section_links as $index => $item) {
            if (($item[4] ?? '') === 'super') {
                unset($nav_sections[$section_label][$index]);
            }
        }
        $nav_sections[$section_label] = array_values($nav_sections[$section_label]);
        if ($nav_sections[$section_label] === []) {
            unset($nav_sections[$section_label]);
        }
    }
}

/*
 * Current page. Sub-pages (edit forms, the couple's budget and guest list)
 * highlight the list they came from instead of leaving the sidebar blank.
 */
$shell_file = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
$shell_parent_page = [
    'blog_events_add.php'               => 'blog_events.php',
    'blog_events_edit.php'              => 'blog_events.php',
    'blog_events_delete.php'            => 'blog_events.php',
    'users_add.php'                     => 'users.php',
    'users_edit.php'                    => 'users.php',
    'users_delete.php'                  => 'users.php',
    'photos_edit.php'                   => 'photos_view.php',
    'client_add.php'                    => 'client.php',
    'client_edit.php'                   => 'client.php',
    'client_assign.php'                 => 'client.php',
    'client_budget.php'                 => 'client.php',
    'client_checklist.php'              => 'client.php',
    'client_guests.php'                 => 'client.php',
    'client_deposit.php'                => 'client.php',
    'client_installment_plan.php'       => 'client.php',
    'client_manage_account_details.php' => 'client.php',
    'client_website.php'                => 'client.php',
    'client_vendors.php'                => 'client.php',
    'client_vendor_enquiry.php'         => 'client.php',
    'client_vendor_plan.php'            => 'client.php',
    'client_proposal.php'               => 'client.php',
    'proposal_view.php'                 => 'proposals.php',
    'vendor_coverage.php'               => 'client.php',
    'vendor_plan.php'                   => 'client.php',
    'guest_list.php'                    => 'client.php',
    'guest_add.php'                     => 'client.php',
    'guest_edit.php'                    => 'client.php',
    'task_calendar.php'                 => 'task_all_calendar.php',
    'task_editEventDate.php'            => 'task_all_calendar.php',
    'task_editEventTitle.php'           => 'task_all_calendar.php',
    'vendor_packages_add.php'           => 'vendor_packages.php',
    'rsvp_dashboard.php'                => 'client.php',
];
$shell_active_file = $shell_parent_page[$shell_file] ?? $shell_file;

/*
 * A menu item that carries the conversation badge points at the waiting message
 * (vendor_thread_open.php) when there is one, but it still BELONGS to the
 * ledger page that opener returns to. Resolve that landing page once, so the
 * active state, the open group and the section eyebrow keep naming the right
 * page even while the entry is pointing at a thread.
 */
$nav_landing = static function (string $href) use ($shell_is_vendor, $sidebar_user): string {
    $path = (string) strtok($href, '?');
    if ($path !== 'vendor_thread_open.php') {
        return $path;
    }
    return $shell_is_vendor
        ? 'vendor_home.php'
        : ($sidebar_user !== null ? 'vendor_enquiries.php' : 'client_vendors.php');
};

// Human titles for the top bar. Unknown files fall back to their file name,
// so a newly added page still gets a readable heading without touching this map.
$shell_titles = [
    'dashboard.php'                     => 'Dashboard',
    'client.php'                        => 'Clients',
    'client_budget.php'                 => 'Wedding Budget',
    'client_checklist.php'              => 'Checklist',
    'client_guests.php'                 => 'Guest List',
    'client_website.php'                => 'Wedding Website',
    'client_deposit.php'                => 'Deposit',
    'client_vendors.php'                => 'Vendor Marketplace',
    'client_vendor_preferences.php'     => 'Taste & Budget',
    'client_vendor_plan.php'            => 'Vendor Plan & Total',
    'client_proposal.php'               => 'Your Wedding Options',
    'proposals.php'                     => 'Proposals',
    'proposal_view.php'                 => 'Proposal',
    'vendor_plan.php'                   => 'Vendor Plan',
    'client_installment_plan.php'       => 'Installment Plan',
    'client_manage_account_details.php' => 'Account Details',
    'task_all_calendar.php'             => 'Task Calendar',
    'headroom.php'                      => 'Capacity Headroom',
    'vendors.php'                       => 'Vendor Marketplace',
    'vendor_enquiries.php'              => 'Vendor Enquiries',
    'vendor_coverage.php'               => 'Vendor Coverage',
    'revenue.php'                       => 'Revenue',
    'subscription.php'                  => 'Subscription Plans',
    'subscription_plans.php'            => 'Plan Definitions',
    'mpesa_ledger.php'                  => 'M-Pesa Ledger',
    'service_list.php'                  => 'Services',
    'blog_events.php'                   => 'Blogs & Events',
    'photos_view.php'                   => 'Gallery',
    'photos_add.php'                    => 'Upload Photos',
    'sms_center.php'                    => 'SMS Center',
    'users.php'                         => 'User Management',
    'users_profile.php'                 => 'My Profile',
    'login_activity.php'                => 'Login Security',
    'api_tokens.php'                    => 'AI Assistant',
    'mcp_approvals.php'                 => 'AI Approvals',
    'ops_pulse.php'                     => 'Business Pulse',
    'job_watch.php'                     => 'Job Health',
    'notifications.php'                 => 'Notifications',
    'budget.php'                        => 'Budget',
    'events.php'                        => 'Events',
    'gallery.php'                       => 'Gallery',
    'rsvp_dashboard.php'                => 'RSVPs',
    'vendor_home.php'                   => 'My Bookings',
    'vendor_services.php'               => 'My Services',
    'vendor_packages.php'               => 'My Packages',
    'vendor_profile.php'                => 'My Profile',
];
$shell_title = $shell_titles[$shell_file] ?? ucwords(str_replace(['_', '-'], ' ', pathinfo($shell_file, PATHINFO_FILENAME)));
if (!$sidebar_user && $shell_file === 'client.php') {
    // client.php is the staff's Clients list, but a couple lands on it as their
    // own bookings — the one page where the same file has two names.
    $shell_title = 'My Bookings';
}

// Which section owns the current page — the small line above the page title.
$shell_section = 'Everafter Planner';
foreach ($nav_sections as $section_label => $section_links) {
    foreach ($section_links as $item) {
        $target = $nav_landing($item[0]);
        if ($target === $shell_active_file || $target === $shell_file) {
            $shell_section = $section_label;
            break 2;
        }
    }
}
?>
<link rel="stylesheet" href="css/design-tokens.css">
<link rel="stylesheet" href="css/app-shell.css">
<link rel="stylesheet" href="css/design-system.css">

<a class="app-skip" href="#appContent">Skip to content</a>

<aside id="appSidebar" class="app-sidebar" aria-label="Main navigation">
    <div class="app-brand">
        <a class="app-brand-link" href="<?= $shell_brand_href; ?>">
            <span class="app-brand-mark" aria-hidden="true"><i class="mdi mdi-heart-outline"></i></span>
            <span class="app-brand-text">Everafter<span>Planner</span></span>
        </a>
        <button type="button" class="app-icon-btn app-nav-close" data-app-nav-close aria-label="Close menu">
            <i class="mdi mdi-close" aria-hidden="true"></i>
        </button>
    </div>

    <div class="app-nav-search">
        <i class="mdi mdi-magnify" aria-hidden="true"></i>
        <input type="search" id="appNavSearch" placeholder="Search the menu" autocomplete="off"
               aria-label="Search the menu" aria-controls="appNav">
        <kbd class="app-kbd" aria-hidden="true">/</kbd>
    </div>

    <nav class="app-nav" id="appNav" aria-label="Sections">
        <?php foreach ($nav_sections as $section_label => $section_links): ?>
            <?php
            $group_slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($section_label));
            $group_has_active = false;
            foreach ($section_links as $item) {
                if ($nav_landing($item[0]) === $shell_active_file) {
                    $group_has_active = true;
                }
            }
            // "Content & comms" and "Team & automation" are the least-visited
            // shelves, so they start folded — unless the page being read is
            // inside them. The choice is remembered per browser.
            $group_starts_open = $group_has_active || !in_array($group_slug, ['content-comms', 'team-automation'], true);
            ?>
            <section class="app-nav-group<?= $group_starts_open ? '' : ' is-collapsed'; ?>"
                     data-nav-group="<?= htmlspecialchars($group_slug); ?>">
                <button type="button" class="app-nav-group-head" data-group-toggle
                        aria-expanded="<?= $group_starts_open ? 'true' : 'false'; ?>"
                        aria-controls="nav-<?= htmlspecialchars($group_slug); ?>">
                    <span><?= htmlspecialchars($section_label); ?></span>
                    <i class="mdi mdi-chevron-down" aria-hidden="true"></i>
                </button>
                <ul class="app-nav-list" id="nav-<?= htmlspecialchars($group_slug); ?>">
                    <?php foreach ($section_links as $item): ?>
                        <?php
                        [$href, $icon, $label] = $item;
                        $badge = $item[3] ?? null;
                        // Compare the page each href lands on, so a badge entry
                        // pointing at a thread still marks the ledger it came
                        // from — and the couple's marketplace entry (which
                        // carries their booking in the query) marks itself.
                        $nav_path  = $nav_landing($href);
                        $is_active = ($nav_path === $shell_active_file)
                            || ($nav_path === $shell_file && str_contains($href, '?'));
                        // The couple's marketplace is a sub-page of My Bookings;
                        // when its own entry is on screen, it alone is current.
                        if ($is_active && !$sidebar_user && $shell_file === 'client_vendors.php' && $href === 'client.php') {
                            $is_active = false;
                        }
                        ?>
                        <li class="app-nav-item">
                            <a class="app-nav-link<?= $is_active ? ' is-active' : ''; ?>"
                               href="<?= htmlspecialchars($href); ?>"
                               <?= $is_active ? 'aria-current="page"' : ''; ?>>
                                <i class="mdi mdi-<?= htmlspecialchars($icon); ?>" aria-hidden="true"></i>
                                <span class="app-nav-label"><?= htmlspecialchars($label); ?></span>
                                <?php if ($badge !== null && (int) $badge['count'] > 0): ?>
                                    <span class="app-nav-badge is-<?= htmlspecialchars($badge['tone'] ?? 'warn'); ?>"
                                          title="<?= htmlspecialchars($badge['title'] ?? ''); ?>"><?= (int) $badge['count']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

        <p class="app-nav-empty" id="appNavEmpty" hidden>No menu item matches that search.</p>
    </nav>

    <div class="app-side-foot">
        <a class="app-user" href="<?= htmlspecialchars($shell_profile); ?>">
            <?php if ($shell_avatar !== ''): ?>
                <img class="app-avatar" src="<?= htmlspecialchars($shell_avatar); ?>" alt=""
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                <span class="app-avatar app-avatar-fallback" style="display:none;"><?= htmlspecialchars($shell_initial); ?></span>
            <?php else: ?>
                <span class="app-avatar app-avatar-fallback"><?= htmlspecialchars($shell_initial); ?></span>
            <?php endif; ?>
            <span class="app-user-meta">
                <span class="app-user-name"><?= htmlspecialchars($shell_name); ?></span>
                <span class="app-user-role"><?= htmlspecialchars($shell_role); ?></span>
            </span>
        </a>
        <a class="app-user-logout" href="logout.php" title="Log out" aria-label="Log out">
            <i class="mdi mdi-logout" aria-hidden="true"></i>
        </a>
    </div>
</aside>

<div class="app-scrim" data-app-nav-close aria-hidden="true"></div>

<header class="app-topbar">
    <button type="button" class="app-icon-btn app-burger" data-app-nav-open
            aria-label="Open menu" aria-controls="appSidebar" aria-expanded="false">
        <i class="mdi mdi-menu" aria-hidden="true"></i>
    </button>

    <div class="app-page">
        <span class="app-page-eyebrow"><?= htmlspecialchars($shell_section); ?></span>
        <h1 class="app-page-title"><?= htmlspecialchars($shell_title); ?></h1>
    </div>

    <div class="app-topbar-actions">
        <button type="button" class="app-search-trigger" data-app-search-focus>
            <i class="mdi mdi-magnify" aria-hidden="true"></i>
            <span>Search menu</span>
            <kbd class="app-kbd" aria-hidden="true">/</kbd>
        </button>

        <?php
        // Notification owner: the SESSION user (staff in tblusers, couples in
        // tblaccounts). Deriving from the session means couples get the bell too.
        [$notifType, $notifUserId] = notification_recipient_for_session();
        ?>
        <?php if ($notifUserId > 0): ?>
            <?php
            $notifCount = Notification::unread_count($notifType, $notifUserId);
            $notifs     = Notification::latest($notifType, $notifUserId, 8);
            ?>
            <div class="app-pop-wrap">
                <button type="button" class="app-icon-btn app-bell" data-app-pop="notifications"
                        aria-label="Notifications<?= $notifCount > 0 ? ' (' . (int) $notifCount . ' unread)' : ''; ?>"
                        aria-expanded="false" aria-controls="appPopNotifications">
                    <i class="mdi mdi-bell-outline" aria-hidden="true"></i>
                    <?php if ($notifCount > 0): ?>
                        <span class="app-bell-dot"><?= (int) $notifCount; ?></span>
                    <?php endif; ?>
                </button>
                <div class="app-pop app-pop-wide" id="appPopNotifications" hidden>
                    <div class="app-pop-head">
                        <strong>Notifications</strong>
                        <?php if ($notifCount > 0): ?>
                            <a href="notifications.php?action=readall">Mark all read</a>
                        <?php endif; ?>
                    </div>
                    <div class="app-pop-body">
                        <?php if (empty($notifs)): ?>
                            <p class="app-pop-empty">You're all caught up.</p>
                        <?php else: ?>
                            <?php foreach ($notifs as $notif): ?>
                                <a class="app-notif<?= ((int) $notif->is_read === 0) ? ' is-unread' : ''; ?>"
                                   href="notifications.php?action=read&id=<?= (int) $notif->id; ?>">
                                    <span class="app-notif-msg"><?= htmlspecialchars($notif->message); ?></span>
                                    <span class="app-notif-time"><?= htmlspecialchars(format_date_kd((string) $notif->created_at, true)); ?></span>
                                </a>
                            <?php endforeach; ?>
                            <a class="app-pop-foot" href="notifications.php">View all notifications</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <span class="app-topbar-divider" aria-hidden="true"></span>

        <div class="app-pop-wrap">
            <button type="button" class="app-user-chip" data-app-pop="user"
                    aria-expanded="false" aria-controls="appPopUser">
                <?php if ($shell_avatar !== ''): ?>
                    <img class="app-avatar app-avatar-sm" src="<?= htmlspecialchars($shell_avatar); ?>" alt=""
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <span class="app-avatar app-avatar-sm app-avatar-fallback" style="display:none;"><?= htmlspecialchars($shell_initial); ?></span>
                <?php else: ?>
                    <span class="app-avatar app-avatar-sm app-avatar-fallback"><?= htmlspecialchars($shell_initial); ?></span>
                <?php endif; ?>
                <span class="app-user-chip-name"><?= htmlspecialchars($shell_name); ?></span>
                <i class="mdi mdi-chevron-down app-user-chip-caret" aria-hidden="true"></i>
            </button>
            <div class="app-pop app-pop-right" id="appPopUser" hidden>
                <div class="app-pop-user">
                    <span class="app-pop-user-name"><?= htmlspecialchars($shell_name); ?></span>
                    <span class="app-pop-user-role"><?= htmlspecialchars($shell_role); ?></span>
                </div>
                <a class="app-pop-link" href="<?= htmlspecialchars($shell_profile); ?>">
                    <i class="mdi mdi-account-outline" aria-hidden="true"></i> My profile
                </a>
                <a class="app-pop-link" href="notifications.php">
                    <i class="mdi mdi-bell-outline" aria-hidden="true"></i> Notifications
                </a>
                <a class="app-pop-link is-danger" href="logout.php">
                    <i class="mdi mdi-logout" aria-hidden="true"></i> Log out
                </a>
            </div>
        </div>
    </div>
</header>

<script src="js/app-shell.js" defer></script>

<main role="main" class="app-content" id="appContent">
