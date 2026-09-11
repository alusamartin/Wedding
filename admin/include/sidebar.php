
<?php
    // Staff pages resolve the profile from tblusers; client sessions (self-service
    // dashboard on client.php) have no tblusers row, so fall back to a client chip.
    $sidebar_user = ($users_profile instanceof Users) ? $users_profile : null;
?>
<nav class="navbar sticky-top p-0" style="width: 16.7%;">
    <a class="navbar-brand col-sm-3 col-md-2 mr-0" style="color:white;" href="<?= $sidebar_user ? ((is_vendor() && vendor_profile_of((int) ($_SESSION['id'] ?? 0)) !== null) ? 'vendor_home.php' : 'dashboard.php') : 'client.php'; ?>">
        <!-- <img src="images/admin.png" alt="" style="width: 200px;"> -->
        Everafter Planner
    </a>
</nav>

<div class="container-fluid">
    <div class="row">
        <nav class="col-md-2 d-none d-md-block bg-light sidebar">
            <div class="sidebar-sticky">
                <ul class="nav flex-column">
                    <?php if ($sidebar_user): ?>
                    <li class="nav-item mt-3">
                        <div class="text-center">
                            <a href="users_profile.php" class="user-button">
                                <img src="<?= $sidebar_user->profile_picture_picture(); ?>" class="img-fluid rounded-circle" width="52" height="52" alt="">
                                <div class="user-profile"><?= $sidebar_user->firstname . ' ' . $sidebar_user->lastname; ?></div>
                            </a>
                                <div class="text-center mt-0" style="font-size: 11px;color:#72777a;">
                                    <?= ($sidebar_user->designation == 0) ? 'Administrator' : 'Moderator'; ?>
                                </div>
                        </div>
                    </li>
                    <?php else: ?>
                    <li class="nav-item mt-3">
                        <div class="text-center">
                            <a href="client.php" class="user-button">
                                <div class="d-inline-flex align-items-center justify-content-center bg-secondary text-white rounded-circle mx-auto" style="width:52px;height:52px;font-size:20px;"><?= strtoupper(substr((string) ($_SESSION['user_email'] ?? 'C'), 0, 1)); ?></div>
                                <div class="user-profile"><?= htmlspecialchars((string) ($_SESSION['user_email'] ?? 'Client')); ?></div>
                            </a>
                            <div class="text-center mt-0" style="font-size: 11px;color:#72777a;">Client Account</div>
                        </div>
                    </li>
                    <li class="nav-item"><div class="dropdown-divider"></div></li>
                    <li class="nav-item">
                        <a class="nav-link" href="client.php">
                            <i class="mdi mdi-calendar-multiple-check mr-3"></i>
                            My Bookings
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($sidebar_user): ?>
                    <li class="nav-item"><div class="dropdown-divider"></div></li>

                    <?php if (is_vendor()): ?>
                    <!-- Vendor self-service navigation (access_level 0) -->
                    <li class="nav-item">
                        <a class="nav-link" href="vendor_home.php">
                            <i class="mdi mdi-handshake-outline mr-3"></i>
                            My Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vendor_profile.php">
                            <i class="mdi mdi-store-edit mr-3"></i>
                            My Profile
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vendor_packages.php">
                            <i class="mdi mdi-package-variant-closed mr-3"></i>
                            My Packages
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vendors.php">
                            <i class="mdi mdi-store mr-3"></i>
                            Marketplace
                        </a>
                    </li>
                    <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="mdi mdi-home mr-3"></i>
                            Dashboard <span class="sr-only">(current)</span>
                        </a>
                    </li>
                    <li class="nav-item"><div class="dropdown-divider"></div></li>
                    <li class="nav-item">
                        <a class="nav-link" href="blog_events.php">
                            <i class="mdi mdi-comment-text mr-3"></i>
                            Blogs &amp; Events
                        </a>
                    </li>
                    <li class="nav-item"><div class="dropdown-divider"></div></li>
                    <li class="nav-item">
                        <a class="nav-link" href="client.php" >
                            <i class="mdi mdi-account-multiple mr-3"></i>
                            Clients
                        </a>
                    </li>
                    <li class="nav-item"><div class="dropdown-divider"></div></li>

                    <li class="nav-item">
                        <a class="nav-link" href="service_list.php">
                            <i class="mdi mdi-verified mr-3"></i>
                            Services
                        </a>
                    </li>
                    <li class="nav-item"><div class="dropdown-divider"></div></li>

                    <li class="nav-item">
                        <a class="nav-link" href="photos_view.php">
                            <i class="mdi mdi-image-multiple mr-3"></i>
                            Gallery
                        </a>
                    </li>

                    <li class="nav-item"><div class="dropdown-divider"></div></li>
                    <li class="nav-item">
                        <a class="nav-link" href="photos_add.php">
                            <i class="mdi mdi-upload-multiple mr-3"></i>
                            Upload Photos
                        </a>
                    </li>
                    <li class="nav-item"><div class="dropdown-divider"></div></li>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">
                            <i class="mdi mdi-account-card-details mr-3"></i>
                            User Management
                        </a>
                    </li>
                    <li class="nav-item"><div class="dropdown-divider"></div></li>

                    <!-- Monetization Section -->
                    <li class="nav-item mt-3 mb-1 ml-3" style="font-size: 11px; text-transform: uppercase; color: #94A3B8; font-weight: 600; letter-spacing: .04em;">
                        <i class="mdi mdi-cash-multiple mr-1"></i> Monetization
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="revenue.php">
                            <i class="mdi mdi-cash-100 mr-3"></i>
                            Revenue
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="subscription.php">
                            <i class="mdi mdi-crown mr-3"></i>
                            Subscription Plans
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vendors.php">
                            <i class="mdi mdi-store mr-3"></i>
                            Vendor Marketplace
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vendor_enquiries.php">
                            <i class="mdi mdi-handshake-outline mr-3"></i>
                            Vendor Enquiries
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sms_center.php">
                            <i class="mdi mdi-message-text mr-3"></i>
                            SMS Center
                        </a>
                    </li>
                    <li class="nav-item"><div class="dropdown-divider"></div></li>

                    <li class="nav-item">
                        <a class="nav-link" href="task_all_calendar.php">
                            <i class="mdi mdi-calendar-text mr-3"></i>
                            Task Calendar
                        </a>
                    </li>
                    <?php endif; /* vendor vs staff nav */ ?>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>
        <main role="main" class="col-md-9 ml-sm-auto col-lg-10" style="margin-top: -40px;z-index: 999">
            <nav class="navbar navbar-expand-lg navbar-light bg-light" style="margin-left:-15px;margin-right: -15px;">
                <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav">

                    </ul>
                </div>

                <div class="form-inline my-2 my-lg-0">
                    <?php
                    // Notification owner: the SESSION user (staff in tblusers,
                    // couples in tblaccounts). Deriving from the session means
                    // couples get the bell too — their confirm/cancel/enquiry
                    // alerts used to have nowhere to land.
                    $notifUserId = (int) ($_SESSION['id'] ?? 0);
                    ?>
                    <?php if ($notifUserId > 0): ?>
                    <?php
                        $notifCount  = Notification::unread_count($notifUserId);
                        $notifs      = Notification::latest($notifUserId, 8);
                    ?>
                    <div class="dropdown mr-2">
                        <button class="btn btn-sm btn-light border position-relative" type="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
                                title="Notifications" style="border-radius:8px;">
                            <i class="mdi mdi-bell-outline" style="font-size:18px;"></i>
                            <?php if ($notifCount > 0): ?>
                            <span class="badge badge-pill badge-danger"
                                  style="position:absolute; top:-6px; right:-6px; font-size:10px;"><?= (int) $notifCount; ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-right p-0" style="width: 340px; border-radius:10px;">
                            <div class="px-3 py-2 d-flex justify-content-between align-items-center" style="border-bottom:1px solid #eef2f7;">
                                <strong style="font-size:13px;">Notifications</strong>
                                <?php if ($notifCount > 0): ?>
                                <a href="notifications.php?action=readall" style="font-size:12px;">Mark all read</a>
                                <?php endif; ?>
                            </div>
                            <?php if (empty($notifs)): ?>
                                <div class="text-center text-muted py-3" style="font-size:13px;">You're all caught up</div>
                            <?php else: foreach ($notifs as $notif): ?>
                                <a class="dropdown-item py-2 <?= ((int) $notif->is_read === 0) ? 'font-weight-bold' : ''; ?>"
                                   href="notifications.php?action=read&id=<?= (int) $notif->id; ?>"
                                   style="white-space:normal; font-size:13px; border-bottom:1px solid #f1f5f9;">
                                    <?= htmlspecialchars($notif->message); ?>
                                    <span class="d-block text-muted" style="font-size:11px;"><?= htmlspecialchars(format_date_kd((string) $notif->created_at, true)); ?></span>
                                </a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <a class="nav-link" href="<?= $sidebar_user ? 'users_profile.php' : 'client.php'; ?>"><b><?= $sidebar_user ? ucfirst($sidebar_user->firstname) . ' ' . ucfirst($sidebar_user->lastname) : htmlspecialchars((string) ($_SESSION['user_email'] ?? 'Client')); ?></b></a>
                    <a class="nav-link" href="logout.php"><b><i class="mdi mdi-logout"></i> Logout</b></a>
                </div>
            </nav>