<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Request — the null-safe accessors every controller reads superglobals
 * through (sweep H holds the whole codebase to them, so this class proves the
 * contract they depend on).
 *
 * The suite runs with failOnWarning="true" (phpunit.xml), which is the real
 * requirement here: PHP 8.x raises "Undefined array key" for a bare
 * $_POST['x'] on a request that did not send it, and that warning is a hard
 * failure. These tests never guard the read with isset(), so if the accessors
 * regress to raw array access the suite fails on the warning itself, not only
 * on the returned default.
 *
 * The other half is the shape guard: a field that arrives as an ARRAY
 * (a crafted email[]=x) must never be handed back where a scalar is promised
 * — that is what turns trim($_POST['x']) into a TypeError. The typed helpers
 * fall back to the default for arrays, objects and null; the raw helpers
 * (post/get/input) deliberately pass the value through untouched, and the
 * test says so.
 */
final class RequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $postBefore = [];
    /** @var array<string, mixed> */
    private array $getBefore  = [];

    protected function setUp(): void
    {
        if (!class_exists('Request', false)) {
            require_once __DIR__ . '/../admin/include/Request.php';
        }
        $this->postBefore = $_POST;
        $this->getBefore  = $_GET;
        $_POST = [];
        $_GET  = [];
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBefore;
        $_GET  = $this->getBefore;
    }

    // ------------------------------------------------------------- raw reads

    public function testPostReturnsTheValueOrTheDefault(): void
    {
        $_POST['plan_id'] = '42';
        self::assertSame('42', Request::post('plan_id'), 'a present field is returned verbatim');
        self::assertSame('', Request::post('missing'), 'an absent field defaults to empty string, no warning');
        self::assertSame(-1, Request::post('missing', -1), 'the default is caller-supplied');
    }

    public function testGetReturnsTheValueOrTheDefault(): void
    {
        $_GET['page'] = '3';
        self::assertSame('3', Request::get('page'));
        self::assertSame('', Request::get('missing'), 'an absent GET field defaults, no warning');
        self::assertSame(1, Request::get('missing', 1));
    }

    public function testInputPrefersPostThenGetThenTheDefault(): void
    {
        $_GET['q']  = 'from-get';
        $_POST['q'] = 'from-post';
        self::assertSame('from-post', Request::input('q'), 'POST wins when both are present');

        unset($_POST['q']);
        self::assertSame('from-get', Request::input('q'), 'GET answers when POST did not');

        unset($_GET['q']);
        self::assertSame('fallback', Request::input('q', 'fallback'), 'the default answers when neither did');
    }

    public function testRawAccessorsPassAnArrayThroughUntouched(): void
    {
        $_POST['tags'] = ['a', 'b'];
        self::assertSame(['a', 'b'], Request::post('tags'), 'the raw accessor is a raw accessor — the typed helpers are the guard');
    }

    // ------------------------------------------------------- presence probes

    public function testHasPostAndHasGetReportPresence(): void
    {
        self::assertFalse(Request::hasPost('submit'), 'a field that was never sent is not present');
        $_POST['submit'] = '';
        self::assertTrue(Request::hasPost('submit'), 'present-but-empty is still present — unlike empty()');

        self::assertFalse(Request::hasGet('slug'));
        $_GET['slug'] = 'brian-linda';
        self::assertTrue(Request::hasGet('slug'));
    }

    // ---------------------------------------------------- typed coercions

    public function testStringHelpersCoerceScalarsAndDefaultWhenAbsent(): void
    {
        $_POST['email'] = 'brian@example.com';
        $_GET['ref']    = 'top';
        self::assertSame('brian@example.com', Request::postString('email'));
        self::assertSame('top', Request::getString('ref'));
        self::assertSame('', Request::postString('missing'));
        self::assertSame('n/a', Request::getString('missing', 'n/a'));
    }

    public function testIntHelpersCoerceAndDefault(): void
    {
        $_POST['plan_id'] = '42';
        $_GET['page']     = 7;
        self::assertSame(42, Request::postInt('plan_id'));
        self::assertSame(7, Request::getInt('page'));
        self::assertSame(0, Request::postInt('missing'));
        self::assertSame(5, Request::getInt('missing', 5));
    }

    public function testFloatHelpersCoerceAndDefault(): void
    {
        $_POST['amount'] = '1500.50';
        $_GET['rate']    = 0.15;
        self::assertSame(1500.50, Request::postFloat('amount'));
        self::assertSame(0.15, Request::getFloat('rate'));
        self::assertSame(0.0, Request::postFloat('missing'));
        self::assertSame(9.99, Request::getFloat('missing', 9.99));
    }

    public function testIntHelpersRejectNonNumericStrings(): void
    {
        $_POST['id'] = '12abc';
        self::assertSame(0, Request::postInt('id'), 'a non-numeric string is not silently truncated');
        self::assertSame(99, Request::postInt('id', 99), 'it falls back to the caller default');

        $_POST['id'] = ' 12 ';
        self::assertSame(12, Request::postInt('id'), 'surrounding whitespace is tolerated');
    }

    // --------------------------------------------------- the shape guard

    public function testTypedHelpersNeverReturnAnArrayWhereAScalarIsPromised(): void
    {
        $_POST['x'] = ['crafted'];
        $_GET['y']  = ['crafted'];

        self::assertSame('', Request::postString('x'), 'a crafted array field falls back to the string default');
        self::assertSame('safe', Request::postString('x', 'safe'));
        self::assertSame(0, Request::postInt('x'), 'and to the int default');
        self::assertSame(0.0, Request::getFloat('y'), 'and to the float default');
        self::assertSame(3, Request::getInt('y', 3));
    }

    public function testTypedHelpersTreatNullAsAbsent(): void
    {
        $_POST['x'] = null;
        self::assertSame('', Request::postString('x'));
        self::assertSame(0, Request::postInt('x'));
        self::assertSame(0.0, Request::postFloat('x'));
        self::assertTrue(Request::hasPost('x'), 'a null field is still "sent" for presence purposes');
    }
}
