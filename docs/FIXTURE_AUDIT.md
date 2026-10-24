# Sweep F — test fixtures a class claims but does not own

An audit of `tests/` for the bug class that produced the suite's longest-lived
"known baseline": **HumanDatesTest asserted a live countdown for booking 215.**
The scratch seed builds bookings 200–204, so the booking was not there at all; on
the development database it *was* there, with a wedding date already past, so the
countdown branch was unreachable in either direction — and the failure was
carried as a documented baseline instead of being fixed. Nothing in that test's
own setup said 215 was its fixture: the id was a literal in a URL, every other
fixture in the file was booking 200, and the class never wrote 215.

Run it with:

```
WPMS_DB_NAME=wpms_sim php scripts/audit_fixtures.php          # the repository
WPMS_DB_NAME=wpms_sim php scripts/audit_fixtures.php --root=<dir>   # a fixture tree
composer code-audit                                            # with sweeps A–G
```

It is row six of `composer code-audit`, and `tests/FixtureSweepTest.php` proves
both halves through that same CLI: the repository is clean, and
`tests/fixtures/fixtures` — a deliberately broken mini-suite — reports every rule
with its evidence.

## What counts as a claim, and as ownership

**Claim.** A method claims a record when it asserts on the response **body** of a
request carrying that record's id — directly, or through a variable assigned from
one. Status-only assertions are not claims: `assertSame(302, self::http(…
booking_id=99999999…)['status'])` is a refusal probe, which is exactly what a
nonexistent id is for, and dozens of them are legitimate. Attributing claims per
method rather than per statement buries those real cases in noise — the first
draft of this sweep did, and reported 30+ false positives.

**Ownership.** A file owns a record when it writes any row keyed by that id. The
tables come from the live schema (every column named `booking_id` / `user_id` /
`event_id` — 35 of them, not a hand list), so a write through `tblaccounts_detail`
or `tbl_liquidation` counts, and so does a write made through the file's own
helper (`self::swapWeddingType(self::OWN_BOOKING, 0)`). The fixture's *constant*
is not ownership: `private const BOOKING_ID = 200` says the fixture is seeded,
which is a legitimate thing to read, just not something the file can be blamed for
keeping alive.

## The rules

| Rule | What it reports | Why it is decidable |
|---|---|---|
| **F1** | The claimed record does not exist in the database at all. If the read is behind a status check the finding says so, because that variant **passes vacuously instead of failing**. | Existence is a query, and an assertion about a row that is not there cannot be true. |
| **F4** | The claimed record exists, the file never writes it, and the assertion is a **calendar claim** about it (a month name, a four-digit year, "to go"). | It is somebody else's fixture and the claim is about the one field that rots with the clock. This is the dbwedding half of the 215 bug. |
| **F2** | A literal compared against an aggregate over a whole table (`assertSame(2, enquiryCount())` where the helper counts every row of `tblvendor_bookings`). | The number describes the seed, not the behaviour: any other class that leaves a row behind changes it. A count bound to a fixture id, or compared against a baseline read in the test, is not reported — `substr_count()` on a response body is not a table either. |
| **F3** | Text asserted about that the file generates in more than one place — `"probe item 3"` while a seeding helper called from two methods writes `"probe item " . $i`. | The assertion answers about whichever batch ran last. It fires only when the literal is prose, the generator's stem is its own digit-stripped tail, and the enclosing helper is called more than once; a fixture's own unique label is left alone. |
| **F6** | The **not-judged list itself** — the sweep's own blind spot. Any gap that `TOLERATED_GAPS` does not cover fails the build, and a tolerated entry that excused nothing fails it too. | A gap list that grows quietly is a blind spot with a progress bar. The key is repository-relative, so the one printed in the failure is the one to paste into the registry, and an allowance cannot outlive the gap it was written for. |

## Findings — what needed fixing

Two, both of the same species. The founding case (HumanDatesTest booking 215) had
already been fixed; the sweep found these two by running over the tree that fix
left behind.

### 1. `CouplePagesAccessTest::testBudgetVsActualPanelMath` — a vacuous branch (F1)

The test claimed `booking_id=215&user_id=215` to prove that a booking with no
package suppresses the funding panel, guarded by `if ($noPackage['status'] ===
200)`. `wpms_sim` builds no booking 215, so the guard was false on the scratch
database, the assertion never ran, and the branch it exists to protect was never
exercised. The sweep reported it as:

```
!! tests/CouplePagesAccessTest.php::F1::booking_id=215: … asserts on the body of
   booking_id=215, which does not exist in wpms_sim — nothing the assertion says
   about it can be true. The read is guarded by a status check, so it passes
   vacuously instead of failing.
```

**Fixed** by driving the branch on the couple's own booking with the transient
pattern the suite already uses for dates and statuses: `swapWeddingType(203, 0)`
— restoring the old value in `finally` — then asserting the page still renders
**200** and the panel is absent. The assertion is now unconditional, and the
fixture is one the class owns the state of.

### 2. `StaffNotificationsPageTest::testArchiveRendersRowsAndPagination` — batch text (F3)

The pagination test seeded 30 rows with `seedProbes(30, 5)` (a helper the class
calls six times, with different batch sizes) and asserted page placement by
message text — `'[page-test] probe notification 30'` on page 1 and `'…1'` on the
last page. Those strings belong to every batch, so the assertion could be
satisfied by a row some other call created, and "oldest rows on the last page" is
a claim about the whole table rather than about this batch.

