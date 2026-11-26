<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: alerts.php

PURPOSE:
1. Display patient dashboard alerts.
2. Show abnormal parameter and measured value.
3. Show alert date and time.
4. Show pending/acknowledged status.
5. Allow patient to acknowledge an alert.

IMPORTANT:
- Alerts are not deleted.
- No Firebase notifications.
- No mobile push notifications.
- No SMS or WhatsApp.
- Only the logged-in patient's assigned device
  alerts are displayed.
=========================================================
*/


// -------------------------------------------------------
// 1. VERIFY PATIENT ACCESS
// -------------------------------------------------------

require_once __DIR__ . "/patient_access.php";


// -------------------------------------------------------
// 2. PREPARE MESSAGE VARIABLES
// -------------------------------------------------------

$successMessage = "";
$errorMessage = "";


// -------------------------------------------------------
// 3. PROCESS ALERT ACKNOWLEDGEMENT
// -------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $alertId = filter_input(
        INPUT_POST,
        "alert_id",
        FILTER_VALIDATE_INT
    );

    $csrfToken = $_POST["csrf_token"] ?? "";

    // Check the CSRF token.
    if (
        empty($_SESSION["ravi3_csrf_token"]) ||
        !is_string($csrfToken) ||
        !hash_equals($_SESSION["ravi3_csrf_token"], $csrfToken)
    ) {

        $errorMessage = "Invalid request. Please refresh the page.";

    } elseif (!$alertId || $alertId < 1) {

        $errorMessage = "Invalid alert selected.";

    } else {

        // Update only an alert belonging to this patient
        // and the device assigned to the current session.
        //
        // Acknowledged alerts are not deleted.

        $updateSql = "
            UPDATE alerts
            SET acknowledged = 1,
                acknowledged_at = NOW()
            WHERE id = ?
              AND patient_id = ?
              AND device_id = ?
              AND acknowledged = 0
        ";

        $updateStmt = $db->prepare($updateSql);

        if (!$updateStmt) {

            error_log("Ravi3 alert update error: " . $db->error);

            $errorMessage = "Unable to acknowledge the alert.";

        } else {

            $updateStmt->bind_param(
                "iss",
                $alertId,
                $patient_id,
                $device_id
            );

            if ($updateStmt->execute()) {

                if ($updateStmt->affected_rows > 0) {

                    $successMessage = "Alert acknowledged successfully.";

                } else {

                    $errorMessage =
                        "Alert was not found or has already been acknowledged.";

                }

            } else {

                error_log(
                    "Ravi3 alert update execution error: " .
                    $updateStmt->error
                );

                $errorMessage = "Unable to update the alert.";

            }

            $updateStmt->close();
        }
    }
}


// -------------------------------------------------------
// 4. CREATE CSRF TOKEN
// -------------------------------------------------------

if (empty($_SESSION["ravi3_csrf_token"])) {

    $_SESSION["ravi3_csrf_token"] = bin2hex(random_bytes(32));

}


// -------------------------------------------------------
// 5. GET PATIENT ALERTS
// -------------------------------------------------------

$alerts = [];

$alertSql = "
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
      AND device_id = ?
    ORDER BY recorded_at DESC, id DESC
    LIMIT 200
";

$alertStmt = $db->prepare($alertSql);

if (!$alertStmt) {

    error_log("Ravi3 alerts query error: " . $db->error);

    die("Unable to load patient alerts.");

}

$alertStmt->bind_param(
    "ss",
    $patient_id,
    $device_id
);

$alertStmt->execute();

$alertResult = $alertStmt->get_result();

while ($row = $alertResult->fetch_assoc()) {

    $alerts[] = $row;

}

$alertStmt->close();


// -------------------------------------------------------
// 6. COUNT PENDING AND ACKNOWLEDGED ALERTS
// -------------------------------------------------------

$pendingCount = 0;
$acknowledgedCount = 0;

foreach ($alerts as $alert) {

    if ((int)$alert["acknowledged"] === 1) {

        $acknowledgedCount++;

    } else {

        $pendingCount++;

    }

}


// -------------------------------------------------------
// 7. SAFE DISPLAY FUNCTION
// -------------------------------------------------------

