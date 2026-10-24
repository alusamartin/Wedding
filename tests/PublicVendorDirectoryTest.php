<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Public vendor directory (vendors.php) against a real php -S server:
 *
 *  1. Open door — an anonymous visitor gets a 200 with approved vendors, KSh
 *     package prices and the sign-in call to action, where before the whole
 *     marketplace required a session.
 *  2. The gate holds — an unapproved listing and an inactive package never
 *     render, and the vendor's phone/email are not printed on a public page
 *     (an enquiry is a ledger row tied to a wedding, not a phone call).
 *  3. Filters — category and county narrow the grid, an impossible pair shows
 *     the designed empty state, and a value that is not a real category or an
 *     existing county is ignored rather than echoed.
 *  4. Profile (vendor.php) — a card opens the full listing: the whole
 *     description and every active package; an unapproved, unknown or
 *     malformed id answers 404 without naming the hidden business.
 *  5. Sharing (vendor.php) — absolute Open Graph / Twitter metadata and a
 *     LocalBusiness JSON-LD block that parses, lists only active packages in
 *     KES, and never carries the contact details the page withholds. The 404
 *     carries no structured data and asks not to be indexed.
 *  6. Sharing (vendors.php) — a self-canonical that keeps the active filters,
 *     a title/description that name them, absolute preview image, and noindex
 *     only when a filter combination is genuinely empty.
 *
 * Integration ('integration' group) — needs the live DB and a bootable php -S.
 * Read-only: this page has no form and writes nothing, so nothing is cleaned up
 * beyond the server process.
 */
final class PublicVendorDirectoryTest extends TestCase
{
    private const APPROVED_VENDOR = 'Nyama Choma Masters';
    private const OTHER_APPROVED  = 'Safari Transport';
    private const UNAPPROVED      = 'Flash Photography KE';
    private const INACTIVE_PKG    = 'Inactive — Old Menu';
    private const VENDOR_PHONE    = '0722000002';
    private const VENDOR_EMAIL    = 'info@nyamachoma.co.ke';

    private static ?Database $db = null;
    private static ?string $base = null;
    private static $serverProc = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            if (!class_exists('Vendor', false)) {
                require_once __DIR__ . '/../admin/include/Monetization.php';
            }
            global $db;
            $db = self::$db = new Database();
            // The seed is the fixture: without these rows the assertions below
            // describe nothing.
            $approved = (int) $db->fetchValue('SELECT COUNT(*) FROM tblvendors WHERE is_approved = 1');
            if ($approved < 2) {
                self::$db = null;
            }
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    #[Group('integration')]
    public function testAnonymousVisitorSeesApprovedVendorsOnly(): void
    {
        $this->requireDb();

        $page = self::http('GET', '/vendors.php');
        self::assertSame(200, $page['status'], 'the directory must serve anonymous visitors');
        $body = $page['body'];

        self::assertStringContainsString('Trusted Kenyan Wedding Vendors', $body);
        self::assertStringContainsString(self::APPROVED_VENDOR, $body, 'approved vendors must render');
        self::assertStringContainsString(self::OTHER_APPROVED, $body);
        self::assertStringContainsString('KSh 45,000', $body, 'package prices must render in KSh');
        self::assertStringContainsString('Choma Premium', $body);
        self::assertStringContainsString('up to 100 pax', $body, 'package capacity must be shown');

        self::assertStringNotContainsString(self::UNAPPROVED, $body, 'an unapproved listing must never render publicly');
        self::assertStringNotContainsString(self::INACTIVE_PKG, $body, 'a hidden package must not advertise a price');

        // The enquiry lives behind the ledger: no phone, no email, no public
        // write endpoint on this page.
        self::assertStringNotContainsString(self::VENDOR_PHONE, $body);
        self::assertStringNotContainsString(self::VENDOR_EMAIL, $body);
        self::assertStringNotContainsString('client_vendor_enquiry', $body);

        // The call to action points a visitor at the one login door.
        self::assertStringContainsString('href="login.php"', $body);
        self::assertStringContainsString('Sign in to enquire', $body);

        // And the page is reachable from the public nav, or nobody finds it.
        $home = self::http('GET', '/index.php');
        self::assertSame(200, $home['status']);
        self::assertStringContainsString('href="vendors.php"', $home['body'], 'the public nav must link the directory');
    }

