<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: owner.php

PURPOSE:
1. Allow the owner to view registered patients.
2. Display their assigned ESP32 devices.
3. Allow the owner to select a patient.
4. Display the selected patient's latest readings.
5. Display the selected patient's dashboard alerts.

IMPORTANT:
This is a read-only owner dashboard.
It does not change patient accounts, device assignments,
or historical readings.

Database connection: config.php
Database: patient_monitor
Connection type: MySQLi
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
// 2. HELPER FUNCTION FOR SAFE HTML OUTPUT
// -------------------------------------------------------

function owner_h($value)
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}


// -------------------------------------------------------
// 3. GET DATABASE TABLE COLUMNS
// -------------------------------------------------------

// This helper checks which columns really exist.
// It helps avoid assuming every optional column exists.

function owner_get_columns($db, $table)
{
    $columns = [];

    // Table names are fixed inside this program.
    $allowedTables = [
        "patients",
        "patient_devices",
        "monitoring_readings",
        "alerts"
    ];

    if (!in_array($table, $allowedTables, true)) {
        return $columns;
    }

    $result = $db->query("SHOW COLUMNS FROM `$table`");

    if ($result) {

        while ($row = $result->fetch_assoc()) {
            $columns[] = $row["Field"];
        }

        $result->free();
    }

    return $columns;
}


// -------------------------------------------------------
// 4. FIND AVAILABLE PATIENT TABLE COLUMNS
// -------------------------------------------------------

$patientColumns = owner_get_columns($db, "patients");
$deviceColumns = owner_get_columns($db, "patient_devices");

$monitorColumns = owner_get_columns($db, "monitoring_readings");
$alertColumns = owner_get_columns($db, "alerts");


// -------------------------------------------------------
// 5. CHECK REQUIRED DATABASE COLUMNS
// -------------------------------------------------------

if (
    !in_array("patient_id", $patientColumns, true) ||
    !in_array("patient_id", $deviceColumns, true) ||
    !in_array("device_id", $deviceColumns, true)
) {

    die(
        "Database structure error: patients.patient_id, " .
        "patient_devices.patient_id or patient_devices.device_id " .
        "is missing. Please check the database structure."
    );

}


// -------------------------------------------------------
// 6. LOAD REGISTERED PATIENTS
// -------------------------------------------------------

$patients = [];

$patientSql = "
    SELECT *
    FROM patients
    ORDER BY patient_id
";

$patientResult = $db->query($patientSql);

if (!$patientResult) {

    error_log("Owner patient list error: " . $db->error);

    die("Unable to load patient records.");

}

while ($patient = $patientResult->fetch_assoc()) {

    $patients[] = $patient;

}

$patientResult->free();


// -------------------------------------------------------
// 7. LOAD DEVICE ASSIGNMENTS
// -------------------------------------------------------

$deviceAssignments = [];

$deviceSql = "
    SELECT *
    FROM patient_devices
    ORDER BY patient_id, device_id
";

$deviceResult = $db->query($deviceSql);

if (!$deviceResult) {

    error_log("Owner device list error: " . $db->error);

    die("Unable to load device assignments.");

}

while ($device = $deviceResult->fetch_assoc()) {

    $patientId = (string)$device["patient_id"];

    if (!isset($deviceAssignments[$patientId])) {
        $deviceAssignments[$patientId] = [];
    }

    $deviceAssignments[$patientId][] = $device;

}

$deviceResult->free();


// -------------------------------------------------------
// 8. GET SELECTED PATIENT
// -------------------------------------------------------

$selectedPatientId = trim(
    (string)($_GET["patient_id"] ?? "")
);

$selectedPatient = null;

foreach ($patients as $patient) {

    if (
        $selectedPatientId !== "" &&
        (string)$patient["patient_id"] === $selectedPatientId
    ) {

        $selectedPatient = $patient;
        break;

    }

}


// -------------------------------------------------------
// 9. GET PATIENT DISPLAY NAME
// -------------------------------------------------------

function owner_patient_name($patient)
{
    $possibleNames = [
        "patient_name",
        "name",
        "full_name"
    ];

    foreach ($possibleNames as $column) {

        if (!empty($patient[$column])) {
            return $patient[$column];
        }

    }

    return $patient["patient_id"] ?? "Unknown patient";
}


