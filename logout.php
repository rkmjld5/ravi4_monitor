<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: logout.php

PURPOSE:
1. End the Ravi3 patient login session.
2. Remove patient session information.
3. Clear the session cookie.
4. Return the patient to login.php.

IMPORTANT:
This file does not delete any patient records,
device assignments, monitoring readings or ECG readings.
=========================================================
*/


// -------------------------------------------------------
// 1. LOAD RAVI3 CONFIGURATION
// -------------------------------------------------------

require_once __DIR__ . "/config.php";


// -------------------------------------------------------
// 2. REMOVE ALL SESSION VARIABLES
// -------------------------------------------------------

$_SESSION = [];


// -------------------------------------------------------
// 3. REMOVE SESSION COOKIE
// -------------------------------------------------------

if (ini_get("session.use_cookies")) {

    $cookieParameters = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 3600,
        $cookieParameters["path"],
        $cookieParameters["domain"],
        $cookieParameters["secure"],
        $cookieParameters["httponly"]
    );
}


// -------------------------------------------------------
// 4. DESTROY SESSION
// -------------------------------------------------------

session_destroy();


// -------------------------------------------------------
// 5. RETURN TO RAVI3 LOGIN PAGE
// -------------------------------------------------------

header("Location: login.php");
exit;

?>
