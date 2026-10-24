<?php include 'admin/include/init.php'; ?>
<?php
/**
 * Public vendor profile — one approved listing, for a visitor with no account.
 *
 * The directory (vendors.php) shows a card per business; this page is what the
 * card opens: the FULL description and EVERY active package, with prices in KSh,
 * still without a session. The same rules as the directory apply, and they live
 * in the same places:
 *
 *  - Approval is part of the lookup (Vendor::find_approved), so an unapproved or
 *    deleted listing answers exactly like one that never existed. A 404 that
 *    said "pending approval" would confirm a hidden business and its name to
 *    anyone walking the ids.
 *  - Packages come from Vendor::packages_for(), which returns active rows only —
 *    a hidden package cannot leak its price through the profile after being
 *    withheld from the card.
 *  - Phone and email stay off the page. An enquiry is a tblvendor_bookings row
 *    tied to a wedding and copied to that couple's planner; the call to action
 *    sends the visitor to sign in instead of printing a number that would route
 *    the lead around the ledger.
 *
 * Read-only: no form, nothing written, and the id is cast before it is used.
 */
    $vendor_id = (int) ($_GET['id'] ?? 0);
    $vendor    = Vendor::find_approved($vendor_id);

    // A missing listing is a 404 for the visitor and a normal page for a crawler.
    http_response_code($vendor === null ? 404 : 200);

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

    $packages = $vendor !== null ? Vendor::packages_for($vendor_id) : [];
    /*
     * Services (v2.27), active only — the same rule as packages above. These
     * are the priced pieces behind a package, each stated with its unit, which
     * is what lets a visitor compare this vendor's catering with another's
     * before sending an enquiry.
     */
    $services = $vendor !== null ? VendorService::for_vendor($vendor_id) : [];
    // Tier pricing (v3.1). The profile keeps quoting the vendor's unit against
    // the Silver price — the "from" figure a couple searches by — and prints the
    // three levels underneath, each with what it includes and leaves out.
    $service_tiers = VendorTier::map_for_services(array_map(static fn (object $s): int => (int) $s->id, $services));
    $package_tiers = VendorTier::map_for_packages(array_map(static fn (object $p): int => (int) $p->id, $packages));
    ['href' => $enquiry_href, 'label' => $enquiry_label] = vendor_enquiry_cta();

    $logo_url = '';
    if ($vendor !== null) {
        // The DB value is a filename, not a path we follow: plain names only,
        // and only when the file is really on disk (see vendors.php).
        $logo = trim((string) ($vendor->logo ?? ''));
        if ($logo !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $logo)
            && is_file(SITE_ROOT . DS . 'upload' . DS . 'vendors' . DS . $logo)) {
            $logo_url = 'admin/upload/vendors/' . rawurlencode($logo);
        }
    }

    // Absolute URL of THIS page — canonical, og:url and the JSON-LD @id share
    // it. An id that was not a listing is not carried into the URL.
    $self_url = public_url('vendor.php', $vendor_id > 0 ? ['id' => $vendor_id] : []);

    $share_default_image = public_url('images/logo/everafter-crest.png');
    $share_image         = $share_default_image;
    $og_type             = 'website';
    $og_title            = 'Vendor not found';
    $vendor_ld           = null;

    if ($vendor !== null) {
        $category_label = $categories[(string) $vendor->category][0] ?? 'Vendor';
        $category_icon  = $categories[(string) $vendor->category][1] ?? 'store';
        // Stored text went through clean() on the way in, so it can carry HTML
        // entities; a preview card and JSON-LD want the characters themselves.
        $category_plain = html_entity_decode(strip_tags($category_label), ENT_QUOTES, 'UTF-8');
        $rating         = (float) $vendor->rating;
        $stars          = max(0, min(5, (int) round($rating)));
        $bookings       = (int) $vendor->total_bookings;
        $rating_count   = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tblvendor_bookings WHERE vendor_id = :id AND rating IS NOT NULL',
            [':id' => $vendor_id]
        );
        $description       = trim((string) ($vendor->description ?? ''));
        $description_plain = trim(html_entity_decode($description, ENT_QUOTES, 'UTF-8'));

        $page_title = $vendor->business_name . ' — ' . $category_plain
            . ' in ' . $vendor->county . ' | Everafter Planner';
        $meta_desc  = $description_plain !== ''
            ? mb_substr(preg_replace('/\s+/', ' ', $description_plain), 0, 155)
            : 'Packages, prices and details for ' . $vendor->business_name . ', an approved wedding vendor on Everafter Planner.';

        $og_type  = 'business.business';
        // A middot, not another em dash: seeded names already carry one.
        $og_title = $vendor->business_name . ' · ' . $category_plain . ' in ' . $vendor->county;
        if ($logo_url !== '') {
            $share_image = public_url($logo_url);
        }

        /*
         * LocalBusiness structured data. It mirrors what the page shows and
         * nothing more: no telephone or email (the page withholds them on
         * purpose), and an aggregateRating only when couples have actually
         * rated delivered deals — a rating count we could not point at real
         * rows would be invented markup, which is exactly what rich-result
         * policies forbid. Offers are the active packages, in KES.
         */
        $offers = [];
        $prices = [];
        foreach ($packages as $p) {
            $pkg_plain = trim(html_entity_decode((string) ($p->description ?? ''), ENT_QUOTES, 'UTF-8'));
            $offer = [
                '@type'         => 'Offer',
                'name'          => html_entity_decode((string) $p->title, ENT_QUOTES, 'UTF-8'),
                'price'         => (float) $p->price,
                'priceCurrency' => 'KES',
                'availability'  => 'https://schema.org/InStock',
            ];
            if ($pkg_plain !== '') {
                $offer['description'] = mb_substr($pkg_plain, 0, 300);
            }
            if ((int) $p->capacity > 0) {
                $offer['eligibleQuantity'] = [
                    '@type'    => 'QuantitativeValue',
                    'maxValue' => (int) $p->capacity,
                    'unitText' => 'guests',
                ];
            }
            $offers[] = $offer;
            $prices[] = (float) $p->price;
        }

        $vendor_ld = [
            '@context'    => 'https://schema.org',
            '@type'       => 'LocalBusiness',
            '@id'         => $self_url . '#vendor',
            'name'        => html_entity_decode((string) $vendor->business_name, ENT_QUOTES, 'UTF-8'),
            'url'         => $self_url,
            'description' => $description_plain !== '' ? $description_plain : $meta_desc,
            'address'     => [
                '@type'           => 'PostalAddress',
                'addressLocality' => html_entity_decode((string) $vendor->location, ENT_QUOTES, 'UTF-8'),
                'addressRegion'   => html_entity_decode((string) $vendor->county, ENT_QUOTES, 'UTF-8'),
                'addressCountry'  => 'KE',
            ],
            'areaServed'  => html_entity_decode((string) $vendor->county, ENT_QUOTES, 'UTF-8'),
            'image'       => $share_image,
        ];
        if ($offers !== []) {
            $vendor_ld['makesOffer'] = $offers;
            $vendor_ld['priceRange'] = format_ksh(min($prices)) . ' – ' . format_ksh(max($prices));
        }
        if ($rating_count > 0 && $rating > 0) {
            $vendor_ld['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $rating,
                'ratingCount' => $rating_count,
                'bestRating'  => 5,
                'worstRating' => 1,
            ];
        }
    } else {
        $page_title = 'Vendor not found | Everafter Planner';
        $meta_desc  = 'This vendor listing is not available. Browse approved wedding vendors on Everafter Planner.';
    }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?= htmlspecialchars($meta_desc); ?>">
    <?php if ($vendor_ld !== null): ?>
    <link rel="canonical" href="<?= htmlspecialchars($self_url); ?>">
    <?php else: ?>
    <?php /* A 404 has nothing canonical to point at; keep it out of the index. */ ?>
    <meta name="robots" content="noindex, follow">
    <?php endif; ?>

    <!-- Link previews (WhatsApp, Facebook, X, iMessage). Absolute URLs only:
         a relative og:image resolves against nothing and the card comes back
         blank, which is why public_url() carries the install folder too. -->
    <meta property="og:site_name" content="Everafter Planner">
    <meta property="og:type" content="<?= htmlspecialchars($og_type); ?>">
    <meta property="og:title" content="<?= htmlspecialchars($og_title); ?>">
    <meta property="og:description" content="<?= htmlspecialchars($meta_desc); ?>">
    <meta property="og:url" content="<?= htmlspecialchars($self_url); ?>">
    <meta property="og:image" content="<?= htmlspecialchars($share_image); ?>">
    <meta property="og:image:alt" content="<?= htmlspecialchars($og_title); ?>">
    <meta property="og:locale" content="en_KE">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= htmlspecialchars($og_title); ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($meta_desc); ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($share_image); ?>">

    <?php if ($vendor_ld !== null): ?>
    <script type="application/ld+json"><?= json_encode(
        $vendor_ld,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_INVALID_UTF8_SUBSTITUTE
    ); ?></script>
    <?php endif; ?>

    <link href="https://fonts.googleapis.com/css?family=Playfair+Display:400,700|Montserrat:300,400,500,600" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=4.6.2">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/modern.css">
    <link rel="stylesheet" href="css/public-vendors.css">
