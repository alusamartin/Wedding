<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/include/Monetization.php';

/**
 * Operator plan definitions (subscription_plans.php).
 *
 * Integration ('integration' group) — live DB + php -S server, same pattern as
 * the other page tests. Probe plans use a 'sptest' key prefix and are deleted in
 * tearDownAfterClass; the seeded plans (starter / professional / agency) are
 * never modified.
 *
 * Why this exists: tblsubscription_plans was read twice (the purchase list and
 * the plan lookup) and written by nothing at all, so subscription.php could
 * honestly promise "Plans will appear here once the team defines them" while no
 * screen and no role could define one. This covers the capability and, just as
 * importantly, that RETIRING a plan is real: off the purchase list AND off the
 * purchase path, with the key still resolving for renewals.
 *
 * Covered:
 *  1. Operator opens the editor; a planner is refused it, with the reason.
 *  2. Define → stored live (price, wedding limit, features) → on sale.
 *  3. Edit → the price and limit change.
 *  4. A duplicate plan key is refused rather than overwriting a sold plan.
 *  5. Retire → off the planner's purchase list, and a crafted POST of the key
 *     can no longer buy it.
 *  6. Put back on sale → purchasable again.
 *  7. Every edit is recorded against the operator who made it, with the value
 *     it replaced (v2.8) — and a no-op save records nothing.
 *  8. A plan the operator defines can actually be BOUGHT, and its money lands
 *     on that plan's row on the revenue dashboard. This is the v2.8 regression:
 *     tblsubscriptions.plan was an ENUM of the three seeded keys, so a new key
 *     was listed for sale and the purchase was silently stored as ''.
 */
