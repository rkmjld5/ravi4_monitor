<?php
/*
=========================================================
PROJECT: RAVI4 PATIENT MONITORING SYSTEM
FILE: index.php

MAIN PATIENT DASHBOARD

IMPORTANT SHARED DEVICE RULE:

ESP32-01 can be assigned to multiple patients.

P1001 + ESP32-01 = P1001 records only.
P1004 + ESP32-01 = P1004 records only.

Every monitoring and alert query must check BOTH:
1. patient_id
2. device_id

No records are deleted or modified.
No ESP32 firmware changes are required.
=========================================================
*/


// -------------------------------------------------------
// 1. VERIFY PATIENT ACCESS
// -------------------------------------------------------

require_once __DIR__ . "/patient_access.php";

/*
   patient_access.php must verify the login session.

   We explicitly take the patient identity from the
   Ravi4 patient session to avoid using a device-only
   patient selection.
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    empty($_SESSION["ravi4_patient_logged_in"]) ||
    empty($_SESSION["ravi4_patient_id"]) ||
    empty($_SESSION["ravi4_device_id"])
) {
    header("Location: login.php");
    exit;
}

// Use the patient ID and device ID belonging to this login.
$patient_id = $_SESSION["ravi4_patient_id"];
$device_id  = $_SESSION["ravi4_device_id"];

$patient_name = $_SESSION["ravi4_patient_name"] ?? "";

// Check database connection.
if (!isset($db) || !($db instanceof mysqli)) {
    die("Database connection is not available.");
}


// -------------------------------------------------------
// 2. GET LATEST MONITORING READING FOR THIS PATIENT
// -------------------------------------------------------

/*
   VERY IMPORTANT:

   Do not remove the patient_id condition.

   Example:
   P1001 login -> only P1001 readings.
   P1004 login -> only P1004 readings.

   Both patients may use ESP32-01.
*/

$sql = "
    SELECT
        id,
        patient_id,
        device_id,
        room_temperature,
        humidity,
        bpm,
        spo2,
        body_temperature,
        mode,
        recorded_at
    FROM monitoring_readings
    WHERE patient_id = ?
      AND device_id = ?
    ORDER BY recorded_at DESC, id DESC
    LIMIT 1
";

$stmt = $db->prepare($sql);

if (!$stmt) {
    error_log("Ravi4 dashboard reading query error: " . $db->error);
    die("Unable to load monitoring readings.");
}

$stmt->bind_param("ss", $patient_id, $device_id);
$stmt->execute();

$result = $stmt->get_result();
$reading = $result->fetch_assoc();

$stmt->close();


// -------------------------------------------------------
// 3. GET LATEST ALERTS FOR THIS PATIENT
// -------------------------------------------------------

$alerts = [];

$alertSql = "
    SELECT
        id,
        patient_id,
        parameter_name,
        measured_value,
        alert_message,
        recorded_at,
        device_id,
        acknowledged,
        acknowledged_at
    FROM alerts
    WHERE patient_id = ?
      AND device_id = ?
    ORDER BY recorded_at DESC, id DESC
    LIMIT 10
";

$alertStmt = $db->prepare($alertSql);

if (!$alertStmt) {

    error_log("Ravi4 dashboard alert query error: " . $db->error);

} else {

    $alertStmt->bind_param("ss", $patient_id, $device_id);
    $alertStmt->execute();

    $alertResult = $alertStmt->get_result();

    while ($row = $alertResult->fetch_assoc()) {
        $alerts[] = $row;
    }

    $alertStmt->close();
}


// -------------------------------------------------------
// 4. HELPER TO DISPLAY SAFE VALUES
// -------------------------------------------------------

function ravi4_h($value)
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}


// -------------------------------------------------------
// 5. FORMAT READING VALUES
// -------------------------------------------------------

function ravi4_reading($reading, $field, $unit)
{
    if (
        !$reading ||
        !isset($reading[$field]) ||
        $reading[$field] === null
    ) {
        return "--";
    }

    return ravi4_h($reading[$field]) . " " . $unit;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Ravi4 Patient Dashboard</title>

    <meta http-equiv="refresh" content="5">

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 15px;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #263238;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        .header {
            background: #174a75;
            color: white;
            padding: 20px;
            border-radius: 9px;
            margin-bottom: 20px;
        }

        .header h2 {
            margin: 0 0 10px 0;
        }

        .header p {
            margin: 5px 0;
        }

        .navigation {
            margin-top: 15px;
        }

        .navigation a {
            display: inline-block;
            background: white;
            color: #174a75;
            padding: 9px 13px;
            margin: 4px 3px 0 0;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
        }

        .navigation a:hover {
            background: #dceaf5;
        }

        .section-title {
            margin: 22px 0 12px;
            color: #174a75;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(190px, 1fr)
            );
            gap: 15px;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 9px;
            box-shadow: 0 2px 7px rgba(0,0,0,0.08);
        }

        .card h3 {
            margin: 0 0 12px;
            color: #526575;
            font-size: 16px;
        }

        .value {
            font-size: 27px;
            font-weight: bold;
            color: #174a75;
        }

        .small {
            font-size: 13px;
            color: #667788;
            margin-top: 10px;
        }

        .table-wrapper {
            overflow-x: auto;
            background: white;
            border-radius: 9px;
            box-shadow: 0 2px 7px rgba(0,0,0,0.08);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e3e8ee;
            font-size: 14px;
        }

        th {
            background: #e8f0f7;
            color: #174a75;
        }

        .alert-pending {
            color: #b00020;
            font-weight: bold;
        }

        .alert-acknowledged {
            color: #18733b;
            font-weight: bold;
        }

        .no-data {
            background: white;
            padding: 20px;
            border-radius: 8px;
            color: #667788;
        }

        .footer {
            text-align: center;
            color: #778899;
            font-size: 13px;
            margin: 25px 0;
        }

    </style>

