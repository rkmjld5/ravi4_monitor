<?php
/*
    PROJECT: ravi2_patient
    FILE: history.php

    PURPOSE:
    Display historical monitoring readings for the logged-in patient.

    FEATURES:
    1. Search by starting DATE AND TIME.
    2. Search by ending DATE AND TIME.
    3. Search using only a start or end date/time if required.
    4. Display BPM, SpO2, temperatures and humidity.
    5. Display the latest 500 matching records.
    6. Only show records belonging to the logged-in patient
       and assigned device.

    DATABASE TABLE:
    monitoring_readings

    No database records are changed by this program.
*/

// --------------------------------------------------
// 1. CHECK PATIENT LOGIN AND ACCESS PERMISSION
// --------------------------------------------------

require_once __DIR__ . "/patient_access.php";

// Patient information comes from the verified session.
$patient_id   = $_SESSION["ravi2_patient_id"];
$patient_name = $_SESSION["ravi2_patient_name"];
$device_id    = $_SESSION["ravi2_device_id"];

// --------------------------------------------------
// 2. VARIABLES FOR DATE AND TIME SEARCH
// --------------------------------------------------

$start_input = trim($_GET["start_datetime"] ?? "");
$end_input   = trim($_GET["end_datetime"] ?? "");

$error_message = "";
$readings = [];

// Default search range:
// If the user leaves a field empty, use a very wide date range.
$start_datetime = "1000-01-01 00:00:00";
$end_datetime   = "9999-12-31 23:59:59";

// --------------------------------------------------
// 3. VALIDATE START DATE AND TIME
// --------------------------------------------------

function history_validate_datetime($value) {

    if ($value === "") {
        return null;
    }

    // datetime-local normally sends YYYY-MM-DDTHH:MM.
    $date = DateTime::createFromFormat("!Y-m-d\TH:i", $value);

    if (!$date || $date->format("Y-m-d\TH:i") !== $value) {
        return false;
    }

    return $date->format("Y-m-d H:i:s");
}

// Check the starting date and time.
$validated_start = history_validate_datetime($start_input);

// Check the ending date and time.
$validated_end = history_validate_datetime($end_input);

if ($validated_start === false) {

    $error_message = "Please enter a valid starting date and time.";

} elseif ($validated_end === false) {

    $error_message = "Please enter a valid ending date and time.";

} else {

    if ($validated_start !== null) {
        $start_datetime = $validated_start;
    }

    if ($validated_end !== null) {
        $end_datetime = $validated_end;
    }

    // Starting date/time must not be later than ending date/time.
    if ($start_datetime > $end_datetime) {

        $error_message =
            "Starting date and time cannot be later than ending date and time.";
    }
}

// --------------------------------------------------
// 4. SEARCH MONITORING READINGS
// --------------------------------------------------

if ($error_message === "") {

    /*
       Both date and time are checked.

       Example:
       Start: 2026-11-24 09:30:00
       End:   2026-11-24 11:45:00

       Only readings between these two times are displayed.
    */

    $sql = "
        SELECT
            id,
            room_temperature,
            humidity,
            bpm,
            spo2,
            body_temperature,
            mode,
            recorded_at
        FROM monitoring_readings
        WHERE
            patient_id = ?
            AND device_id = ?
            AND recorded_at >= ?
            AND recorded_at <= ?
        ORDER BY recorded_at DESC, id DESC
        LIMIT 500
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {

        error_log("History query prepare error: " . $conn->error);

        $error_message = "Unable to prepare the history search.";

    } else {

        $stmt->bind_param(
            "ssss",
            $patient_id,
            $device_id,
            $start_datetime,
            $end_datetime
        );

        if (!$stmt->execute()) {

            error_log("History query execution error: " . $stmt->error);

            $error_message = "Unable to retrieve monitoring history.";

        } else {

            $stmt->bind_result(
                $reading_id,
                $room_temperature,
                $humidity,
                $bpm,
                $spo2,
                $body_temperature,
                $mode,
                $recorded_at
            );

            while ($stmt->fetch()) {

                $readings[] = [
                    "id" => $reading_id,
                    "room_temperature" => $room_temperature,
                    "humidity" => $humidity,
                    "bpm" => $bpm,
                    "spo2" => $spo2,
                    "body_temperature" => $body_temperature,
                    "mode" => $mode,
                    "recorded_at" => $recorded_at
                ];
            }
        }

        $stmt->close();
    }
}

// --------------------------------------------------
// 5. SAFE OUTPUT FUNCTION
// --------------------------------------------------

