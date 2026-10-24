<?php
declare(strict_types=1);

/**
 * VendorSignup — a wedding business creating its own listing and its own login.
 *
 * THE GAP THIS FILLS
 * Until now a vendor could only exist because someone inside the product made
 * one: staff registered the business from admin/vendor_register.php, or an
 * admin created the tblusers row and the vendor filled the profile afterwards.
 * The marketplace's "Are you a wedding vendor?" band pointed at contact.php, so
 * every listing cost a conversation. This class is the public front door:
 * a business signs up, gets an account it can use immediately, describes what
 * it sells, and waits for an admin to approve the listing.
 *
 * WHY AN ACCOUNT IS CREATED BEFORE APPROVAL (and not instead of it)
 * The two gates answer different questions. `tblusers.email_verified` asks "is
 * this mailbox real enough to sign in?" — the vendor proved nothing beyond
 * choosing a password, so we do not pretend otherwise: the account is usable
 * at once, exactly like a vendor an admin creates today (`email_verified` 1),
 * because the vendor's own dashboard, services and packages are theirs to fill
 * in while the application waits. `tblvendors.is_approved` asks "may this
 * business be shown to couples?" — it starts at 0 and only the operator flips
 * it (admin/vendors.php's "Awaiting approval" queue). Nothing a self-registered
 * vendor can do makes their listing visible; nothing an admin does is needed to
 * let them log in.
 *
 * WHY THE WRITES ARE ONE TRANSACTION
 * A half-created vendor is worse than a refused one: an account with no profile
 * lands on vendor_register.php (fine), but a profile with no account can never
 * be signed into, and services without a vendor row are orphan rows. Account,
 * profile, services and package are committed together or not at all.
 *
 * WHAT IS DELIBERATELY NOT HERE
 * No logo upload (that is vendor_profile.php), no gallery, no premium flag, no
 * approval: those are operator concerns or later steps in the vendor's own
 * dashboard. The signup form is the shortest honest path from "I cater" to "I
 * exist in the system, pending a look".
 *
 * DEGRADING WHEN v2.27 IS ABSENT
 * The services form is optional and its table arrived in v2.27. If that
 * migration has not been run, a signup still creates the account and profile
 * and simply stores no services — the vendor can add them from their dashboard
 * once the table exists. `VendorService::installed()` is the probe.
 */
final class VendorSignup
{
    /** Same floor as planner signup: long enough to be worth hashing slowly. */
    public const MIN_PASSWORD = 8;

    /** How many service rows the signup form offers. More live in the dashboard. */
    public const MAX_SERVICES = 3;

    public const MAX_NAME     = 32;
    public const MAX_BUSINESS = 200;
    public const MAX_PHONE    = 20;
    public const MAX_LOCATION = 100;
    public const MAX_COUNTY   = 50;
    public const MAX_PACKAGE  = 200;

    /**
     * The towns the registration form offers — the same list the staff form
     * uses, so a vendor registered either way is found by the same filter.
     *
     * @var string[]
     */
    public const LOCATIONS = ['Nairobi', 'Mombasa', 'Nakuru', 'Kisumu', 'Eldoret', 'Nyeri', 'Kiambu', 'Machakos', 'Other'];

