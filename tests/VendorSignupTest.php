<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vendor self-registration (vendor_signup.php + VendorSignup, v2.27).
 *
 * What is under test, and why it matters:
 *
 *  1. The page is PUBLIC — a business with no account can reach it, and it asks
 *     for the three things the rest of the product needs: an account, a
 *     business profile, and what the business sells.
 *  2. One POST creates all of it atomically: a tblusers row at access level 0
 *     (a vendor, not a planner), an UNAPPROVED tblvendors profile, the priced
 *     services and the optional package — and signs the vendor in.
 *  3. Approval is the only thing between a self-registered business and the
 *     marketplace: hidden from vendors.php until is_approved is set, visible
 *     immediately after.
 *  4. Refusals leave nothing behind — a duplicate email, a missing field, a
 *     service with no name, a package with no price, or a POST without a CSRF
 *     token must not create a half-vendor.
 *
 * Integration ('integration'): a live scratch database plus a booted php -S.
 * The probe rows are removed in tearDownAfterClass by their email/business
 * prefix, so the seeded marketplace is untouched.
 */
final class VendorSignupTest extends TestCase
{
    /** Every probe row this class writes carries one of these. */
    private const EMAIL_PREFIX = 'signup-probe-';
    private const BUSINESS_TAG = '[signup probe]';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;
    private static int $sequence = 0;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../admin/include/config.php';
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../admin/include/Database.php';
            }
            global $db;
            $db = self::$db = new Database();
            // The models the page and the signup path need, without a session.
            require_once __DIR__ . '/../admin/include/models.php';
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                $vendorIds = self::$db->fetchAll(
                    'SELECT id FROM tblvendors WHERE business_name LIKE :tag',
                    [':tag' => self::BUSINESS_TAG . '%']
                );
                foreach ($vendorIds as $row) {
                    $id = (int) $row->id;
                    self::$db->execute('DELETE FROM tblvendor_packages WHERE vendor_id = :v', [':v' => $id]);
                    self::$db->execute('DELETE FROM tblvendor_services WHERE vendor_id = :v', [':v' => $id]);
                    self::$db->execute('DELETE FROM tblvendors WHERE id = :v', [':v' => $id]);
                }
                self::$db->execute(
                    'DELETE FROM tblusers WHERE email LIKE :tag',
                    [':tag' => self::EMAIL_PREFIX . '%']
                );
            } catch (Throwable $e) {
                // leave state untouched on failure; the next clean run rebuilds
            }
        }

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null || self::$base === null) {
            self::markTestSkipped('live DB + php -S unavailable');
        }
        if (!VendorService::installed()) {
            self::fail('tblvendor_services is missing — run `composer test:db` (the build imports migrations/v2.27_vendor_services.sql)');
        }
        self::$jar = [];
    }

    // ------------------------------------------------------------------
    // 1. The page
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testThePageIsPublicAndAsksForAccountBusinessServicesAndPackage(): void
    {
        $page = self::http('GET', '/vendor_signup.php');
        self::assertSame(200, $page['status'], 'vendor signup must be reachable without signing in first');

        foreach (['List your wedding business', 'Your account', 'Your business', 'What you offer', 'A package'] as $section) {
            self::assertStringContainsString($section, $page['body'], "the form must ask for {$section}");
        }
        // The category vocabulary and the pricing units the rest of the product
        // compares on, not free text the model would later refuse.
        self::assertStringContainsString('Catering', $page['body']);
        self::assertStringContainsString('Per guest', $page['body']);
        self::assertMatchesRegularExpression('/name="_token" value="[^"]+"/', $page['body'], 'the form carries a CSRF token');
        self::assertStringContainsString('after a quick approval', $page['body'], 'the page must say the listing is reviewed');

        foreach (['Fatal error', 'Warning:', 'Attempt to read property'] as $marker) {
            self::assertStringNotContainsString($marker, $page['body']);
        }
    }

    // ------------------------------------------------------------------
    // 2. One POST creates the whole vendor
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testSignupCreatesTheAccountListingServicesAndPackageAndSignsTheVendorIn(): void
    {
        $email = self::probeEmail();
        $post  = array_merge(self::validPost($email), [
            'services' => [
                1 => [
                    'title'    => 'Classic buffet',
                    'unit'     => 'per_guest',
                    'price'    => '1200',
                    'min_qty'  => '50',
                    'includes' => 'staff, crockery',
                    'tags'     => 'Rustic, halal, rustic',
                ],
                2 => [
                    'title' => 'Full setup crew',
                    'unit'  => 'flat',
                    'price' => '25000',
                ],
                3 => ['title' => '', 'unit' => 'flat', 'price' => '', 'includes' => '', 'tags' => ''], // blank row ignored
            ],
            'package' => [
                'title'       => 'Gold package',
                'price'       => '320000',
                'capacity'    => '150',
                'description' => 'Buffet, staff and setup',
            ],
        ]);

        $response = self::post('/vendor_signup.php', $post);
        self::assertSame(302, $response['status']);
        self::assertStringContainsString('admin/vendor_home.php', $response['location'], 'a vendor lands on their own dashboard');

        $user = self::$db->fetchOne('SELECT * FROM tblusers WHERE email = :e', [':e' => $email]);
        self::assertNotNull($user, 'the account must exist');
        self::assertSame('0', (string) $user->access_level, 'a vendor is access level 0 — never a planner');
        self::assertSame(1, (int) $user->email_verified, 'the account is usable immediately; the LISTING is what waits');
        self::assertSame('Vendor', (string) $user->designation);

        $vendor = self::$db->fetchOne(
            'SELECT * FROM tblvendors WHERE user_id = :u',
            [':u' => (int) $user->id]
        );
        self::assertNotNull($vendor, 'the listing must be attached to the account');
        self::assertSame(0, (int) $vendor->is_approved, 'a self-registered listing waits for an operator');
        self::assertSame('catering', (string) $vendor->category);
        self::assertSame(self::BUSINESS_TAG . ' Nyama', (string) $vendor->business_name);
        self::assertSame('Nairobi', (string) $vendor->location);
        self::assertSame('Nairobi County', (string) $vendor->county);

        $services = self::$db->fetchAll(
            'SELECT * FROM tblvendor_services WHERE vendor_id = :v ORDER BY id ASC',
            [':v' => (int) $vendor->id]
        );
        self::assertCount(2, $services, 'two filled rows are stored, the blank one is not a service');
        self::assertSame('per_guest', (string) $services[0]->pricing_unit);
        self::assertEqualsWithDelta(1200.0, (float) $services[0]->price, 0.01);
        self::assertSame('rustic,halal', (string) $services[0]->tags, 'tags are normalised like every other service');
        self::assertSame(1, (int) $services[0]->is_active, 'signup services are live for the vendor from the start');
        self::assertSame('flat', (string) $services[1]->pricing_unit);

        $package = self::$db->fetchOne(
            'SELECT * FROM tblvendor_packages WHERE vendor_id = :v',
            [':v' => (int) $vendor->id]
        );
        self::assertNotNull($package);
        self::assertEqualsWithDelta(320000.0, (float) $package->price, 0.01);
        self::assertSame(150, (int) $package->capacity);

        // The vendor is signed in already and sees the honest "pending" state.
        $home = self::http('GET', '/admin/vendor_home.php');
        self::assertSame(200, $home['status'], 'signup must sign the vendor in (redirect: ' . ($home['location'] ?? '') . ')');
        self::assertStringContainsString('Approval pending', $home['body']);
    }

    // ------------------------------------------------------------------
    // 3. Approval is the only door into the marketplace
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testTheListingIsInvisibleToCouplesUntilAnOperatorApprovesIt(): void
    {
        $email  = self::probeEmail();
        $result = VendorSignup::create(self::modelInput($email));
        self::assertTrue($result['ok'], $result['error']);

        $before = self::http('GET', '/vendors.php');
        self::assertSame(200, $before['status']);
        self::assertStringNotContainsString(self::BUSINESS_TAG, $before['body'],
            'an unapproved listing must never appear in the marketplace');

        self::$db->execute('UPDATE tblvendors SET is_approved = 1 WHERE id = :v', [':v' => $result['vendor_id']]);

        $after = self::http('GET', '/vendors.php');
        self::assertStringContainsString(self::BUSINESS_TAG, $after['body'],
            'once approved, the listing is what couples came for');
    }

    // ------------------------------------------------------------------
    // 4. Refusals write nothing
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testRefusalsLeaveNoHalfVendor(): void
    {
        $email = self::probeEmail();
        $first = VendorSignup::create(self::modelInput($email));
        self::assertTrue($first['ok'], $first['error']);
        $usersBefore = self::userCount();

        // A taken email — sign in instead, and nothing new is written.
        $duplicate = VendorSignup::create(self::modelInput($email));
        self::assertFalse($duplicate['ok']);
        self::assertStringContainsString('already registered', $duplicate['error']);
        self::assertSame($usersBefore, self::userCount());

        // A service with a price and no name is a mistake, not an omission.
        $nameless = VendorSignup::create(array_merge(self::modelInput(self::probeEmail()), [
            'services' => [['title' => '', 'unit' => 'flat', 'price' => '5000']],
        ]));
        self::assertFalse($nameless['ok']);
        self::assertStringContainsString('Service 1', $nameless['error'], 'the vendor must be told WHICH row is wrong');

        // A package with no price cannot be chosen by a planner, so it is refused.
        $priceless = VendorSignup::create(array_merge(self::modelInput(self::probeEmail()), [
            'package' => ['title' => 'Mystery bundle', 'price' => ''],
        ]));
        self::assertFalse($priceless['ok']);
        self::assertStringContainsString('price above zero', $priceless['error']);

        // Required business fields, one at a time.
        $noBusiness = VendorSignup::create(array_merge(self::modelInput(self::probeEmail()), ['business_name' => '']));
        self::assertFalse($noBusiness['ok']);
        $noCategory = VendorSignup::create(array_merge(self::modelInput(self::probeEmail()), ['category' => 'spaceship']));
        self::assertFalse($noCategory['ok']);
        $shortPassword = VendorSignup::create(array_merge(self::modelInput(self::probeEmail()), ['password' => 'short']));
        self::assertFalse($shortPassword['ok']);

        self::assertSame($usersBefore, self::userCount(), 'no refused attempt may leave an account behind');
    }

    #[Group('integration')]
    public function testAPostWithoutACsrfTokenCreatesNothing(): void
    {
        $email = self::probeEmail();
        $usersBefore = self::userCount();

        $response = self::post('/vendor_signup.php', self::validPost($email), csrf: false);
        self::assertSame(302, $response['status']);
        self::assertStringContainsString('vendor_signup.php', $response['location']);

        self::assertSame($usersBefore, self::userCount());
        self::assertNull(self::$db->fetchOne('SELECT id FROM tblusers WHERE email = :e', [':e' => $email]));
    }

    #[Group('integration')]
    public function testASignedInVisitorIsSentToTheirOwnHomeInsteadOfTheForm(): void
    {
        $result = VendorSignup::create(self::modelInput(self::probeEmail()));
        self::assertTrue($result['ok'], $result['error']);
        self::loginVendorAt(self::$db->fetchOne('SELECT email FROM tblusers WHERE id = :i', [':i' => $result['user_id']])->email, self::PASSWORD);

        $page = self::http('GET', '/vendor_signup.php');
        self::assertSame(302, $page['status'], 'the form is never shown twice to the same session');
        self::assertStringContainsString('vendor_home.php', $page['location']);
    }

    // ------------------------------------------------------------------
    // Fixtures and HTTP helpers
    // ------------------------------------------------------------------

    private const PASSWORD = 'ProbePass#2026';

    private static function probeEmail(): string
    {
        self::$sequence++;
        return self::EMAIL_PREFIX . self::$sequence . '-' . bin2hex(random_bytes(3)) . '@example.test';
    }

    /** The raw form shape the page posts. */
    private static function validPost(string $email): array
    {
        return [
            'firstname'      => 'Wanjiku',
            'lastname'       => 'Kamau',
            'email'          => $email,
            'password'       => self::PASSWORD,
            'phone'          => '0712345678',
            'business_name'  => self::BUSINESS_TAG . ' Nyama',
            'category'       => 'catering',
            'description'    => 'Slow-roasted nyama choma for weddings.',
            'business_email' => '',
            'location'       => 'Nairobi',
            'county'         => 'Nairobi County',
        ];
    }

    /** The same values, shaped for VendorSignup::create(). */
    private static function modelInput(string $email): array
    {
        return self::validPost($email) + ['services' => [], 'package' => []];
    }

    private static function userCount(): int
    {
        return (int) self::$db->fetchValue('SELECT COUNT(*) FROM tblusers');
    }

    private static function csrfToken(): string
    {
        $res = self::http('GET', '/vendor_signup.php');
        if (preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail('could not extract a CSRF token from /vendor_signup.php');
    }

    private static function post(string $path, array $fields, bool $csrf = true): array
    {
        if ($csrf) {
            $fields['_token'] = self::csrfToken();
        }
        $fields['register_vendor'] = '1';
        return self::http('POST', $path, $fields);
    }

    private static function loginVendorAt(string $email, string $password): void
    {
        $res = self::http('GET', '/login.php');
        if ($res['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $password,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_vs_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_vs_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 32000 + random_int(0, 8999);
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

    private static function http(string $method, string $path, array $post = []): array
    {
        if (self::$base === null) {
            throw new RuntimeException('test server not booted — refusing to issue HTTP requests');
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
            throw new RuntimeException('HTTP request failed: ' . curl_error($ch));
        }

        $status = 0;
        $location = '';
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 10));
            }
        }
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
