<?php
/**
 * Set the venue headcount plan for one wedding — the writer behind
 * sheet_venue.php's "Headcount plan per function" table.
 *
 * WHY THIS FILE EXISTS (v2.9)
 * That table printed family/individual counts per function from
 * tbleventbooking, and nothing in the application could write them: the class
 * describing the table did not extend DB_Object and had no create()/update(),
 * and no screen offered the fields. The sheet was therefore showing numbers no
 * role could enter (or correct) — a brief for the venue and the caterers that
 * was frozen at whatever the seed dump contained.
 *
 * WHO MAY SET IT
 * The same rule as the sheet itself (include/sheet_boot.php): staff may set the
 * plan for any wedding they can open, a couple only for their own. The couple
 * are the people who know how many family and individual guests are coming to
 * each function, and the planner consolidates it with the venue; both act on
 * the page where the numbers are shown, so the edit does not live away from the
 * figure it controls.
 *
 * WHAT IS ACCEPTED
 * Only functions that are ACTUALLY on this wedding's calendar: the submitted
 * ids are looked up in the booking's own function list rather than trusted, so
 * a crafted POST cannot park counts against another couple's function where no
 * page would ever show them. Counts must be numeric and non-negative.
 *
 * WHO IS TOLD
 * A couple's save is the change the venue and the caterers have to act on, so
 * it notifies the assigned planner, the booked venue contact and every other
 * vendor booked on the wedding — each of them with JUST the functions whose
 * numbers moved, scoped to the functions their own deal covers
 * (include/HeadcountBrief.php). A staff edit saves silently: the planner works
 * this sheet all day and their own corrections must not echo to the vendors. A
 * save that moves nothing notifies nobody.
 *
 * AND WHEN THE PLAN OUTGROWS WHAT WAS SOLD
 * A vendor package states a guest capacity (tblvendor_packages.capacity) and a
 * deal records the package it was sold on, so this save also checks the numbers
 * it just wrote against those limits (include/PackageCapacity.php). On a
 * couple's save the warning rides in the SAME brief (one bell per account, not
 * two for one edit); on a staff save — where the brief says nothing — the
 * vendors holding a package that no longer covers the day are warned, and the
 * assigned planner too unless they are the one who saved. The saver always sees
 * it on the page: a plan that has outgrown the catering is not something to
 * discover on the day.
 */
include 'include/init.php'; // paywall: staff need an active plan to work on any booking
    if (staff_paywall_locked()) { redirect_to('subscription.php'); exit; }

$booking_id = (int) ($_POST['booking_id'] ?? $_GET['booking_id'] ?? 0);

if (!isset($_SESSION['id'])) {
    redirect_to('../');
    die();
}

$booking = Booking::find_by_booking_id($booking_id);
if (!$booking) {
    redirect_to('client.php');
    die();
}

// Same access rule as the sheet, from the same helper: staff any wedding, a
// couple only their own — and a vendor session, whose tblusers id can equal a
// couple's tblaccounts id, is refused outright.
//
// The booking-only predicate is correct here on purpose: this page posts no
// user_id into its decisions (the return links below address the BOOKING's
// owner), so the ?user_id shape check of may_open_couple_page() has nothing to
// verify and would only read an undefined variable.
if (!booking_visible_to_session($booking)) {
    redirect_to('client.php');
    die();
}

/*
 * The return links address the BOOKING's owner, never the posted user_id. The
 * check above is what decides who may write; a posted id was only ever copied
 * into the redirect, so ?booking_id=203&user_id=204 sent an authorized couple
 * back to their own sheet addressed to another account.
 */
$links = 'booking_id=' . $booking_id . '&user_id=' . (int) $booking->user_id;

