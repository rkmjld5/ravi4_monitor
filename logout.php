<?php
/*
    PROJECT: ravi2_patient
    FILE: logout.php

    PURPOSE:
    Log out the currently logged-in patient.

    ACTIONS:
    1. Clear all patient session information.
    2. Remove the session cookie.
    3. Destroy the PHP session.
    4. Redirect to login.php.

    No database changes are made.
*/

// --------------------------------------------------
// 1. LOAD CONFIGURATION AND START SESSION
// --------------------------------------------------

require_once __DIR__ . "/config.php";

// --------------------------------------------------
// 2. CLEAR ALL SESSION INFORMATION
// --------------------------------------------------

$_SESSION = [];

// --------------------------------------------------
// 3. REMOVE THE SESSION COOKIE
// --------------------------------------------------

if (ini_get("session.use_cookies")) {

    $cookie_parameters = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $cookie_parameters["path"],
        $cookie_parameters["domain"],
        $cookie_parameters["secure"],
        $cookie_parameters["httponly"]
    );
}

// --------------------------------------------------
// 4. DESTROY THE SESSION
// --------------------------------------------------

session_destroy();

// --------------------------------------------------
// 5. RETURN TO PATIENT LOGIN PAGE
// --------------------------------------------------

header("Location: login.php?message=" . urlencode("You have been logged out successfully."));
exit;

?>
