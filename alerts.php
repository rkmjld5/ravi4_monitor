<?php
/*
=========================================================
 PROJECT : ravi2_patient
 FILE    : alerts.php

 PURPOSE :
 1. Display the logged-in patient's alerts.
 2. Display the latest 100 alerts.
 3. Allow the patient to acknowledge an alert.
 4. Save acknowledgement date and time.
 5. Protect the page using patient_access.php.

 IMPORTANT :
 - Web dashboard alerts only.
 - No Firebase or mobile notifications.
 - No SMS or WhatsApp.
 - No database table changes.
 - A patient can acknowledge only their own alerts.
=========================================================
*/


// -------------------------------------------------------
// 1. CHECK PATIENT LOGIN AND ACCESS
// -------------------------------------------------------

require_once __DIR__ . "/patient_access.php";


// -------------------------------------------------------
// 2. GET PATIENT INFORMATION
// -------------------------------------------------------

$patient_id   = $_SESSION["ravi2_patient_id"];
$patient_name = $_SESSION["ravi2_patient_name"];
$device_id    = $_SESSION["ravi2_device_id"];


// -------------------------------------------------------
// 3. CREATE SECURITY TOKEN
// -------------------------------------------------------

/*
 This token protects the acknowledgement form.
 It helps prevent unauthorized requests.
*/

if (empty($_SESSION["alerts_csrf_token"])) {

    $_SESSION["alerts_csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["alerts_csrf_token"];


// -------------------------------------------------------
// 4. HANDLE ACKNOWLEDGE BUTTON
// -------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $message = "";

    // Check the security token.
    $submitted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {

        $message = "Invalid request. Please refresh the page and try again.";

    } else {

        // Read the alert ID.
        $alert_id = filter_var(
            $_POST["alert_id"] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$alert_id || $alert_id < 1) {

            $message = "Invalid alert ID.";

        } else {

            /*
             Only update an alert belonging to this patient.

             acknowledged = 0 means it has not yet
             been acknowledged.

             NOW() uses the database session time zone
             set in config.php to +05:30.
            */

            $update_sql = "
                UPDATE alerts
                SET
                    acknowledged = 1,
                    acknowledged_at = NOW()
                WHERE
                    id = ?
                    AND patient_id = ?
                    AND acknowledged = 0
            ";

            $update_stmt = $conn->prepare($update_sql);

            if (!$update_stmt) {

                error_log(
                    "Alert acknowledgement prepare error: " .
                    $conn->error
                );

                $message = "Unable to acknowledge this alert. Please try again.";

            } else {

                $update_stmt->bind_param(
                    "is",
                    $alert_id,
                    $patient_id
                );

                if (!$update_stmt->execute()) {

                    error_log(
                        "Alert acknowledgement execution error: " .
                        $update_stmt->error
                    );

                    $message = "Unable to acknowledge this alert. Please try again.";

                } elseif ($update_stmt->affected_rows === 1) {

                    $message = "Alert successfully acknowledged.";

                } else {

                    $message = "This alert was already acknowledged or could not be found.";

                }

                $update_stmt->close();
            }
        }
    }

    // Save message for display after redirect.
    $_SESSION["alerts_message"] = $message;

    // Redirect to prevent accidental repeated form submission.
    header("Location: alerts.php");
    exit;
}


// -------------------------------------------------------
// 5. DISPLAY MESSAGE AFTER ACKNOWLEDGEMENT
// -------------------------------------------------------

$alert_message = "";

if (isset($_SESSION["alerts_message"])) {

    $alert_message = $_SESSION["alerts_message"];

    unset($_SESSION["alerts_message"]);
}


// -------------------------------------------------------
// 6. INITIALIZE ALERT VARIABLES
// -------------------------------------------------------

$alerts = [];

$alert_error = "";


// -------------------------------------------------------
// 7. READ LATEST 100 ALERTS
// -------------------------------------------------------

$sql = "
    SELECT
        id,
        parameter_name,
        measured_value,
        alert_message,
        recorded_at,
        device_id,
        acknowledged,
        acknowledged_at
    FROM alerts
    WHERE patient_id = ?
    ORDER BY recorded_at DESC, id DESC
    LIMIT 100
";

$stmt = $conn->prepare($sql);

if (!$stmt) {

    error_log("Alerts page SQL error: " . $conn->error);

    $alert_error = "Unable to load alerts. Please try again later.";

} else {

    $stmt->bind_param("s", $patient_id);

    if (!$stmt->execute()) {

        error_log("Alerts page execution error: " . $stmt->error);

        $alert_error = "Unable to load alerts. Please try again later.";

    } else {

        $alert_id = 0;
        $parameter_name = "";
        $measured_value = "";
        $alert_text = "";
        $recorded_at = "";
        $alert_device_id = "";
        $acknowledged = 0;
        $acknowledged_at = "";

        $stmt->bind_result(
            $alert_id,
            $parameter_name,
            $measured_value,
            $alert_text,
            $recorded_at,
            $alert_device_id,
            $acknowledged,
            $acknowledged_at
        );

        while ($stmt->fetch()) {

            $alerts[] = [
                "id" => $alert_id,
                "parameter_name" => $parameter_name,
                "measured_value" => $measured_value,
                "alert_message" => $alert_text,
                "recorded_at" => $recorded_at,
                "device_id" => $alert_device_id,
                "acknowledged" => $acknowledged,
                "acknowledged_at" => $acknowledged_at
            ];
        }
    }

    $stmt->close();
}


// -------------------------------------------------------
// 8. SAFE DISPLAY FUNCTION
// -------------------------------------------------------

function alert_escape($value)
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}


