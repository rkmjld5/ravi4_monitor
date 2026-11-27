<?php
/*
=========================================================
RAVI4 PATIENT MONITORING SYSTEM
File: ecg_history.php

PURPOSE:
1. Display historical ECG recordings.
2. Show patient ID and device ID for every recording.
3. Show recording date, sample rate and sample count.
4. Display ECG waveform as a continuous line graph.
5. Download ECG sample data as CSV.
6. Download ECG graph as PNG.
7. Print / save ECG graph as PDF.

SECURITY:
- Uses patient_access.php.
- Gets patient ID and device ID from the authenticated session.
- Retrieves records using BOTH patient_id and device_id.
- Never retrieves records using device_id alone.

DATABASE TABLE:
ecg_readings
=========================================================
*/


// -------------------------------------------------------
// 1. VERIFY RAVI4 PATIENT ACCESS
// -------------------------------------------------------

require_once __DIR__ . "/patient_access.php";


// -------------------------------------------------------
// 2. VERIFY DATABASE CONNECTION
// -------------------------------------------------------

if (!isset($db) || !($db instanceof mysqli)) {
    error_log("Ravi4 ECG history: Database connection unavailable.");
    die("Database connection is unavailable.");
}


// -------------------------------------------------------
// 3. VERIFY PATIENT AND DEVICE ID
// -------------------------------------------------------

$patient_id = trim((string)$patient_id);
$device_id  = trim((string)$device_id);
$patient_name = trim((string)$patient_name);

if ($patient_id === "" || $device_id === "") {
    error_log("Ravi4 ECG history: Missing patient or device identity.");
    die("Patient access information is incomplete.");
}


// -------------------------------------------------------
// 4. GET ONLY THIS PATIENT'S ECG RECORDINGS
// -------------------------------------------------------

// IMPORTANT:
// Both patient_id and device_id are compulsory filters.
//
// Example:
// P1004 + ESP32-01 can see only P1004 records from ESP32-01.
// P1001 + ESP32-01 can see only P1001 records from ESP32-01.
//
// Even if both patients share ESP32-01, their histories
// remain separate.

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
    error_log("Ravi4 ECG history prepare error: " . $db->error);
    die("Unable to load ECG history.");
}

$stmt->bind_param("ss", $patient_id, $device_id);

if (!$stmt->execute()) {
    error_log("Ravi4 ECG history execute error: " . $stmt->error);
    $stmt->close();
    die("Unable to load ECG history.");
}

$result = $stmt->get_result();

$recordings = [];

while ($row = $result->fetch_assoc()) {

    // Extra safety check.
    // Do not display a row belonging to another patient
    // or another controller.

    if (
        $row["patient_id"] !== $patient_id ||
        $row["device_id"] !== $device_id
    ) {
        continue;
    }

    $recordings[] = $row;
}

$stmt->close();


// -------------------------------------------------------
// 5. SAFE DISPLAY FUNCTION
// -------------------------------------------------------

function ravi4_ecg_h($value)
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}


// -------------------------------------------------------
// 6. PREPARE ECG DATA FOR JAVASCRIPT
// -------------------------------------------------------

$ecgData = [];