// -------------------------------------------------------
// 10. GET SELECTED PATIENT DEVICE IDs
// -------------------------------------------------------

$selectedDevices = [];

if ($selectedPatient !== null) {

    $selectedId = (string)$selectedPatient["patient_id"];

    $selectedDevices = $deviceAssignments[$selectedId] ?? [];

}


// -------------------------------------------------------
// 11. LOAD RECENT MONITORING READINGS
// -------------------------------------------------------

$monitoringReadings = [];

$monitoringError = "";

if ($selectedPatient !== null) {

    $requiredMonitorColumns = [
        "patient_id",
        "device_id",
        "recorded_at"
    ];

    foreach ($requiredMonitorColumns as $column) {

        if (!in_array($column, $monitorColumns, true)) {

            $monitoringError =
                "The monitoring_readings table is missing column: " .
                $column;

            break;

        }

    }

    if ($monitoringError === "") {

        $monitorSql = "
            SELECT *
            FROM monitoring_readings
            WHERE patient_id = ?
            ORDER BY recorded_at DESC
            LIMIT 50
        ";

        $monitorStmt = $db->prepare($monitorSql);

        if ($monitorStmt) {

            $selectedId = (string)$selectedPatient["patient_id"];

            $monitorStmt->bind_param("s", $selectedId);

            if ($monitorStmt->execute()) {

                $monitorResult = $monitorStmt->get_result();

                while ($reading = $monitorResult->fetch_assoc()) {
                    $monitoringReadings[] = $reading;
                }

            } else {

                $monitoringError = "Unable to read monitoring records.";

            }

            $monitorStmt->close();

        } else {

            $monitoringError = "Unable to prepare monitoring query.";

        }

    }

}


// -------------------------------------------------------
// 12. LOAD RECENT DASHBOARD ALERTS
// -------------------------------------------------------

$patientAlerts = [];

$alertError = "";

if ($selectedPatient !== null) {

    $requiredAlertColumns = [
        "patient_id",
        "recorded_at"
    ];

    foreach ($requiredAlertColumns as $column) {

        if (!in_array($column, $alertColumns, true)) {

            $alertError =
                "The alerts table is missing column: " . $column;

            break;

        }

    }

    if ($alertError === "") {

        $alertSql = "
            SELECT *
            FROM alerts
            WHERE patient_id = ?
            ORDER BY recorded_at DESC
            LIMIT 50
        ";

        $alertStmt = $db->prepare($alertSql);

        if ($alertStmt) {

            $selectedId = (string)$selectedPatient["patient_id"];

            $alertStmt->bind_param("s", $selectedId);

            if ($alertStmt->execute()) {

                $alertResult = $alertStmt->get_result();

                while ($alert = $alertResult->fetch_assoc()) {
                    $patientAlerts[] = $alert;
                }

            } else {

                $alertError = "Unable to read alert records.";

            }

            $alertStmt->close();

        } else {

            $alertError = "Unable to prepare alert query.";

        }

    }

}


// -------------------------------------------------------
// 13. DISPLAY COLUMN VALUE SAFELY
// -------------------------------------------------------

function owner_value($row, $column, $default = "-")
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
// 14. OWNER USERNAME
// -------------------------------------------------------

$ownerUsername = $_SESSION["ravi3_owner_username"] ?? "Owner";

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Ravi3 Owner Dashboard</title>

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

