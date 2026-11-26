<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: owner_patient.php

PURPOSE:
1. Verify owner login.
2. Receive a patient_id from owner.php.
3. Display the selected patient's details.
4. Display recent monitoring readings.
5. Display recent dashboard alerts.

This page is READ ONLY.

It does not change:
- Patient accounts
- Passwords
- Device assignments
- Monitoring readings
- ECG records
- Alerts

Database connection: config.php
Database: patient_monitor
Connection: MySQLi
=========================================================
*/

require_once __DIR__ . "/config.php";

date_default_timezone_set("Asia/Kolkata");


// -------------------------------------------------------
// 1. CHECK OWNER LOGIN
// -------------------------------------------------------

if (
    empty($_SESSION["ravi3_owner_logged_in"]) ||
    $_SESSION["ravi3_owner_logged_in"] !== true
) {
    header("Location: owner_login.php");
    exit;
}


// -------------------------------------------------------
// 2. SAFE HTML OUTPUT FUNCTION
// -------------------------------------------------------

function report_h($value)
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}


// -------------------------------------------------------
// 3. GET PATIENT ID
// -------------------------------------------------------

$patientId = trim(
    (string)($_GET["patient_id"] ?? "")
);

if ($patientId === "") {
    die("Patient ID was not provided.");
}


// -------------------------------------------------------
// 4. LOAD PATIENT DETAILS
// -------------------------------------------------------

$patientSql = "
    SELECT *
    FROM patients
    WHERE patient_id = ?
    LIMIT 1
";

$patientStmt = $db->prepare($patientSql);

if (!$patientStmt) {
    error_log("Owner patient report error: " . $db->error);
    die("Unable to prepare patient query.");
}

$patientStmt->bind_param("s", $patientId);

if (!$patientStmt->execute()) {
    $patientStmt->close();
    die("Unable to load patient information.");
}

$patientResult = $patientStmt->get_result();

$patient = $patientResult->fetch_assoc();

$patientStmt->close();

if (!$patient) {
    die("Patient record was not found.");
}


// -------------------------------------------------------
// 5. GET PATIENT NAME
// -------------------------------------------------------

$patientName = $patient["patient_name"]
    ?? $patient["name"]
    ?? $patient["full_name"]
    ?? $patientId;


// -------------------------------------------------------
// 6. LOAD DEVICE ASSIGNMENTS
// -------------------------------------------------------

$devices = [];

$deviceSql = "
    SELECT *
    FROM patient_devices
    WHERE patient_id = ?
    ORDER BY device_id
";

$deviceStmt = $db->prepare($deviceSql);

if (!$deviceStmt) {
    error_log("Owner device report error: " . $db->error);
    die("Unable to prepare device query.");
}

$deviceStmt->bind_param("s", $patientId);

if (!$deviceStmt->execute()) {
    $deviceStmt->close();
    die("Unable to load device assignments.");
}

$deviceResult = $deviceStmt->get_result();

while ($row = $deviceResult->fetch_assoc()) {
    $devices[] = $row;
}

$deviceStmt->close();


// -------------------------------------------------------
// 7. LOAD MONITORING READINGS
// -------------------------------------------------------

$readings = [];

$monitorSql = "
    SELECT *
    FROM monitoring_readings
    WHERE patient_id = ?
    ORDER BY recorded_at DESC
    LIMIT 200
";

$monitorStmt = $db->prepare($monitorSql);

if (!$monitorStmt) {
    error_log("Owner monitoring report error: " . $db->error);
    die("Unable to prepare monitoring query.");
}

$monitorStmt->bind_param("s", $patientId);

if (!$monitorStmt->execute()) {
    $monitorStmt->close();
    die("Unable to load monitoring readings.");
}

$monitorResult = $monitorStmt->get_result();

while ($row = $monitorResult->fetch_assoc()) {
    $readings[] = $row;
}

$monitorStmt->close();


// -------------------------------------------------------
// 8. LOAD DASHBOARD ALERTS
// -------------------------------------------------------

$alerts = [];

$alertSql = "
    SELECT *
    FROM alerts
    WHERE patient_id = ?
    ORDER BY recorded_at DESC
    LIMIT 200
";

$alertStmt = $db->prepare($alertSql);

if (!$alertStmt) {
    error_log("Owner alert report error: " . $db->error);
    die("Unable to prepare alert query.");
}

$alertStmt->bind_param("s", $patientId);

if (!$alertStmt->execute()) {
    $alertStmt->close();
    die("Unable to load patient alerts.");
}

$alertResult = $alertStmt->get_result();

while ($row = $alertResult->fetch_assoc()) {
    $alerts[] = $row;
}

$alertStmt->close();


// -------------------------------------------------------
// 9. HELPER TO DISPLAY DATABASE VALUES
// -------------------------------------------------------

function report_value($row, $column, $default = "-")
{
    if (
        isset($row[$column]) &&
        $row[$column] !== ""
    ) {
        return $row[$column];
    }

    return $default;
}


// -------------------------------------------------------
// 10. OWNER USERNAME
// -------------------------------------------------------

$ownerUsername =
    $_SESSION["ravi3_owner_username"] ?? "Owner";

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Patient Report - Ravi3</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 20px;
    font-family: Arial, sans-serif;
    background: #f3f6fa;
    color: #263238;
}

.container {
    max-width: 1250px;
    margin: auto;
}

