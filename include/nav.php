<?php
    // Public nav is session-aware: guests get Login/Join; a signed-in couple
    // gets a direct link to their bookings and a working sign-out.
    $nav_has_session = isset($_SESSION['id']) || isset($_SESSION['user_email']);
    $nav_is_staff = function_exists('is_staff') && is_staff();
    $nav_portal_url = $nav_is_staff ? 'admin/dashboard.php' : 'admin/client.php';
    $nav_identity = $_SESSION['user_email'] ?? (isset($_SESSION['email']) ? $_SESSION['email'] : '');
?>
<nav class="navbar navbar-expand-xl wpms-nav fixed-top">
    <div class="container-fluid" style="max-width:1180px;">
        <a class="navbar-brand wpms-brand d-flex align-items-center" href="index.php">
            <img class="wpms-brand-logo" src="images/logo/everafter-crest.png" alt="Everafter Planner logo">
            <span class="wpms-brand-text">Everafter<span class="text-kenya"> Planner</span></span>
        </a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown"
                aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation" style="border:none;">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNavDropdown">
            <ul class="navbar-nav mr-auto" style="gap:.2rem;">
                <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="pricing.php">Pricing <span style="font-size:10px; background:var(--kenya-cream); border:1px solid #FDE68A; color:#92400E; padding:1px 6px; border-radius:999px; margin-left:4px;">KSh</span></a></li>
                <li class="nav-item"><a class="nav-link" href="real-weddings.php">Inspiration</a></li>
                <li class="nav-item"><a class="nav-link" href="gallery.php">Gallery</a></li>
                <li class="nav-item"><a class="nav-link" href="about.php">About</a></li>
                <li class="nav-item"><a class="nav-link" href="contact.php">Contact</a></li>
            </ul>
            <div class="d-flex align-items-center" style="gap:.5rem;">
                <?php if ($nav_has_session): ?>
                    <?php if ($nav_identity !== ''): ?>
                        <span class="mr-1" style="font-size:12px; color:#57534E;"><?= htmlspecialchars($nav_identity); ?></span>
                    <?php endif; ?>
                    <a class="wpms-cta btn btn-sm" href="<?= $nav_portal_url; ?>"><i class="mdi mdi-calendar-heart mr-1"></i> <?= $nav_is_staff ? 'My Dashboard' : 'My Bookings'; ?></a>
                    <a class="wpms-cta-ghost btn btn-sm" href="admin/logout.php"><i class="mdi mdi-logout mr-1"></i> Log Out</a>
                <?php else: ?>
                    <a class="wpms-cta-ghost btn btn-sm" href="#" data-toggle="modal" data-target="#loginModal">Login</a>
                    <a class="wpms-cta-ghost btn btn-sm" href="admin/login.php"><i class="mdi mdi-shield-account-outline mr-1"></i> Admin</a>
                    <a class="wpms-cta btn btn-sm" href="index.php#sign-up">Join Now — Free</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<?php if (!$nav_has_session): ?>
<!-- Client Login Modal (shared by all public pages) -->
<div class="modal fade" id="loginModal" tabindex="-1" role="dialog" aria-labelledby="loginModalTitle" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="loginModalTitle"><img src="images/logo/everafter-crest.png" alt="Everafter Planner" style="height:40px;"></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form action="login.php" method="post">
                    <?php if (function_exists('is_staff') && isset($session) && $session->message()): ?>
                        <div class="mb-2"><?= $session->message(); ?></div>
                    <?php endif; ?>
                    <?php if (isset($session) && method_exists($session, 'csrf_field')): ?>
                        <?= $session->csrf_field(); ?>
                    <?php endif; ?>
                    <div class="form-group row">
                        <label for="inputEmail" class="col-sm-2 col-form-label">Email:</label>
                        <div class="col-sm-10">
                            <input type="text" class="form-control" id="inputEmail" name="input_email"
                                   placeholder="Enter email">
                        </div>
                    </div>
                    <div class="form-group row">
                        <label for="inputPassword" class="col-sm-2 col-form-label">Password:</label>
                        <div class="col-sm-10">
                            <input type="password" class="form-control" id="inputPassword" name="input_password" placeholder="Enter password">
                        </div>
                    </div>
                    <div class="form-group row">
                        <label for="login" class="col-sm-2 col-form-label"></label>
                        <div class="col-sm-10">
                            <button type="submit" name="login" class="btn mr-2 custom-btn" style="font-size:14px; background:#065F46; color:#fff; border-radius:999px; padding:.45rem 1.3rem; font-weight:600; border:0;">
                                Login
                            </button>
                            <a href="contact.php" style="color: #6B82A6;">Forgot your password?</a>
                            <br/>
                            <div class="mt-2">Not a member yet? <a href="index.php#sign-up" style="color: #6B82A6;">Join Now</a></div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
