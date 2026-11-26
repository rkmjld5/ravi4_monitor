<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: patient_access.php

PURPOSE:
1. Check whether the patient has logged in.
2. Check the patient's session information.
3. Confirm that the assigned device is enabled.
4. Check the device assignment start and expiry dates.
5. Stop access if the account/device is disabled or expired.

IMPORTANT:
Include this file at the beginning of patient pages:
index.php, history.php, ecg_history.php and alerts.php.

This file uses the Ravi3 session name and variables.
=========================================================
*/


// -------------------------------------------------------
// 1. LOAD DATABASE CONNECTION
// -------------------------------------------------------

require_once __DIR__ . "/config.php";


// -------------------------------------------------------
// 2. CHECK PATIENT LOGIN
// -------------------------------------------------------

if (
    empty($_SESSION["ravi3_patient_id"]) ||
    empty($_SESSION["ravi3_patient_name"]) ||
    empty($_SESSION["ravi3_device_id"])
) {
    header("Location: login.php");
    exit;
}


// -------------------------------------------------------
// 3. GET PATIENT DETAILS FROM SESSION
// -------------------------------------------------------

$patient_id = trim($_SESSION["ravi3_patient_id"]);
$patient_name = trim($_SESSION["ravi3_patient_name"]);
$device_id = trim($_SESSION["ravi3_device_id"]);


// -------------------------------------------------------
// 4. CHECK DEVICE ASSIGNMENT
// -------------------------------------------------------

// The patient must have an enabled assignment for this
// exact device ID.
//
// Expected patient_devices columns:
// patient_id, device_id, enabled, valid_from, valid_until.
//
// The assignment is not changed by this program.

$sql = "
    SELECT
        patient_id,
        device_id,
        enabled,
        valid_from,
        valid_until
    FROM patient_devices
    WHERE patient_id = ?
      AND device_id = ?
    LIMIT 1
";

$stmt = $db->prepare($sql);

if (!$stmt) {
    error_log("Ravi3 patient access query preparation failed: " . $db->error);
    die("Unable to verify patient access. Please try again later.");
}

$stmt->bind_param("ss", $patient_id, $device_id);

if (!$stmt->execute()) {
    error_log("Ravi3 patient access query execution failed: " . $stmt->error);
    $stmt->close();
    die("Unable to verify patient access. Please try again later.");
}

$result = $stmt->get_result();

$assignment = $result->fetch_assoc();

$stmt->close();


// -------------------------------------------------------
// 5. STOP ACCESS IF NO ASSIGNMENT EXISTS
// -------------------------------------------------------

if (!$assignment) {

    session_unset();
    session_destroy();

    header("Location: login.php?error=assignment");
    exit;
}


// -------------------------------------------------------
// 6. CHECK WHETHER DEVICE IS ENABLED
// -------------------------------------------------------

if ((int)$assignment["enabled"] !== 1) {

    session_unset();
    session_destroy();

    header("Location: login.php?error=disabled");
    exit;
}


// -------------------------------------------------------
// 7. CHECK START AND EXPIRY DATES
// -------------------------------------------------------

date_default_timezone_set("Asia/Kolkata");

$now = new DateTime("now", new DateTimeZone("Asia/Kolkata"));


// Check the assignment start date.
if (!empty($assignment["valid_from"])) {

    $validFrom = new DateTime(
        $assignment["valid_from"],
        new DateTimeZone("Asia/Kolkata")
    );

    if ($now < $validFrom) {

        session_unset();
        session_destroy();

        header("Location: login.php?error=notstarted");
        exit;
    }
}


// Check the assignment expiry date.
if (!empty($assignment["valid_until"])) {

    $validUntil = new DateTime(
        $assignment["valid_until"],
        new DateTimeZone("Asia/Kolkata")
    );

    if ($now > $validUntil) {

        session_unset();
        session_destroy();

        header("Location: login.php?error=expired");
        exit;
    }
}


// -------------------------------------------------------
// 8. ACCESS APPROVED
// -------------------------------------------------------

// The following variables are available to the
// patient dashboard and report pages:
//
// $patient_id
// $patient_name
// $device_id
//
// Access is allowed only for the assigned device.


// -------------------------------------------------------
// END OF patient_access.php
// -------------------------------------------------------
?>
