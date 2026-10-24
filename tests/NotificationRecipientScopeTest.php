<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/include/config.php';

/**
 * The bell/outbox recipient boundary (v2.13).
 *
 * `tblnotifications.user_id` and `tbl_mail_logs.user_id` hold an id from ONE of
 * two account tables — `tblusers.id` (staff and vendor leads) or
 * `tblaccounts.user_id` (couples) — and `recipient_type` is the column that says
 * which. Before v2.13 every read was scoped by the bare number, so an account in
 * one table whose id collided with an account in the other could read its feed.
 *
 * THIS CLASS BUILDS THE COLLISION ON PURPOSE — a probe staff row (tblusers, a
 * super admin so no subscription gate stands in the way) and a probe couple
 * (tblaccounts) deliberately share one number that is free in both tables — and
 * then proves BOTH directions, at the model and over HTTP against a real php -S
 * server:
 *
 *   1. each account's feed holds its own row and only its own;
 *   2. neither can mark the other's row read;
 *   3. neither can pull the other's deep link out of the archive;
 *   4. the archive page (which also renders the sidebar's bell dropdown) shows
 *      each of them their own message and the other's not at all;
 *   5. the data invariant holds for every row in the database, and the column
 *      has no default — naming it on a write is mandatory;
 *   6. statically: no page under admin/ or the public root touches either table
 *      directly, and none calls the untyped create().
 *
 * Everything it writes is a probe it removes in tearDownAfterClass; seed rows are
 * never modified. Skips cleanly when the live DB or the migration is missing.
 */
final class NotificationRecipientScopeTest extends TestCase
{
    /**
     * The only files allowed to name the feed tables, each for a reason the scan
     * can state rather than a pattern it cannot:
     *   include/Notification.php — the door itself (and the one writer of 'none')
     *   deploy_check.php         — the deployment health check lists the tables it
     *                              requires; it never reads or writes a feed row
     */
    private const TABLE_NAME_ALLOWED = [
        'admin/include/Notification.php',
        'deploy_check.php',
    ];

    private const STAFF_MSG = '[recipient-scope] staff-side alert for the shared number';
    private const COUPLE_MSG = '[recipient-scope] couple-side alert for the shared number';
    private const STAFF_LINK = 'dashboard.php';
    private const COUPLE_LINK = 'client.php';
    private const PASSWORD = 'Recipient@Scope2026!';

    private static ?Database $db = null;
    private static ?string $base = null;
    /** @var array<string, string> */
    private static array $jar = [];
    private static $serverProc = null;

