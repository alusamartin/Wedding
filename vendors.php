<?php include 'admin/include/init.php'; ?>
<?php
/**
 * Public vendor directory — the one shop window a visitor with no account can see.
 *
 * The marketplace itself lives behind a login: admin/client_vendors.php is scoped
 * to a couple's own wedding, admin/vendors.php to staff and to vendors. Until now
 * a vendor was advertised only to people who already had a booking, so a listing
 * could serve an existing couple but could never bring a new one in. This page is
 * the anonymous door.
 *
 * The vendor set is Vendor::get_approved(), the same predicate both logged-in
 * marketplaces use (approved only, premium first, then rating), so the three
 * screens cannot disagree about who is live. Filtering is the marketplace's own
 * vocabulary: the category enum, and the county column. Read-only — there is no
 * form here and nothing a visitor types is written anywhere.
 *
 * WHAT IT DELIBERATELY WITHHOLDS: the vendor's phone number and email address.
 * An enquiry is a tblvendor_bookings row tied to a real wedding, copied to that
 * couple's planner — that row is the product, and printing a phone number on a
 * public page would route the lead around the ledger and around the commission
 * the platform earns from it. So the call to action sends a visitor to sign in or
 * join, which is where the enquiry flow (and the vendor's own ledger) begins.
 */
    $categories = [
        'catering'    => ['Catering', 'food'],
        'decor'       => ['Decor', 'flower'],
        'photography' => ['Photography', 'camera'],
        'music'       => ['Music &amp; DJ', 'music'],
        'transport'   => ['Transport', 'bus'],
        'mc'          => ['MC &amp; Entertainment', 'microphone'],
        'venue'       => ['Venue', 'home'],
        'cakes'       => ['Cakes', 'cake'],
        'flowers'     => ['Flowers', 'flower-tulip'],
        'other'       => ['Other', 'briefcase'],
    ];

    // Category: compared against the enum, never echoed back as typed.
    $category_filter = trim((string) ($_GET['category'] ?? ''));
    if (!isset($categories[$category_filter])) {
        $category_filter = '';
    }

    // Counties are read from the approved listings themselves, so the dropdown
    // can never offer a county with nothing behind it.
    $counties = [];
    foreach ($db->fetchAll('SELECT DISTINCT county FROM tblvendors WHERE is_approved = 1') as $row) {
        $county = trim((string) ($row->county ?? ''));
        if ($county !== '') {
            $counties[] = $county;
        }
    }
    sort($counties, SORT_NATURAL | SORT_FLAG_CASE);

    /*
     * A requested county is resolved to the canonical value in that list. An
     * unknown value filters nothing and is never printed, so the query string
     * cannot put text of its own on the page. Comparing in PHP matches the
     * couple-facing marketplace (which filters counties the same way); the
     * vendor table is tens of rows, and category is the real navigation.
     */
    $county_filter = '';
    $requested_county = trim((string) ($_GET['county'] ?? ''));
    if ($requested_county !== '') {
        foreach ($counties as $known) {
            if (strcasecmp($known, $requested_county) === 0) {
                $county_filter = $known;
                break;
            }
        }
    }

    $all_approved = Vendor::get_approved(null);
    $vendors = Vendor::get_approved($category_filter !== '' ? $category_filter : null);
    if ($county_filter !== '') {
        $vendors = array_values(array_filter(
            $vendors,
            static fn($v) => strcasecmp(trim((string) $v->county), $county_filter) === 0
        ));
    }

    // Active packages per vendor, for the price lines on each card.
    $packages_by_vendor = [];
    foreach ($vendors as $v) {
        $packages_by_vendor[(int) $v->id] = Vendor::packages_for((int) $v->id);
    }

    /** Build a filter URL: this page, the two filters, and one override. */
    $directory_url = static function (array $overrides = []) use ($category_filter, $county_filter): string {
        $query = array_filter([
            'category' => $overrides['category'] ?? $category_filter,
            'county'   => $overrides['county'] ?? $county_filter,
        ], static fn($value) => $value !== '');
        return 'vendors.php' . ($query !== [] ? '?' . http_build_query($query) : '');
    };

    // Where a visitor goes to actually enquire — shared with vendor.php so the
    // card and the profile cannot disagree. Signed-in accounts already have a
    // portal for their role; everyone else starts at the one login door.
    ['href' => $enquiry_href, 'label' => $enquiry_label] = vendor_enquiry_cta();

    /*
     * Share previews and the canonical URL.
     *
     * The URL is SELF, filters included: /vendors.php?category=catering and
     * ?county=Nakuru+County are different pages with different cards, so each
     * is its own canonical rather than a duplicate of the unfiltered grid. The
     * title and description name the filter too — a shared "catering in Nakuru"
     * link should say so in the preview instead of looking like the homepage.
     *
     * A filter combination with nothing behind it is not worth indexing, so it
     * asks not to be; the directory itself stays indexable.
     */
    $filter_query = array_filter([
        'category' => $category_filter,
        'county'   => $county_filter,
    ], static fn($value) => $value !== '');
    $self_url = public_url('vendors.php', $filter_query);

    $share_image = public_url('images/logo/everafter-crest.png');
    // Empty because of a filter, not because the directory is new: only the
    // former is a dead facet nobody should index.
    $no_results = $vendors === [] && $filter_query !== [];

    if ($no_results) {
        $og_title   = 'Wedding vendors — nothing matches this filter';
        $meta_desc  = 'No approved vendor matches this category and county on Everafter Planner. Browse the full directory instead.';
        $page_title = $og_title . ' | Everafter Planner';
    } elseif ($category_filter !== '') {
        $category_plain = html_entity_decode($categories[$category_filter][0], ENT_QUOTES, 'UTF-8');
        $where          = $county_filter !== '' ? ' in ' . $county_filter : ' in Kenya';
        $og_title       = $category_plain . ' Vendors' . $where;
        $page_title     = $og_title . ' | Everafter Planner';
        $meta_desc      = 'Compare ' . count($vendors) . ' approved ' . strtolower($category_plain) . ' vendors'
            . $where . ' — packages and prices in KSh, reviewed by Everafter Planner.';
    } elseif ($county_filter !== '') {
        $og_title   = 'Wedding Vendors in ' . $county_filter;
        $page_title = $og_title . ' | Everafter Planner';
        $meta_desc  = 'Browse ' . count($vendors) . ' approved wedding vendors in ' . $county_filter
            . ' — package prices in KSh, reviewed by Everafter Planner.';
    } else {
        $og_title   = 'Wedding Vendors in Kenya';
        $page_title = 'Wedding Vendors in Kenya — Catering, Decor, Photography | Everafter Planner';
        $meta_desc  = 'Browse approved Kenyan wedding vendors — catering, decor, photography, music, transport, venues, cakes and flowers — with package prices in KSh, by county.';
    }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?= htmlspecialchars($meta_desc); ?>">
    <link rel="canonical" href="<?= htmlspecialchars($self_url); ?>">
    <?php if ($no_results): ?>
    <meta name="robots" content="noindex, follow">
    <?php endif; ?>

    <!-- Link previews (WhatsApp, Facebook, X, iMessage). Absolute URLs only —
         the install folder comes from public_url(), not from a relative path
         the sharing client cannot resolve. -->
    <meta property="og:site_name" content="Everafter Planner">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($og_title); ?>">
    <meta property="og:description" content="<?= htmlspecialchars($meta_desc); ?>">
    <meta property="og:url" content="<?= htmlspecialchars($self_url); ?>">
    <meta property="og:image" content="<?= htmlspecialchars($share_image); ?>">
    <meta property="og:image:alt" content="Everafter Planner — Kenyan wedding vendors">
    <meta property="og:locale" content="en_KE">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= htmlspecialchars($og_title); ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($meta_desc); ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($share_image); ?>">

    <link href="https://fonts.googleapis.com/css?family=Playfair+Display:400,700|Montserrat:300,400,500,600" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=4.6.2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modern.css">
    <link rel="stylesheet" href="css/public-vendors.css">
