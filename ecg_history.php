<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: ecg_history.php

PURPOSE:
1. Display historical ECG recordings.
2. Show recording date and time.
3. Show sample rate and sample count.
4. Display ECG waveform.
5. Restrict access to the logged-in patient's device.

IMPORTANT:
This version assumes ECG data is stored in:
    ecg_readings

Expected columns:
    id
    patient_id
    device_id
    samples_json
    mode
    recorded_at
    sample_rate
    sample_count

No Firebase or mobile push notification code.
=========================================================
*/


// -------------------------------------------------------
// 1. VERIFY PATIENT ACCESS
// -------------------------------------------------------

require_once __DIR__ . "/patient_access.php";


// -------------------------------------------------------
// 2. GET ECG RECORDINGS
// -------------------------------------------------------

$sql = "
    SELECT
        id,
        patient_id,
        device_id,
        samples_json,
        mode,
        recorded_at,
        sample_rate,
        sample_count
    FROM ecg_readings
    WHERE patient_id = ?
      AND device_id = ?
    ORDER BY recorded_at DESC, id DESC
    LIMIT 50
";

$stmt = $db->prepare($sql);

if (!$stmt) {
    error_log("Ravi3 ECG history query error: " . $db->error);
    die("Unable to load ECG history.");
}

$stmt->bind_param("ss", $patient_id, $device_id);

$stmt->execute();

$result = $stmt->get_result();

$recordings = [];

while ($row = $result->fetch_assoc()) {
    $recordings[] = $row;
}

$stmt->close();


// -------------------------------------------------------
// 3. SAFE DISPLAY FUNCTION
// -------------------------------------------------------

function ravi3_ecg_h($value)
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}


// -------------------------------------------------------
// 4. PREPARE ECG DATA FOR JAVASCRIPT
// -------------------------------------------------------

$ecgData = [];

foreach ($recordings as $recording) {

    $samples = json_decode(
        $recording["samples_json"] ?? "",
        true
    );

    // Accept only a JSON array of numeric ECG samples.
    if (!is_array($samples)) {
        $samples = [];
    }

    $validSamples = [];

    foreach ($samples as $sample) {

        if (is_numeric($sample)) {

            $validSamples[] = (float)$sample;

        }
    }

    $ecgData[] = [
        "id" => (int)$recording["id"],
        "date" => $recording["recorded_at"],
        "mode" => $recording["mode"],
        "sample_rate" => (int)$recording["sample_rate"],
        "sample_count" => (int)$recording["sample_count"],
        "samples" => $validSamples
    ];
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Ravi3 ECG History</title>

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

        .info {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .record-card {
            background: white;
            padding: 18px;
            border-radius: 8px;
            margin-bottom: 18px;
            box-shadow: 0 2px 7px rgba(0,0,0,0.08);
        }

        .record-card h3 {
            color: #174a75;
            margin-top: 0;
        }

        canvas {
            width: 100%;
            height: 220px;
            border: 1px solid #d5dce3;
            border-radius: 5px;
            background: #fffafa;
            display: block;
            margin-top: 12px;
        }

        .metadata {
            line-height: 1.8;
            color: #465563;
            font-size: 14px;
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

        <h2>Patient ECG History</h2>

        <p>
            Patient:
            <strong>
                <?php echo ravi3_ecg_h($patient_name); ?>
            </strong>
        </p>

        <p>
            Patient ID:
            <?php echo ravi3_ecg_h($patient_id); ?>
        </p>

        <p>
            Device ID:
            <?php echo ravi3_ecg_h($device_id); ?>
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
         RECORDING INFORMATION
    ------------------------------------------------ -->

    <div class="info">

        <strong>Total ECG recordings displayed:</strong>

        <?php echo count($recordings); ?>

        <br><br>

        Maximum records displayed: 50

        <br><br>

        Newest recording appears first.

    </div>


    <!-- -----------------------------------------------
         ECG RECORDINGS
    ------------------------------------------------ -->

    <?php if (count($recordings) > 0): ?>

        <?php foreach ($recordings as $index => $recording): ?>

            <div class="record-card">

                <h3>
                    ECG Recording
                    #<?php echo (int)$recording["id"]; ?>
                </h3>

                <div class="metadata">

                    <strong>Date / Time:</strong>

                    <?php
                    echo ravi3_ecg_h($recording["recorded_at"]);
                    ?>

                    <br>

                    <strong>Sample Rate:</strong>

                    <?php
                    echo ravi3_ecg_h($recording["sample_rate"]);
                    ?>

                    samples/second

                    <br>

                    <strong>Sample Count:</strong>

                    <?php
                    echo ravi3_ecg_h($recording["sample_count"]);
                    ?>

                    <br>

                    <strong>Mode:</strong>

                    <?php
                    echo ravi3_ecg_h($recording["mode"]);
                    ?>

                </div>


                <?php
                $samples = $ecgData[$index]["samples"];
                ?>

                <?php if (count($samples) > 1): ?>

                    <canvas
                        id="ecg_canvas_<?php echo (int)$recording["id"]; ?>"
                        width="1000"
                        height="220">
                    </canvas>

                    <script>
                    (function () {

                        const samples = <?php
                            echo json_encode(
                                $samples,
                                JSON_HEX_TAG |
                                JSON_HEX_APOS |
                                JSON_HEX_QUOT |
                                JSON_HEX_AMP
                            );
                        ?>;

                        const canvas = document.getElementById(
                            "ecg_canvas_<?php
                            echo (int)$recording["id"];
                            ?>"
                        );

                        const ctx = canvas.getContext("2d");

                        const width = canvas.width;
                        const height = canvas.height;

                        // Draw a light ECG grid.
                        ctx.strokeStyle = "#f1dede";
                        ctx.lineWidth = 1;

                        for (let x = 0; x < width; x += 20) {

                            ctx.beginPath();
                            ctx.moveTo(x, 0);
                            ctx.lineTo(x, height);
                            ctx.stroke();

                        }

                        for (let y = 0; y < height; y += 20) {

                            ctx.beginPath();
                            ctx.moveTo(0, y);
                            ctx.lineTo(width, y);
                            ctx.stroke();

                        }

                        if (!samples.length) {
                            return;
                        }

                        let minValue = Math.min(...samples);
                        let maxValue = Math.max(...samples);

                        // Prevent division by zero for flat recordings.
                        if (maxValue === minValue) {
                            maxValue = minValue + 1;
                        }

                        const padding = 10;

                        ctx.strokeStyle = "#d00000";
                        ctx.lineWidth = 1.5;
                        ctx.beginPath();

                        for (let i = 0; i < samples.length; i++) {

                            const x =
                                padding +
                                (i / (samples.length - 1)) *
                                (width - padding * 2);

                            const y =
                                height - padding -
                                (
                                    (samples[i] - minValue) /
                                    (maxValue - minValue)
                                ) *
                                (height - padding * 2);

                            if (i === 0) {
                                ctx.moveTo(x, y);
                            } else {
                                ctx.lineTo(x, y);
                            }

                        }

                        ctx.stroke();

                    })();
                    </script>

                <?php else: ?>

                    <p>
                        ECG waveform data is not available
                        for this recording.
                    </p>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    <?php else: ?>

        <div class="no-data">

            No ECG recordings were found for this patient
            and device.

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