function ravi3_alert_h($value)
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

    <title>Ravi3 Patient Alerts</title>

    <style>

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

        .summary {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(180px, 1fr)
            );
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: white;
            padding: 18px;
            border-radius: 8px;
            box-shadow: 0 2px 7px rgba(0,0,0,0.08);
        }

        .summary-card h3 {
            margin: 0 0 10px;
            color: #526575;
            font-size: 15px;
        }

        .summary-number {
            font-size: 27px;
            font-weight: bold;
        }

        .pending-number {
            color: #b00020;
        }

        .ack-number {
            color: #18733b;
        }

        .message {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 6px;
        }

        .success {
            background: #e4f6e9;
            color: #176b35;
        }

        .error {
            background: #ffe5e5;
            color: #a00000;
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
            min-width: 850px;
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

        .pending {
            color: #b00020;
            font-weight: bold;
        }

        .acknowledged {
            color: #18733b;
            font-weight: bold;
        }

        .ack-button {
            background: #1769aa;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
        }

        .ack-button:hover {
            background: #104d80;
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

        <h2>Patient Alerts</h2>

        <p>
            Patient:
            <strong>
                <?php echo ravi3_alert_h($patient_name); ?>
            </strong>
        </p>

        <p>
            Patient ID:
            <?php echo ravi3_alert_h($patient_id); ?>
        </p>

        <p>
            Device ID:
            <?php echo ravi3_alert_h($device_id); ?>
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
         SUCCESS AND ERROR MESSAGES
    ------------------------------------------------ -->

    <?php if ($successMessage !== ""): ?>

        <div class="message success">

            <?php echo ravi3_alert_h($successMessage); ?>

        </div>

    <?php endif; ?>


    <?php if ($errorMessage !== ""): ?>

        <div class="message error">

            <?php echo ravi3_alert_h($errorMessage); ?>

        </div>

    <?php endif; ?>


    <!-- -----------------------------------------------
         ALERT SUMMARY
    ------------------------------------------------ -->

    <div class="summary">

        <div class="summary-card">

            <h3>Total Alerts Displayed</h3>

            <div class="summary-number">
                <?php echo count($alerts); ?>
            </div>

        </div>


        <div class="summary-card">

            <h3>Pending Alerts</h3>

            <div class="summary-number pending-number">
                <?php echo $pendingCount; ?>
            </div>

        </div>


        <div class="summary-card">

            <h3>Acknowledged Alerts</h3>

            <div class="summary-number ack-number">
                <?php echo $acknowledgedCount; ?>
            </div>

        </div>

    </div>


    <!-- -----------------------------------------------
         ALERT TABLE
    ------------------------------------------------ -->

    <h3>Alert Details</h3>

    <?php if (count($alerts) > 0): ?>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>

                        <th>Alert ID</th>

                        <th>Date / Time</th>

                        <th>Parameter</th>

                        <th>Measured Value</th>

                        <th>Alert Message</th>

                        <th>Status</th>

                        <th>Action</th>

                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($alerts as $alert): ?>

                        <tr>

                            <td>
                                <?php
                                echo (int)$alert["id"];
                                ?>
                            </td>

                            <td>
                                <?php
                                echo ravi3_alert_h(
                                    $alert["recorded_at"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo ravi3_alert_h(
                                    $alert["parameter_name"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo ravi3_alert_h(
                                    $alert["measured_value"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo ravi3_alert_h(
                                    $alert["alert_message"]
                                );
                                ?>
                            </td>

                            <td>

                                <?php if ((int)$alert["acknowledged"] === 1): ?>

                                    <span class="acknowledged">
                                        Acknowledged
                                    </span>

                                    <?php if (!empty($alert["acknowledged_at"])): ?>

                                        <br>

                                        <small>
                                            <?php
                                            echo ravi3_alert_h(
                                                $alert["acknowledged_at"]
                                            );
                                            ?>
                                        </small>

                                    <?php endif; ?>

                                <?php else: ?>

                                    <span class="pending">
                                        Pending
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if ((int)$alert["acknowledged"] !== 1): ?>

                                    <form
                                        method="POST"
                                        action="alerts.php"
                                    >

                                        <input
                                            type="hidden"
                                            name="alert_id"
                                            value="<?php
                                            echo (int)$alert["id"];
                                            ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php
                                            echo ravi3_alert_h(
                                                $_SESSION["ravi3_csrf_token"]
                                            );
                                            ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="ack-button"
                                        >
                                            Acknowledge
                                        </button>

                                    </form>

                                <?php else: ?>

                                    --

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php else: ?>

        <div class="no-data">

            No alerts are available for this patient and device.

        </div>

    <?php endif; ?>


    <!-- -----------------------------------------------
         FOOTER
    ------------------------------------------------ -->

    <div class="footer">

        Ravi3 Patient Monitoring System

        <br>

        Acknowledging an alert does not delete it.

    </div>

</div>

</body>
</html>