.header {
    background: #17365d;
    color: white;
    padding: 18px;
    border-radius: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.header h2 {
    margin: 0 0 7px 0;
}

.header a {
    color: white;
    text-decoration: none;
    background: #1769aa;
    padding: 10px 14px;
    border-radius: 5px;
}

.card {
    background: white;
    padding: 18px;
    margin-top: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
}

h3 {
    color: #17365d;
    margin-top: 0;
}

.info {
    background: #e8f2ff;
    padding: 14px;
    border-radius: 5px;
    line-height: 1.9;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 12px;
    background: white;
}

th, td {
    border: 1px solid #ddd;
    padding: 9px;
    text-align: left;
    font-size: 13px;
}

th {
    background: #e9eef5;
}

tr:nth-child(even) {
    background: #fafafa;
}

.table-wrap {
    overflow-x: auto;
}

.status-ok {
    color: #147a35;
    font-weight: bold;
}

.status-pending {
    color: #b52b2b;
    font-weight: bold;
}

.small {
    color: #666;
    font-size: 13px;
}

@media(max-width:700px) {

    body {
        padding: 10px;
    }

    .header {
        display: block;
    }

    .header a {
        display: inline-block;
        margin-top: 12px;
    }

    th, td {
        padding: 7px;
        font-size: 12px;
    }

}

</style>

</head>

<body>

<div class="container">


    <!-- PAGE HEADER -->

    <div class="header">

        <div>

            <h2>Patient Monitoring Report</h2>

            <div>
                Owner:
                <?= report_h($ownerUsername) ?>
            </div>

        </div>

        <div>

            <a href="owner.php">
                Back to Owner Dashboard
            </a>

            <a href="owner_logout.php">
                Logout
            </a>

        </div>

    </div>


    <!-- PATIENT INFORMATION -->

    <div class="card">

        <h3>Patient Information</h3>

        <div class="info">

            <strong>Patient ID:</strong>
            <?= report_h($patientId) ?>

            <br>

            <strong>Patient Name:</strong>
            <?= report_h($patientName) ?>

            <br>

            <strong>Assigned Device:</strong>

            <?php if (count($devices) === 0): ?>

                No device assigned.

            <?php else: ?>

                <?php foreach ($devices as $device): ?>

                    <?= report_h($device["device_id"]) ?>

                    <?php if (isset($device["enabled"])): ?>

                        (
                        <?= ((int)$device["enabled"] === 1)
                            ? "Enabled"
                            : "Disabled" ?>
                        )

                    <?php endif; ?>

                    <br>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>


    <!-- MONITORING SUMMARY -->

    <div class="card">

        <h3>Monitoring Summary</h3>

        <p>
            Total readings displayed:
            <strong><?= count($readings) ?></strong>
        </p>

        <?php if (count($readings) === 0): ?>

            <p>No monitoring readings found.</p>

        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>
                            <th>Date and Time</th>
                            <th>Device ID</th>
                            <th>Room Temp.</th>
                            <th>Humidity</th>
                            <th>BPM</th>
                            <th>SpO2</th>
                            <th>Body Temp.</th>
                            <th>Mode</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($readings as $reading): ?>

                        <tr>

                            <td>
                                <?= report_h(
                                    report_value($reading, "recorded_at")
                                ) ?>
                            </td>

                            <td>
                                <?= report_h(
                                    report_value($reading, "device_id")
                                ) ?>
                            </td>

                            <td>
                                <?= report_h(
                                    report_value($reading, "room_temperature")
                                ) ?>
                            </td>

                            <td>
                                <?= report_h(
                                    report_value($reading, "humidity")
                                ) ?>
                            </td>

                            <td>
                                <?= report_h(
                                    report_value($reading, "bpm")
                                ) ?>
                            </td>

                            <td>
                                <?= report_h(
                                    report_value($reading, "spo2")
                                ) ?>
                            </td>

                            <td>
                                <?= report_h(
                                    report_value($reading, "body_temperature")
                                ) ?>
                            </td>

                            <td>
                                <?= report_h(
                                    report_value($reading, "mode")
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>


    <!-- ALERT HISTORY -->

    <div class="card">

        <h3>Dashboard Alert History</h3>

        <p>
            Total alerts displayed:
            <strong><?= count($alerts) ?></strong>
        </p>

        <?php if (count($alerts) === 0): ?>

            <p>No alerts found.</p>

        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>
                            <th>Date and Time</th>
                            <th>Device ID</th>
                            <th>Parameter</th>
                            <th>Measured Value</th>
                            <th>Alert Message</th>
                            <th>Status</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($alerts as $alert): ?>

                        <tr>

                            <td>
                                <?= report_h(
                                    report_value($alert, "recorded_at")
                                ) ?>
                            </td>

                            <td>
                                <?= report_h(
                                    report_value($alert, "device_id")
                                ) ?>
                            </td>

                            <td>
                                <?= report_h(
                                    report_value($alert, "parameter_name")
                                ) ?>
                            </td>

                            <td>
                                <?= report_h(
                                    report_value($alert, "measured_value")
                                ) ?>
                            </td>

                            <td>
                                <?= report_h(
                                    report_value($alert, "alert_message")
                                ) ?>
                            </td>

                            <td>

                                <?php if (
                                    isset($alert["acknowledged"]) &&
                                    (int)$alert["acknowledged"] === 1
                                ): ?>

                                    <span class="status-ok">
                                        Acknowledged
                                    </span>

                                <?php else: ?>

                                    <span class="status-pending">
                                        Pending
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>


    <!-- FOOTER -->

    <div class="card">

        <p class="small">
            This report is read-only. No patient records,
            device assignments or historical readings
            have been changed.
        </p>

    </div>


</div>

</body>
</html>