foreach ($recordings as $recording) {

    $samples = json_decode(
        $recording["samples_json"] ?? "",
        true
    );

    if (!is_array($samples)) {
        $samples = [];
    }

    $validSamples = [];

    foreach ($samples as $sample) {

        if (is_numeric($sample) && is_finite((float)$sample)) {
            $validSamples[] = (float)$sample;
        }
    }

    $ecgData[] = [
        "id" => (int)$recording["id"],
        "patient_id" => (string)$recording["patient_id"],
        "device_id" => (string)$recording["device_id"],
        "date" => (string)$recording["recorded_at"],
        "mode" => (string)$recording["mode"],
        "sample_rate" => (int)$recording["sample_rate"],
        "sample_count" => (int)$recording["sample_count"],
        "actual_samples" => count($validSamples),
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

    <title>Ravi4 ECG History</title>

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
            line-height: 1.8;
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

        .identity {
            background: #edf5fb;
            padding: 10px;
            border-radius: 5px;
            margin: 12px 0;
            line-height: 1.8;
            font-size: 14px;
        }

        .no-data {
            background: white;
            padding: 20px;
            border-radius: 8px;
            color: #667788;
        }

        .download-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }

        .download-buttons button {
            border: none;
            padding: 11px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            color: white;
        }

        .download-buttons button:hover {
            opacity: 0.85;
        }

        .csv-button {
            background: #16834a;
        }

        .png-button {
            background: #1769aa;
        }

        .pdf-button {
            background: #bd3434;
        }

        .download-note {
            color: #667788;
            font-size: 12px;
            margin-top: 10px;
        }

        .sample-warning {
            color: #a35b00;
            font-size: 13px;
            margin-top: 8px;
        }

        .footer {
            text-align: center;
            color: #778899;
            font-size: 13px;
            margin: 25px 0;
        }

        @media (max-width: 600px) {

            .download-buttons {
                flex-direction: column;
            }

            .download-buttons button {
                width: 100%;
            }

        }

    </style>

</head>

<body>

<div class="container">


    <!-- PAGE HEADER -->

    <div class="header">

        <h2>Patient ECG History</h2>

        <p>
            Patient:
            <strong>
                <?php echo ravi4_ecg_h($patient_name); ?>
            </strong>
        </p>

        <p>
            Patient ID:
            <?php echo ravi4_ecg_h($patient_id); ?>
        </p>

        <p>
            Device ID:
            <?php echo ravi4_ecg_h($device_id); ?>
        </p>

        <div class="navigation">

            <a href="index.php">Dashboard</a>

            <a href="history.php">Reading History</a>

            <a href="ecg_history.php">ECG History</a>

            <a href="alerts.php">Alerts</a>

            <a href="logout.php">Logout</a>

        </div>

    </div>


    <!-- RECORDING INFORMATION -->

    <div class="info">

        <strong>Logged-in patient:</strong>
        <?php echo ravi4_ecg_h($patient_id); ?>

        <br>

        <strong>Assigned controller:</strong>
        <?php echo ravi4_ecg_h($device_id); ?>

        <br>

        <strong>Total ECG recordings displayed:</strong>
        <?php echo count($recordings); ?>

        <br>

        Maximum records displayed: 50

        <br>

        Newest recording appears first.

        <br>

        Every recording is filtered by patient ID and device ID.

    </div>


    <!-- ECG RECORDINGS -->

    <?php if (count($recordings) > 0): ?>

        <?php foreach ($recordings as $index => $recording): ?>

            <div class="record-card">

                <h3>
                    ECG Recording #<?php echo (int)$recording["id"]; ?>
                </h3>

                <!-- RECORD IDENTITY -->

                <div class="identity">

                    <strong>Patient ID:</strong>
                    <?php echo ravi4_ecg_h($recording["patient_id"]); ?>

                    <br>

                    <strong>Device ID:</strong>
                    <?php echo ravi4_ecg_h($recording["device_id"]); ?>

                </div>


                <!-- RECORDING DETAILS -->

                <div class="metadata">

                    <strong>Date / Time:</strong>

                    <?php
                    echo ravi4_ecg_h($recording["recorded_at"]);
                    ?>

                    <br>

                    <strong>Sample Rate:</strong>

                    <?php
                    echo ravi4_ecg_h($recording["sample_rate"]);
                    ?>

                    samples/second

                    <br>

                    <strong>Stored Sample Count:</strong>

                    <?php
                    echo ravi4_ecg_h($recording["sample_count"]);
                    ?>

                    <br>

                    <strong>Decoded Sample Count:</strong>

                    <?php
                    echo count($ecgData[$index]["samples"]);
                    ?>

                    <br>

                    <strong>Mode:</strong>

                    <?php
                    echo ravi4_ecg_h($recording["mode"]);
                    ?>

                </div>


                <?php
                $samples = $ecgData[$index]["samples"];
                $recordingId = (int)$recording["id"];
                ?>

                <?php if (
                    count($samples) > 1 &&
                    $ecgData[$index]["sample_count"] === count($samples)
                ): ?>

                    <!-- ECG GRAPH -->

                    <canvas
                        id="ecg_canvas_<?php echo $recordingId; ?>"
                        width="1000"
                        height="220">
                    </canvas>


                    <!-- DOWNLOAD BUTTONS -->

                    <div class="download-buttons">

                        <button
                            type="button"
                            class="csv-button"
                            onclick="downloadECGCSV(<?php echo $recordingId; ?>)">
                            Download CSV
                        </button>

                        <button
                            type="button"
                            class="png-button"
                            onclick="downloadECGPNG(<?php echo $recordingId; ?>)">
                            Download PNG
                        </button>

                        <button
                            type="button"
                            class="pdf-button"
                            onclick="downloadECGPDF(<?php echo $recordingId; ?>)">
                            Download PDF
                        </button>

                    </div>

                    <div class="download-note">
                        PDF opens a print-ready report.
                        Select "Save as PDF" in the print window.
                    </div>

                <?php else: ?>

                    <p class="sample-warning">
                        ECG waveform is unavailable or its decoded
                        sample count does not match the stored count.
                        This recording is not displayed as a graph.
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


    <!-- FOOTER -->

    <div class="footer">

        Ravi4 Patient Monitoring System

    </div>

</div>


<script>

// -------------------------------------------------------
// ECG DATA FROM THE AUTHENTICATED PATIENT'S RECORDS
// -------------------------------------------------------

const ECG_RECORDINGS = <?php

echo json_encode(
    $ecgData,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP |
    JSON_INVALID_UTF8_SUBSTITUTE
);

?>;


// -------------------------------------------------------
// DRAW ECG LINE GRAPHS
// -------------------------------------------------------

function drawECGGraph(recording) {

    const samples = recording.samples;

    const canvas = document.getElementById(
        "ecg_canvas_" + recording.id
    );

    if (!canvas || samples.length < 2) {
        return;
    }

    const ctx = canvas.getContext("2d");

    const width = canvas.width;
    const height = canvas.height;

    ctx.clearRect(0, 0, width, height);

    // ECG grid.

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

    // Minimum and maximum values.

    let minValue = samples[0];
    let maxValue = samples[0];

    for (let i = 1; i < samples.length; i++) {

        if (samples[i] < minValue) {
            minValue = samples[i];
        }

        if (samples[i] > maxValue) {
            maxValue = samples[i];
        }

    }

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

}


// Draw all graphs when page loads.

document.addEventListener("DOMContentLoaded", function () {

    ECG_RECORDINGS.forEach(function (recording) {

        drawECGGraph(recording);

    });

});


// -------------------------------------------------------
// FIND RECORDING BY ID
// -------------------------------------------------------

function getECGRecording(recordingId) {

    return ECG_RECORDINGS.find(function (recording) {

        return recording.id === recordingId;

    });

}


// -------------------------------------------------------
// DOWNLOAD ECG DATA AS CSV
// -------------------------------------------------------

function downloadECGCSV(recordingId) {

    const recording = getECGRecording(recordingId);

    if (!recording || recording.samples.length === 0) {

        alert("ECG sample data is not available.");

        return;
    }

    let csv = "";

    csv += "Sample Number,Time (seconds),ECG Value\n";

    recording.samples.forEach(function (value, index) {

        let time = "";

        if (recording.sample_rate > 0) {
            time = (index / recording.sample_rate).toFixed(6);
        }

        csv +=
            (index + 1) + "," +
            time + "," +
            value + "\n";

    });

    const blob = new Blob(
        ["\uFEFF" + csv],
        { type: "text/csv;charset=utf-8;" }
    );

    const url = URL.createObjectURL(blob);

    const link = document.createElement("a");

    link.href = url;

    link.download =
        "ECG_" +
        recording.patient_id + "_" +
        recording.device_id + "_" +
        recordingId + ".csv";

    document.body.appendChild(link);

    link.click();

    document.body.removeChild(link);

    URL.revokeObjectURL(url);

}


// -------------------------------------------------------
// DOWNLOAD ECG GRAPH AS PNG
// -------------------------------------------------------

function downloadECGPNG(recordingId) {

    const recording = getECGRecording(recordingId);

    if (!recording) {

        alert("ECG recording not found.");

        return;
    }

    const canvas = document.getElementById(
        "ecg_canvas_" + recordingId
    );

    if (!canvas) {

        alert("ECG graph is not available.");

        return;
    }

    const imageURL = canvas.toDataURL("image/png");

    const link = document.createElement("a");

    link.href = imageURL;

    link.download =
        "ECG_" +
        recording.patient_id + "_" +
        recording.device_id + "_" +
        recordingId + ".png";

    document.body.appendChild(link);

    link.click();

    document.body.removeChild(link);

}


// -------------------------------------------------------
// PRINT / SAVE ECG GRAPH AS PDF
// -------------------------------------------------------

function downloadECGPDF(recordingId) {

    const recording = getECGRecording(recordingId);

    if (!recording) {

        alert("ECG recording not found.");

        return;
    }

    const canvas = document.getElementById(
        "ecg_canvas_" + recordingId
    );

    if (!canvas) {

        alert("ECG graph is not available.");

        return;
    }

    const printWindow = window.open("", "_blank");

    if (!printWindow) {

        alert(
            "The print window was blocked. " +
            "Please allow pop-ups for this website and try again."
        );

        return;
    }

    const graphImage = canvas.toDataURL("image/png");

    function escapeHTML(value) {

        return String(value ?? "").replace(
            /[&<>"']/g,
            function (character) {

                const replacements = {
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#039;"
                };

                return replacements[character];

            }
        );

    }

    const reportHTML = `
        <!DOCTYPE html>
        <html>
        <head>

            <meta charset="UTF-8">

            <title>ECG Recording ${recordingId}</title>

            <style>

                body {
                    font-family: Arial, sans-serif;
                    padding: 25px;
                    color: #263238;
                }

                h2 {
                    color: #174a75;
                    text-align: center;
                }

                .details {
                    line-height: 1.9;
                    margin-bottom: 25px;
                }

                img {
                    width: 100%;
                    border: 1px solid #ccc;
                }

                .footer {
                    margin-top: 30px;
                    text-align: center;
                    font-size: 12px;
                    color: #777;
                }

                @media print {
                    body {
                        padding: 10px;
                    }
                }

            </style>

        </head>

        <body>

            <h2>Patient ECG Report</h2>

            <div class="details">

                <strong>Patient:</strong>
                ${escapeHTML(<?php echo json_encode($patient_name); ?>)}
                <br>

                <strong>Patient ID:</strong>
                ${escapeHTML(recording.patient_id)}
                <br>

                <strong>Device ID:</strong>
                ${escapeHTML(recording.device_id)}
                <br>

                <strong>Recording ID:</strong>
                ${recording.id}
                <br>

                <strong>Date / Time:</strong>
                ${escapeHTML(recording.date)}
                <br>

                <strong>Sample Rate:</strong>
                ${recording.sample_rate} samples/second
                <br>

                <strong>Sample Count:</strong>
                ${recording.sample_count}
                <br>

                <strong>Mode:</strong>
                ${escapeHTML(recording.mode)}

            </div>

            <h3>ECG Waveform</h3>

            <img src="${graphImage}" alt="ECG Graph">

            <div class="footer">
                Ravi4 Patient Monitoring System
            </div>

        </body>
        </html>
    `;

    printWindow.document.open();

    printWindow.document.write(reportHTML);

    printWindow.document.close();

    printWindow.onload = function () {

        printWindow.focus();

        printWindow.print();

    };

}

</script>

</body>
</html>
