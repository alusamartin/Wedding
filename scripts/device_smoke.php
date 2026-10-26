<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * WPMS Kenya — device smoke test (the pre-release gate's fifth step).
 *
 * The contract the Android app has with this system is otherwise only ever
 * exercised by a person: install the APK, sign in, tap through the screens.
 * This script does that unattended:
 *
 *   1. finds the release APK and an attached device (emulator or phone);
 *   2. installs it, wipes its data so it starts signed out, and launches it;
 *   3. points it at this machine's seeded install if it is not already there;
 *   4. signs in as the seeded couple through the real form;
 *   5. opens every core screen and FAILS when one is stuck loading, shows the
 *      offline banner, an error banner, or an empty state where the seed has
 *      data — plus any crash the app wrote to logcat during the run.
 *
 * Steps 1-4 of the gate (scripts/release_preflight.php) prove the server half
 * of the contract over HTTP. None of them can see the client half: a screen
 * that throws only in a release build, an offline banner in front of a
 * reachable server, or a field the API renamed under it.
 *
 * Usage:
 *   php scripts/device_smoke.php                       # attached emulator, :8137
 *   php scripts/device_smoke.php --serial=emulator-5554 --port=8137
 *   php scripts/device_smoke.php --require-device      # no device = FAIL, not SKIP
 *   php scripts/device_smoke.php --apk=<path>          # check a different build
 *
 * The seeded server is started on --port when nothing is listening there yet
 * and stopped again on the way out, so the script is safe to run on its own.
 *
 * Exit codes:
 *   0 = every check passed
 *   1 = a check failed (a screen is broken)
 *   2 = skipped: nothing to test (no adb, no device, no APK) — never a pass
 *   3 = the environment failed while testing (install failed, app never started)
 */

const PKG      = 'ke.everafter.couple';
const ACTIVITY = 'ke.everafter.couple/.MainActivity';

const DEFAULT_PORT = 8137;
const DEFAULT_DB   = 'wpms_preflight';
const DEFAULT_APK  = 'android/app/build/outputs/apk/release/app-release.apk';

const CLIENT_EMAIL = 'brian.wafula@gmail.com';
const CLIENT_PASS  = 'Test@1234';

/**
 * The app's own sentences that mean "this screen is broken".
 *
 * Taken from the client source, because a screen can only fail in the ways it
 * can speak. A shorter list would let a silently offline Home pass; a longer
 * one would turn a copy change into a release failure.
 */
const ERROR_MARKERS = [
    'Cannot reach your server',
    'You are offline',
    'Not downloaded yet',
    'Something went wrong',
    'Your session expired',
    'does not look like a web address',
    'Invalid security token',
    // ApiClient's transport and envelope failures (ApiClient.kt): the two that
    // matter on a gate run are a server that is not answering and a body the
    // app could not read, which look identical from a screenshot and are not.
    'No connection',
    'The server sent something unexpected',
    'The server returned no data',
    'The request failed (HTTP',
];

/** Text that only appears while a screen is still waiting for data. */
const LOADING_MARKERS = [
    'Loading your checklist',
    'Loading your budget',
    'Loading your conversations',
    'Loading your guest list',
];

/**
 * Every core screen: its "the seeded data arrived" anchor, and the empty-state
 * sentences that would mean the seed never made it to the phone.
 *
 * @var list<array{label:string, anchor:string, empty:list<string>}>
 */
const SCREENS = [
    [
        'label'  => 'Checklist',
        // The header's own words: the sentence that explains ticking sits at the
        // very bottom of a 26-milestone list and is never composed to be read.
        'anchor' => 'milestones done',
        'empty'  => ['No checklist yet'],
    ],
    [
        'label'  => 'Money',
        'anchor' => 'Payments recorded by your planner',
        'empty'  => ['No payment record yet'],
    ],
    [
        'label'  => 'Messages',
        'anchor' => 'Talk to the vendors you have deals with.',
        'empty'  => ['No conversations yet', 'No vendor deals yet'],
    ],
    [
        'label'  => 'More',
        'anchor' => 'Version',
        'empty'  => [],
    ],
];

/** The guest list, reached from More (it has no tab of its own). */
const GUESTS_ANCHOR = 'Answers arrive as guests use their own RSVP link.';
const GUESTS_EMPTY  = ['No guest list yet', 'Nobody here'];

final class DeviceSmoke
{
    private int $passed = 0;
    private int $failed = 0;
    /** @var list<string> */
    private array $notes = [];
    /** @var list<array{label:string, ok:bool, detail:string}> */
    private array $checks = [];

    private string $adb = '';
    private string $serial = '';
    /** @var resource|null */
    private $server = null;
    private bool $stopServer = false;
    private ?string $skipReason = null;

    /** @param array<string, mixed> $opts */
    public function __construct(private array $opts)
    {
    }

    // ---------------------------------------------------------------- entry

    public function run(): int
    {
        try {
            $code = $this->attempt();
        } finally {
            // Whatever happened, do not leave a stray server holding the port.
            $this->stopServer();
        }
        return $this->summary($code);
    }

    /** The run itself; returns the exit code the class then reports. */
    private function attempt(): int
    {
        $root  = dirname(__DIR__);
        $apk   = (string) ($this->opt('apk') ?? ($root . '/' . DEFAULT_APK));
        $port  = (int) ($this->opt('port') ?? DEFAULT_PORT);
        $db    = (string) ($this->opt('db') ?? DEFAULT_DB);
        $email = (string) ($this->opt('email') ?? CLIENT_EMAIL);
        $pass  = (string) ($this->opt('password') ?? CLIENT_PASS);

        echo "\nEverafter — device smoke test\n";

        $this->adb = $this->resolveAdb() ?? '';
        if ($this->adb === '') {
            return $this->skip('adb was not found (set ANDROID_HOME or pass --adb=PATH)');
        }

        if (!$this->pickDevice()) {
            return $this->skip('no device attached — start an emulator or plug in a phone');
        }
        $this->check('device attached', true, $this->serial);

        // The emulator reaches this machine's loopback as 10.0.2.2; a USB phone
        // needs an adb reverse tunnel and then talks to its own localhost.
        $emulator = str_starts_with($this->serial, 'emulator-');
        $deviceHost = $emulator ? '10.0.2.2' : '127.0.0.1';
        if (!$emulator) {
            $reverse = $this->adbCall(['reverse', 'tcp:' . $port, 'tcp:' . $port]);
            if ($reverse['code'] !== 0) {
                return $this->skip('could not tunnel port ' . $port . ' to ' . $this->serial
                    . ' (adb reverse failed: ' . $this->firstLine($reverse['out']) . ')');
            }
        }
        $expected = rtrim((string) ($this->opt('api-base') ?? ('http://' . $deviceHost . ':' . $port)), '/');
        echo '  device   ' . $this->serial . '  (' . ($emulator ? 'emulator' : 'usb') . ")\n";
        echo '  server   ' . $expected . "\n";

        if (!is_file($apk)) {
            return $this->skip('no APK at ' . $this->rel($apk, $root) . ' — build it first (assembleRelease)');
        }
        $this->check('APK present', true, $this->rel($apk, $root) . ' (' . round(filesize($apk) / 1048576, 2) . ' MB)');

        if (($problem = $this->ensureServer($root, $port, $db)) !== null) {
            return $this->envFail($problem);
        }

        if (!$this->install($apk)) {
            return $this->envFail('the APK would not install — see the adb output above');
        }

        // Launch state must be a phone that has never signed in: a stored
        // bearer from an earlier run would skip the form this step exists to
        // walk.
        $this->adbCall(['shell', 'pm', 'clear', PKG]);
        if ($emulator) {
            $this->adbCall(['logcat', '-c']);
        }
        // A locked screen still shows the activity but eats the taps, and the
        // failure looks like a broken form.
        $this->adbCall(['shell', 'wm', 'dismiss-keyguard'], 30);
        $this->adbCall(['shell', 'am', 'start', '-W', '-n', ACTIVITY], 90);

        if ($this->waitFor('Sign in', 90) === null) {
            return $this->envFail('the app never reached the sign-in screen; screen read: '
                . $this->screenDigest());
        }
        $this->check('app launched to sign-in', true);

        if (!$this->pointAtServer($expected)) {
            return 1;
        }
        if (!$this->signIn($email, $pass)) {
            return 1;
        }

        $this->checkScreen('Home', 'Karibu', ['Planning progress']);
        foreach (SCREENS as $screen) {
            $this->openTab($screen['label']);
            $this->checkScreen($screen['label'], $screen['anchor'], [], $screen['empty']);
        }
        $this->openGuests();

        $this->checkCrashes();

        return $this->failed === 0 ? 0 : 1;
    }

    // ----------------------------------------------------------- adb helpers

    private function resolveAdb(): ?string
    {
        $candidates = [];
        if (($given = $this->opt('adb')) !== null) {
            $candidates[] = $given;
        }
        foreach (['ANDROID_HOME', 'ANDROID_SDK_ROOT'] as $var) {
            $home = getenv($var);
            if (is_string($home) && $home !== '') {
                $candidates[] = rtrim($home, '/\\') . '/' . $this->bin('platform-tools/adb');
            }
        }
        foreach (preg_split('/[' . preg_quote(PATH_SEPARATOR, '/') . ']/', (string) getenv('PATH')) ?: [] as $dir) {
            if ($dir !== '') {
                $candidates[] = rtrim($dir, '/\\') . '/' . $this->bin('adb');
            }
        }
        $local = getenv('LOCALAPPDATA');
        if (is_string($local) && $local !== '') {
            $candidates[] = rtrim($local, '/\\') . '/Android/Sdk/' . $this->bin('platform-tools/adb');
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $this->win($candidate);
            }
        }
        return null;
    }

    private function bin(string $name): string
    {
        return stripos(PHP_OS, 'WIN') === 0 ? str_replace('/', '\\', $name) . '.exe' : $name;
    }

    private function win(string $path): string
    {
        return stripos(PHP_OS, 'WIN') === 0 ? str_replace('/', '\\', $path) : $path;
    }

    /** @param list<string> $args @return array{code:int, out:string} */
    private function adbCall(array $args, int $timeout = 120): array
    {
        $argv = [$this->adb];
        if ($this->serial !== '') {
            $argv[] = '-s';
            $argv[] = $this->serial;
        }
        return $this->exec(array_merge($argv, $args), $timeout);
    }

    /** @param list<string> $argv @return array{code:int, out:string} */
    private function exec(array $argv, int $timeout): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'wpms-dev-') ?: (sys_get_temp_dir() . '/wpms-dev.log');
        $sink = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
        $descriptors = [0 => ['file', $sink, 'r'], 1 => ['file', $capture, 'w'], 2 => ['file', $capture, 'w']];
        $proc = @proc_open($argv, $descriptors, $pipes, dirname(__DIR__));
        if (!is_resource($proc)) {
            @unlink($capture);
            return ['code' => 127, 'out' => 'could not run: ' . implode(' ', $argv)];
        }
        $deadline = microtime(true) + $timeout;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $code = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                @proc_terminate($proc, 9);
                usleep(200000);
                @proc_close($proc);
                $out = (string) @file_get_contents($capture);
                @unlink($capture);
                return ['code' => 124, 'out' => $out . "\n[device] timed out after {$timeout}s"];
            }
            usleep(120000);
        }
        @proc_close($proc);
        $out = (string) @file_get_contents($capture);
        @unlink($capture);
        return ['code' => $code, 'out' => $out];
    }

    private function pickDevice(): bool
    {
        $list = $this->exec([$this->adb, 'devices'], 30)['out'];
        $devices = [];
        foreach (preg_split('/\R/', $list) ?: [] as $line) {
            if (preg_match('/^(\S+)\s+(device|offline|unauthorized)\b/', trim($line), $m)) {
                $devices[$m[1]] = $m[2];
            }
        }
        $serial = $this->opt('serial');
        if ($serial !== null) {
            if (($devices[$serial] ?? '') !== 'device') {
                $this->notes[] = 'requested serial ' . $serial . ' is ' . ($devices[$serial] ?? 'not attached');
                return false;
            }
            $this->serial = $serial;
            return true;
        }
        $ready = array_keys(array_filter($devices, fn ($state) => $state === 'device'));
        if ($ready === []) {
            foreach ($devices as $id => $state) {
                $this->notes[] = $id . ' is ' . $state;
            }
            return false;
        }
        $this->serial = (string) $ready[0];
        if (count($ready) > 1) {
            $this->notes[] = 'more than one device attached (' . implode(', ', $ready)
                . ') — using ' . $this->serial . ', pass --serial= to choose';
        }
        return true;
    }

    // -------------------------------------------------------- seeded server

    /** Returns a reason when the server could not be reached, null on success. */
    private function ensureServer(string $root, int $port, string $db): ?string
    {
        if ($this->httpProbe('http://127.0.0.1:' . $port . '/api/v1/?r=ping') !== false) {
            $this->notes[] = 'reused the server already listening on ' . $port;
            return null;
        }
        $log = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'wpms_device_server.log';
        @unlink($log);
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['WPMS_DB_NAME'] = $db;
        $proc = @proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $root,
            $env
        );
        if (!is_resource($proc)) {
            return 'could not start the seeded server on port ' . $port;
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $this->server = $proc;
        $this->stopServer = true;

        $until = microtime(true) + 60;
        while (microtime(true) < $until) {
            if ($this->httpProbe('http://127.0.0.1:' . $port . '/api/v1/?r=ping') !== false) {
                $this->notes[] = 'started a seeded server on ' . $port . ' (database ' . $db . ')';
                return null;
            }
            usleep(400000);
        }
        return 'the seeded server never answered on port ' . $port . ' — see ' . $log;
    }

    private function stopServer(): void
    {
        if (!$this->stopServer || !is_resource($this->server)) {
            return;
        }
        @proc_terminate($this->server, 9);
        @proc_close($this->server);
        $this->server = null;
    }

    private function httpProbe(string $url): string|false
    {
        $context = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return false;
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
        return $status === 200 ? $body : false;
    }

    // ------------------------------------------------------------- install

    private function install(string $apk): bool
    {
        $result = $this->adbCall(['install', '-r', '-d', $apk], 240);
        $ok = $result['code'] === 0 && stripos($result['out'], 'Success') !== false;
        $this->check('installed on the device', $ok, $ok ? basename($apk) : $this->firstLine($result['out']));
        if (!$ok) {
            echo $this->indent($result['out'], 6);
        }
        return $ok;
    }

    // -------------------------------------------------------------- the app

    /**
     * Make the app talk to this machine.
     *
     * An APK built with -PapiBase=http://10.0.2.2:<port> is already pointed at
     * it, and then this only reads the row and moves on. Anything else — a
     * build stamped for production, or one built with no base at all — is
     * pointed by hand through the app's own dialog, which also proves that
     * dialog works in a release build.
     */
    private function pointAtServer(string $expected): bool
    {
        $shown = $this->findText('Server · ');
        if ($shown === null) {
            $this->check('app points at the seeded install', false,
                'no server row on the sign-in screen; screen read: ' . $this->screenDigest());
            return false;
        }
        if ($this->sameBase($this->text($shown), $expected)) {
            $this->check('app points at the seeded install', true,
                $this->clip($this->text($shown)) . ' (built in)');
            return true;
        }

        $this->tap($shown);
        if ($this->waitFor('The website this app talks to', 20) === null) {
            $this->check('app points at the seeded install', false, 'the server dialog never opened');
            return false;
        }
        $field = $this->firstEditText();
        if ($field === null) {
            $this->check('app points at the seeded install', false, 'the server dialog has no text field');
            return false;
        }

        $this->tap($field);
        if (!$this->sameBase($this->text($field), $expected)) {
            if (!$this->clearField()) {
                $this->check('app points at the seeded install', false,
                    'the address field would not clear; it holds "' . $this->text($field) . '"');
                return false;
            }
            $this->type($expected);
        }
        $this->hideKeyboard();

        // The field has to hold the address we mean before Save; a browser-side
        // keyboard that ate a character would otherwise save a broken server
        // and the sign-in failure five lines later would blame the wrong thing.
        $readBack = $this->firstEditText();
        $typed = $readBack !== null ? $this->text($readBack) : '';
        if (!$this->sameBase($typed, $expected)) {
            $this->check('app points at the seeded install', false,
                'the address field holds "' . $typed . '" instead of "' . $expected . '"');
            return false;
        }

        $save = $this->findClickableText('Save', true);
        if ($save === null || !$this->tap($save)) {
            $this->check('app points at the seeded install', false, 'the dialog has no Save button');
            return false;
        }
        usleep(600000);

        $shown = $this->findText('Server · ');
        if ($shown === null || !$this->sameBase($this->text($shown), $expected)) {
            $this->check('app points at the seeded install', false,
                'after saving, the app still shows "' . ($shown !== null ? $this->text($shown) : '(nothing)') . '"');
            return false;
        }
        $this->check('app points at the seeded install', true,
            $this->clip($this->text($shown)) . ' (set in the app)');
        return true;
    }

    /**
     * Is the string this app's server address, in any of the shapes it uses?
     *
     * The store keeps a normalised base (`https://host/api/v1/`) while the
     * sign-in screen prints just the host and port, so comparing the raw
     * strings would report a mismatch where there is none.
     */
    private function sameBase(string $a, string $b): bool
    {
        $clean = static function (string $value): string {
            $value = trim($value);
            // The sign-in row prints "Server · host:port"; drop the label.
            if (($cut = strpos($value, '·')) !== false) {
                $value = substr($value, $cut + strlen('·'));
            }
            $value = preg_replace('#^https?://#i', '', $value) ?? $value;
            $value = preg_replace('#/api/v1/?$#i', '', $value) ?? $value;
            $value = preg_replace('#^server\s*#i', '', $value) ?? $value;
            return strtolower(rtrim(trim($value), '/'));
        };
        return $clean($a) === $clean($b);
    }

    private function signIn(string $email, string $pass): bool
    {
        $fields = $this->editTexts();
        if (count($fields) < 2) {
            $this->check('sign-in form has both fields', false, 'found ' . count($fields) . ' text fields');
            return false;
        }
        $this->tap($fields[0]);
        $this->type($email);
        $this->hideKeyboard();
        $this->tap($this->editTexts()[1] ?? $fields[1]);
        $this->type($pass);
        $this->hideKeyboard();

        $filled = $this->editTexts();
        $emailValue = isset($filled[0]) ? $this->text($filled[0]) : '';
        $passValue = isset($filled[1]) ? $this->text($filled[1]) : '';
        if ($emailValue !== $email || trim($passValue) === '') {
            $this->check('credentials typed into the form', false,
                'email field reads "' . $emailValue . '", password field is '
                . ($passValue === '' ? 'empty' : 'filled'));
            return false;
        }
        $this->check('credentials typed into the form', true);

        $button = $this->findClickableText('Sign in', true);
        if ($button === null || !$this->tap($button)) {
            $this->check('sign-in button tapped', false, 'no tappable Sign in button in the tree');
            return false;
        }

        if ($this->waitFor('Karibu', 60) === null) {
            // Lead with the app's own words for what went wrong: "Cannot reach
            // your server" and "Email or password is wrong" are different
            // bugs, and the operator should not have to guess which one this is.
            $said = $this->screenMarkers();
            $this->check('signed in as the seeded couple', false,
                'no home screen after signing in'
                . ($said === [] ? '' : ' — the app says: ' . implode(' | ', $said))
                . '; screen read: ' . $this->screenDigest());
            return false;
        }
        $this->check('signed in as the seeded couple', true, $email);
        return true;
    }

    /**
     * What a screen must and must not say, judged from one dump.
     *
     * @param list<string> $alsoRequired
     * @param list<string> $emptyIfPresent
     */
    private function checkScreen(string $label, string $anchor, array $alsoRequired = [], array $emptyIfPresent = []): void
    {
        $screen = null;
        $until = microtime(true) + 30;
        while (microtime(true) < $until) {
            $screen = $this->readScreen();
            if ($screen === null) {
                break;
            }
            if (str_contains($screen['text'], $anchor)) {
                break;
            }
            usleep(700000);
        }

        if ($screen === null) {
            $this->check($label . ' renders', false, 'the screen could not be read at all');
            return;
        }

        $required = array_merge([$anchor], $alsoRequired);
        $missing = [];
        foreach ($required as $needle) {
            if (!str_contains($screen['text'], $needle)) {
                $missing[] = $needle;
            }
        }
        $this->check($label . ' shows its data', $missing === [],
            $missing === [] ? '' : 'missing: ' . implode(' | ', $missing) . ' — screen read: ' . $screen['digest']);

        $broken = [];
        foreach (array_merge(ERROR_MARKERS, LOADING_MARKERS) as $marker) {
            if (str_contains($screen['text'], $marker)) {
                $broken[] = $marker;
            }
        }
        foreach ($emptyIfPresent as $marker) {
            if (str_contains($screen['text'], $marker)) {
                $broken[] = $marker;
            }
        }
        $this->check($label . ' has no error or empty state', $broken === [],
            $broken === [] ? '' : 'shows: ' . implode(' | ', $broken));
    }

    private function openTab(string $label): void
    {
        $target = null;
        $lowest = -1.0;
        $screen = $this->readScreen();
        if ($screen === null) {
            return;
        }
        foreach ($screen['nodes'] as $node) {
            if ($this->text($node) !== $label) {
                continue;
            }
            $bounds = $this->bounds($node);
            // The bottom bar sits in the last fifth of the screen; a heading
            // with the same word ("Money") is not the way to that screen.
            if ($bounds['y1'] > $screen['height'] * 0.8 && $bounds['y1'] > $lowest) {
                $lowest = (float) $bounds['y1'];
                $target = $node;
            }
        }
        if ($target === null) {
            $this->check($label . ' tab reachable', false, 'no "' . $label . '" item in the bottom bar');
            return;
        }
        $this->tap($target);
        usleep(900000);
    }

    private function openGuests(): void
    {
        $more = $this->findClickableText('Open guest list', true);
        if ($more === null) {
            $this->check('Guests screen renders', false, 'no "Open guest list" row on More');
            return;
        }
        $this->tap($more);
        $this->checkScreen('Guests', GUESTS_ANCHOR, [], GUESTS_EMPTY);
        $this->adbCall(['shell', 'input', 'keyevent', '4']); // back to More
        usleep(800000);
    }

    private function checkCrashes(): void
    {
        $log = $this->adbCall(['logcat', '-d', '-v', 'brief'], 90)['out'];
        $crashes = [];
        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if (stripos($line, PKG) === false && !str_contains($line, 'AndroidRuntime')) {
                continue;
            }
            if (preg_match('/FATAL EXCEPTION|AndroidRuntime: (?:FATAL|Process:)|ANR in /i', $line)) {
                $crashes[] = trim($line);
            }
        }
        $crashes = array_values(array_unique($crashes));
        $this->check('no crash in logcat', $crashes === [],
            $crashes === [] ? '' : implode("\n", array_slice($crashes, 0, 4)));
    }

    // ------------------------------------------------------------- ui driver

    /**
     * Read the current screen: every node's text, the window height, and a
     * short digest for failure messages.
     *
     * @return array{text:string, nodes:list<DOMElement>, height:float, digest:string}|null
     */
    private function readScreen(): ?array
    {
        $this->adbCall(['shell', 'rm', '-f', '/sdcard/window_dump.xml'], 30);
        $dump = $this->adbCall(['shell', 'uiautomator', 'dump', '/sdcard/window_dump.xml'], 60);
        $cat = $this->adbCall(['exec-out', 'cat', '/sdcard/window_dump.xml'], 60);
        $xml = $cat['out'];
        $start = strpos($xml, '<hierarchy');
        if ($start === false) {
            $this->notes[] = 'uiautomator produced no dump: ' . $this->firstLine($dump['out'] . $cat['out']);
            return null;
        }
        $xml = substr($xml, $start);
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) {
            // A truncated read happens when the dump is rewritten mid-cat.
            $doc = new DOMDocument();
            if (!@$doc->loadXML($xml . '</hierarchy>')) {
                $this->notes[] = 'the uiautomator dump could not be parsed';
                return null;
            }
        }
        $nodes = [];
        foreach ($doc->getElementsByTagName('node') as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }
        $height = 0.0;
        $root = $doc->documentElement;
        if ($root instanceof DOMElement) {
            $height = (float) $this->bounds($root)['y2'];
        }
        if ($height <= 0) {
            $height = 2000.0;
        }

        $texts = [];
        foreach ($nodes as $node) {
            $text = trim($this->text($node));
            if ($text !== '') {
                $texts[] = $text;
            }
        }
        $texts = array_values(array_unique($texts));

        return [
            'text'   => implode("\n", $texts),
            'nodes'  => $nodes,
            'height' => $height,
            'digest' => $this->clip(implode(' · ', array_slice($texts, 0, 14))),
        ];
    }

    /**
     * Which of the app's known failure sentences are on the screen right now.
     *
     * @return list<string>
     */
    private function screenMarkers(): array
    {
        $screen = $this->readScreen();
        if ($screen === null) {
            return [];
        }
        $found = [];
        foreach (ERROR_MARKERS as $marker) {
            if (str_contains($screen['text'], $marker)) {
                $found[] = $marker;
            }
        }
        return $found;
    }

    /** Everything on the screen right now, as text (for failure messages). */
    private function screenDigest(): string
    {
        $screen = $this->readScreen();
        return $screen === null ? '(unreadable)' : $screen['digest'];
    }

    private function findText(string $needle, bool $exact = false): ?DOMElement
    {
        return $this->findAllText($needle, $exact)[0] ?? null;
    }

    /**
     * Every node whose text matches, in the order the screen lists them.
     *
     * @return list<DOMElement>
     */
    private function findAllText(string $needle, bool $exact = false): array
    {
        $screen = $this->readScreen();
        if ($screen === null) {
            return [];
        }
        $found = [];
        foreach ($screen['nodes'] as $node) {
            $text = trim($this->text($node));
            if ($text === '') {
                continue;
            }
            if (($exact && $text === $needle)
                || (!$exact && ($text === $needle || str_contains($text, $needle)))
            ) {
                $found[] = $node;
            }
        }
        return $found;
    }

    /**
     * The node that is actually touchable among those carrying this text.
     *
     * "Sign in" is both the heading and the button on the sign-in screen, and
     * the heading comes first — tapping it does nothing at all, which reads
     * later as "sign-in is broken". Only a node with a clickable ancestor is
     * a control, so those are the candidates, bottom-most first.
     */
    private function findClickableText(string $needle, bool $exact = false): ?DOMElement
    {
        foreach (array_reverse($this->findAllText($needle, $exact)) as $node) {
            $walk = $node;
            while ($walk instanceof DOMElement) {
                if ($this->attr($walk, 'clickable') === 'true') {
                    return $node;
                }
                $walk = $walk->parentNode;
            }
        }
        return null;
    }

    private function waitFor(string $needle, int $seconds): ?DOMElement
    {
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) {
            $node = $this->findText($needle);
            if ($node !== null) {
                return $node;
            }
            usleep(700000);
        }
        return null;
    }

    /** @return list<DOMElement> */
    private function editTexts(): array
    {
        $screen = $this->readScreen();
        if ($screen === null) {
            return [];
        }
        $fields = [];
        foreach ($screen['nodes'] as $node) {
            if ($this->attr($node, 'class') === 'android.widget.EditText') {
                $fields[] = $node;
            }
        }
        return $fields;
    }

    private function firstEditText(): ?DOMElement
    {
        return $this->editTexts()[0] ?? null;
    }

    private function tap(?DOMElement $node): bool
    {
        if ($node === null) {
            return false;
        }
        // Compose marks the item, not the label inside it; tap what is
        // actually touchable or the tap lands on dead pixels.
        $target = $node;
        $walk = $node;
        while ($walk instanceof DOMElement) {
            if ($this->attr($walk, 'clickable') === 'true') {
                $target = $walk;
                break;
            }
            $walk = $walk->parentNode;
        }
        $bounds = $this->bounds($target);
        $x = (int) round(($bounds['x1'] + $bounds['x2']) / 2);
        $y = (int) round(($bounds['y1'] + $bounds['y2']) / 2);
        if ($x <= 0 && $y <= 0) {
            return false;
        }
        $this->adbCall(['shell', 'input', 'tap', (string) $x, (string) $y], 45);
        usleep(400000);
        return true;
    }

    private function type(string $text): void
    {
        $this->adbCall(['shell', 'input', 'text', $text], 60);
        usleep(400000);
    }

    /**
     * Empty the focused field, and say whether it worked.
     *
     * Caret to the end, then delete: one adb call carries all the key events,
     * so a long pre-filled address costs one round trip and not one per
     * character. Deleting past the start of a field is harmless, so the count
     * is generous, and the loop re-reads the field instead of assuming.
     */
    private function clearField(): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->adbCall(['shell', 'input', 'keyevent', '123'], 30); // MOVE_END
            $this->adbCall(array_merge(['shell', 'input', 'keyevent'], array_fill(0, 100, '67')), 90);
            usleep(400000);
            $field = $this->firstEditText();
            if ($field === null || trim($this->text($field)) === '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Put the soft keyboard away, so it cannot swallow the next tap.
     *
     * BACK is the one key the keyboard eats while it is up — with it down the
     * same key would dismiss the dialog, so the keyboard's state is read from
     * the input method service rather than assumed. ESCAPE is not used: on a
     * Compose dialog it can dismiss the whole sheet.
     */
    private function hideKeyboard(): void
    {
        for ($attempt = 0; $attempt < 3 && $this->keyboardUp(); $attempt++) {
            $this->adbCall(['shell', 'input', 'keyevent', '4'], 30);
            usleep(600000);
        }
    }

    private function keyboardUp(): bool
    {
        $state = $this->adbCall(['shell', 'dumpsys', 'input_method'], 60)['out'];
        return (bool) preg_match('/mInputShown=true/', $state);
    }

    private function text(DOMElement $node): string
    {
        return $this->attr($node, 'text');
    }

    private function attr(DOMElement $node, string $name): string
    {
        return $node->hasAttribute($name) ? $node->getAttribute($name) : '';
    }

    /** @return array{x1:float, y1:float, x2:float, y2:float} */
    private function bounds(DOMElement $node): array
    {
        $raw = $this->attr($node, 'bounds');
        if (preg_match_all('/-?\d+/', $raw, $m) === 4) {
            return ['x1' => (float) $m[0][0], 'y1' => (float) $m[0][1], 'x2' => (float) $m[0][2], 'y2' => (float) $m[0][3]];
        }
        return ['x1' => 0.0, 'y1' => 0.0, 'x2' => 0.0, 'y2' => 0.0];
    }

    // ----------------------------------------------------------------- plumbing

    private function opt(string $key): ?string
    {
        $value = $this->opts[$key] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function rel(string $path, string $root): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function firstLine(string $text): string
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                return $this->clip(trim($line));
            }
        }
        return '(no output)';
    }

    private function clip(string $text, int $max = 220): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return strlen($text) > $max ? substr($text, 0, $max) . '…' : $text;
    }

    private function indent(string $text, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);
        return $pad . implode("\n" . $pad, preg_split('/\R/', trim($text)) ?: []) . "\n";
    }

    private function check(string $label, bool $ok, string $detail = ''): void
    {
        $this->checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        if ($ok) {
            $this->passed++;
            echo '  [ok]   ' . $label . ($detail !== '' ? '  ' . $this->clip($detail) : '') . "\n";
        } else {
            $this->failed++;
            echo '  [FAIL] ' . $label . ($detail !== '' ? '  ' . $this->clip($detail) : '') . "\n";
        }
    }

    private function skip(string $reason): int
    {
        $this->notes[] = $reason;
        $this->skipReason = $reason;
        return 2;
    }

    private function envFail(string $reason): int
    {
        $this->checks[] = ['label' => 'environment', 'ok' => false, 'detail' => $reason];
        $this->failed++;
        echo '  [FAIL] ' . $reason . "\n";
        return 3;
    }

    private function summary(int $code): int
    {
        echo "\n{$this->passed} passed, {$this->failed} failed";
        if ($this->notes !== []) {
            echo ' (' . count($this->notes) . ' note' . (count($this->notes) === 1 ? '' : 's') . ')';
        }
        echo "\n";
        foreach ($this->notes as $note) {
            echo '  - ' . $this->clip($note) . "\n";
        }

        $json = $this->opt('json');
        if ($json !== null) {
            @file_put_contents($json, (string) json_encode([
                'passed'  => $this->passed,
                'failed'  => $this->failed,
                'serial'  => $this->serial,
                'checks'  => $this->checks,
                'notes'   => $this->notes,
            ], JSON_PRETTY_PRINT));
        }

        return $code;
    }

    /** A one-line reason for the caller when nothing was tested. */
    public function skipReason(): string
    {
        return $this->skipReason ?? 'nothing to test';
    }
}