    /** The number both probe accounts share. Free in both tables. */
    private static int $collisionId = 0;
    private static string $staffEmail = '';
    private static string $coupleEmail = '';
    private static int $staffNotifId = 0;
    private static int $coupleNotifId = 0;

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
            if (!class_exists('Notification', false)) {
                require_once __DIR__ . '/../admin/include/Notification.php';
            }
            global $db;
            $db = self::$db = new Database();
        } catch (Throwable $e) {
            self::$db = null;
            return;
        }

        // The migration must have run, or there is nothing to prove here.
        try {
            foreach (['tblnotifications', 'tbl_mail_logs'] as $table) {
                self::$db->fetchValue("SELECT recipient_type FROM `{$table}` LIMIT 1");
            }
        } catch (Throwable $e) {
            self::$db = null;
            return;
        }

        if (!self::seedProbes()) {
            self::$db = null;
            return;
        }

        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                if (self::$staffNotifId > 0) {
                    self::$db->execute('DELETE FROM tbl_mail_logs WHERE notification_id = :n', [':n' => self::$staffNotifId]);
                    self::$db->execute('DELETE FROM tblnotifications WHERE id = :n', [':n' => self::$staffNotifId]);
                }
                if (self::$coupleNotifId > 0) {
                    self::$db->execute('DELETE FROM tbl_mail_logs WHERE notification_id = :n', [':n' => self::$coupleNotifId]);
                    self::$db->execute('DELETE FROM tblnotifications WHERE id = :n', [':n' => self::$coupleNotifId]);
                }
                if (self::$collisionId > 0) {
                    // Anything else the run left behind for this number.
                    self::$db->execute('DELETE FROM tbl_mail_logs WHERE user_id = :u', [':u' => self::$collisionId]);
                    self::$db->execute('DELETE FROM tblnotifications WHERE user_id = :u', [':u' => self::$collisionId]);
                }
                self::$db->execute("DELETE FROM tblnotifications WHERE message LIKE '[recipient-scope]%'");
                self::$db->execute("DELETE FROM tbl_mail_logs WHERE subject LIKE '%recipient-scope%'");
            } catch (Throwable $e) {
                // leave state untouched on failure
            }
            try {
                if (self::$staffEmail !== '') {
                    self::$db->execute('DELETE FROM tblusers WHERE email = :e', [':e' => self::$staffEmail]);
                }
                if (self::$coupleEmail !== '') {
                    self::$db->execute('DELETE FROM tblaccounts WHERE user_email = :e', [':e' => self::$coupleEmail]);
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        self::$serverProc = null;
    }

    // =====================================================================
    // 1. The two rows share a number and still do not share a feed
    // =====================================================================

    #[Group('integration')]
    public function testTwoAccountsCanShareANumberAndStillEachHaveTheirOwnFeed(): void
    {
        $this->requireDb();

        self::assertGreaterThan(0, self::$staffNotifId, 'the staff alert must have been written');
        self::assertGreaterThan(0, self::$coupleNotifId, 'the couple alert must have been written');

        // The two probe rows really do share the number — otherwise this class
        // would be proving nothing about the collision.
        self::assertSame(
            self::$collisionId,
            (int) self::$db->fetchValue('SELECT user_id FROM tblnotifications WHERE id = :i', [':i' => self::$staffNotifId]),
            'the staff probe row must carry the shared number'
        );
        self::assertSame(
            self::$collisionId,
            (int) self::$db->fetchValue('SELECT user_id FROM tblnotifications WHERE id = :i', [':i' => self::$coupleNotifId]),
            'the couple probe row must carry the same number'
        );
        self::assertSame(
            'user',
            (string) self::$db->fetchValue('SELECT recipient_type FROM tblnotifications WHERE id = :i', [':i' => self::$staffNotifId]),
            'the staff alert must be stamped as a tblusers id'
        );
        self::assertSame(
            'account',
            (string) self::$db->fetchValue('SELECT recipient_type FROM tblnotifications WHERE id = :i', [':i' => self::$coupleNotifId]),
            'the couple alert must be stamped as a tblaccounts id'
        );

        // Counts, dropdown and archive all answer per (type, id).
        self::assertSame(1, Notification::unread_count(Notification::FOR_USER, self::$collisionId), 'staff unread count');
        self::assertSame(1, Notification::unread_count(Notification::FOR_ACCOUNT, self::$collisionId), 'couple unread count');
        self::assertSame(1, Notification::count_for(Notification::FOR_USER, self::$collisionId), 'staff total');
        self::assertSame(1, Notification::count_for(Notification::FOR_ACCOUNT, self::$collisionId), 'couple total');

        $staffLatest = Notification::latest(Notification::FOR_USER, self::$collisionId, 8);
        $coupleLatest = Notification::latest(Notification::FOR_ACCOUNT, self::$collisionId, 8);
        self::assertSame([self::STAFF_MSG], array_map(static fn($n) => (string) $n->message, $staffLatest), 'the staff dropdown shows the staff alert alone');
        self::assertSame([self::COUPLE_MSG], array_map(static fn($n) => (string) $n->message, $coupleLatest), 'the couple dropdown shows the couple alert alone');

        $staffPage = Notification::page(Notification::FOR_USER, self::$collisionId, 0, 25);
        $couplePage = Notification::page(Notification::FOR_ACCOUNT, self::$collisionId, 0, 25);
        self::assertSame([self::STAFF_MSG], array_map(static fn($n) => (string) $n->message, $staffPage), 'the staff archive holds one row');
        self::assertSame([self::COUPLE_MSG], array_map(static fn($n) => (string) $n->message, $couplePage), 'the couple archive holds one row');
    }

    // =====================================================================
    // 2 & 3. Directions: neither account can touch the other's row
    // =====================================================================

    #[Group('integration')]
    public function testTheStaffAccountCannotReadOrMarkTheCouplesRow(): void
    {
        $this->requireDb();

        // Reading: the couple's row is nowhere in the staff feed.
        $ids = array_map(
            static fn($n) => (int) $n->id,
            Notification::page(Notification::FOR_USER, self::$collisionId, 0, 100)
        );
        self::assertNotContains(self::$coupleNotifId, $ids, 'the couple row must not appear in the staff archive');
        self::assertNotContains(self::$coupleNotifId, array_map(
            static fn($n) => (int) $n->id,
            Notification::latest(Notification::FOR_USER, self::$collisionId, 100)
        ), 'the couple row must not appear in the staff bell');

        // Deep link: only the staff row's own link comes back.
        self::assertSame('', Notification::link_for(Notification::FOR_USER, self::$coupleNotifId, self::$collisionId), 'the staff account must not get the couple link');
        self::assertSame(self::STAFF_LINK, Notification::link_for(Notification::FOR_USER, self::$staffNotifId, self::$collisionId), 'the staff account gets its own link');

        // Writing: marking the couple's row read is refused, and refused for real
        // (the row is still unread in the database).
        self::assertFalse(
            Notification::mark_read(Notification::FOR_USER, self::$coupleNotifId, self::$collisionId),
            'mark_read must refuse a row that belongs to the other account table'
        );
        self::assertSame('0', (string) self::$db->fetchValue(
            'SELECT is_read FROM tblnotifications WHERE id = :i',
            [':i' => self::$coupleNotifId]
        ), 'the couple row must still be unread after the staff attempt');

        // Mark-all flips the staff row and only the staff row.
        self::assertTrue(Notification::mark_all_read(Notification::FOR_USER, self::$collisionId));
        self::assertSame('1', (string) self::$db->fetchValue('SELECT is_read FROM tblnotifications WHERE id = :i', [':i' => self::$staffNotifId]), 'the staff row is read');
        self::assertSame('0', (string) self::$db->fetchValue('SELECT is_read FROM tblnotifications WHERE id = :i', [':i' => self::$coupleNotifId]), 'the couple row is untouched');
        self::assertSame(0, Notification::unread_count(Notification::FOR_USER, self::$collisionId), 'the staff feed is clear');
        self::assertSame(1, Notification::unread_count(Notification::FOR_ACCOUNT, self::$collisionId), 'the couple feed is not');

        // Put the staff row back for the HTTP test below.
        self::$db->execute('UPDATE tblnotifications SET is_read = 0 WHERE id = :i', [':i' => self::$staffNotifId]);
    }

    #[Group('integration')]
    public function testTheCoupleAccountCannotReadOrMarkTheStaffRow(): void
    {
        $this->requireDb();

        $ids = array_map(
            static fn($n) => (int) $n->id,
            Notification::page(Notification::FOR_ACCOUNT, self::$collisionId, 0, 100)
        );
        self::assertNotContains(self::$staffNotifId, $ids, 'the staff row must not appear in the couple archive');
        self::assertNotContains(self::$staffNotifId, array_map(
            static fn($n) => (int) $n->id,
            Notification::latest(Notification::FOR_ACCOUNT, self::$collisionId, 100)
        ), 'the staff row must not appear in the couple bell');

        self::assertSame('', Notification::link_for(Notification::FOR_ACCOUNT, self::$staffNotifId, self::$collisionId), 'the couple must not get the staff link');
        self::assertSame(self::COUPLE_LINK, Notification::link_for(Notification::FOR_ACCOUNT, self::$coupleNotifId, self::$collisionId), 'the couple gets its own link');

        self::assertFalse(
            Notification::mark_read(Notification::FOR_ACCOUNT, self::$staffNotifId, self::$collisionId),
            'mark_read must refuse the staff row for a couple session'
        );
        self::assertSame('0', (string) self::$db->fetchValue(
            'SELECT is_read FROM tblnotifications WHERE id = :i',
            [':i' => self::$staffNotifId]
        ), 'the staff row must still be unread after the couple attempt');
    }

    // =====================================================================
    // 4. The page the account actually opens (archive + sidebar dropdown)
    // =====================================================================

    #[Group('integration')]
    public function testTheArchivePageShowsEachAccountOnlyItsOwnAlert(): void
    {
        if (self::$base === null || self::$db === null) {
            $this->markTestSkipped('php -S server / live DB unavailable');
        }

        self::login(self::$staffEmail);
        $staffPage = self::http('GET', '/admin/notifications.php');
        self::assertSame(200, $staffPage['status'], 'the archive must render for the probe staff account');
        self::assertStringContainsString(self::STAFF_MSG, $staffPage['body'], 'the staff account must see its own alert');
        self::assertStringNotContainsString(self::COUPLE_MSG, $staffPage['body'], 'the couple alert must not reach the staff archive');

        self::login(self::$coupleEmail);
        $couplePage = self::http('GET', '/admin/notifications.php');
        self::assertSame(200, $couplePage['status'], 'the archive must render for the probe couple');
        self::assertStringContainsString(self::COUPLE_MSG, $couplePage['body'], 'the couple must see its own alert');
        self::assertStringNotContainsString(self::STAFF_MSG, $couplePage['body'], 'the staff alert must not reach the couple archive');

        // And the click-through: the couple following its own link leads home,
        // following the staff row's id leads home too — never to the staff page.
        $own = self::http('GET', '/admin/notifications.php?action=read&id=' . self::$coupleNotifId);
        self::assertSame(302, $own['status'], 'the read action redirects');
        self::assertStringContainsString('client.php', (string) ($own['headers']['location'] ?? ''), 'the couple link goes to the couple page');

        $foreign = self::http('GET', '/admin/notifications.php?action=read&id=' . self::$staffNotifId);
        self::assertSame(302, $foreign['status'], 'the read action redirects');
        self::assertStringNotContainsString('dashboard.php', (string) ($foreign['headers']['location'] ?? ''), 'a foreign id must not hand the couple the staff link');
    }

    // =====================================================================
    // 5. The invariant, over the whole database
    // =====================================================================

    #[Group('integration')]
    public function testTheRecipientInvariantHoldsForEveryRowAndTheColumnHasNoDefault(): void
    {
        $this->requireDb();

        foreach (['tblnotifications', 'tbl_mail_logs'] as $table) {
            self::assertSame(0, (int) self::$db->fetchValue(
                "SELECT COUNT(*) FROM `{$table}` WHERE (user_id = 0) <> (recipient_type = 'none')"
            ), "{$table}: 'none' must mean user_id 0 and user_id 0 must mean 'none'");

            self::assertSame(0, (int) self::$db->fetchValue(
                "SELECT COUNT(*) FROM `{$table}` WHERE recipient_type = ''"
            ), "{$table}: no row may sit on the removed default");

            $column = self::$db->fetchOne("SHOW COLUMNS FROM `{$table}` LIKE 'recipient_type'");
            self::assertNotNull($column, "{$table}.recipient_type must exist");
            self::assertSame('NO', strtoupper((string) $column->Null), "{$table}.recipient_type must be NOT NULL");
            self::assertNull($column->Default, "{$table}.recipient_type must have no default — a writer has to name it");
        }
    }

    // =====================================================================
    // 6. The boundary is kept statically
    // =====================================================================

    /**
     * Every page reads and writes the feed through Notification, which scopes by
     * (recipient_type, user_id). A direct `tblnotifications` reference in a page
     * is the untyped shape coming back; a bare `Notification::create(` is the
     * untyped write. Discovered by scanning, not by a hand list, so a page added
     * later is covered without editing this test.
     */
    public function testNoPageReachesTheFeedTablesOrTheUntypedCreate(): void
    {
        $files = array_merge(
            glob(__DIR__ . '/../admin/*.php') ?: [],
            glob(__DIR__ . '/../admin/include/*.php') ?: [],
            glob(__DIR__ . '/../*.php') ?: []
        );
        self::assertGreaterThanOrEqual(60, count($files), 'the page scan found far too few files to be meaningful');

        $direct = [];
        $untyped = [];
        $typed = 0;
        foreach ($files as $file) {
            $code = self::code($file);
            $name = str_replace(__DIR__ . '/../', '', $file);

            if ((str_contains($code, 'tblnotifications') || str_contains($code, 'tbl_mail_logs'))
                && !in_array($name, self::TABLE_NAME_ALLOWED, true)) {
                $direct[] = $name;
            }
            if (preg_match('/Notification::create\(/', $code) === 1) {
                $untyped[] = $name;
            }
            $typed += preg_match_all('/Notification::create_for_(staff|vendor|couple)\(/', $code);
        }

        self::assertSame([], $direct, 'the feed tables may only be named by include/Notification.php — scope every read by recipient_type');
        self::assertSame([], $untyped, 'write through create_for_staff()/create_for_vendor()/create_for_couple(), never the untyped create()');
        self::assertGreaterThanOrEqual(8, $typed, 'the scan must actually see the typed call sites, or it is guarding nothing');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * One number free in BOTH account tables, plus a probe account on each side
     * sharing it. The staff probe is a super admin: an operator is never behind
     * the subscription gate, so the archive is reachable without seeding a plan.
     */
    private static function seedProbes(): bool
    {
        global $db;
        try {
            $maxUser   = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM tblusers');
            $maxAccount = (int) $db->fetchValue('SELECT COALESCE(MAX(user_id), 0) FROM tblaccounts');
            $candidate = max($maxUser, $maxAccount) + 10;

            for ($try = 0; $try < 50; $try++, $candidate++) {
                $inUsers    = (int) $db->fetchValue('SELECT COUNT(*) FROM tblusers WHERE id = :i', [':i' => $candidate]);
                $inAccounts = (int) $db->fetchValue('SELECT COUNT(*) FROM tblaccounts WHERE user_id = :i', [':i' => $candidate]);
                if ($inUsers === 0 && $inAccounts === 0) {
                    break;
                }
            }
            if ($inUsers !== 0 || $inAccounts !== 0) {
                return false; // could not find a free number — skip cleanly
            }

            self::$collisionId = $candidate;
            $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
            self::$staffEmail  = 'recipient-scope-staff-' . $candidate . '@example.test';
            self::$coupleEmail = 'recipient-scope-couple-' . $candidate . '@example.test';

            $db->prepare(
                'INSERT INTO tblusers (id, firstname, lastname, gender, username, password, email, designation, address, access_level, profile_picture, date_created, email_verified) '
                . "VALUES (:id, 'Probe', 'Staff', 'f', :uname, :pw, :email, 'Planner', 'Nairobi', '2', '', NOW(), 1)"
            )->execute([
                ':id'    => self::$collisionId,
                ':uname' => 'recipient_scope_staff_' . $candidate,
                ':pw'    => $hash,
                ':email' => self::$staffEmail,
            ]);

            $db->prepare(
                'INSERT INTO tblaccounts (user_id, user_email, user_password, access_level) VALUES (:id, :email, :pw, "0")'
            )->execute([
                ':id'    => self::$collisionId,
                ':email' => self::$coupleEmail,
                ':pw'    => $hash,
            ]);

            // Written through the API under test — the writers stamp the type.
            self::$staffNotifId = Notification::create_for_staff(
                self::$collisionId,
                0,
                self::STAFF_MSG,
                self::STAFF_LINK,
                '[recipient-scope] staff probe'
            );
            self::$coupleNotifId = Notification::create_for_couple(
                self::$collisionId,
                0,
                self::COUPLE_MSG,
                self::COUPLE_LINK,
                '[recipient-scope] couple probe'
            );
        } catch (Throwable $e) {
            return false;
        }
        return self::$staffNotifId > 0 && self::$coupleNotifId > 0;
    }

    /** Source with comments stripped — a comment naming a table is not a query. */
    private static function code(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    private function requireDb(): void
    {
        if (self::$db === null || self::$collisionId <= 0) {
            $this->markTestSkipped('live DB / v2.13 recipient_type unavailable — recipient scope tests skipped');
        }
    }

    private static function login(string $email): void
    {
        self::$jar = [];
        $token = self::fetchCsrfToken('/login.php');
        $res = self::http('POST', '/login.php', [
            'input_email'    => $email,
            'input_password' => self::PASSWORD,
            '_token'         => $token,
            'login'          => '1',
        ]);
        if ($res['status'] !== 302) {
            self::fail('login failed for ' . $email . ' (status ' . $res['status'] . ')');
        }
    }

    private static function fetchCsrfToken(string $path): string
    {
        $res = self::http('GET', $path);
        if (preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        self::fail("could not extract CSRF token from $path");
    }

    /** @return array{status:int, body:string, headers:array<string,string>, location:?string} */
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
            $err = curl_error($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }

        $status = 0;
        $named  = [];
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
            if (preg_match('#^([A-Za-z-]+):\s*(.*)$#', $h, $m)) {
                $named[strtolower($m[1])] = $m[2];
            }
        }

        if (preg_match_all('/^Set-Cookie:\s*(PHPSESSID)=[^;]*;/im', implode("\n", $headers), $m)) {
            foreach ($m[0] as $cookie) {
                if (preg_match('/PHPSESSID=([^;]+)/', $cookie, $cm)) {
                    self::$jar['PHPSESSID'] = $cm[1];
                }
            }
        }

        return [
            'status'   => $status,
            'body'     => (string) $response,
            'headers'  => $named,
            'location' => $named['location'] ?? null,
        ];
    }

    private static function bootServer(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        $root   = realpath(__DIR__ . '/..');
        $logOut = tempnam(sys_get_temp_dir(), 'wpms_rs_out');
        $logErr = tempnam(sys_get_temp_dir(), 'wpms_rs_err');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = 31000 + random_int(0, 8999);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe) {
                fclose($probe);
                continue;
            }
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
            self::$serverProc = @proc_open($cmd, [
                0 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'],
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
}