</head>
<body>
<?php include 'include/nav.php'; ?>

<?php if ($vendor === null): ?>
    <header class="vd-hero">
        <h1>Vendor not found</h1>
        <p>This listing is not available — it may not be approved yet, or it may no longer be active.</p>
    </header>

    <main class="vd-wrap">
        <div class="vd-empty">
            <i class="mdi mdi-store-off-outline"></i>
            <h5 class="mt-2 mb-1">Nothing to show here</h5>
            <p class="text-muted mb-3" style="font-size:13px;">
                Only approved listings are published. Everything live is on the vendor directory.
            </p>
            <a href="vendors.php" class="vd-ghost"><i class="mdi mdi-arrow-left mr-1"></i>Browse every vendor</a>
        </div>
    </main>
<?php else: ?>
    <header class="vd-hero">
        <?php if ($logo_url !== ''): ?>
            <div class="vd-avatar vd-avatar--lg mx-auto mb-3" style="background:rgba(255,255,255,.16);">
                <img src="<?= htmlspecialchars($logo_url); ?>" alt="<?= htmlspecialchars((string) $vendor->business_name); ?> logo">
            </div>
        <?php endif; ?>
        <h1><?= htmlspecialchars((string) $vendor->business_name); ?></h1>
        <p>
            <?= $category_label; ?> &middot;
            <?= htmlspecialchars((string) $vendor->location); ?>, <?= htmlspecialchars((string) $vendor->county); ?>
        </p>
        <span class="vd-count">
            <?php if ((int) $vendor->is_premium === 1): ?>
                <i class="mdi mdi-crown"></i> Premium listing
            <?php else: ?>
                <i class="mdi mdi-check-decagram"></i> Approved vendor
            <?php endif; ?>
        </span>
    </header>

    <main class="vd-wrap">
        <nav class="vd-crumbs" aria-label="Breadcrumb">
            <a href="vendors.php"><i class="mdi mdi-arrow-left"></i> Vendor directory</a>
            <span class="mx-1">/</span>
            <span><?= htmlspecialchars((string) $vendor->business_name); ?></span>
        </nav>

        <div class="vd-layout">
            <div>
                <section class="vd-panel">
                    <h2>About <?= htmlspecialchars((string) $vendor->business_name); ?></h2>
                    <?php if ($description !== ''): ?>
                        <div class="vd-prose"><?= nl2br(htmlspecialchars($description)); ?></div>
                    <?php else: ?>
                        <p class="text-muted mb-0" style="font-size:13.5px;">
                            This vendor has not added a description yet. Enquire and they will tell you what they cover.
                        </p>
                    <?php endif; ?>
                </section>

                <section class="vd-panel">
                    <h2>Services &amp; prices</h2>
                    <?php if ($services === []): ?>
                        <p class="text-muted mb-0" style="font-size:13.5px;">
                            No individual services have been published yet — packages below, or send an enquiry for a quote.
                        </p>
                    <?php else: ?>
                        <?php foreach ($services as $s): ?>
                            <article class="vd-pkg-full">
                                <div class="vd-pkg-full-head">
                                    <div class="vd-pkg-full-title"><?= htmlspecialchars((string) $s->title); ?></div>
                                    <div class="vd-pkg-full-price"><?= htmlspecialchars(VendorService::price_line($s)); ?></div>
                                </div>
                                <?php $tiers = $service_tiers[(int) $s->id] ?? []; ?>
                                <?php if ($tiers !== []): ?>
                                    <div class="vd-pkg-full-meta" style="display:block;">
                                        <?php foreach ($tiers as $tier_row): ?>
                                            <div class="mt-1" style="font-size:12.5px;">
                                                <strong><?= htmlspecialchars(VendorTier::label((string) $tier_row->tier)); ?></strong>
                                                — <?= htmlspecialchars(format_ksh((float) $tier_row->price)); ?>
                                                <?php if (trim((string) $tier_row->includes) !== ''): ?>
                                                    <span class="text-muted">· includes <?= htmlspecialchars((string) $tier_row->includes); ?></span>
                                                <?php endif; ?>
                                                <?php if (trim((string) $tier_row->excludes) !== ''): ?>
                                                    <span class="text-muted">· not included: <?= htmlspecialchars((string) $tier_row->excludes); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (trim((string) ($s->includes ?? '')) !== ''): ?>
                                    <div class="vd-pkg-full-meta">
                                        <span><i class="mdi mdi-check-circle-outline"></i> Includes: <?= htmlspecialchars((string) $s->includes); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (trim((string) ($s->description ?? '')) !== ''): ?>
                                    <p class="vd-pkg-full-desc"><?= nl2br(htmlspecialchars(trim((string) $s->description))); ?></p>
                                <?php endif; ?>
                                <?php if (VendorService::tag_list($s) !== []): ?>
                                    <div class="vd-pkg-full-meta">
                                        <?php foreach (VendorService::tag_list($s) as $tag): ?>
                                            <span class="mr-2"><i class="mdi mdi-tag-outline"></i> <?= htmlspecialchars($tag); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <section class="vd-panel">
                    <h2>Packages &amp; prices</h2>
                    <?php if ($packages === []): ?>
                        <p class="text-muted mb-0" style="font-size:13.5px;">
                            No packages have been published yet — send an enquiry for a quote.
                        </p>
                    <?php else: ?>
                        <?php foreach ($packages as $p): ?>
                            <article class="vd-pkg-full">
                                <div class="vd-pkg-full-head">
                                    <div class="vd-pkg-full-title"><?= htmlspecialchars((string) $p->title); ?></div>
                                    <div class="vd-pkg-full-price"><?= format_ksh((float) $p->price); ?></div>
                                </div>
                                <?php $pkg_tiers = $package_tiers[(int) $p->id] ?? []; ?>
                                <?php if ($pkg_tiers !== []): ?>
                                    <div class="vd-pkg-full-meta" style="display:block;">
                                        <?php foreach ($pkg_tiers as $tier_row): ?>
                                            <div class="mt-1" style="font-size:12.5px;">
                                                <strong><?= htmlspecialchars(VendorTier::label((string) $tier_row->tier)); ?></strong>
                                                — <?= htmlspecialchars(format_ksh((float) $tier_row->price)); ?>
                                                <?php if (trim((string) $tier_row->includes) !== ''): ?>
                                                    <span class="text-muted">· includes <?= htmlspecialchars((string) $tier_row->includes); ?></span>
                                                <?php endif; ?>
                                                <?php if (trim((string) $tier_row->excludes) !== ''): ?>
                                                    <span class="text-muted">· not included: <?= htmlspecialchars((string) $tier_row->excludes); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="vd-pkg-full-meta">
                                    <?php if ((int) $p->capacity > 0): ?>
                                        <span><i class="mdi mdi-account-multiple"></i> Up to <?= (int) $p->capacity; ?> guests</span>
                                    <?php endif; ?>
                                    <?php if ((int) $p->duration_hours > 0): ?>
                                        <span class="ml-3"><i class="mdi mdi-clock-outline"></i> <?= (int) $p->duration_hours; ?> hours</span>
                                    <?php endif; ?>
                                    <?php if ((int) $p->capacity <= 0 && (int) $p->duration_hours <= 0): ?>
                                        <span>Details on enquiry</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (trim((string) ($p->description ?? '')) !== ''): ?>
                                    <p class="vd-pkg-full-desc"><?= nl2br(htmlspecialchars(trim((string) $p->description))); ?></p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            </div>

            <aside>
                <section class="vd-panel">
                    <div class="vd-stars vd-stars--lg">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="mdi mdi-star<?= $i <= $stars ? '' : '-outline'; ?>"></i>
                        <?php endfor; ?>
                        <span class="text-muted" style="font-size:12.5px;">
                            <?php if ($rating > 0): ?>
                                <?= number_format($rating, 1); ?>/5<?= $rating_count > 0 ? ' · ' . $rating_count . ' rating' . ($rating_count === 1 ? '' : 's') : ''; ?>
                            <?php else: ?>
                                No rating yet
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="vd-spec">
                        <div class="vd-spec-row">
                            <span class="vd-spec-label">Category</span>
                            <span class="vd-spec-value"><?= $category_label; ?></span>
                        </div>
                        <div class="vd-spec-row">
                            <span class="vd-spec-label">Location</span>
                            <span class="vd-spec-value"><?= htmlspecialchars((string) $vendor->location); ?></span>
                        </div>
                        <div class="vd-spec-row">
                            <span class="vd-spec-label">County</span>
                            <span class="vd-spec-value"><?= htmlspecialchars((string) $vendor->county); ?></span>
                        </div>
                        <div class="vd-spec-row">
                            <span class="vd-spec-label">Confirmed bookings</span>
                            <span class="vd-spec-value"><?= $bookings; ?></span>
                        </div>
                        <div class="vd-spec-row">
                            <span class="vd-spec-label">Services</span>
                            <span class="vd-spec-value"><?= count($services); ?></span>
                        </div>
                        <div class="vd-spec-row">
                            <span class="vd-spec-label">Packages</span>
                            <span class="vd-spec-value"><?= count($packages); ?></span>
                        </div>
                    </div>

                    <div class="mt-3">
                        <a class="vd-cta vd-cta--block" href="<?= htmlspecialchars($enquiry_href); ?>">
                            <i class="mdi mdi-message-text-outline mr-1"></i><?= htmlspecialchars($enquiry_label); ?>
                        </a>
                        <p class="text-muted mt-3 mb-0" style="font-size:11.5px; line-height:1.6;">
                            Every enquiry is tied to your wedding and copied to your planner, so the price you agree is the price on record.
                        </p>
                    </div>
                </section>

                <section class="vd-panel">
                    <h2 style="font-size:18px;">Browse more</h2>
                    <p class="text-muted mb-3" style="font-size:12.5px;">
                        Compare <?= strtolower(strip_tags($category_label)); ?> and other vendors in KSh before you commit.
                    </p>
                    <a class="vd-ghost" href="vendors.php?category=<?= urlencode((string) $vendor->category); ?>">
                        <i class="mdi mdi-store mr-1"></i>All <?= strtolower(strip_tags($category_label)); ?> vendors
                    </a>
                </section>
            </aside>
        </div>
    </main>
<?php endif; ?>

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