</head>
<body>
<?php include 'include/nav.php'; ?>

<header class="vd-hero">
    <h1>Trusted Kenyan Wedding Vendors</h1>
    <p>Every business here has been reviewed and approved by Everafter Planner. Compare packages in KSh, then enquire through your planner — so nothing is promised that no one records.</p>
    <span class="vd-count"><?= count($all_approved); ?> approved vendor<?= count($all_approved) === 1 ? '' : 's'; ?></span>
</header>

<main class="vd-wrap">
    <!-- Filters: category chips and a county dropdown, both plain GET links -->
    <form class="vd-filters" method="get" action="vendors.php">
        <div class="d-flex flex-wrap align-items-center">
            <span class="vd-cat mr-2 mb-1">Category</span>
            <a class="vd-chip <?= $category_filter === '' ? 'is-active' : ''; ?>"
               href="<?= htmlspecialchars($directory_url(['category' => ''])); ?>">All</a>
            <?php foreach ($categories as $slug => [$label, $icon]): ?>
                <a class="vd-chip <?= $category_filter === $slug ? 'is-active' : ''; ?>"
                   href="<?= htmlspecialchars($directory_url(['category' => $slug])); ?>">
                    <i class="mdi mdi-<?= $icon; ?> mr-1"></i><?= $label; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (count($counties) > 1 || $county_filter !== ''): ?>
        <div class="d-flex flex-wrap align-items-center mt-2">
            <label class="vd-cat mr-2 mb-1" for="county">County</label>
            <?php if ($category_filter !== ''): ?>
                <input type="hidden" name="category" value="<?= htmlspecialchars($category_filter); ?>">
            <?php endif; ?>
            <select name="county" id="county" class="vd-county" onchange="this.form.submit()">
                <option value="">All counties</option>
                <?php foreach ($counties as $c): ?>
                    <option value="<?= htmlspecialchars($c); ?>" <?= $county_filter === $c ? 'selected' : ''; ?>><?= htmlspecialchars($c); ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-outline-success ml-2" style="border-radius:999px;font-size:12px;">Apply</button></noscript>
            <?php if ($county_filter !== '' || $category_filter !== ''): ?>
                <a class="ml-2" style="font-size:12px;color:#64748B;" href="vendors.php">Clear filters</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>

    <p class="text-muted mb-3" style="font-size:13px;">
        <?php if ($vendors === []): ?>
            No vendors match this filter.
        <?php else: ?>
            Showing <strong><?= count($vendors); ?></strong>
            <?= ($category_filter !== '' || $county_filter !== '') ? 'matching' : 'approved'; ?>
            vendor<?= count($vendors) === 1 ? '' : 's'; ?><?= $county_filter !== '' ? ' in ' . htmlspecialchars($county_filter) : ''; ?>.
        <?php endif; ?>
    </p>

    <div class="row">
        <?php if ($vendors === []): ?>
            <div class="col-12">
                <div class="vd-empty">
                    <i class="mdi mdi-store-outline"></i>
                    <h5 class="mt-2 mb-1">Nothing here yet</h5>
                    <p class="text-muted mb-3" style="font-size:13px;">
                        <?= $county_filter !== ''
                            ? 'No approved vendor in ' . htmlspecialchars($county_filter) . ' for this category — try another county or category.'
                            : 'Vendors in this category appear here once our team has reviewed and approved them.'; ?>
                    </p>
                    <a href="vendors.php" class="btn btn-sm btn-outline-success" style="border-radius:999px;font-size:12.5px;">Browse every vendor</a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($vendors as $vendor): ?>
                <?php
                    $icon  = $categories[(string) $vendor->category][1] ?? 'store';
                    $pkgs  = $packages_by_vendor[(int) $vendor->id] ?? [];
                    $stars = max(0, min(5, (int) round((float) $vendor->rating)));
                    // Logo only when the file is really there and its name is a
                    // plain filename — the DB value is not a path we will follow.
                    $logo     = trim((string) ($vendor->logo ?? ''));
                    $logo_url = '';
                    if ($logo !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $logo)
                        && is_file(SITE_ROOT . DS . 'upload' . DS . 'vendors' . DS . $logo)) {
                        $logo_url = 'admin/upload/vendors/' . rawurlencode($logo);
                    }
                ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="vd-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="d-flex align-items-center">
                                <div class="vd-avatar mr-2">
                                    <?php if ($logo_url !== ''): ?>
                                        <img src="<?= htmlspecialchars($logo_url); ?>" alt="<?= htmlspecialchars((string) $vendor->business_name); ?> logo">
                                    <?php else: ?>
                                        <i class="mdi mdi-<?= $icon; ?>"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h2 class="vd-name"><a href="vendor.php?id=<?= (int) $vendor->id; ?>"><?= htmlspecialchars((string) $vendor->business_name); ?></a></h2>
                                    <div class="vd-cat"><?= $categories[(string) $vendor->category][0] ?? 'Vendor'; ?></div>
                                </div>
                            </div>
                            <?php if ((int) $vendor->is_premium === 1): ?>
                                <span class="vd-badge"><i class="mdi mdi-crown"></i> Premium</span>
                            <?php endif; ?>
                        </div>

                        <div class="vd-meta">
                            <i class="mdi mdi-map-marker"></i>
                            <?= htmlspecialchars((string) $vendor->location); ?>, <?= htmlspecialchars((string) $vendor->county); ?>
                        </div>
                        <div class="vd-stars mt-1">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="mdi mdi-star<?= $i <= $stars ? '' : '-outline'; ?>"></i>
                            <?php endfor; ?>
                            <span class="text-muted" style="font-size:11.5px;">
                                <?= (float) $vendor->rating > 0 ? number_format((float) $vendor->rating, 1) . '/5 · ' : ''; ?><?= (int) $vendor->total_bookings; ?> booking<?= (int) $vendor->total_bookings === 1 ? '' : 's'; ?>
                            </span>
                        </div>

                        <?php if (trim((string) $vendor->description) !== ''): ?>
                            <p class="vd-desc">
                                <?= htmlspecialchars(mb_substr(trim((string) $vendor->description), 0, 130)); ?><?= mb_strlen(trim((string) $vendor->description)) > 130 ? '…' : ''; ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($pkgs !== []): ?>
                            <div class="vd-pkgs">
                                <?php foreach (array_slice($pkgs, 0, 3) as $p): ?>
                                    <div class="vd-pkg">
                                        <span>
                                            <?= htmlspecialchars((string) $p->title); ?>
                                            <?php if ((int) $p->capacity > 0): ?>
                                                <span class="text-muted" style="font-size:11px;">· up to <?= (int) $p->capacity; ?> pax</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="vd-pkg-price"><?= format_ksh((float) $p->price); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($pkgs) > 3): ?>
                                    <div class="vd-more">+ <?= count($pkgs) - 3; ?> more package<?= count($pkgs) - 3 === 1 ? '' : 's'; ?> on enquiry</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="vd-card-foot">
                            <a class="vd-cta" href="<?= htmlspecialchars($enquiry_href); ?>">
                                <i class="mdi mdi-message-text-outline mr-1"></i><?= htmlspecialchars($enquiry_label); ?>
                            </a>
                            <a class="vd-profile" href="vendor.php?id=<?= (int) $vendor->id; ?>">
                                Full profile &amp; packages <i class="mdi mdi-arrow-right"></i>
                            </a>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <section class="vd-vendor-band">
        <h3>Are you a wedding vendor?</h3>
        <p>
            Catering, decor, photography, transport, venues and more — approved listings appear on this page,
            reach couples planning real weddings, and receive enquiries with the planner already in the loop.
            List your business yourself: create your login, describe what you sell, and we will switch the
            listing on after a quick approval.
        </p>
        <a href="vendor_signup.php" class="btn btn-success"><i class="mdi mdi-store-plus-outline mr-1"></i> List your business — free</a>
        <a href="about.php" class="btn btn-outline-success ml-2">How Everafter works</a>
    </section>
</main>

<footer class="vd-footer">
    <p class="mb-1"><strong>Everafter Planner</strong> — Wedding Planning Made Easy</p>
    <p class="mb-0">&copy; <?= date('Y'); ?> Everafter Planner. All amounts in KSh. M-Pesa, Africa's Talking integrated.</p>
</footer>

<script src="js/jquery-3.7.1.slim.min.js"></script>
<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js?v=4.6.2"></script>
</body>
</html>
