<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: history.php

PURPOSE:
1. Display historical patient monitoring readings.
2. Show room temperature and humidity.
3. Show BPM and SpO2.
4. Show body temperature.
5. Show reading date, time and device mode.

Only the logged-in patient's assigned device readings
are displayed.

No Firebase or mobile push notification code.
=========================================================
*/


// -------------------------------------------------------
// 1. VERIFY PATIENT ACCESS
// -------------------------------------------------------

require_once __DIR__ . "/patient_access.php";


// -------------------------------------------------------
// 2. GET HISTORICAL READINGS
// -------------------------------------------------------

// Change the LIMIT if you want to display more or fewer
// historical readings.

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
    LIMIT 500
";

$stmt = $db->prepare($sql);

if (!$stmt) {
    error_log("Ravi3 history query error: " . $db->error);
    die("Unable to load patient history.");
}

$stmt->bind_param("ss", $patient_id, $device_id);

$stmt->execute();

$result = $stmt->get_result();

$readings = [];

while ($row = $result->fetch_assoc()) {
    $readings[] = $row;
}

$stmt->close();


// -------------------------------------------------------
// 3. SAFE DISPLAY FUNCTION
// -------------------------------------------------------

function ravi3_history_h($value)
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Ravi3 Reading History</title>

    <style>

        body {
            margin: 0;
            padding: 15px;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #263238;
        }

        .container {
            max-width: 1200px;
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
            margin-top: 0;
        }

        .navigation a {
            display: inline-block;
            background: white;
            color: #174a75;
            padding: 9px 13px;
            margin: 5px 4px 0 0;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
        }

        .navigation a:hover {
            background: #dceaf5;
        }

        .info {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            box-shadow: 0 2px 7px rgba(0,0,0,0.08);
        }

        .table-wrapper {
            overflow-x: auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 7px rgba(0,0,0,0.08);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        th, td {
            padding: 11px;
            text-align: center;
            border-bottom: 1px solid #e3e8ee;
            font-size: 14px;
        }

        th {
            background: #e8f0f7;
            color: #174a75;
            position: sticky;
            top: 0;
        }

        tr:hover {
            background: #f5f9fc;
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
         PAGE HEADER
    ------------------------------------------------ -->

    <div class="header">

        <h2>Patient Reading History</h2>

        <p>
            Patient:
            <strong>
                <?php echo ravi3_history_h($patient_name); ?>
            </strong>
        </p>

        <p>
            Patient ID:
            <?php echo ravi3_history_h($patient_id); ?>
        </p>

        <p>
            Device ID:
            <?php echo ravi3_history_h($device_id); ?>
        </p>

        <div class="navigation">

            <a href="index.php">Dashboard</a>

            <a href="history.php">Reading History</a>

            <a href="ecg_history.php">ECG History</a>

            <a href="alerts.php">Alerts</a>

            <a href="logout.php">Logout</a>

        </div>

    </div>


    <!-- -----------------------------------------------
         HISTORY INFORMATION
    ------------------------------------------------ -->

    <div class="info">

        <strong>Total records displayed:</strong>

        <?php echo count($readings); ?>

        <br><br>

        <strong>Display order:</strong>

        Newest reading first.

        <br><br>

        <strong>Maximum records:</strong>

        500

    </div>


    <!-- -----------------------------------------------
         READING TABLE
    ------------------------------------------------ -->

    <?php if (count($readings) > 0): ?>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>Record ID</th>

                        <th>Date / Time</th>

                        <th>Room Temp (°C)</th>

                        <th>Humidity (%)</th>

                        <th>BPM</th>

                        <th>SpO2 (%)</th>

                        <th>Body Temp (°C)</th>

                        <th>Mode</th>

                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($readings as $row): ?>

                        <tr>

                            <td>
                                <?php
                                echo ravi3_history_h($row["id"]);
                                ?>
                            </td>

                            <td>
                                <?php
                                echo ravi3_history_h($row["recorded_at"]);
                                ?>
                            </td>

                            <td>
                                <?php
                                echo ravi3_history_h(
                                    $row["room_temperature"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo ravi3_history_h(
                                    $row["humidity"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo ravi3_history_h($row["bpm"]);
                                ?>
                            </td>

                            <td>
                                <?php
                                echo ravi3_history_h($row["spo2"]);
                                ?>
                            </td>

                            <td>
                                <?php
                                echo ravi3_history_h(
                                    $row["body_temperature"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo ravi3_history_h($row["mode"]);
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php else: ?>

        <div class="no-data">

            No historical monitoring readings were found
            for this patient and device.

        </div>

    <?php endif; ?>


    <!-- -----------------------------------------------
         FOOTER
    ------------------------------------------------ -->

    <div class="footer">

        Ravi3 Patient Monitoring System

    </div>

</div>

</body>
</html>