.header {
    background: #17365d;
    color: white;
    padding: 18px;
    border-radius: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.header h2 {
    margin: 0;
}

.header a {
    color: white;
    text-decoration: none;
    background: #b52b2b;
    padding: 9px 14px;
    border-radius: 5px;
}

.container {
    max-width: 1200px;
    margin: 20px auto;
}

.card {
    background: white;
    padding: 18px;
    margin-bottom: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
}

h3 {
    margin-top: 0;
    color: #17365d;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 12px;
    background: white;
}

th, td {
    padding: 10px;
    border: 1px solid #ddd;
    text-align: left;
    font-size: 14px;
}

th {
    background: #e9eef5;
}

tr:nth-child(even) {
    background: #fafafa;
}

select {
    width: 100%;
    max-width: 450px;
    padding: 11px;
    border: 1px solid #bbb;
    border-radius: 5px;
    font-size: 15px;
    background: white;
}

button {
    padding: 10px 18px;
    border: none;
    border-radius: 5px;
    background: #1769aa;
    color: white;
    cursor: pointer;
    margin-top: 10px;
}

button:hover {
    background: #0d4f85;
}

.info {
    padding: 12px;
    background: #e8f2ff;
    border-radius: 5px;
    margin-top: 10px;
}

.warning {
    padding: 12px;
    background: #fff0d5;
    color: #795000;
    border-radius: 5px;
    margin-top: 10px;
}

.status-enabled {
    color: #147a35;
    font-weight: bold;
}

.status-disabled {
    color: #b52b2b;
    font-weight: bold;
}

.small {
    font-size: 13px;
    color: #666;
}

@media (max-width: 700px) {

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

    table {
        font-size: 12px;
    }

    th, td {
        padding: 7px;
    }

}

</style>

</head>

<body>

<div class="container">

    <!-- OWNER HEADER -->

    <div class="header">

        <div>
            <h2>Ravi3 Owner Dashboard</h2>

            <div class="small" style="color:white;">
                Logged in as:
                <?= owner_h($ownerUsername) ?>
            </div>
        </div>

        <a href="owner_logout.php">
            Owner Logout
        </a>

    </div>


    <!-- PATIENT LIST -->

    <div class="card">

        <h3>Registered Patients</h3>

        <p>
            Total registered patients:
            <strong><?= count($patients) ?></strong>
        </p>

        <?php if (count($patients) === 0): ?>

            <div class="warning">
                No patient records were found.
            </div>

        <?php else: ?>

            <div style="overflow-x:auto;">

                <table>

                    <thead>

                        <tr>
                            <th>Patient ID</th>
                            <th>Patient Name</th>
                            <th>Assigned Device ID</th>
                            <th>Device Status</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($patients as $patient): ?>

                        <?php

                        $patientId = (string)$patient["patient_id"];

                        $assignments =
                            $deviceAssignments[$patientId] ?? [];

                        ?>

                        <tr>

                            <td>
                                <?= owner_h($patientId) ?>
                            </td>

                            <td>
                                <?= owner_h(owner_patient_name($patient)) ?>
                            </td>

                            <td>

                                <?php if (count($assignments) === 0): ?>

                                    Not assigned

                                <?php else: ?>

                                    <?php foreach ($assignments as $assignment): ?>

                                        <?= owner_h($assignment["device_id"]) ?><br>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if (count($assignments) === 0): ?>

                                    <span class="status-disabled">
                                        No assignment
                                    </span>

                                <?php else: ?>

                                    <?php foreach ($assignments as $assignment): ?>

                                        <?php

                                        $enabled =
                                            !isset($assignment["enabled"]) ||
                                            (int)$assignment["enabled"] === 1;

                                        ?>

                                        <?php if ($enabled): ?>

                                            <span class="status-enabled">
                                                Enabled
                                            </span>

                                        <?php else: ?>

                                            <span class="status-disabled">
                                                Disabled
                                            </span>

                                        <?php endif; ?>

                                        <br>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>


    <!-- SELECT PATIENT -->

    <div class="card">

        <h3>Select Patient to View Reports</h3>

        <form method="GET" action="owner.php">

            <label for="patient_id">
                Select patient:
            </label>

            <br><br>

            <select
                name="patient_id"
                id="patient_id"
                required
            >

                <option value="">
                    -- Select Patient --
                </option>

                <?php foreach ($patients as $patient): ?>

                    <option
                        value="<?= owner_h($patient["patient_id"]) ?>"
                        <?= (
                            $selectedPatientId ===
                            (string)$patient["patient_id"]
                        ) ? "selected" : "" ?>
                    >

                        <?= owner_h(owner_patient_name($patient)) ?>
                        (<?= owner_h($patient["patient_id"]) ?>)

                    </option>

                <?php endforeach; ?>

            </select>

            <br>

            <button type="submit">
                View Patient Reports
            </button>

        </form>

    </div>


    <?php if ($selectedPatient !== null): ?>

        <!-- SELECTED PATIENT DETAILS -->

        <div class="card">

            <h3>Selected Patient Details</h3>

            <div class="info">

                <strong>Patient ID:</strong>
                <?= owner_h($selectedPatient["patient_id"]) ?>

                <br><br>

                <strong>Patient Name:</strong>
                <?= owner_h(owner_patient_name($selectedPatient)) ?>

                <br><br>

                <strong>Assigned Device IDs:</strong>

                <?php if (count($selectedDevices) === 0): ?>

                    No device assigned.

                <?php else: ?>

                    <?php foreach ($selectedDevices as $device): ?>

                        <?= owner_h($device["device_id"]) ?><br>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>


        <!-- MONITORING READINGS -->

        <div class="card">

            <h3>Recent Monitoring Readings</h3>

            <?php if ($monitoringError !== ""): ?>

                <div class="warning">
                    <?= owner_h($monitoringError) ?>
                </div>

            <?php elseif (count($monitoringReadings) === 0): ?>

                <p>No monitoring readings found for this patient.</p>

            <?php else: ?>

                <div style="overflow-x:auto;">

                    <table>

                        <thead>

                            <tr>
                                <th>Date and Time</th>
                                <th>Device</th>
                                <th>Room Temp.</th>
                                <th>Humidity</th>
                                <th>BPM</th>
                                <th>SpO2</th>
                                <th>Body Temp.</th>
                                <th>Mode</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($monitoringReadings as $reading): ?>

                            <tr>

                                <td>
                                    <?= owner_h(owner_value($reading, "recorded_at")) ?>
                                </td>

                                <td>
                                    <?= owner_h(owner_value($reading, "device_id")) ?>
                                </td>

                                <td>
                                    <?= owner_h(owner_value($reading, "room_temperature")) ?>
                                </td>

                                <td>
                                    <?= owner_h(owner_value($reading, "humidity")) ?>
                                </td>

                                <td>
                                    <?= owner_h(owner_value($reading, "bpm")) ?>
                                </td>

                                <td>
                                    <?= owner_h(owner_value($reading, "spo2")) ?>
                                </td>

                                <td>
                                    <?= owner_h(owner_value($reading, "body_temperature")) ?>
                                </td>

                                <td>
                                    <?= owner_h(owner_value($reading, "mode")) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>


        <!-- PATIENT ALERTS -->

        <div class="card">

            <h3>Patient Dashboard Alerts</h3>

            <?php if ($alertError !== ""): ?>

                <div class="warning">
                    <?= owner_h($alertError) ?>
                </div>

            <?php elseif (count($patientAlerts) === 0): ?>

                <p>No alerts found for this patient.</p>

            <?php else: ?>

                <div style="overflow-x:auto;">

                    <table>

                        <thead>

                            <tr>
                                <th>Date and Time</th>
                                <th>Device</th>
                                <th>Parameter</th>
                                <th>Measured Value</th>
                                <th>Alert Message</th>
                                <th>Status</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($patientAlerts as $alert): ?>

                            <tr>

                                <td>
                                    <?= owner_h(owner_value($alert, "recorded_at")) ?>
                                </td>

                                <td>
                                    <?= owner_h(owner_value($alert, "device_id")) ?>
                                </td>

                                <td>
                                    <?= owner_h(owner_value($alert, "parameter_name")) ?>
                                </td>

                                <td>
                                    <?= owner_h(owner_value($alert, "measured_value")) ?>
                                </td>

                                <td>
                                    <?= owner_h(owner_value($alert, "alert_message")) ?>
                                </td>

                                <td>

                                    <?php if (
                                        isset($alert["acknowledged"]) &&
                                        (int)$alert["acknowledged"] === 1
                                    ): ?>

                                        Acknowledged

                                    <?php else: ?>

                                        Pending

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>


    <div class="card">

        <p class="small">
            Ravi3 Owner Dashboard.
            Patient records, device assignments and historical
            readings are displayed without modification.
        </p>

    </div>

</div>

</body>
</html>
