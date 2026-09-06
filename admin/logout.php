<?php
/**
 * Sign out — everyone returns to the single login door (login.php at the
 * site root), which routes each role to their landing page on the way back
 * in. For anonymous visitors the door is simply the login form.
 */
session_start();
session_unset();
session_destroy();
header("Location: ../login.php");