    /**
     * Create the account, the listing (unapproved), up to three services and an
     * optional package.
     *
     * @param array<string, mixed> $input raw form input
     * @return array{ok:bool, error:string, user_id:int, vendor_id:int,
     *               services:int, package:bool, services_skipped:bool}
     */
    public static function create(array $input): array
    {
        $fail = static fn (string $error): array => [
            'ok' => false, 'error' => $error, 'user_id' => 0, 'vendor_id' => 0,
            'services' => 0, 'package' => false, 'services_skipped' => false,
        ];

        $firstname = self::text((string) ($input['firstname'] ?? ''), self::MAX_NAME);
        $lastname  = self::text((string) ($input['lastname'] ?? ''), self::MAX_NAME);
        $email     = strtolower(self::text((string) ($input['email'] ?? ''), 100));
        $password  = (string) ($input['password'] ?? '');
        $phone     = self::text((string) ($input['phone'] ?? ''), self::MAX_PHONE);

        $business      = self::text((string) ($input['business_name'] ?? ''), self::MAX_BUSINESS);
        $category      = self::text((string) ($input['category'] ?? ''), 20);
        $description   = self::text((string) ($input['description'] ?? ''), 2000);
        $businessEmail = strtolower(self::text((string) ($input['business_email'] ?? ''), 100));
        $location      = self::text((string) ($input['location'] ?? ''), self::MAX_LOCATION);
        $county        = self::text((string) ($input['county'] ?? ''), self::MAX_COUNTY);

        if ($firstname === '' || $lastname === '') {
            return $fail('Please enter the name of the person we should talk to.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $fail('Please enter a valid email address — it is your login.');
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            return $fail('Your password must be at least ' . self::MIN_PASSWORD . ' characters.');
        }
        if ($business === '') {
            return $fail('Please enter your business name — couples see it on every listing.');
        }
        if (!in_array($category, VendorCoverage::slugs(), true)) {
            return $fail('Choose the kind of vendor you are.');
        }
        if ($phone === '') {
            return $fail('A phone number is required — couples and planners call before they book.');
        }
        if ($location === '' || $county === '') {
            return $fail('Tell us the town and county you work in.');
        }
        if ($businessEmail !== '' && !filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) {
            return $fail('That business email address does not look right.');
        }
        if (self::email_taken($email)) {
            return $fail('That email is already registered. Sign in instead, or use another address.');
        }

        // An install without v2.27 can still take the signup: the profile and
        // account are the point, and services are what the vendor adds next
        // from their dashboard once the table exists.
        $wanted = self::service_rows($input);
        $services_supported = VendorService::installed();
        $services = $services_supported ? $wanted : [];
        foreach ($services as $index => $row) {
            $clean = VendorService::normalise_input($row);
            $error = VendorService::validate($clean);
            if ($error !== '') {
                return $fail('Service ' . ($index + 1) . ': ' . $error);
            }
        }
        $package = self::package_row($input);
        if ($package['error'] !== '') {
            return $fail($package['error']);
        }

        global $db;
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }

        try {
            /*
             * The account. access_level is a STRING enum ('0','1','2') — binding
             * an int makes MySQL read it as the enum INDEX, which is how vendor
             * accounts were once created as planners. email_verified 1 because
             * the operator approves the LISTING, not the login.
             */
            $db->execute(
                'INSERT INTO tblusers
                    (firstname, lastname, gender, username, password, email, designation,
                     address, access_level, profile_picture, date_created, phone, email_verified)
                 VALUES
                    (:fn, :ln, :g, :un, :pw, :em, :des, :ad, :lvl, :pic, NOW(), :ph, 1)',
                [
                    ':fn'  => $firstname,
                    ':ln'  => $lastname,
                    ':g'   => 'f',
                    ':un'  => 'vendor_' . strtolower(bin2hex(random_bytes(4))),
                    ':pw'  => password_hash($password, PASSWORD_DEFAULT),
                    ':em'  => $email,
                    ':des' => 'Vendor',
                    ':ad'  => '',
                    ':lvl' => '0',
                    ':pic' => '',
                    ':ph'  => $phone,
                ]
            );
            $user_id = (int) $db->lastInsertId();

            $db->execute(
                'INSERT INTO tblvendors
                    (user_id, business_name, category, description, phone, email, location, county, is_approved)
                 VALUES (:uid, :bname, :cat, :descr, :phone, :email, :loc, :county, 0)',
                [
                    ':uid'    => $user_id,
                    ':bname'  => $business,
                    ':cat'    => $category,
                    ':descr'  => $description,
                    ':phone'  => $phone,
                    ':email'  => $businessEmail !== '' ? $businessEmail : $email,
                    ':loc'    => $location,
                    ':county' => $county,
                ]
            );
            $vendor_id = (int) $db->lastInsertId();

            $created = 0;
            foreach ($services as $row) {
                $result = VendorService::create($vendor_id, $row);
                if (!$result['ok']) {
                    throw new RuntimeException($result['error']);
                }
                $created++;
            }

            $hasPackage = false;
            if ($package['title'] !== '') {
                $db->execute(
                    'INSERT INTO tblvendor_packages (vendor_id, title, description, price, capacity)
                     VALUES (:v, :title, :descr, :price, :cap)',
                    [
                        ':v'     => $vendor_id,
                        ':title' => $package['title'],
                        ':descr' => $package['description'] !== '' ? $package['description'] : null,
                        ':price' => $package['price'],
                        ':cap'   => $package['capacity'],
                    ]
                );
                $hasPackage = true;
            }

            if ($own) {
                $db->commit();
            }

            return [
                'ok' => true, 'error' => '', 'user_id' => $user_id, 'vendor_id' => $vendor_id,
                'services' => $created, 'package' => $hasPackage,
                'services_skipped' => $wanted !== [] && !$services_supported,
            ];
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[WPMS][VendorSignup] ' . $e->getMessage());
            return $fail('Your registration could not be completed. Nothing was saved — please try again.');
        }
    }