</head>

<body>

<div class="container">


    <!-- -----------------------------------------------
         PATIENT HEADER
    ------------------------------------------------ -->

    <div class="header">

        <h2>Patient Monitoring Dashboard</h2>

        <p>
            Patient:
            <strong><?php echo ravi4_h($patient_name); ?></strong>
        </p>

        <p>
            Patient ID:
            <strong><?php echo ravi4_h($patient_id); ?></strong>
        </p>

        <p>
            Device ID:
            <strong><?php echo ravi4_h($device_id); ?></strong>
        </p>

        <div class="navigation">

            <a href="index.php">Dashboard</a>

            <a href="history.php">Reading History</a>

            <a href="ecg_history.php">ECG History</a>

            <a href="alerts.php">All Alerts</a>

            <a href="logout.php">Logout</a>

        </div>

    </div>


    <!-- -----------------------------------------------
         LATEST READINGS
    ------------------------------------------------ -->

    <h3 class="section-title">Latest Patient Readings</h3>

    <?php if ($reading): ?>

        <div class="grid">

            <div class="card">

                <h3>Room Temperature</h3>

                <div class="value">
                    <?php
                    echo ravi4_reading(
                        $reading,
                        "room_temperature",
                        "°C"
                    );
                    ?>
                </div>

            </div>


            <div class="card">

                <h3>Humidity</h3>

                <div class="value">
                    <?php
                    echo ravi4_reading(
                        $reading,
                        "humidity",
                        "%"
                    );
                    ?>
                </div>

            </div>


            <div class="card">

                <h3>Heart Rate</h3>

                <div class="value">
                    <?php
                    echo ravi4_reading(
                        $reading,
                        "bpm",
                        "BPM"
                    );
                    ?>
                </div>

            </div>


            <div class="card">

                <h3>SpO2</h3>

                <div class="value">
                    <?php
                    echo ravi4_reading(
                        $reading,
                        "spo2",
                        "%"
                    );
                    ?>
                </div>

            </div>


            <div class="card">

                <h3>Body Temperature</h3>

                <div class="value">
                    <?php
                    echo ravi4_reading(
                        $reading,
                        "body_temperature",
                        "°C"
                    );
                    ?>
                </div>

            </div>

        </div>


        <div class="card" style="margin-top:15px;">

            <strong>Last Reading:</strong>

            <?php echo ravi4_h($reading["recorded_at"]); ?>

            <br><br>

            <strong>Device Mode:</strong>

            <?php echo ravi4_h($reading["mode"]); ?>

            <br><br>

            <strong>Reading Patient ID:</strong>

            <?php echo ravi4_h($reading["patient_id"]); ?>

        </div>

    <?php else: ?>

        <div class="no-data">
            No monitoring readings are available for this patient
            and device yet.
        </div>

    <?php endif; ?>


    <!-- -----------------------------------------------
         RECENT DASHBOARD ALERTS
    ------------------------------------------------ -->

    <h3 class="section-title">Recent Alerts</h3>

    <?php if (count($alerts) > 0): ?>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>
                        <th>Date / Time</th>
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
                            <?php
                            echo ravi4_h($alert["recorded_at"]);
                            ?>
                        </td>

                        <td>
                            <?php
                            echo ravi4_h($alert["parameter_name"]);
                            ?>
                        </td>

                        <td>
                            <?php
                            echo ravi4_h($alert["measured_value"]);
                            ?>
                        </td>

                        <td>
                            <?php
                            echo ravi4_h($alert["alert_message"]);
                            ?>
                        </td>

                        <td>

                            <?php if ((int)$alert["acknowledged"] === 1): ?>

                                <span class="alert-acknowledged">
                                    Acknowledged
                                </span>

                            <?php else: ?>

                                <span class="alert-pending">
                                    Pending
                                </span>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php else: ?>

        <div class="no-data">
            No alerts are available for this patient.
        </div>

    <?php endif; ?>


    <!-- -----------------------------------------------
         FOOTER
    ------------------------------------------------ -->

    <div class="footer">

        Ravi4 Patient Monitoring System

        <br>

        Dashboard refreshes automatically every 5 seconds.

    </div>

</div>

</body>
</html>