    #[Group('integration')]
    public function testCategoryAndCountyFiltersNarrowTheDirectory(): void
    {
        $this->requireDb();

        $catering = self::http('GET', '/vendors.php?category=catering');
        self::assertSame(200, $catering['status']);
        self::assertStringContainsString(self::APPROVED_VENDOR, $catering['body']);
        self::assertStringNotContainsString(self::OTHER_APPROVED, $catering['body'], 'other categories must be filtered out');

        $nairobi = self::http('GET', '/vendors.php?county=' . rawurlencode('Nairobi County'));
        self::assertSame(200, $nairobi['status']);
        self::assertStringContainsString(self::OTHER_APPROVED, $nairobi['body']);
        self::assertStringNotContainsString(self::APPROVED_VENDOR, $nairobi['body'], 'other counties must be filtered out');

        // The county dropdown must not offer a county nobody trades in.
        self::assertStringNotContainsString('value="Kilifi County"', $nairobi['body']);

        // An impossible pair renders the designed empty state.
        $empty = self::http('GET', '/vendors.php?category=mc&county=' . rawurlencode('Nakuru County'));
        self::assertSame(200, $empty['status']);
        self::assertStringContainsString('Nothing here yet', $empty['body']);
        self::assertStringNotContainsString(self::APPROVED_VENDOR, $empty['body']);

        // A combination that does exist keeps both sides of the link honest.
        $both = self::http('GET', '/vendors.php?category=catering&county=' . rawurlencode('Nakuru County'));
        self::assertSame(200, $both['status']);
        self::assertStringContainsString(self::APPROVED_VENDOR, $both['body']);
    }

    #[Group('integration')]
    public function testUnknownFilterValuesAreIgnoredNotEchoed(): void
    {
        $this->requireDb();

        $payload = '<script>alert(1)</script>';

        $badCategory = self::http('GET', '/vendors.php?category=' . rawurlencode($payload));
        self::assertSame(200, $badCategory['status']);
        self::assertStringNotContainsString('alert(1)', $badCategory['body'], 'an unknown category must never be echoed');
        self::assertStringContainsString(self::APPROVED_VENDOR, $badCategory['body'], 'an unknown category filters nothing');

        $badCounty = self::http('GET', '/vendors.php?county=' . rawurlencode($payload));
        self::assertSame(200, $badCounty['status']);
        self::assertStringNotContainsString('alert(1)', $badCounty['body'], 'an unknown county must never be echoed');
        self::assertStringContainsString(self::APPROVED_VENDOR, $badCounty['body'], 'an unknown county filters nothing');

        $badBoth = self::http('GET', '/vendors.php?category=%22%3E%3Cimg%20src%3Dx%20onerror%3Dalert(1)%3E&county=%22%3E%3Cimg%20src%3Dx%20onerror%3Dalert(1)%3E');
        self::assertSame(200, $badBoth['status']);
        self::assertStringNotContainsString('onerror=alert', $badBoth['body']);
    }

    #[Group('integration')]
    public function testDirectoryLinksToTheFullProfile(): void
    {
        $this->requireDb();

        $page = self::http('GET', '/vendors.php');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('href="vendor.php?id=200"', $page['body'], 'the card must open the profile');
        self::assertStringContainsString('Full profile', $page['body']);
    }

    #[Group('integration')]
    public function testProfileShowsFullDescriptionAndEveryActivePackage(): void
    {
        $this->requireDb();

        $page = self::http('GET', '/vendor.php?id=200');
        self::assertSame(200, $page['status']);
        $body = $page['body'];

        self::assertStringContainsString(self::APPROVED_VENDOR, $body);
        self::assertStringContainsString('Nakuru County', $body);
        self::assertStringContainsString('About ' . self::APPROVED_VENDOR, $body);

        // Every ACTIVE package is on the page, with its price.
        self::assertStringContainsString('Choma Basic', $body);
        self::assertStringContainsString('KSh 45,000', $body);
        self::assertStringContainsString('Choma Premium', $body);
        self::assertStringContainsString('KSh 120,000', $body);
        self::assertStringContainsString('Up to 100 guests', $body);
        self::assertStringNotContainsString(self::INACTIVE_PKG, $body, 'a hidden package must not appear on the profile');

        // The complete stored description, not the card's 130-character cut.
        // Asserted line by line because nl2br() inserts <br> between them.
        $description = trim((string) self::$db->fetchValue(
            'SELECT description FROM tblvendors WHERE id = 200'
        ));
        foreach (preg_split('/\R/', $description) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                self::assertStringContainsString(
                    htmlspecialchars($line),
                    $body,
                    'the profile must print the whole description'
                );
            }
        }

