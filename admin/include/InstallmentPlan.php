<?php
declare(strict_types=1);

/**
 * Opt-in month-by-month installment plans.
 *
 * A couple spreads their package balance over N monthly installments; the
 * overview nudge then tracks their pace against THEIR plan ("You're on
 * track — KSh 20,000 of KSh 100,000 paid; installment 2 of 5 due 5 Oct")
 * instead of a generic deadline suggestion. One active plan per booking
 * (UNIQUE booking_id); creating a new plan supersedes the old one, and
 * cancel returns the nudge to the generic deadline bands.
 *
 * The plan never moves money and never charges anything — it is a pacing
 * commitment the couple sets themselves, with the actual deposits still
 * flowing through the normal self-serve M-Pesa flow.
 */
class InstallmentPlan extends DB_Object
{
    protected static $db_table = 'tbl_installment_plans';
    protected static $db_table_fields = [
        'id', 'booking_id', 'months', 'monthly_amount', 'starting_paid', 'started_at', 'is_active', 'created_at',
    ];

    public $id;
    public $booking_id = 0;
    public $months = 0;
    public $monthly_amount = 0.0;
    /** Amount already paid when the plan was created — progress counts only payments after this anchor. */
    public $starting_paid = 0.0;
    /** @var string|null Y-m-d the plan starts (first installment due date anchor) */
    public $started_at;
    public $is_active = 1;
    public $created_at;

    /** Valid plan lengths (months). Kenyan planning horizon: 2-12. */
    public const MONTHS_CHOICES = [2, 3, 4, 5, 6, 9, 12];

    public static function for_booking(int $bookingId): ?self
    {
        global $db;
        $row = $db->fetchOne(
            'SELECT * FROM tbl_installment_plans WHERE booking_id = :b AND is_active = 1 LIMIT 1',
            [':b' => $bookingId]
        );
        if (!$row) {
            return null;
        }
        $plan = new self();
        foreach ((array) $row as $k => $v) {
            if (property_exists($plan, $k)) {
                $plan->$k = $v;
            }
        }
        return $plan;
    }

    /**
     * Create (or replace) the active plan for a booking. Monthly amount is
     * balance-at-creation divided by months, rounded UP to the nearest 50
     * (KSh 50 is the practical M-Pesa granularity — a plan that ends at
     * 199,975.50 is worse than one that over-collects slightly).
     */
    public static function create_plan(int $bookingId, int $months): ?self
    {
        global $db;
        $months = max(2, min(12, $months));

        $booking = Booking::find_by_booking_id($bookingId);
        if (!$booking) {
            return null;
        }
        $category = Category::find_by_id((int) $booking->wedding_type);
        $price    = (float) ($category->price ?? 0);
        if ($price <= 0) {
            return null; // nothing to plan against
        }
        $balance = max(0.0, $price - Liquidation::getTotalAmount($bookingId));
        if ($balance <= 0) {
            return null; // already paid — no plan needed
        }

        // Supersede any existing plan (UNIQUE booking_id forces replace).
        $db->execute('DELETE FROM tbl_installment_plans WHERE booking_id = :b', [':b' => $bookingId]);

        $monthly = ceil($balance / $months / 50) * 50;

        $plan = new self();
        $plan->booking_id     = $bookingId;
        $plan->months         = $months;
        $plan->monthly_amount = $monthly;
        // Progress anchors HERE: only payments made after plan creation count
        // toward installments, so a couple with prior deposits starts their
        // plan at installment 1, not with it somehow already complete.
        $plan->starting_paid  = Liquidation::getTotalAmount($bookingId);
        $plan->started_at     = date('Y-m-d');
        $plan->is_active      = 1;
        $id = $plan->create();
        return $id ? $plan : null;
    }

    public static function cancel(int $bookingId): bool
    {
        global $db;
        $db->execute(
            'DELETE FROM tbl_installment_plans WHERE booking_id = :b',
            [':b' => $bookingId]
        );
        return true;
    }

    /**
     * @param float $amountPaid total paid on the booking NOW
     * @return array{done:int, total:int, next_due:string|null, next_amount:float, on_track:bool, paid_into_plan:float}
     */
    public function progress(float $amountPaid): array
    {
        $total   = $this->months;
        $perInst = (float) $this->monthly_amount;
        // Only payments made AFTER the plan started count toward installments.
        $planPaid = max(0.0, $amountPaid - (float) $this->starting_paid);
        $done     = (int) floor($planPaid / max(0.01, $perInst));
        $done     = max(0, min($total, $done));

        // Next due: started_at + done months (the first installment anchors
        // at the plan's start date; each subsequent one a month later).
        // A due date that has already passed means the couple is behind pace:
        // it is kept as-is ("due NOW") so the nudge can say so, instead of
        // silently clamping to today and erasing the signal.
        $nextDue = null;
        $behind  = false;
        if ($done < $total) {
            $nextDue = date('Y-m-d', strtotime((string) $this->started_at . ' +' . $done . ' months'));
            if ($nextDue < date('Y-m-d')) {
                $behind  = true;
                $nextDue = date('Y-m-d');
            }
        }

        $onTrack = $nextDue !== null && !$behind;

        return [
            'done'          => $done,
            'total'         => $total,
            'next_due'      => $nextDue,
            'next_amount'   => $perInst,
            'on_track'      => $onTrack,
            'paid_into_plan' => $planPaid,
        ];
    }

    /**
     * The nudge line for the couple's overview: plan-aware when a plan
     * exists, null otherwise (the page then falls back to deadline bands).
     *
     * @return array{head:string, note:string}|null
     */
    public static function nudge_for(int $bookingId, float $amountPaid, float $packagePrice): ?array
    {
        $plan = self::for_booking($bookingId);
        if (!$plan) {
            return null;
        }
        $balance = max(0.0, $packagePrice - $amountPaid);
        if ($balance <= 0) {
            return null; // the paid nudge takes over
        }

        $prog = $plan->progress($amountPaid);
        $fmt  = fn (float $a): string => 'KSh ' . number_format($a);

        if ($prog['next_due'] === null) {
            // All N installments are paid, but a balance remains (the monthly
            // figure rounds up or the couple paused). Ask for the settlement.
            return [
                'head' => 'All ' . $prog['total'] . ' installments of your plan are paid — a balance of ' . $fmt($balance) . ' remains.',
                'note' => 'Top up the difference via M-Pesa to close it out.',
            ];
        }

        $inst = 'installment ' . ($prog['done'] + 1) . ' of ' . $prog['total'];
        if ($prog['done'] === 0) {
            $head = 'Your plan: ' . $prog['total'] . ' × ' . $fmt($prog['next_amount'])
                . ' a month — installment 1 of ' . $prog['total'] . ' is ready whenever you are.';
        } else {
            $head = $fmt($prog['paid_into_plan']) . ' paid on your plan — ' . $inst
                . ' of ' . $prog['total'] . ' (' . $fmt($prog['next_amount']) . ') is next.';
        }

        $due = $prog['next_due'];
        $note = $due === date('Y-m-d')
            ? 'This installment is due now — clear it to stay on track.'
            : 'Next installment due ' . date('j M Y', strtotime($due)) . '.'
                . ($prog['on_track'] ? '' : ' You are behind pace — a catch-up payment helps.');

        return ['head' => $head, 'note' => $note];
    }
}
