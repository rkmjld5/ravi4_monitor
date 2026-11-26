
<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: owner_logout.php

PURPOSE:
1. Log out the owner.
2. Clear owner session information.
3. Destroy the current session.
4. Return to owner_login.php.

=========================================================
*/

require_once __DIR__ . "/config.php";

// Remove owner-specific session information.
unset($_SESSION["ravi3_owner_logged_in"]);
unset($_SESSION["ravi3_owner_username"]);
unset($_SESSION["ravi3_owner_user_id"]);
unset($_SESSION["ravi3_owner_role"]);

// Clear all remaining session variables.
$_SESSION = [];

// Expire the session cookie.
if (ini_get("session.use_cookies")) {

    $cookieParams = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $cookieParams["path"],
        $cookieParams["domain"],
        $cookieParams["secure"],
        $cookieParams["httponly"]
    );
}

// Destroy the session.
session_destroy();

// Return to owner login page.
header("Location: owner_login.php");
exit;

?>
