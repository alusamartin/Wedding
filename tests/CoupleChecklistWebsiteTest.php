<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Couple engagement features against a real php -S server:
 *
 *  1. Planning checklist (admin/client_checklist.php) — the Kenyan milestone
 *     template seeds on first open, couples toggle items (POST + CSRF,
 *     booking-scoped), the toggle endpoint rejects GET/tokenless/foreign
 *     requests, and past weddings are never flagged overdue.
 *  2. Wedding-website builder (admin/client_website.php) — the couple can
 *     create, edit and publish their shareable public page; the public
 *     site.php renders published pages only and no longer posts a dead RSVP
 *     form (guests RSVP via their personal token link).
 *
 * Integration ('integration' group) — requires the live DB and a bootable
 * php -S. The original tblwedding_websites row for booking 200 is captured
 * before the create-flow test and restored exactly in teardown; checklist
 * toggles made by the tests are reverted. Seed SMS rows, guests and
 * notifications are untouched.
 */
final class CoupleChecklistWebsiteTest extends TestCase
{
    private const COUPLE_EMAIL = 'brian.wafula@gmail.com';
    private const COUPLE_PASS  = 'Test@1234';
    private const BOOKING_ID   = 200;

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var int[] checklist items toggled by the tests (reset in teardown) */
    private static array $toggledItemIds = [];
    /** Original website row for booking 200 (restored in teardown). */
    private static ?object $originalWebsite = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/Database.php';
            }
            if (!class_exists('DB_Object', false)) {
                require_once __DIR__ . '/../admin/include/db_object.php';
            }
            if (!function_exists('format_date_kd')) {
                require_once __DIR__ . '/../admin/include/Helper.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();

        if (self::$db !== null) {
            $rows = self::$db->fetchAll('SELECT * FROM tblwedding_websites WHERE booking_id = :b', [':b' => self::BOOKING_ID]);
            self::$originalWebsite = $rows[0] ?? null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                // Revert checklist toggles made by the tests.
                if (self::$toggledItemIds !== []) {
                    self::$db->execute(
                        'UPDATE tblchecklist SET is_done = 0, done_at = NULL WHERE id IN (' .
                        implode(',', self::$toggledItemIds) . ')'
                    );
                }
                // Restore the exact original website row (the create-flow test
                // deletes and recreates it).
                self::restoreOriginalWebsite();
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$toggledItemIds = [];

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    // ------------------------------------------------------------------
    // Checklist
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testChecklistSeedsOnceAndRendersKenyanMilestones(): void
    {
        $this->requireDb();
        self::loginCouple();

        $page = self::http('GET', '/admin/client_checklist.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(200, $page['status'], 'the owning couple must see their checklist');

        self::assertStringContainsString('Planning progress', $page['body']);
        foreach (['Foundation', 'Bookings', 'Legal &amp; Church', 'Guests &amp; Details', 'Final Month'] as $phase) {
            self::assertStringContainsString($phase, $page['body'], "phase $phase must render");
        }
        self::assertStringContainsString('ruracio', strtolower($page['body']), 'Kenyan milestone wording must be present');
        self::assertStringContainsString('client_checklist_toggle.php', $page['body'], 'items must post to the scoped toggle endpoint');

        $count1 = self::countItems();
        self::assertSame(26, $count1, 'the Kenyan template must seed 26 milestones');

        // Second open must not duplicate anything (idempotent seeding).
        self::http('GET', '/admin/client_checklist.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame($count1, self::countItems(), 're-opening the checklist must not duplicate items');

        // Foreign couple bounce.
        $foreign = self::http('GET', '/admin/client_checklist.php?booking_id=201&user_id=201');
        self::assertSame(302, $foreign['status'], 'a client must be bounced off another booking\'s checklist');
    }

    #[Group('integration')]
    public function testChecklistToggleIsPostCsrfAndBookingScoped(): void
    {
        $this->requireDb();
        self::loginCouple();

        // GET is rejected (redirect back, no change).
        $get = self::http('GET', '/admin/client_checklist_toggle.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(302, $get['status'], 'toggle must reject GET requests');

        // Tokenless POST is rejected with no change.
        $itemId = self::firstOpenItemId();
        self::assertSame(0, self::itemDone($itemId), 'fixture: first item starts open');
        $tokenless = self::http('POST', '/admin/client_checklist_toggle.php?booking_id=' . self::BOOKING_ID . '&user_id=200', ['item_id' => $itemId]);
        self::assertSame(302, $tokenless['status'], 'tokenless POST must bounce');
        self::assertSame(0, self::itemDone($itemId), 'tokenless POST must not change state');

        // CSRF POST from the couple flips their own item.
        $token = self::fetchCsrfToken();
        $ok = self::http('POST', '/admin/client_checklist_toggle.php?booking_id=' . self::BOOKING_ID . '&user_id=200', ['item_id' => $itemId, '_token' => $token]);
        self::assertSame(302, $ok['status']);
        self::assertSame(1, self::itemDone($itemId), 'CSRF POST must mark the item done');
        self::$toggledItemIds[] = $itemId;

        // Toggling again clears it.
        self::http('POST', '/admin/client_checklist_toggle.php?booking_id=' . self::BOOKING_ID . '&user_id=200', ['item_id' => $itemId, '_token' => $token]);
        self::assertSame(0, self::itemDone($itemId), 'second toggle must clear the item');

        // A foreign booking id is refused even with a valid token.
        $foreign = self::http('POST', '/admin/client_checklist_toggle.php?booking_id=201&user_id=201', ['item_id' => $itemId, '_token' => $token]);
        self::assertSame(302, $foreign['status'], 'foreign booking must bounce');
        self::assertSame(0, self::itemDone($itemId), 'foreign POST must not touch the item');
    }

    #[Group('integration')]
    public function testPastWeddingsAreNeverFlaggedOverdue(): void
    {
        $this->requireDb();
        self::loginCouple();

        // Booking 200's wedding (2025-12-20) is in the past — an all-open
        // checklist must render WITHOUT any overdue badge.
        $page = self::http('GET', '/admin/client_checklist.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('check-item  is-overdue', $page['body'], 'past weddings must never show overdue nagging');
    }

    // ------------------------------------------------------------------
    // Wedding website builder
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testWebsiteBuilderGatesAndPublishToggle(): void
    {
        $this->requireDb();
        self::loginCouple();

        // Anonymous visitors bounce.
        self::$jar = [];
        $anon = self::http('GET', '/admin/client_website.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(302, $anon['status'], 'anonymous users must bounce off the builder');

        // Foreign booking bounces.
        self::loginCouple();
        $foreign = self::http('GET', '/admin/client_website.php?booking_id=201&user_id=201');
        self::assertSame(302, $foreign['status'], 'a client must be bounced off another booking\'s website builder');

        // The owning couple sees their page with the share box.
        $page = self::http('GET', '/admin/client_website.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('client_website.php', $page['body'], 'the form must post back to the builder');
        self::assertStringContainsString('Share on WhatsApp', $page['body'], 'WhatsApp share must be offered');

        // Publish toggle round-trip (original state restored at the end).
        $original = self::websitePublished();
        $token = self::fetchCsrfToken();
        self::http('POST', '/admin/client_website.php?booking_id=' . self::BOOKING_ID . '&user_id=200', ['toggle_publish' => '1', '_token' => $token]);
        self::assertSame($original === 1 ? 0 : 1, self::websitePublished(), 'toggle must flip is_published');

        self::http('POST', '/admin/client_website.php?booking_id=' . self::BOOKING_ID . '&user_id=200', ['toggle_publish' => '1', '_token' => $token]);
        self::assertSame($original, self::websitePublished(), 'second toggle must restore the original state');

        // Tokenless toggle POST is rejected.
        $before = self::websitePublished();
        self::http('POST', '/admin/client_website.php?booking_id=' . self::BOOKING_ID . '&user_id=200', ['toggle_publish' => '1']);
        self::assertSame($before, self::websitePublished(), 'tokenless toggle must not change state');
    }

    #[Group('integration')]
    public function testWebsiteBuilderCreateEditFlow(): void
    {
        $this->requireDb();
        self::loginCouple();

        // The create-flow test needs a booking without a website row. Capture
        // and delete the original (restored exactly in teardown).
        $original = self::$originalWebsite;
        self::assertNotNull($original, 'fixture: booking 200 must start with a website row');
        self::$db->execute('DELETE FROM tblwedding_websites WHERE booking_id = :b', [':b' => self::BOOKING_ID]);
        self::assertNull(self::fetchWebsiteRow(), 'setup: website row must be gone');
        $token = self::fetchCsrfToken();

        // Empty title is rejected, nothing created.
        self::http('POST', '/admin/client_website.php?booking_id=' . self::BOOKING_ID . '&user_id=200', ['title' => '', 'story' => 'x', '_token' => $token]);
        self::assertNull(self::fetchWebsiteRow(), 'empty title must not create a row');

        // Valid create: slug generated from the couple names, unpublished.
        $ok = self::http('POST', '/admin/client_website.php?booking_id=' . self::BOOKING_ID . '&user_id=200', [
            'title' => 'Brian & Linda Say I Do',
            'story' => 'Karibu! Test story.',
            'venue' => 'Karen Gardens',
            'venue_address' => 'Karen Road, Nairobi',
            '_token' => $token,
        ]);
        self::assertSame(200, $ok['status']);
        $row = self::fetchWebsiteRow();
        self::assertNotNull($row, 'valid POST must create the row');
        self::assertSame(0, (int) $row->is_published, 'new pages start hidden');
        self::assertMatchesRegularExpression('/^linda-wafula-brian-wafula(-\d+)?$/', (string) $row->slug, 'slug must derive from the couple names');

        // Public page 404s while hidden.
        $hidden = self::http('GET', '/site.php?slug=' . rawurlencode((string) $row->slug));
        self::assertSame(302, $hidden['status'], 'unpublished page must not render publicly');

        // Publish via toggle, public page now renders.
        self::http('POST', '/admin/client_website.php?booking_id=' . self::BOOKING_ID . '&user_id=200', ['toggle_publish' => '1', '_token' => $token]);
        $live = self::http('GET', '/site.php?slug=' . rawurlencode((string) $row->slug));
        self::assertSame(200, $live['status'], 'published page must render publicly');
        // clean() stores entities; the public page re-escapes, so the stored
        // 'Say I Do' text renders verbatim while & shows as &amp;amp; in the
        // HTML source — assert on the unambiguous tail instead.
        self::assertStringContainsString('Say I Do', $live['body']);

        // Tokenless edit POST is rejected — fields unchanged.
        self::http('POST', '/admin/client_website.php?booking_id=' . self::BOOKING_ID . '&user_id=200', ['title' => 'Hacked']);
        self::assertStringContainsString('Say I Do', (string) self::fetchWebsiteRow()->title, 'tokenless edit must not change fields');
    }

    #[Group('integration')]
    public function testPublicSiteRendersPublishedOnlyAndDropsDeadForm(): void
    {
        $this->requireDb();

        // Tests above may have swapped the seed row — restore it so this test
        // always sees the original published state.
        self::restoreOriginalWebsite();

        // Published seed page renders with the personal-invitation RSVP copy.
        $page = self::http('GET', '/site.php?slug=brian-linda');
        self::assertSame(200, $page['status'], 'published page must render');
        self::assertStringContainsString('personal invitation', $page['body'], 'RSVP section must explain the personal-link flow');
        self::assertStringNotContainsString('action="rsvp.php"', $page['body'], 'the dead website RSVP form must be gone');

        // Unpublished and unknown slugs bounce home.
        $unpublished = self::http('GET', '/site.php?slug=faith-james');
        self::assertSame(302, $unpublished['status'], 'unpublished pages must not render');
        $unknown = self::http('GET', '/site.php?slug=no-such-page');
        self::assertSame(302, $unknown['status'], 'unknown slugs must not render');
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

    private static function countItems(): int
    {
        return (int) self::$db->fetchValue(
            'SELECT COUNT(*) FROM tblchecklist WHERE booking_id = :b',
            [':b' => self::BOOKING_ID]
        );
    }

    private static function firstOpenItemId(): int
    {
        $rows = self::$db->fetchAll(
            'SELECT id FROM tblchecklist WHERE booking_id = :b AND is_done = 0 ORDER BY position ASC, id ASC LIMIT 1',
            [':b' => self::BOOKING_ID]
        );
        return (int) ($rows[0]->id ?? 0);
    }

    private static function itemDone(int $id): int
    {
        return (int) self::$db->fetchValue('SELECT is_done FROM tblchecklist WHERE id = :i', [':i' => $id]);
    }

    private static function fetchWebsiteRow(): ?object
    {
        $rows = self::$db->fetchAll('SELECT * FROM tblwedding_websites WHERE booking_id = :b', [':b' => self::BOOKING_ID]);
        return $rows[0] ?? null;
    }

    /** Delete any row on booking 200 and re-insert the captured original. */
    private static function restoreOriginalWebsite(): void
    {
        if (self::$db === null || self::$originalWebsite === null) {
            return;
        }
        self::$db->execute('DELETE FROM tblwedding_websites WHERE booking_id = :b', [':b' => self::BOOKING_ID]);
        $o = self::$originalWebsite;
        self::$db->execute(
            'INSERT INTO tblwedding_websites (id, booking_id, slug, title, story, venue, venue_address, wedding_date, cover_image, is_published, views, created_at)
             VALUES (:id, :booking_id, :slug, :title, :story, :venue, :venue_address, :wedding_date, :cover_image, :is_published, :views, :created_at)',
            [
                ':id'            => $o->id,
                ':booking_id'    => $o->booking_id,
                ':slug'          => $o->slug,
                ':title'         => $o->title,
                ':story'         => $o->story,
                ':venue'         => $o->venue,
                ':venue_address' => $o->venue_address,
                ':wedding_date'  => $o->wedding_date,
                ':cover_image'   => $o->cover_image,
                ':is_published'  => $o->is_published,
                ':views'         => $o->views,
                ':created_at'    => $o->created_at,
            ]
        );
    }

    private static function websitePublished(): int
    {
        $row = self::fetchWebsiteRow();
        return $row === null ? -1 : (int) $row->is_published;
    }

    private static function fetchCsrfToken(): string
    {
        $res = self::http('GET', '/admin/client_checklist.php?booking_id=' . self::BOOKING_ID . '&user_id=200');
        if (preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail('could not extract CSRF token from the checklist page');
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_cw_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_cw_err');

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

    private static function loginCouple(): void
    {
        self::login(self::COUPLE_EMAIL, self::COUPLE_PASS, '/login.php');
    }

    private static function login(string $email, string $pass, string $page): void
    {
        self::$jar = [];
        $res = self::http('GET', $page);
        if ($res['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', $page, [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private static function http(string $method, string $path, array $post = []): array
    {
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext/curl required by the test HTTP client');
        }
        $ch = curl_init(self::$base . $path);
        $headers = [];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers): int {
                $headers[] = trim($line);
                return strlen($line);
            },
        ];
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $opts[CURLOPT_HTTPHEADER] = ['Cookie: ' . implode('; ', $parts)];
        }
        if ($post !== []) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }
        curl_close($ch);

        $status = 0;
        $location = '';
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 10));
            }
        }

        // Track session cookies for logged-in requests.
        if (preg_match_all('/^Set-Cookie:\s*(PHPSESSID)=[^;]*;/im', implode("\n", $headers), $m)) {
            foreach ($m[0] as $cookie) {
                if (preg_match('/PHPSESSID=([^;]+)/', $cookie, $cm)) {
                    self::$jar['PHPSESSID'] = $cm[1];
                }
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }
}