function history_escape($value) {

    if ($value === null || $value === "") {
        return "--";
    }

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Monitoring History</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f1f4f8;
            margin: 0;
            color: #263238;
        }

        .header {
            background: #1769aa;
            color: white;
            padding: 18px 20px;
        }

        .header h1 {
            margin: 0;
            font-size: 23px;
        }

        .container {
            width: 96%;
            max-width: 1250px;
            margin: 22px auto;
        }

        .patient-info {
            background: white;
            padding: 15px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .search-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }

        .search-box h2 {
            margin-top: 0;
            color: #1769aa;
            font-size: 20px;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
            font-size: 14px;
        }

        input[type="datetime-local"] {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 15px;
        }

        .search-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }

        button,
        .button-link {
            display: inline-block;
            border: none;
            border-radius: 5px;
            padding: 11px 17px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }

        .search-button {
            background: #1769aa;
            color: white;
        }

        .reset-button {
            background: #687782;
            color: white;
        }

        .search-button:hover {
            background: #10558c;
        }

        .reset-button:hover {
            background: #4d5b64;
        }

        .result-info {
            background: white;
            padding: 15px;
            border-radius: 7px;
            margin-bottom: 15px;
        }

        .error {
            background: #ffe6e6;
            color: #a00000;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .table-container {
            background: white;
            border-radius: 8px;
            overflow-x: auto;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }

        table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
        }

        th {
            background: #1769aa;
            color: white;
            padding: 12px 10px;
            text-align: center;
            font-size: 13px;
            white-space: nowrap;
        }

        td {
            padding: 11px 10px;
            border-bottom: 1px solid #e5e5e5;
            text-align: center;
            font-size: 13px;
            white-space: nowrap;
        }

        tr:hover {
            background: #f2f7fc;
        }

        .navigation {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 22px 0;
        }

        .navigation a {
            background: #1769aa;
            color: white;
            padding: 11px 16px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 14px;
        }

        .navigation a:hover {
            background: #10558c;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 12px;
        }

        @media (max-width: 650px) {

            .search-grid {
                grid-template-columns: 1fr;
            }

            .header h1 {
                font-size: 19px;
            }
        }

    </style>

</head>

<body>

    <!-- PAGE HEADER -->

    <div class="header">

        <h1>Monitoring Reading History</h1>

    </div>


    <div class="container">

        <!-- PATIENT INFORMATION -->

        <div class="patient-info">

            <strong>Patient:</strong>
            <?php echo history_escape($patient_name); ?>

            &nbsp; | &nbsp;

            <strong>Patient ID:</strong>
            <?php echo history_escape($patient_id); ?>

            &nbsp; | &nbsp;

            <strong>Device:</strong>
            <?php echo history_escape($device_id); ?>

        </div>


        <!-- DATE AND TIME SEARCH -->

        <div class="search-box">

            <h2>Search Readings by Date and Time</h2>

            <form method="GET" action="history.php">

                <div class="search-grid">

                    <div>

                        <label for="start_datetime">
                            Starting Date and Time
                        </label>

                        <input
                            type="datetime-local"
                            id="start_datetime"
                            name="start_datetime"
                            value="<?php echo history_escape($start_input); ?>"
                        >

                    </div>


                    <div>

                        <label for="end_datetime">
                            Ending Date and Time
                        </label>

                        <input
                            type="datetime-local"
                            id="end_datetime"
                            name="end_datetime"
                            value="<?php echo history_escape($end_input); ?>"
                        >

                    </div>

                </div>


                <div class="search-buttons">

                    <button
                        type="submit"
                        class="search-button"
                    >
                        Search
                    </button>

                    <a
                        href="history.php"
                        class="button-link reset-button"
                    >
                        Show All Records
                    </a>

                </div>

            </form>

            <p style="color:#666;font-size:13px;margin-bottom:0;">

                Enter both date and time for a specific time period.
                You may also enter only a starting or ending date and time.

            </p>

        </div>


        <!-- SEARCH RESULTS -->

        <?php if ($error_message !== ""): ?>

            <div class="error">
                <?php echo history_escape($error_message); ?>
            </div>

        <?php else: ?>

            <div class="result-info">

                <strong>Matching Records:</strong>
                <?php echo count($readings); ?>

                <br><br>

                <strong>Search Period:</strong>

                <?php
                    echo ($start_input !== "")
                        ? history_escape($start_input)
                        : "Beginning of available records";
                ?>

                &nbsp; to &nbsp;

                <?php
                    echo ($end_input !== "")
                        ? history_escape($end_input)
                        : "Latest available records";
                ?>

                <?php if (count($readings) === 500): ?>

                    <p style="color:#996600;font-size:13px;">
                        Showing the latest 500 matching records.
                        Narrow the date and time range to see a smaller period.
                    </p>

                <?php endif; ?>

            </div>


            <div class="table-container">

                <table>

                    <thead>

                        <tr>

                            <th>Sl. No.</th>

                            <th>Date and Time</th>

                            <th>Heart Rate<br>(BPM)</th>

                            <th>SpO2<br>(%)</th>

                            <th>Body Temp<br>(°C)</th>

                            <th>Room Temp<br>(°C)</th>

                            <th>Humidity<br>(%)</th>

                            <th>Mode</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (count($readings) === 0): ?>

                            <tr>

                                <td colspan="8">
                                    No readings found for the selected date and time.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php $serial_number = 1; ?>

                            <?php foreach ($readings as $row): ?>

                                <tr>

                                    <td>
                                        <?php echo $serial_number++; ?>
                                    </td>

                                    <td>
                                        <?php echo history_escape($row["recorded_at"]); ?>
                                    </td>

                                    <td>
                                        <?php echo history_escape($row["bpm"]); ?>
                                    </td>

                                    <td>
                                        <?php echo history_escape($row["spo2"]); ?>
                                    </td>

                                    <td>
                                        <?php echo history_escape($row["body_temperature"]); ?>
                                    </td>

                                    <td>
                                        <?php echo history_escape($row["room_temperature"]); ?>
                                    </td>

                                    <td>
                                        <?php echo history_escape($row["humidity"]); ?>
                                    </td>

                                    <td>
                                        <?php echo history_escape($row["mode"]); ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>


        <!-- NAVIGATION -->

        <div class="navigation">

            <a href="index.php">
                Dashboard
            </a>

            <a href="ecg_history.php">
                ECG History
            </a>

            <a href="alerts.php">
                Alerts
            </a>

            <a href="logout.php">
                Logout
            </a>

        </div>

    </div>


    <div class="footer">

        Ravi2 Patient Monitoring System

    </div>

</body>

</html>