/** Back to the sheet with a message — it renders session messages. */
function headcount_back(string $links, string $msg, string $cls = 'alert-danger'): void
{
    global $session;
    $session->message('<div class="' . $cls . ' alert-dismissible fade show" role="alert"><strong><i class="mdi mdi-alert"></i></strong> '
        . $msg
        . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
    redirect_to('sheet_venue.php?' . $links);
    die();
}

if (!isset($_POST['save_headcounts'])) {
    headcount_back($links, 'Invalid request.');
}
if (!$session->verify_csrf()) {
    headcount_back($links, 'Invalid security token. Please try again.');
}

// The wedding's own functions are the only writable keys, and iterating them
// (rather than the submitted array) means a form can never invent a function.
$functions = BookingEvent::plan_for($booking_id);

$submitted_family     = (array) ($_POST['family'] ?? []);
$submitted_individual = (array) ($_POST['individual'] ?? []);

$counts   = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
$refused  = 0;
$badValue = 0;
$moves    = [];   // the functions whose numbers actually moved, for the brief
$package  = (int) ($booking->wedding_type ?? 0); // the package the plan sits under

$allowed = [];
foreach ($functions as $fn) {
    $allowed[(string) $fn->features_id] = $fn;
}

// Anything submitted that is NOT a function of this wedding is refused and
// said so: the form can only post its own functions, so a key from outside is
// either a crafted request or a form for a different wedding. Silently
// dropping it would hide both — and hiding it is how a page ends up keeping
// numbers no role can see.
foreach (array_unique(array_merge(array_keys($submitted_family), array_keys($submitted_individual))) as $key) {
    if (!isset($allowed[(string) $key])) {
        $refused++;
    }
}

foreach ($allowed as $key => $fn) {
    $fid = (int) $fn->features_id;

    // A missing field means the row was not in the submitted form: leave it be.
    if (!array_key_exists($key, $submitted_family) || !array_key_exists($key, $submitted_individual)) {
        continue;
    }

    $family     = headcount_input($submitted_family[$key]);
    $individual = headcount_input($submitted_individual[$key]);
    if ($family === null || $individual === null) {
        $badValue++;
        continue;
    }

    // Nothing planned yet and nothing entered: writing a 0/0 row would only add
    // a line that means the same as no line at all.
    if (!$fn->planned && $family === 0 && $individual === 0) {
        $counts['unchanged']++;
        continue;
    }

    $result = BookingEvent::set_counts($booking_id, $package, $fid, $family, $individual);
    if ($result === 'invalid') {
        $refused++;
        continue;
    }
    $counts[$result]++;

    // The writer is the judge of what moved ('created'/'updated' vs
    // 'unchanged', decided against the stored row); the pre-save snapshot from
    // plan_for() supplies the "before" side of the brief, and features_id is
    // what lets a vendor's deal be matched to the function it covers.
    if ($result !== 'unchanged') {
        $moves[] = [
            'features_id' => $fid,
            'title'       => (string) $fn->title,
            'from'        => $fn->planned ? ['family' => (int) $fn->family, 'individual' => (int) $fn->individual] : null,
            'to'          => ['family' => $family, 'individual' => $individual],
        ];
    }
}

// ----------------------------------------------------------------- report
$changed = $counts['created'] + $counts['updated'];

/*
 * Did the plan just outgrow a package a vendor is booked on? The check runs on
 * the functions that MOVED (the same list the brief is built from), because the
 * warning is about the change that was just made: a plan that was already over a
 * limit is printed on the sheet (sheet_venue.php) and reported by the save that
 * put it there, not re-announced on every later save.
 */
$capacity = [];
$breaches = [];
if ($moves !== []) {
    $moved = [];
    foreach ($moves as $move) {
        $to  = (array) ($move['to'] ?? []);
        $from = $move['from'] ?? null;
        $moved[] = [
            'features_id' => (int) ($move['features_id'] ?? 0),
            'title'       => (string) ($move['title'] ?? ''),
            'total'       => max(0, (int) ($to['family'] ?? 0)) + max(0, (int) ($to['individual'] ?? 0)),
            'was'         => $from === null
                ? null
                : max(0, (int) ($from['family'] ?? 0)) + max(0, (int) ($from['individual'] ?? 0)),
        ];
    }
    $breaches = PackageCapacity::breaches($booking_id, $moved);
    if ($breaches !== []) {
        $capacity = PackageCapacity::clauses($breaches);
    }
}

$parts   = [];
if ($counts['created'] > 0) {
    $parts[] = $counts['created'] . ' function' . ($counts['created'] === 1 ? '' : 's') . ' planned';
}
if ($counts['updated'] > 0) {
    $parts[] = $counts['updated'] . ' updated';
}

$warnings = [];
if ($refused > 0) {
    $warnings[] = $refused . ' row' . ($refused === 1 ? '' : 's') . ' refused — only functions on this wedding\'s calendar can be planned.';
}
if ($badValue > 0) {
    $warnings[] = $badValue . ' row' . ($badValue === 1 ? '' : 's') . ' ignored — a headcount must be a whole number of 0 or more.';
}

// Tell the planner and the venue contact about the move — only when a couple
// made it, and only about the functions that changed. The capacity warning is
// folded into the same message (one bell per account), so a vendor hears once
// per save.
$brief = null;
if ($changed > 0 && $moves !== [] && !is_staff()) {
    $brief = HeadcountBrief::announce($booking_id, $moves, $capacity);
}

// A staff save says nothing about the move, but a package that no longer covers
// the day is a fact about the deal rather than about who typed the number: the
// vendors holding it are warned, and so is the assigned planner unless they are
// the one who saved. On a couple's save the brief above has already carried it.
$capacityNotice = '';
if ($changed > 0 && $capacity !== []) {
    if (is_staff()) {
        $capacityNotice = PackageCapacity::notice(
            $breaches,
            (string) HeadcountBrief::announce_capacity($booking_id, $capacity, (int) ($_SESSION['id'] ?? 0))['told']
        );
    } else {
        $capacityNotice = PackageCapacity::notice($breaches);
    }
}

if ($changed > 0) {
    headcount_back($links, 'Headcount plan saved — ' . implode(', ', $parts) . '.'
        . ($brief !== null ? ' ' . HeadcountBrief::notice($brief) : '')
        . ($capacityNotice !== '' ? ' ' . $capacityNotice : '')
        . ($warnings !== [] ? ' ' . implode(' ', $warnings) : ''), 'alert-success');
}
if ($warnings !== []) {
    headcount_back($links, 'Nothing was saved. ' . implode(' ', $warnings));
}
headcount_back($links, 'No changes to save — the headcount plan already matches what you entered.', 'alert-info');

/**
 * A submitted count as an int, or null when it is not a whole number >= 0.
 * (int) alone would turn "abc" into 0 and silently wipe a plan.
 */
function headcount_input(mixed $value): ?int
{
    $value = is_string($value) ? trim($value) : $value;
    if ($value === '' || $value === null) {
        return 0; // an empty box means "no plan for this function"
    }
    if (!is_numeric($value)) {
        return null;
    }
    $n = (int) $value;
    if ($n < 0 || (float) $value !== (float) $n) {
        return null;
    }
    return min(10000, $n); // a venue brief, not a stadium
}
