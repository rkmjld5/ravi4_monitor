<?php
/*
    PROJECT: ravi2_patient
    FILE: patient_access.php

    PURPOSE:
    Check whether the currently logged-in patient is still allowed
    to use the monitoring application.

    This file must be included at the beginning of every protected
    patient page, before any HTML is displayed.

    CHECKS:
    1. Patient is logged in.
    2. Login account still exists.
    3. Account role is patient.
    4. Login account is enabled.
    5. Login validity dates are correct.
    6. Device is still assigned to the patient.
    7. Device is enabled and assignment dates are valid.
    8. Patient record still exists.

    No database tables are changed by this program.
*/

// --------------------------------------------------
// 1. CONNECT TO DATABASE AND START SESSION
// --------------------------------------------------

require_once __DIR__ . "/config.php";

// Check database connection.
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is not available.");
}

// --------------------------------------------------
// 2. CHECK WHETHER PATIENT IS LOGGED IN
// --------------------------------------------------

if (
    empty($_SESSION["ravi2_patient_logged_in"]) ||
    empty($_SESSION["ravi2_username"]) ||
    empty($_SESSION["ravi2_patient_id"]) ||
    empty($_SESSION["ravi2_device_id"])
) {
    header("Location: login.php");
    exit;
}

// Read the patient information saved during login.
$username  = $_SESSION["ravi2_username"];
$patient_id = $_SESSION["ravi2_patient_id"];
$device_id  = $_SESSION["ravi2_device_id"];

// --------------------------------------------------
// 3. CHECK LOGIN ACCOUNT AGAINST DATABASE
// --------------------------------------------------

$account_sql = "
    SELECT username
    FROM web_users
    WHERE
        username = ?
        AND role = 'patient'
        AND enabled = 1
        AND device_id = ?
        AND (valid_from IS NULL OR valid_from <= NOW())
        AND (valid_until IS NULL OR valid_until >= NOW())
    LIMIT 1
";

$account_stmt = $conn->prepare($account_sql);

if (!$account_stmt) {
    error_log("Patient access account check: " . $conn->error);
    die("Unable to verify your account. Please try again later.");
}

$account_stmt->bind_param("ss", $username, $device_id);
$account_stmt->execute();
$account_stmt->bind_result($verified_username);

$account_valid = $account_stmt->fetch();

$account_stmt->close();

// If the account is disabled, expired, or changed, stop access.
if (!$account_valid) {
    $error_message = "Your patient account is disabled or expired.";
} else {
    $error_message = "";
}

// --------------------------------------------------
// 4. CHECK DEVICE ASSIGNMENT AND PATIENT RECORD
// --------------------------------------------------

if ($error_message === "") {

    $device_sql = "
        SELECT
            p.patient_id,
            p.full_name
        FROM patient_devices d
        INNER JOIN patients p
            ON p.patient_id = d.patient_id
        WHERE
            d.device_id = ?
            AND d.patient_id = ?
            AND d.enabled = 1
            AND (
                d.start_date_time IS NULL
                OR d.start_date_time <= NOW()
            )
            AND (
                d.end_date_time IS NULL
                OR d.end_date_time >= NOW()
            )
        LIMIT 1
    ";

    $device_stmt = $conn->prepare($device_sql);

    if (!$device_stmt) {
        error_log("Patient access device check: " . $conn->error);
        die("Unable to verify your device assignment. Please try again later.");
    }

    $device_stmt->bind_param("ss", $device_id, $patient_id);
    $device_stmt->execute();

    $device_stmt->bind_result(
        $verified_patient_id,
        $verified_patient_name
    );

    $device_valid = $device_stmt->fetch();

    $device_stmt->close();

    if (!$device_valid) {
        $error_message =
            "Your monitoring device is disabled, expired, or no longer assigned to your account.";
    }
}

// --------------------------------------------------
// 5. BLOCK ACCESS IF ANY CHECK FAILED
// --------------------------------------------------

if ($error_message !== "") {

    // Remove the patient's login session.
    $_SESSION = [];

    // Remove the session cookie.
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

    // Destroy the session.
    session_destroy();

    // Send the patient back to the login page.
    $message = urlencode($error_message);

    header("Location: login.php?message=" . $message);
    exit;
}

// --------------------------------------------------
// 6. UPDATE VERIFIED PATIENT INFORMATION
// --------------------------------------------------

/*
   Use the latest information from the database.
   Do not rely only on information saved during login.
*/

$_SESSION["ravi2_patient_id"] = $verified_patient_id;

$_SESSION["ravi2_patient_name"] = $verified_patient_name;

$_SESSION["ravi2_device_id"] = $device_id;

$_SESSION["ravi2_role"] = "patient";

// --------------------------------------------------
// 7. PATIENT ACCESS APPROVED
// --------------------------------------------------

/*
   The requested protected page can now continue.
   No HTML is displayed by this file.
*/
?>
