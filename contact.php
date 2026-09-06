<?php
// Contact form handler — self-contained (no DB/session needed).
// TODO: replace with the real business inbox once provided.
$contact_recipient = 'info@wpms.co.ke';

$contact_name = $contact_email = $contact_phone = $contact_message = '';
$contact_error = '';
$contact_sent  = isset($_GET['sent']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $contact_name    = trim((string) ($_POST['contact_person'] ?? ''));
    $contact_email   = trim((string) ($_POST['contact_email'] ?? ''));
    $contact_phone   = trim((string) ($_POST['contact_phone'] ?? ''));
    $contact_message = trim((string) ($_POST['contact_message'] ?? ''));

    if ($contact_name === '' || $contact_message === '') {
        $contact_error = 'Please provide your name and a message.';
    } elseif (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $contact_error = 'Please enter a valid email address so we can reply.';
    } else {
        // Strip CR/LF to block header injection through name/email.
        $safe_name  = str_replace(["\r", "\n"], '', $contact_name);
        $safe_email = str_replace(["\r", "\n"], '', $contact_email);

        $subject = 'Website enquiry from ' . $safe_name;
        $body    = "Name:    {$safe_name}\n"
                 . "Email:   {$safe_email}\n"
                 . "Phone:   " . ($contact_phone !== '' ? $contact_phone : '-') . "\n\n"
                 . "Message:\n{$contact_message}\n";
        $headers = "From: Everafter Planner <{$contact_recipient}>\r\n"
                 . "Reply-To: {$safe_name} <{$safe_email}>\r\n";

        $sent = @mail($contact_recipient, $subject, $body, $headers);
        if ($sent) {
            header('Location: contact.php?sent=1');
            exit;
        }
        $contact_error = 'Sorry — the message could not be sent right now. Please email us directly at '
            . htmlspecialchars($contact_recipient) . ' or call +254 700 000 000.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Wedding Planner</title>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans|Roboto" rel="stylesheet">
    <link rel="stylesheet" type="text/css"
          href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/datepicker.css">
    <link rel="stylesheet" href="css/style.css"></link><link rel="stylesheet" href="css/modern.css">
    <link rel="stylesheet" href="css/carousel.css">
    <style>
        body {
            font-family: 'Open Sans', 'Roboto', sans-serif;
            line-height: 1.5em;
            margin-bottom: 2%;
            margin-top: 3%;
            width: 100%;
            overflow-x: hidden;
            background: #f1f1f1;
        }

        .navbar-light .navbar-brand {
            color: #1a1a1a;
            font-weight: bold;
            line-height: 22px;
        }

        .navbar {
            font-weight: 700;
            padding: 12px;
            font-style: normal;
            font-size: 14px;
            text-transform: uppercase;
            color: black;
            border-bottom: 1px solid #ddd;
        }

        li.nav-item > a.nav-link {
            color: black !important;
            font-weight: bold !important;
        }

        #review {
            font-size: 16px;
            font-weight: bold;
            margin-right: 5px;
        }

        .form-inline > a.mr-2, .btn.btn-sm.my-2.my-sm-0 {
            color: black;
            font-size: 14px;
            font-weight: 700;
            margin-left: 10px;
        }

        .form-inline > a.mr-2:hover, .btn.btn-sm.my-2.my-sm-0:hover {
            color: #17b4bc;
            text-decoration: none;
        }

        a.btn.btn-sm.my-2.my-sm-0.mr-2.loginbtn {
            background: #dc3545;
            font-size: 14px;
            color: white;
            padding: 5px;
            border: 2px solid transparent;
            width: 85px;
        }

        a.btn.btn-sm.my-2.my-sm-0.mr-2.loginbtn:hover {
            background: white;
            border: 2px solid #dc3545;
            color: #dc3545;
        }

        .navbar-expand-lg .navbar-nav .nav-link {
            padding-right: .9rem;
        }

        .navbar-brand {
            margin-left: 20px;
            width: 200px;
        }

        .form-control {
            font-size: 12px;
            border-radius: 0;
            margin-top: 0;
        }



    </style>
</head>
<body>
<?php include "include/nav.php"; ?>
<div class="container">
    <div class="row">
        <div class="col-lg-12">
            <h3 class="text-center mb-3">CONTACT US</h3>
            <?php if ($contact_sent) : ?>
                <div class="alert alert-success" role="alert"><strong>Thank you!</strong> Your message has been sent — we will get back to you soon.</div>
            <?php elseif ($contact_error !== '') : ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($contact_error); ?></div>
            <?php endif; ?>
            <div class="bg-white p-4">
                <div class="contact-information">
                    <h5>Everafter Planner</h5>
                    <div class="row">
                        <div class="col-md-8">
                            <table class="table table-sm" style="font-size: 12px;">
                                <tr>
                                    <td><i class="mdi mdi-map-marker mr-2"></i> Nairobi, Kenya</td>
                                    <td><i class="mdi mdi-email mr-3"></i> info@wpms.co.ke</td>
                                </tr>
                                <tr>
                                    <td><i class="mdi mdi-phone mr-2"></i> <a href="tel:+254700000000">+254 700 000 000</a></td>
                                    <td><i class="mdi mdi-whatsapp mr-1" style="color:#25D366;"></i> <a href="https://wa.me/254700000000?text=<?= rawurlencode('Hi Everafter Planner! I am planning my wedding and would like to know more about your packages.'); ?>" target="_blank" rel="noopener">Chat with us on WhatsApp</a></td>
                                </tr>
                                <tr>
                                    <td><i class="mdi mdi-web mr-2"></i> www.wpms.co.ke</td>
                                    <td></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center mt-3">
                                <i class="mdi mdi-map-marker" style="font-size: 110px;color: #065F46;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white mt-3 pt-1 pl-4 pb-3">
                <div class="row mt-3">
                    <div class="col-md-12">
                        <form action="contact.php" method="post" style="font-size: 12px;">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-row">
                                        <div class="form-group col-md-6 mb-1">
                                            <label for="contact_person">Name</label>
                                            <input type="text" class="form-control" id="contact_person" name="contact_person" value="<?= htmlspecialchars($contact_name); ?>" placeholder="Your full name">
                                        </div>
                                        <div class="form-group col-md-6 mb-1">
                                            <label for="contact_email">Email</label>
                                            <input type="email" class="form-control" id="contact_email" name="contact_email" value="<?= htmlspecialchars($contact_email); ?>" placeholder="you@example.com">
                                        </div>
                                        <div class="form-group col-md-6 mb-1">
                                            <label for="contact_phone">Phone</label>
                                            <input type="tel" class="form-control" id="contact_phone" name="contact_phone" value="<?= htmlspecialchars($contact_phone); ?>" placeholder="+254 7XX XXX XXX">
                                        </div>
                                        <div class="form-group col-md-12 mb-1">
                                            <label for="contact_message">Message</label>
                                            <textarea class="form-control" name="contact_message"
                                                      style="resize: none;" rows="8"
                                                      id="contact_message" placeholder="How can we help with your wedding planning?"><?= htmlspecialchars($contact_message); ?></textarea>
                                        </div>

                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="contact_submit" class="btn btn-sm" style="font-size: 13px;margin-top: 10px;background: #065F46;color: #fff;border-radius: 999px;padding: .5rem 1.5rem;font-weight: 600;border: 0;">Send Message</button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
<!-- Optional JavaScript -->
<!-- jQuery first, then Popper.js, then Bootstrap JS -->
<script src="js/jquery-3.2.1.slim.min.js"></script>
<script src="js/jquery.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>