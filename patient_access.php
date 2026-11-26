<?php
/*
=========================================================
RAVI4 PATIENT MONITORING SYSTEM
File: patient_access.php

PURPOSE:
1. Check whether the patient has logged in.
2. Check Ravi4 patient session information.
3. Confirm that the patient has an enabled device assignment.
4. Check device assignment start and expiry dates.
5. Stop access if the assignment is disabled or expired.

IMPORTANT:
This file belongs to Ravi4 only.
It does not modify patient records or device assignments.
=========================================================
*/


// -------------------------------------------------------
// 1. LOAD RAVI4 DATABASE CONNECTION AND SESSION
// -------------------------------------------------------

require_once __DIR__ . "/config.php";


// -------------------------------------------------------
// 2. CHECK PATIENT LOGIN
// -------------------------------------------------------

// These are the session names created by Ravi4 login.php.

if (
    empty($_SESSION["ravi4_patient_logged_in"]) ||
    $_SESSION["ravi4_patient_logged_in"] !== true ||
    empty($_SESSION["ravi4_patient_id"]) ||
    empty($_SESSION["ravi4_patient_name"]) ||
    empty($_SESSION["ravi4_device_id"])
) {
    header("Location: login.php");
    exit;
}


// -------------------------------------------------------
// 3. GET PATIENT DETAILS FROM RAVI4 SESSION
// -------------------------------------------------------

$patient_id = trim($_SESSION["ravi4_patient_id"]);

$patient_name = trim($_SESSION["ravi4_patient_name"]);

$device_id = trim($_SESSION["ravi4_device_id"]);


// -------------------------------------------------------
// 4. CHECK DEVICE ASSIGNMENT
// -------------------------------------------------------

// The actual assignment date columns used by the existing
// Ravi2 application are:
// start_date_time
// end_date_time
//
// We only read the assignment. Nothing is modified.

$sql = "
    SELECT
        patient_id,
        device_id,
        enabled,
        start_date_time,
        end_date_time
    FROM patient_devices
    WHERE patient_id = ?
      AND device_id = ?
    LIMIT 1
";

$stmt = $db->prepare($sql);

if (!$stmt) {

    error_log(
        "Ravi4 patient access prepare error: " . $db->error
    );

    die("Unable to verify patient access. Please try again later.");
}

$stmt->bind_param("ss", $patient_id, $device_id);

if (!$stmt->execute()) {

    error_log(
        "Ravi4 patient access execution error: " . $stmt->error
    );

    $stmt->close();

    die("Unable to verify patient access. Please try again later.");
}

$result = $stmt->get_result();

$assignment = $result->fetch_assoc();

$stmt->close();


// -------------------------------------------------------
// 5. FUNCTION TO STOP PATIENT ACCESS
// -------------------------------------------------------

function ravi4_stop_patient_access($reason)
{
    error_log("Ravi4 patient access stopped: " . $reason);

    // Clear the current Ravi4 login session.
    $_SESSION = [];

    session_destroy();

    header("Location: login.php?error=" . urlencode($reason));

    exit;
}


// -------------------------------------------------------
// 6. CHECK WHETHER ASSIGNMENT EXISTS
// -------------------------------------------------------

if (!$assignment) {

    ravi4_stop_patient_access("assignment");
}


// -------------------------------------------------------
// 7. CHECK WHETHER DEVICE IS ENABLED
// -------------------------------------------------------

if ((int)$assignment["enabled"] !== 1) {

    ravi4_stop_patient_access("disabled");
}


// -------------------------------------------------------
// 8. CHECK DEVICE ASSIGNMENT START AND EXPIRY DATES
// -------------------------------------------------------

date_default_timezone_set("Asia/Kolkata");

$indiaTimezone = new DateTimeZone("Asia/Kolkata");

$now = new DateTime("now", $indiaTimezone);


// Check assignment start date.

if (!empty($assignment["start_date_time"])) {

    $validFrom = new DateTime(
        $assignment["start_date_time"],
        $indiaTimezone
    );

    if ($now < $validFrom) {

        ravi4_stop_patient_access("notstarted");
    }
}


// Check assignment expiry date.

if (!empty($assignment["end_date_time"])) {

    $validUntil = new DateTime(
        $assignment["end_date_time"],
        $indiaTimezone
    );

    if ($now > $validUntil) {

        ravi4_stop_patient_access("expired");
    }
}


// -------------------------------------------------------
// 9. PATIENT ACCESS APPROVED
// -------------------------------------------------------

// The following variables are now available to the
// patient dashboard and report pages:
//
// $patient_id
// $patient_name
// $device_id
//
// Access is allowed only for the assigned device.


// -------------------------------------------------------
// END OF RAVI4 patient_access.php
// -------------------------------------------------------
?>