        // Contact details stay off the public page; the enquiry goes through
        // the account, not a phone call.
        self::assertStringNotContainsString(self::VENDOR_PHONE, $body);
        self::assertStringNotContainsString(self::VENDOR_EMAIL, $body);
        self::assertStringContainsString('Sign in to enquire', $body);
    }

    #[Group('integration')]
    public function testProfileIsApprovedOnlyAndAnswers404Otherwise(): void
    {
        $this->requireDb();

        // An unapproved listing is indistinguishable from one that does not exist.
        $unapproved = self::http('GET', '/vendor.php?id=202');
        self::assertSame(404, $unapproved['status']);
        self::assertStringNotContainsString(self::UNAPPROVED, $unapproved['body'], 'an unapproved listing must not even be named');
        self::assertStringContainsString('Vendor not found', $unapproved['body']);

        // A 404 shares a generic card and must not be indexed or marked up.
        self::assertStringContainsString('property="og:title" content="Vendor not found"', $unapproved['body']);
        self::assertStringContainsString('name="robots" content="noindex', $unapproved['body']);
        self::assertStringNotContainsString('application/ld+json', $unapproved['body']);

        foreach (['/vendor.php?id=999999', '/vendor.php', '/vendor.php?id=abc', '/vendor.php?id=-4'] as $path) {
            $missing = self::http('GET', $path);
            self::assertSame(404, $missing['status'], $path . ' must be a 404');
        }
    }

    #[Group('integration')]
    public function testProfileCarriesShareMetadataAndStructuredData(): void
    {
        $this->requireDb();

        $page = self::http('GET', '/vendor.php?id=200');
        self::assertSame(200, $page['status']);
        $body = $page['body'];

        // Absolute URLs: a shared link has no page context to resolve against.
        $selfUrl = self::$base . '/vendor.php?id=200';
        self::assertStringContainsString('rel="canonical" href="' . $selfUrl . '"', $body);
        self::assertStringContainsString('property="og:url" content="' . $selfUrl . '"', $body);
        self::assertStringContainsString('property="og:type" content="business.business"', $body);
        self::assertStringContainsString('property="og:title" content="Nyama Choma Masters', $body);
        self::assertStringContainsString('property="og:image" content="' . self::$base . '/images/logo/everafter-crest.png"', $body);
        self::assertStringContainsString('name="twitter:card" content="summary"', $body);
        self::assertStringContainsString('property="og:locale" content="en_KE"', $body);

        // The structured data block is valid JSON, and says what the page says.
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m));
        $ld = json_decode($m[1], true);
        self::assertIsArray($ld, 'the JSON-LD block must parse');
        self::assertSame('https://schema.org', $ld['@context']);
        self::assertSame('LocalBusiness', $ld['@type']);
        self::assertSame('Nyama Choma Masters — Nakuru', $ld['name']);
        self::assertSame($selfUrl, $ld['url']);
        self::assertSame('Nakuru', $ld['address']['addressLocality']);
        self::assertSame('Nakuru County', $ld['address']['addressRegion']);
        self::assertSame('KE', $ld['address']['addressCountry']);

        // Only the packages the page actually offers, priced in KES.
        $offerNames = array_column($ld['makesOffer'], 'name');
        self::assertCount(2, $offerNames, 'only active packages are marked up');
        self::assertContains('Choma Basic — 100 pax', $offerNames);
        self::assertContains('Choma Premium — 250 pax', $offerNames);
        foreach ($ld['makesOffer'] as $offer) {
            self::assertSame('KES', $offer['priceCurrency']);
            self::assertGreaterThan(0, $offer['price']);
        }

        // A rating is only claimed when ratings really exist — never invented
        // from total_bookings.
        $rated = (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblvendor_bookings WHERE vendor_id = 200 AND rating IS NOT NULL'
        );
        if ($rated === 0) {
            self::assertArrayNotHasKey('aggregateRating', $ld);
        } else {
            self::assertSame($rated, $ld['aggregateRating']['ratingCount']);
            self::assertSame(5, $ld['aggregateRating']['bestRating']);
        }

        // The markup must not smuggle out what the page withholds.
        self::assertArrayNotHasKey('telephone', $ld);
        self::assertArrayNotHasKey('email', $ld);
        self::assertStringNotContainsString(self::VENDOR_PHONE, $body);
        self::assertStringNotContainsString(self::VENDOR_EMAIL, $body);
    }

    #[Group('integration')]
    public function testDirectoryCarriesShareMetadataAndCanonical(): void
    {
        $this->requireDb();

        $page = self::http('GET', '/vendors.php');
        self::assertSame(200, $page['status']);
        $body = $page['body'];
        $base = self::$base;

        self::assertStringContainsString('rel="canonical" href="' . $base . '/vendors.php"', $body);
        self::assertStringContainsString('property="og:url" content="' . $base . '/vendors.php"', $body);
        self::assertStringContainsString('property="og:type" content="website"', $body);
        self::assertStringContainsString('property="og:title" content="Wedding Vendors in Kenya"', $body);
        self::assertStringContainsString('property="og:image" content="' . $base . '/images/logo/everafter-crest.png"', $body);
        self::assertStringContainsString('property="og:locale" content="en_KE"', $body);
        self::assertStringContainsString('name="twitter:card" content="summary"', $body);
        self::assertStringNotContainsString('name="robots"', $body, 'the open directory stays indexable');

        // A category keeps itself canonical and names itself in the preview.
        $catering = self::http('GET', '/vendors.php?category=catering');
        self::assertStringContainsString(
            'rel="canonical" href="' . $base . '/vendors.php?category=catering"',
            $catering['body']
        );
        self::assertStringContainsString('<title>Catering Vendors in Kenya | Everafter Planner</title>', $catering['body']);
        self::assertStringContainsString('property="og:title" content="Catering Vendors in Kenya"', $catering['body']);

        // A county is encoded once, then escaped for the attribute.
        $countyUrl = $base . '/vendors.php?' . http_build_query(['county' => 'Nakuru County']);
        $county = self::http('GET', '/vendors.php?county=' . rawurlencode('Nakuru County'));
        self::assertStringContainsString('rel="canonical" href="' . htmlspecialchars($countyUrl) . '"', $county['body']);
        self::assertStringContainsString('<title>Wedding Vendors in Nakuru County | Everafter Planner</title>', $county['body']);

        // Both filters survive in the canonical, in a stable order.
        $bothUrl = $base . '/vendors.php?' . http_build_query(['category' => 'catering', 'county' => 'Nakuru County']);
        $both = self::http('GET', '/vendors.php?category=catering&county=' . rawurlencode('Nakuru County'));
        self::assertStringContainsString('rel="canonical" href="' . htmlspecialchars($bothUrl) . '"', $both['body']);
        self::assertStringContainsString('property="og:title" content="Catering Vendors in Nakuru County"', $both['body']);

        // A category label carrying "&" is decoded for the metadata and then
        // escaped exactly once for the attribute. The seed has no music vendor,
        // so a probe row is added for this assertion and removed again.
        $probeId = 0;
        try {
            self::$db->execute(
                "INSERT INTO tblvendors (user_id, business_name, category, description, phone, email, location, county, is_approved)
                 VALUES (8, :name, 'music', 'Probe row for share metadata', '0700000000', 'probe@example.com', 'Nairobi', 'Nairobi County', 1)",
                [':name' => 'Share Metadata Probe Music']
            );
            $probeId = (int) self::$db->the_insert_id();

            $music = self::http('GET', '/vendors.php?category=music');
            self::assertStringContainsString('property="og:title" content="Music &amp; DJ Vendors in Kenya"', $music['body']);
            self::assertStringNotContainsString('&amp;amp;', $music['body']);
        } finally {
            if ($probeId > 0) {
                self::$db->execute('DELETE FROM tblvendors WHERE id = :id', [':id' => $probeId]);
            }
        }

        // A dead filter combination is a preview, not a search result.
        $empty = self::http('GET', '/vendors.php?category=mc&county=' . rawurlencode('Nakuru County'));
        self::assertStringContainsString('name="robots" content="noindex', $empty['body']);
        self::assertStringContainsString('property="og:title" content="Wedding vendors — nothing matches this filter"', $empty['body']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function requireDb(): void
    {
        if (self::$db === null || self::$base === null) {
            $this->markTestSkipped('live DB + php -S unavailable');
        }
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_vd_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_vd_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 31000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
            self::$serverProc = @proc_open($cmd, [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $logOut, 'w'],
                2 => ['file', $logErr, 'w'],
            ], $pipes, $root);
            if (!is_resource(self::$serverProc)) {
                continue;
            }
            $ready = false;
            for ($i = 0; $i < 40; $i++) {
                usleep(100_000);
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($probe) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
            }
            if ($ready) {
                self::$base = 'http://127.0.0.1:' . $port;
                return;
            }
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        self::$base = null;
    }

    private static function http(string $method, string $path): array
    {
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
        $ch = curl_init(self::$base . $path);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers): int {
                $headers[] = trim($line);
                return strlen($line);
            },
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }

        $status = 0;
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