**Fixed** the same way as the unread-filter test: `seedProbes()` already returns
the ids it created, so page 1 is asserted on `$rowLink(max($ids))`, and the last
page on the oldest row **on file**, read with `SELECT MIN(id) …` rather than
assumed to be this batch's first row.

After both fixes the repository run is:

```
scanned: 42 test file(s) | records claimed: 49 | 42 file(s) with no finding
findings: 0 | not judged: 0 (0 tolerated, 0 new) | accepted: 0
SWEEP F CLEAN
```

## What the sweep refuses to judge

Printed on every run — visible rather than silent, and since F6 fatal unless
`TOLERATED_GAPS` covers the key with a written reason:

- **A foreign record that exists and carries no calendar claim.** Whether its
  seeded *state* matches the assertion (a paid balance, a status, a package price)
  is not decidable from the test file, and guessing would flag every legitimate
  read of a seeded fixture. The calendar case is fatal because the clock
  guarantees the state changes.
- **Ids whose entity has no table** (`id=…` on an arbitrary page, `feature_id=…`).
  Without a table there is no existence check to make.
- **Writes made through a variable the sweep cannot resolve.** Ownership is
  credited from the statement around an INSERT/UPDATE/DELETE on a table carrying
  the id, plus integer arguments of the file's own write-helpers; a write three
  calls away is not credited, which shows up as a finding rather than as silence —
  the safe direction for an audit that fails the build.

On the current tree both lists are empty: every one of the 49 claimed records is
written by the file that asserts about it. That is not luck — F6's ratchet is
what keeps it true.

### The ratchet (F6)

Those not-judged entries are gaps, and a gap is exactly what this sweep cannot
see. Left alone they would accumulate the way the old four-failure baseline did,
so the list is a ratchet instead: an entry no allowance covers **fails the build**
with the key to argue about, and an allowance that excused nothing fails it as
well, so the registry cannot rot into a blind spot either.

```
!! tests/fixtures/fixtures/ForeignFixtureReadTest.php::F6::booking_id=200: … the not-judged list grew —
   booking_id=200 is a claimed record this sweep will not judge (the row exists but the file never writes it: …)
     own the fixture, or record the key in TOLERATED_GAPS with the reason this gap is tolerated
```

An allowance belongs to a tree — the repository's keys are judged when the
repository is scanned, a fixture tree's when that tree is — so a fixture tree can
hold a tolerated gap without the repository run calling that entry obsolete. The
registry prints every entry with its reason on each run.

## Proven able to fail

`tests/FixtureSweepTest.php` (8 tests, 24 assertions) runs the sweep over
`tests/fixtures/fixtures`, whose five deliberately broken files produce **11
findings** — F1 ×4 (including the guarded, vacuous variant), F4 ×2, F2 ×2 (helper
and inline form), F3 ×2, and one F6 for the gap no allowance covers. The tolerated
twin of that last file makes the same read and is excused **in writing**, which is
the other half of the ratchet. It also pins the negatives, because a rule that
reports everything guards nothing:

- a nonexistent id used for a refusal (`booking_id=99999999`, status-only) is not
  a finding;
- a `COUNT(*) … WHERE id = :i` constrained to the fixture's own row is not a
  finding (exactly two F2 findings, no third);
- a label generated once and asserted once (`QDL-ONLY-ONCE-42`) is not a finding.

The fixture trees are data, not tests: `phpunit.xml` now excludes
`tests/fixtures` from discovery, so a deliberately broken mini-suite can never be
executed by the suite itself.

## Verification

| Check | Command | Result |
|---|---|---|
| Sweep F on the repository | `WPMS_DB_NAME=wpms_sim php scripts/audit_fixtures.php` | **exit 0**, `SWEEP F CLEAN`, 42 files, 49 claims, `not judged: 0 (0 tolerated, 0 new)` |
| Sweep F against the development database | `WPMS_DB_NAME=dbwedding php scripts/audit_fixtures.php` | **exit 0**, clean — the sweep only reads, and neither database has a fixture no file owns |
| The audit chain | the seven `audit_*.php` scripts in `composer code-audit` order | **all exit 0** — sweeps A/B/C, D (promises), E (enum values), F (fixtures) and G (unbound SQL) |
| Sweep F's own failure modes | `phpunit --filter FixtureSweepTest` | **OK, 8 tests / 24 assertions** |
| The ratchet's obsolete half | `--root=tests/fixtures/fixtures` with `ToleratedForeignReadTest.php` moved aside, then restored | observed: `tolerated gap …::booking_id=200 is obsolete — nothing was left unjudged under it on this run`, exit 1; after restoring the file the run is back to its 11 findings with no obsolete line (the file was restored byte-identical) |
| The fixed classes | `phpunit --filter 'CouplePagesAccessTest|StaffNotificationsPageTest'` | green, in default order and under random method order |
| Whole suite | `WPMS_DB_NAME=wpms_sim php vendor/bin/phpunit` (after `demo_reset.php --yes --db=wpms_sim`) | **OK, 375 tests / 2662 assertions**, exit 0 |
| Lint | `php scripts/lint.php` + `php -l` on the new script, test and fixture files | clean |