    /**
     * The service rows the form actually filled in, re-keyed 0..n for messages
     * a human reads ("Service 1", "Service 2"), capped at MAX_SERVICES.
     *
     * A completely blank row is ignored — the form ships three rows and most
     * vendors use one. A half-filled row is NOT ignored: it goes on to
     * validation so "a title with no price" is refused rather than silently
     * dropped from what they thought they had listed.
     *
     * @param array<string, mixed> $input
     * @return array<int, array<string, mixed>>
     */
    public static function service_rows(array $input): array
    {
        $raw = $input['services'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $touched = trim((string) ($row['title'] ?? '')) !== ''
                || trim((string) ($row['price'] ?? '')) !== ''
                || trim((string) ($row['includes'] ?? '')) !== ''
                || trim((string) ($row['tags'] ?? '')) !== '';
            if (!$touched) {
                continue;
            }
            $rows[] = [
                'title'    => (string) ($row['title'] ?? ''),
                'unit'     => (string) ($row['unit'] ?? 'flat'),
                'price'    => (string) ($row['price'] ?? ''),
                'min_qty'  => (string) ($row['min_qty'] ?? ''),
                'includes' => (string) ($row['includes'] ?? ''),
                'tags'     => (string) ($row['tags'] ?? ''),
            ];
            if (count($rows) >= self::MAX_SERVICES) {
                break;
            }
        }
        return $rows;
    }

    /**
     * The optional package: a bundle the vendor sells as one thing ("Gold
     * buffet, 150 guests"). Blank is normal; a title without a price is not.
     *
     * @param array<string, mixed> $input
     * @return array{title:string, price:float, capacity:?int, description:string, error:string}
     */
    public static function package_row(array $input): array
    {
        $raw = $input['package'] ?? null;
        $raw = is_array($raw) ? $raw : [];

        $title       = self::text((string) ($raw['title'] ?? ''), self::MAX_PACKAGE);
        $description = self::text((string) ($raw['description'] ?? ''), 2000);
        $priceRaw    = trim((string) ($raw['price'] ?? ''));
        $capRaw      = trim((string) ($raw['capacity'] ?? ''));

        $result = [
            'title' => $title,
            'price' => $priceRaw === '' ? 0.0 : round((float) $priceRaw, 2),
            'capacity' => $capRaw !== '' && (int) $capRaw > 0 ? (int) $capRaw : null,
            'description' => $description,
            'error' => '',
        ];

        if ($title === '' && $priceRaw === '' && $description === '') {
            return $result; // nothing offered — normal
        }
        if ($title === '') {
            return ['error' => 'Your package needs a name, or leave the package blank.'] + $result;
        }
        if ($result['price'] <= 0) {
            return ['error' => 'Your package needs a price above zero, or leave it blank.'] + $result;
        }
        return $result;
    }

    /** Is this address already a couple account or a staff/vendor account? */
    public static function email_taken(string $email): bool
    {
        return Accounts::email_exists($email) || Users::email_exists($email);
    }

    private static function text(string $value, int $max): string
    {
        $value = trim(strip_tags($value));
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