$opts = getopt('', ['apk::', 'adb::', 'serial::', 'port::', 'db::', 'api-base::', 'email::', 'password::', 'json::', 'require-device', 'help']) ?: [];
if (array_key_exists('help', $opts)) {
    fwrite(STDOUT, "Device smoke test - installs the APK on a device, signs in against a seeded database,\n"
        . "opens every core screen and fails on any error state.\n\n"
        . "  --apk=PATH       APK to install (default " . DEFAULT_APK . ")\n"
        . "  --adb=PATH       adb binary (default: ANDROID_HOME, PATH, %LOCALAPPDATA%\\Android\\Sdk)\n"
        . "  --serial=ID      device to use (default: the only attached one)\n"
        . "  --port=N         port of the seeded server (default " . DEFAULT_PORT . ")\n"
        . "  --db=NAME        database it serves (default " . DEFAULT_DB . ")\n"
        . "  --api-base=URL   the address the app should use (default http://10.0.2.2:N)\n"
        . "  --email/--password  the seeded couple (default " . CLIENT_EMAIL . ")\n"
        . "  --json=PATH      write the check results as JSON\n"
        . "  --require-device fail (exit 2) when no device is attached instead of skipping\n");
    exit(0);
}

$smoke = new DeviceSmoke($opts);
$code = $smoke->run();

if ($code === 2) {
    $reason = $smoke->skipReason();
    if (array_key_exists('require-device', $opts)) {
        fwrite(STDOUT, "\nDEVICE CHECK REQUIRED, SO THIS IS A FAILURE: {$reason}\n");
        exit(1);
    }
    fwrite(STDOUT, "\nSKIPPED: {$reason}\n");
    exit(2);
}
if ($code !== 0) {
    exit($code);
}

echo "\nDevice smoke passed.\n";
exit(0);
