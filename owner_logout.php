<?php
/*
    PROJECT: ravi2_patient
    FILE: owner_logout.php

    PURPOSE:
    Secure logout for Owner / Administrator.

    IMPORTANT:
    1. Destroys the Owner session.
    2. Does not delete or modify database records.
    3. Does not log out the patient session.
    4. Redirects to owner_login.php.
*/

// Load config.php and start the session.
require_once __DIR__ . "/config.php";

// Remove only Owner / Administrator session variables.
unset($_SESSION["owner_logged_in"]);
unset($_SESSION["owner_id"]);
unset($_SESSION["owner_username"]);
unset($_SESSION["owner_name"]);
unset($_SESSION["owner_full_name"]);
unset($_SESSION["owner_csrf"]);
unset($_SESSION["admin_login_csrf"]);

// Regenerate session ID.
session_regenerate_id(true);

// Redirect to Owner login page.
header("Location: owner_login.php");
exit;
?>