// -------------------------------------------------------
// 9. COUNT ALERTS
// -------------------------------------------------------

$total_alerts = count($alerts);

$unacknowledged_count = 0;

$acknowledged_count = 0;

foreach ($alerts as $alert) {

    if ((int)$alert["acknowledged"] === 1) {

        $acknowledged_count++;

    } else {

        $unacknowledged_count++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Patient Alerts</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        .header {
            background: #ffffff;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        h2 {
            margin-top: 0;
            color: #1f3b5b;
        }

        .patient-info {
            line-height: 1.8;
        }

        .summary {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-box {
            background: white;
            padding: 18px;
            border-radius: 8px;
            flex: 1;
            min-width: 140px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        .summary-number {
            font-size: 28px;
            font-weight: bold;
            margin-top: 8px;
        }

        .alert-table-box {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th {
            background: #24476b;
            color: white;
            padding: 12px;
            text-align: left;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
        }

        tr:hover {
            background: #f7f9fc;
        }

        .status-new {
            color: #b42318;
            font-weight: bold;
        }

        .status-acknowledged {
            color: #16803c;
            font-weight: bold;
        }

        .acknowledge-button {
            background: #16803c;
            color: white;
            border: none;
            padding: 9px 13px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
        }

        .acknowledge-button:hover {
            background: #10632e;
        }

        .message {
            background: #e8f5e9;
            color: #166534;
            padding: 14px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .error {
            background: #fff0f0;
            color: #b42318;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .no-alerts {
            padding: 25px;
            text-align: center;
            color: #16803c;
            font-size: 17px;
        }

        .navigation {
            margin-bottom: 20px;
        }

        .navigation a {
            display: inline-block;
            background: #24476b;
            color: white;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 5px;
            margin: 4px 5px 4px 0;
        }

        .navigation a:hover {
            background: #16324f;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            color: #777;
            font-size: 13px;
        }

        @media (max-width: 600px) {

            body {
                padding: 10px;
            }

            .summary-box {
                min-width: 120px;
            }
        }

    </style>

</head>


<body>

<div class="container">


    <!-- PATIENT INFORMATION -->

    <div class="header">

        <h2>Patient Alert History</h2>

        <div class="patient-info">

            <strong>Patient Name:</strong>
            <?php echo alert_escape($patient_name); ?>

            <br>

            <strong>Patient ID:</strong>
            <?php echo alert_escape($patient_id); ?>

            <br>

            <strong>Device ID:</strong>
            <?php echo alert_escape($device_id); ?>

            <br>

            <strong>Time Zone:</strong>
            Asia/Kolkata

        </div>

    </div>


    <!-- NAVIGATION -->

    <div class="navigation">

        <a href="index.php">Dashboard</a>

        <a href="history.php">Reading History</a>

        <a href="ecg_history.php">ECG History</a>

        <a href="logout.php">Logout</a>

    </div>


    <!-- ACKNOWLEDGEMENT MESSAGE -->

    <?php if ($alert_message !== ""): ?>

        <div class="message">
            <?php echo alert_escape($alert_message); ?>
        </div>

    <?php endif; ?>


    <!-- SUMMARY -->

    <div class="summary">

        <div class="summary-box">

            Total Alerts

            <div class="summary-number">
                <?php echo $total_alerts; ?>
            </div>

        </div>


        <div class="summary-box">

            Unacknowledged Alerts

            <div class="summary-number" style="color:#b42318;">

                <?php echo $unacknowledged_count; ?>

            </div>

        </div>


        <div class="summary-box">

            Acknowledged Alerts

            <div class="summary-number" style="color:#16803c;">

                <?php echo $acknowledged_count; ?>

            </div>

        </div>

    </div>


    <!-- DATABASE ERROR -->

    <?php if ($alert_error !== ""): ?>

        <div class="error">
            <?php echo alert_escape($alert_error); ?>
        </div>

    <?php endif; ?>


    <!-- ALERT TABLE -->

    <div class="alert-table-box">

        <h3>Latest Patient Alerts</h3>

        <?php if ($alert_error === "" && $total_alerts === 0): ?>

            <div class="no-alerts">
                No alerts are available for this patient.
            </div>

        <?php elseif ($alert_error === ""): ?>

            <table>

                <thead>

                    <tr>

                        <th>Alert ID</th>
                        <th>Date / Time</th>
                        <th>Parameter</th>
                        <th>Measured Value</th>
                        <th>Alert Message</th>
                        <th>Device ID</th>
                        <th>Status / Action</th>

                    </tr>

                </thead>


                <tbody>

                    <?php foreach ($alerts as $alert): ?>

                        <tr>

                            <td>
                                <?php echo alert_escape($alert["id"]); ?>
                            </td>

                            <td>
                                <?php echo alert_escape($alert["recorded_at"]); ?>
                            </td>

                            <td>
                                <strong>
                                    <?php
                                    echo alert_escape(
                                        $alert["parameter_name"]
                                    );
                                    ?>
                                </strong>
                            </td>

                            <td>
                                <?php
                                echo alert_escape(
                                    $alert["measured_value"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo alert_escape(
                                    $alert["alert_message"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo alert_escape(
                                    $alert["device_id"]
                                );
                                ?>
                            </td>

                            <td>

                                <?php if (
                                    (int)$alert["acknowledged"] === 1
                                ): ?>

                                    <span class="status-acknowledged">
                                        Acknowledged
                                    </span>

                                    <?php if (
                                        !empty($alert["acknowledged_at"])
                                    ): ?>

                                        <br><br>

                                        <small>
                                            Acknowledged at:<br>
                                            <?php
                                            echo alert_escape(
                                                $alert["acknowledged_at"]
                                            );
                                            ?>
                                        </small>

                                    <?php endif; ?>

                                <?php else: ?>

                                    <span class="status-new">
                                        Not Acknowledged
                                    </span>

                                    <br><br>

                                    <!-- ACKNOWLEDGE BUTTON -->

                                    <form method="POST"
                                          action="alerts.php">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php
                                            echo alert_escape($csrf_token);
                                            ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="alert_id"
                                            value="<?php
                                            echo alert_escape($alert["id"]);
                                            ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="acknowledge-button"
                                            onclick="return confirm('Are you sure you want to acknowledge this alert?');"
                                        >
                                            Acknowledge
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>


    <!-- FOOTER -->

    <div class="footer">

        Patient Monitoring System

        <br>

        All displayed times use Indian Standard Time.

    </div>


</div>

</body>

</html>