final class SubscriptionPlanAdminTest extends TestCase
{
    private const STAFF_EMAIL   = 'wafula@wpms.co.ke';
    private const STAFF_PASS    = 'Admin@2024!';
    private const PLANNER_EMAIL = 'amina@wpms.co.ke';
    private const PLANNER_PASS  = 'Test@1234';
    private const KEY_PREFIX    = 'sptest';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string,string> */
    private static array $jar = [];
    private static $serverProc = null;
    /** @var int[] probe plan ids created by this run */
    private static array $probePlanIds = [];
    /** @var int[] probe subscription ids bought by this run */
    private static array $probeSubIds = [];
    /** M-Pesa code used for the probe purchase (10 chars, unique per run) */
    private static string $probeCode = '';

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
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
        }

        self::bootServer();
        if (self::$base === null || self::$db === null) {
            return; // tests skip
        }
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            // By id where we have it, and by prefix so a failed run still cleans up.
            try {
                if (self::$probePlanIds !== []) {
                    self::$db->execute('DELETE FROM tblsubscription_plans WHERE id IN (' . implode(',', self::$probePlanIds) . ')');
                }
                self::$db->execute("DELETE FROM tblsubscription_plans WHERE plan_slug LIKE '" . self::KEY_PREFIX . "%'");
                /*
                 * The ledger has no FK (an audit trail must not be erasable by
                 * deleting its subject), so a deleted probe plan leaves its
                 * history behind unless it is taken too. The probe subscription
                 * is removed as well: a leftover purchase would otherwise show
                 * up as revenue on the dashboard for every later test.
                 */
                self::$db->execute("DELETE FROM tblsubscription_plan_changes WHERE plan_slug LIKE '" . self::KEY_PREFIX . "%'");
                self::$db->execute("DELETE FROM tblsubscriptions WHERE plan LIKE '" . self::KEY_PREFIX . "%'");
                foreach (self::$probeSubIds as $subId) {
                    self::$db->execute('DELETE FROM tblsubscriptions WHERE id = :id', [':id' => $subId]);
                }
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
        }
        self::$probePlanIds = [];
        self::$probeSubIds  = [];
        self::$jar = [];

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    // ------------------------------------------------------------------
    // Server + HTTP client (same stream-based pattern as the other suites)
    // ------------------------------------------------------------------

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_sp_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_sp_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 31000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            self::$serverProc = @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [
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
                $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
                if (@file_get_contents(self::$base . '/index.php', false, $ctx) === false) {
                    proc_terminate(self::$serverProc);
                    proc_close(self::$serverProc);
                    self::$serverProc = null;
                    self::$base = null;
                    continue;
                }
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
        $cookieHeader = '';
        if (self::$jar !== []) {
            $parts = [];
            foreach (self::$jar as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            $cookieHeader = 'Cookie: ' . implode('; ', $parts) . "\r\n";
        }
        $body = $post === [] ? '' : http_build_query($post);
        $header = 'Host: 127.0.0.1' . "\r\n"
            . $cookieHeader
            . ($post !== [] ? "Content-Type: application/x-www-form-urlencoded\r\n" : '')
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close";

        $ctx = stream_context_create(['http' => [
            'method'          => $method,
            'header'          => $header,
            'content'         => $body,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'timeout'         => 10,
        ]]);
        $response = @file_get_contents(self::$base . $path, false, $ctx);
        $headers  = $http_response_header ?? [];

        $status = 0;
        $location = '';
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                $pair = explode(';', substr($h, 11), 2)[0];
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    self::$jar[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }

    private static function login(string $email, string $pass): void
    {
        self::$jar = [];
        $res = self::http('GET', '/login.php');
        if ($res['status'] !== 200 || !preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            self::$base = null;
            return;
        }
        self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => $pass,
            '_token'         => html_entity_decode($m[1], ENT_QUOTES),
            'login'          => '1',
        ]);
    }

    private static function fetchCsrf(string $path): string
    {
        $res = self::http('GET', $path);
        if (preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail("could not extract CSRF token from $path");
    }

    private function requireHttp(): void
    {
        if (self::$base === null || self::$db === null) {
            self::markTestSkipped('php -S server / live DB unavailable — plan admin tests skipped');
        }
    }

    /** A fresh, unique probe plan key. */
    private static function probeKey(): string
    {
        return self::KEY_PREFIX . bin2hex(random_bytes(3));
    }

    /**
     * A well-formed probe M-Pesa code: exactly 10 characters, random, and only
     * ever used by this run — so the row can be found again and removed whatever
     * else the scratch database holds.
     */
    private static function probeCode(): string
    {
        if (self::$probeCode === '') {
            self::$probeCode = 'S' . strtoupper(bin2hex(random_bytes(4))) . 'X'; // 1 + 8 + 1
        }
        return self::$probeCode;
    }

    private static function userId(string $email): int
    {
        global $db;
        return (int) $db->fetchValue('SELECT id FROM tblusers WHERE email = :e', [':e' => $email]);
    }

    /** One plan definition by key (null when the write did not land). */
    private static function planIdFor(string $key): int
    {
        global $db;
        return (int) $db->fetchValue('SELECT id FROM tblsubscription_plans WHERE plan_slug = :k', [':k' => $key]);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Group('integration')]
    public function testPlannerIsRefusedTheEditorAndToldWhy(): void
    {
        $this->requireHttp();

        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
        $refused = self::http('GET', '/admin/subscription_plans.php');
        self::assertSame(302, $refused['status'], 'a planner must not open the plan editor');
        self::assertStringContainsString('subscription.php', $refused['location']);

        // The refusal is explained where the planner lands, not swallowed.
        $landing = self::http('GET', '/admin/subscription.php');
        self::assertStringContainsString('Only the operator can define subscription plans', $landing['body']);

        // And the gate sits before the POST handlers: a planner cannot write.
        $token = self::fetchCsrf('/admin/subscription.php');
        $key   = self::probeKey();
        self::http('POST', '/admin/subscription_plans.php', [
            '_token' => $token, 'save_plan' => '1', 'plan_id' => '0',
            'plan_slug' => $key, 'name' => 'sneaky', 'price' => '1',
            'wedding_limit' => '1', 'features' => 'x',
        ]);
        global $db;
        $written = (int) $db->fetchValue('SELECT COUNT(*) FROM tblsubscription_plans WHERE plan_slug = :k', [':k' => $key]);
        self::assertSame(0, $written, 'a planner must not be able to define a plan by posting the form');
    }

    #[Group('integration')]
    public function testOperatorDefinesEditsRetiresAndRevivesAPlan(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        // --- define -------------------------------------------------------
        $key   = self::probeKey();
        $token = self::fetchCsrf('/admin/subscription_plans.php');
        $res   = self::http('POST', '/admin/subscription_plans.php', [
            '_token' => $token, 'save_plan' => '1', 'plan_id' => '0',
            'plan_slug' => $key, 'name' => 'SP Test Plan',
            'price' => '4321', 'wedding_limit' => '7', 'features' => 'Alpha, Beta',
        ]);
        self::assertSame(302, $res['status']);

        $row = $db->fetchOne('SELECT * FROM tblsubscription_plans WHERE plan_slug = :k', [':k' => $key]);
        self::assertNotNull($row, 'the plan must be written');
        $planId = (int) $row->id;
        self::$probePlanIds[] = $planId;
        self::assertSame(1, (int) $row->is_active, 'a new plan is live immediately');
        self::assertEqualsWithDelta(4321.0, (float) $row->price, 0.01);
        self::assertSame(7, (int) $row->wedding_limit);
        self::assertSame('Alpha, Beta', (string) $row->features);

        // The promise on the planner's page is now kept: it is for sale.
        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
        $shop = self::http('GET', '/admin/subscription.php');
        self::assertStringContainsString('SP Test Plan', $shop['body'], 'a defined plan must appear on the purchase page');

        // --- edit ---------------------------------------------------------
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        $token = self::fetchCsrf('/admin/subscription_plans.php');
        self::http('POST', '/admin/subscription_plans.php', [
            '_token' => $token, 'save_plan' => '1', 'plan_id' => (string) $planId,
            'plan_slug' => $key, 'name' => 'SP Test Plan v2',
            'price' => '9999', 'wedding_limit' => '0', 'features' => 'Alpha, Beta, Gamma',
        ]);
        $row = $db->fetchOne('SELECT * FROM tblsubscription_plans WHERE id = :i', [':i' => $planId]);
        self::assertEqualsWithDelta(9999.0, (float) $row->price, 0.01, 'the price must be editable');
        self::assertSame(0, (int) $row->wedding_limit, 'the wedding limit must be editable (0 = unlimited)');
        self::assertSame('SP Test Plan v2', (string) $row->name);

        // --- retire, and prove retirement is real -------------------------
        $token = self::fetchCsrf('/admin/subscription_plans.php');
        self::http('POST', '/admin/subscription_plans.php', [
            '_token' => $token, 'set_plan_active' => '1',
            'plan_id' => (string) $planId, 'active' => '0',
        ]);
        $active = (int) $db->fetchValue('SELECT is_active FROM tblsubscription_plans WHERE id = :i', [':i' => $planId]);
        self::assertSame(0, $active, 'retiring must flip is_active');

        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
        $shop = self::http('GET', '/admin/subscription.php');
        self::assertStringNotContainsString('SP Test Plan', $shop['body'], 'a retired plan must leave the purchase list');

        // Off the purchase PATH too: post the key straight at the handler with
        // a well-formed code. The refusal this gate produces comes before any
        // M-Pesa verification, so a pass cannot be the code check.
        $before = (int) $db->fetchValue('SELECT COUNT(*) FROM tblsubscriptions WHERE plan = :k', [':k' => $key]);
        $token  = self::fetchCsrf('/admin/subscription.php');
        self::http('POST', '/admin/subscription.php', [
            '_token' => $token, 'purchase' => '1', 'plan' => $key, 'mpesa_code' => 'QKJ7GH9P2W',
        ]);
        $after = (int) $db->fetchValue('SELECT COUNT(*) FROM tblsubscriptions WHERE plan = :k', [':k' => $key]);
        self::assertSame($before, $after, 'a retired plan must not be purchasable');
        self::assertSame(0, $after);
        $page = self::http('GET', '/admin/subscription.php');
        self::assertStringContainsString('not available for purchase', $page['body'], 'the refusal must be visible');

        // --- revive -------------------------------------------------------
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        $token = self::fetchCsrf('/admin/subscription_plans.php');
        self::http('POST', '/admin/subscription_plans.php', [
            '_token' => $token, 'set_plan_active' => '1',
            'plan_id' => (string) $planId, 'active' => '1',
        ]);
        $active = (int) $db->fetchValue('SELECT is_active FROM tblsubscription_plans WHERE id = :i', [':i' => $planId]);
        self::assertSame(1, $active, 'a retired plan must be recoverable');

        self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
        $shop = self::http('GET', '/admin/subscription.php');
        self::assertStringContainsString('SP Test Plan', $shop['body'], 'a revived plan is on sale again');
    }

    #[Group('integration')]
    public function testReusedPlanKeyIsRefusedAndSeededPlansAreUntouched(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        // A key that has been sold must not be quietly redefined: reuse the
        // seeded 'starter' key and check the seeded row is unchanged.
        $seeded = $db->fetchOne("SELECT * FROM tblsubscription_plans WHERE plan_slug = 'starter'");
        self::assertNotNull($seeded, 'the seed must ship a starter plan');
        $seededPrice = (float) $seeded->price;
        $seededName  = (string) $seeded->name;

        $token = self::fetchCsrf('/admin/subscription_plans.php');
        $res   = self::http('POST', '/admin/subscription_plans.php', [
            '_token' => $token, 'save_plan' => '1', 'plan_id' => '0',
            'plan_slug' => 'starter', 'name' => 'Hijacked',
            'price' => '1', 'wedding_limit' => '1', 'features' => 'nothing',
        ]);
        self::assertSame(302, $res['status'], 'the form must be re-presented, not 500');
        $landing = self::http('GET', '/admin/subscription_plans.php');
        self::assertStringContainsString('already taken', $landing['body'], 'the refusal must be explained');

        $after = $db->fetchOne("SELECT * FROM tblsubscription_plans WHERE plan_slug = 'starter'");
        self::assertSame($seededPrice, (float) $after->price, 'an existing plan key must not be rewritten');
        self::assertSame($seededName, (string) $after->name);
        self::assertSame(1, (int) $db->fetchValue("SELECT COUNT(*) FROM tblsubscription_plans WHERE plan_slug = 'starter'"));
    }

    /**
     * Every edit to a plan is recorded against the operator who made it, with
     * the value it replaced.
     *
     * Until v2.8 a plan's price was simply overwritten: only the current number
     * survived, so "who moved this price, and when, and from what" had no answer
     * at all. The ledger is walked here the way an operator would create it —
     * define, save without changing anything, change the price, retire — with a
     * database assertion after each step.
     */
    #[Group('integration')]
    public function testEveryPlanEditIsRecordedWithTheOperatorWhoMadeIt(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        $operatorId = self::userId(self::STAFF_EMAIL);
        self::assertGreaterThan(0, $operatorId, 'the seeded operator must exist');

        // --- define: a DEFINITION (no previous value), not a change --------
        $key   = self::probeKey();
        $token = self::fetchCsrf('/admin/subscription_plans.php');
        self::http('POST', '/admin/subscription_plans.php', [
            '_token' => $token, 'save_plan' => '1', 'plan_id' => '0',
            'plan_slug' => $key, 'name' => 'Audit Probe', 'price' => '1500',
            'wedding_limit' => '3', 'features' => 'Alpha',
        ]);
        $planId = self::planIdFor($key);
        self::assertGreaterThan(0, $planId, 'the definition must be written');
        self::$probePlanIds[] = $planId;

        $defined = $db->fetchOne(
            "SELECT * FROM tblsubscription_plan_changes WHERE plan_id = :i AND field_name = 'price' ORDER BY id ASC LIMIT 1",
            [':i' => $planId]
        );
        self::assertNotNull($defined, 'defining a plan must record the price it was defined at');
        self::assertNull($defined->old_value, 'a definition replaces nothing');
        self::assertSame('1500.00', (string) $defined->new_value);
        self::assertSame($key, (string) $defined->plan_slug);
        self::assertSame($operatorId, (int) $defined->user_id, 'the operator who defined it must be on the entry');

        // --- a save that changes nothing writes nothing --------------------
        $before = (int) $db->fetchValue('SELECT COUNT(*) FROM tblsubscription_plan_changes WHERE plan_id = :i', [':i' => $planId]);
        $token  = self::fetchCsrf('/admin/subscription_plans.php');
        self::http('POST', '/admin/subscription_plans.php', [
            '_token' => $token, 'save_plan' => '1', 'plan_id' => (string) $planId,
            'plan_slug' => $key, 'name' => 'Audit Probe', 'price' => '1500',
            'wedding_limit' => '3', 'features' => 'Alpha',
        ]);
        self::assertSame(
            $before,
            (int) $db->fetchValue('SELECT COUNT(*) FROM tblsubscription_plan_changes WHERE plan_id = :i', [':i' => $planId]),
            'opening the page and saving must not look like a change'
        );

        // --- the price moves: one entry, carrying BOTH numbers -------------
        $token = self::fetchCsrf('/admin/subscription_plans.php');
        self::http('POST', '/admin/subscription_plans.php', [
            '_token' => $token, 'save_plan' => '1', 'plan_id' => (string) $planId,
            'plan_slug' => $key, 'name' => 'Audit Probe', 'price' => '2750',
            'wedding_limit' => '3', 'features' => 'Alpha',
        ]);
        $change = $db->fetchOne(
            "SELECT * FROM tblsubscription_plan_changes WHERE plan_id = :i AND field_name = 'price' ORDER BY id DESC LIMIT 1",
            [':i' => $planId]
        );
        self::assertSame('1500.00', (string) $change->old_value, 'the replaced price must be preserved, not overwritten');
        self::assertSame('2750.00', (string) $change->new_value);
        self::assertSame($operatorId, (int) $change->user_id);
        self::assertSame(2, (int) $db->fetchValue(
            "SELECT COUNT(*) FROM tblsubscription_plan_changes WHERE plan_id = :i AND field_name = 'price'",
            [':i' => $planId]
        ), 'one entry per price change — including the definition');

        // The reader the page uses returns it, newest first, with the author.
        $history = Subscription::price_history($planId);
        self::assertCount(2, $history);
        self::assertSame('2750.00', (string) $history[0]->new_value);
        self::assertSame('1500.00', (string) $history[1]->new_value, 'the definition comes after it — newest first');
        self::assertNotSame(
            '',
            trim((string) $history[0]->firstname . (string) $history[0]->lastname),
            'the reader must resolve the author, not just carry an id'
        );

        // --- and the editor says who moved it, and what it moved from ------
        $page = self::http('GET', '/admin/subscription_plans.php')['body'];
        self::assertStringContainsString('Price last changed by', $page, 'the editor must show the provenance');
        self::assertStringContainsString('KSh 1,500.00', $page, 'the replaced price must be shown');
        self::assertStringContainsString('KSh 2,750.00', $page, 'the current price must be shown');
        $actor = trim((string) $db->fetchValue("SELECT CONCAT(firstname, ' ', lastname) FROM tblusers WHERE id = :u", [':u' => $operatorId]));
        self::assertNotSame('', $actor);
        self::assertStringContainsString(htmlspecialchars($actor), $page, 'the operator must be named, not just an id');
        self::assertStringContainsString('Full change history', $page, 'the whole history must be reachable');

        // --- retiring is a change too, recorded with both states -----------
        $token = self::fetchCsrf('/admin/subscription_plans.php');
        self::http('POST', '/admin/subscription_plans.php', [
            '_token' => $token, 'set_plan_active' => '1',
            'plan_id' => (string) $planId, 'active' => '0',
        ]);
        $retire = $db->fetchOne(
            "SELECT * FROM tblsubscription_plan_changes WHERE plan_id = :i AND field_name = 'is_active' ORDER BY id DESC LIMIT 1",
            [':i' => $planId]
        );
        self::assertSame('1', (string) $retire->old_value, 'retiring records what the flag was');
        self::assertSame('0', (string) $retire->new_value);
        self::assertSame($operatorId, (int) $retire->user_id);
    }

    /**
     * A plan the operator defines can be BOUGHT, and the money lands on it.
     *
     * The regression this locks down: tblsubscriptions.plan was
     * ENUM('starter','professional','agency','trial') while the editor accepts
     * any 2–20 character key, and sql_mode here is not strict — so purchasing
     * an operator-defined plan stored plan = '' with no error at all. The
     * planner paid and held a subscription attached to no plan, and no
     * subscriber could ever appear on the plan the operator had just created.
     */
    #[Group('integration')]
    public function testAPlanTheOperatorDefinesCanBeBoughtAndEarnsOnTheDashboard(): void
    {
        $this->requireHttp();
        global $db;
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);

        // --- define it at a price no other row uses -----------------------
        $key   = self::probeKey();
        $token = self::fetchCsrf('/admin/subscription_plans.php');
        self::http('POST', '/admin/subscription_plans.php', [
            '_token' => $token, 'save_plan' => '1', 'plan_id' => '0',
            'plan_slug' => $key, 'name' => 'SP Buyable Plan', 'price' => '6123',
            'wedding_limit' => '5', 'features' => 'Buyable',
        ]);
        $planId = self::planIdFor($key);
        self::assertGreaterThan(0, $planId, 'the definition must be written');
        self::$probePlanIds[] = $planId;

        // --- a planner buys it --------------------------------------------
        $code = self::probeCode();
        if (MpesaC2B::verification_enabled()) {
            /*
             * With Daraja verification on, a purchase also requires a matching
             * callback row — that gate is covered by MpesaC2BTest. The row is
             * written through the same call the page makes so the storage
             * guarantee below is still exercised; the purchase PATH is what the
             * branch above it already asserts (a retired plan is refused).
             */
            Subscription::create_subscription(self::userId(self::PLANNER_EMAIL), $key, 6123.0, $code);
        } else {
            self::login(self::PLANNER_EMAIL, self::PLANNER_PASS);
            $token = self::fetchCsrf('/admin/subscription.php');
            self::http('POST', '/admin/subscription.php', [
                '_token' => $token, 'purchase' => '1', 'plan' => $key, 'mpesa_code' => $code,
            ]);
        }

        $sub = $db->fetchOne('SELECT * FROM tblsubscriptions WHERE mpesa_code = :c ORDER BY id DESC LIMIT 1', [':c' => $code]);
        self::assertNotNull($sub, 'the purchase must write a subscription row');
        self::$probeSubIds[] = (int) $sub->id;
        self::assertSame($key, (string) $sub->plan, 'the plan key must survive the INSERT — this is the ENUM regression');
        self::assertEqualsWithDelta(6123.0, (float) $sub->amount, 0.01, 'the price charged is the plan price');
        self::assertSame('active', (string) $sub->status);

        // --- the aggregation sees exactly that one subscriber --------------
        $row = null;
        foreach (Revenue::plan_breakdown()['plans'] as $planRow) {
            if ($planRow['plan_slug'] === $key) {
                $row = $planRow;
                break;
            }
        }
        self::assertNotNull($row, 'the plan must appear in the revenue breakdown');
        self::assertSame(1, $row['subscribers'], 'exactly one planner has paid on it');
        self::assertSame(1, $row['live_now']);
        self::assertEqualsWithDelta(6123.0, (float) $row['revenue'], 0.01);

        // --- and both pages show it ---------------------------------------
        self::login(self::STAFF_EMAIL, self::STAFF_PASS);
        $dashboard = self::http('GET', '/admin/revenue.php')['body'];
        self::assertStringContainsString('Revenue by plan', $dashboard, 'the dashboard must break revenue down by plan');
        self::assertStringContainsString('SP Buyable Plan', $dashboard, 'the new plan needs a row');
        self::assertStringContainsString('KSh 6,123.00', $dashboard, 'its revenue must be on the dashboard');

        $editor = self::http('GET', '/admin/subscription_plans.php')['body'];
        self::assertMatchesRegularExpression(
            '/' . preg_quote($key, '/') . '[\s\S]{0,500}?1 subscriber · 1 live now/',
            $editor,
            'the editor must count the live subscriber on this plan'
        );
    }
}
