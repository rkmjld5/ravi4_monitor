<?php
/*
    ECG HISTORY PAGE
    Project: ravi2_patient

    This page:
    1. Checks that the patient is logged in and authorized.
    2. Reads ECG records from ecg_readings.
    3. Allows searching by start and end DATE AND TIME.
    4. Displays ECG sample rate and sample count.
    5. Draws the ECG waveform when valid samples are available.

    The page does not change any database records.
*/

// Check patient login and current authorization first.
require_once "patient_access.php";

date_default_timezone_set("Asia/Kolkata");

// Patient and device details from the verified session.
$patientId = $_SESSION["ravi2_patient_id"];
$patientName = $_SESSION["ravi2_patient_name"];
$deviceId = $_SESSION["ravi2_device_id"];

// --------------------------------------------------
// DATE AND TIME SEARCH
// --------------------------------------------------

$startInput = trim($_GET["start_datetime"] ?? "");
$endInput   = trim($_GET["end_datetime"] ?? "");

$startDateTime = "1000-01-01 00:00:00";
$endDateTime   = "9999-12-31 23:59:59";

$errorMessage = "";

// Convert a datetime-local value into MySQL datetime.
function convertDateTime($value)
{
    if ($value === "") {
        return null;
    }

    $date = DateTime::createFromFormat("!Y-m-d\TH:i", $value);

    if (!$date || $date->format("Y-m-d\TH:i") !== $value) {
        return false;
    }

    return $date->format("Y-m-d H:i:s");
}

if ($startInput !== "") {
    $converted = convertDateTime($startInput);

    if ($converted === false) {
        $errorMessage = "Please enter a valid start date and time.";
    } else {
        $startDateTime = $converted;
    }
}

if ($endInput !== "" && $errorMessage === "") {
    $converted = convertDateTime($endInput);

    if ($converted === false) {
        $errorMessage = "Please enter a valid end date and time.";
    } else {
        $endDateTime = $converted;
    }
}

if (
    $errorMessage === "" &&
    $startDateTime > $endDateTime
) {
    $errorMessage = "Start date and time cannot be later than end date and time.";
}

// --------------------------------------------------
// GET ECG RECORDS
// --------------------------------------------------

$records = [];

if ($errorMessage === "") {

    /*
       We use both patient_id and device_id.
       This ensures that the patient sees only ECG
       records belonging to their assigned device.
    */

    $sql = "
        SELECT
            id,
            samples_json,
            mode,
            recorded_at,
            sample_rate,
            sample_count
        FROM ecg_readings
        WHERE patient_id = ?
          AND device_id = ?
          AND recorded_at >= ?
          AND recorded_at <= ?
        ORDER BY recorded_at DESC, id DESC
        LIMIT 20
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        $errorMessage = "Unable to prepare the ECG history query.";
    } else {

        mysqli_stmt_bind_param(
            $stmt,
            "ssss",
            $patientId,
            $deviceId,
            $startDateTime,
            $endDateTime
        );

        if (!mysqli_stmt_execute($stmt)) {
            $errorMessage = "Unable to retrieve ECG records.";
        } else {

            mysqli_stmt_bind_result(
                $stmt,
                $id,
                $samplesJson,
                $mode,
                $recordedAt,
                $sampleRate,
                $sampleCount
            );

            while (mysqli_stmt_fetch($stmt)) {

                // Store each ECG record for display.
                $records[] = [
                    "id" => $id,
                    "samples_json" => $samplesJson,
                    "mode" => $mode,
                    "recorded_at" => $recordedAt,
                    "sample_rate" => $sampleRate,
                    "sample_count" => $sampleCount
                ];
            }
        }

        mysqli_stmt_close($stmt);
    }
}

// --------------------------------------------------
// PREPARE ECG SAMPLES FOR WAVEFORM DISPLAY
// --------------------------------------------------

function getEcgSamples($json)
{
    $data = json_decode($json, true);

    if (!is_array($data)) {
        return [];
    }

    // Normally samples_json contains a direct numeric array.
    // Also support common named array formats.
    if (isset($data["ecg_samples"]) && is_array($data["ecg_samples"])) {
        $data = $data["ecg_samples"];
    } elseif (isset($data["samples"]) && is_array($data["samples"])) {
        $data = $data["samples"];
    } elseif (isset($data["data"]) && is_array($data["data"])) {
        $data = $data["data"];
    }

    $samples = [];

    foreach ($data as $value) {

        // Keep only numeric ECG sample values.
        if (is_numeric($value)) {
            $samples[] = (float)$value;
        }
    }

    return $samples;
}

// --------------------------------------------------
// HTML PAGE
// --------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>ECG History</title>

<style>
body {
    font-family: Arial, sans-serif;
    background: #f2f5f9;
    margin: 0;
    padding: 15px;
    color: #222;
}

.container {
    max-width: 1100px;
    margin: auto;
}

.header {
    background: #ffffff;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 18px;
}

.header h2 {
    margin-top: 0;
    color: #174a75;
}

.navigation {
    margin-top: 15px;
}

.navigation a {
    display: inline-block;
    margin: 5px 8px 5px 0;
    padding: 9px 14px;
    background: #174a75;
    color: white;
    text-decoration: none;
    border-radius: 5px;
}

.navigation a:hover {
    background: #103653;
}

.search-box {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 18px;
}

.search-box h3 {
    margin-top: 0;
}

.search-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.search-field {
    flex: 1;
    min-width: 220px;
}

.search-field label {
    display: block;
    margin-bottom: 7px;
    font-weight: bold;
}

.search-field input {
    width: 100%;
    padding: 10px;
    border: 1px solid #bbb;
    border-radius: 5px;
    box-sizing: border-box;
}

button {
    padding: 11px 20px;
    border: none;
    background: #16804a;
    color: white;
    border-radius: 5px;
    cursor: pointer;
    margin-top: 15px;
}

button:hover {
    background: #105d35;
}

.clear-button {
    display: inline-block;
    margin-left: 8px;
    padding: 11px 15px;
    background: #666;
    color: white;
    text-decoration: none;
    border-radius: 5px;
}

.record {
    background: white;
    padding: 18px;
    border-radius: 8px;
    margin-bottom: 18px;
    overflow: hidden;
}

.record h3 {
    margin-top: 0;
    color: #174a75;
}

.record-details {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 15px;
}

.detail {
    background: #f1f5f8;
    padding: 10px;
    border-radius: 5px;
}

.waveform {
    width: 100%;
    height: 160px;
    display: block;
    background: #fffafa;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.no-waveform {
    color: #777;
    padding: 15px;
    background: #f7f7f7;
    border-radius: 5px;
}

.error {
    background: #ffe8e8;
    color: #a00000;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.success {
    background: #e8f5e9;
    color: #17652a;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.empty {
    background: white;
    padding: 25px;
    text-align: center;
    border-radius: 8px;
    color: #666;
}

@media (max-width: 600px) {
    body {
        padding: 8px;
    }

    .record {
        padding: 12px;
    }

    .waveform {
        height: 130px;
    }
}
</style>
</head>

<body>

<div class="container">

    <div class="header">

        <h2>ECG History</h2>

        <p>
            <strong>Patient:</strong>
            <?php echo htmlspecialchars($patientName); ?>
        </p>

        <p>
            <strong>Patient ID:</strong>
            <?php echo htmlspecialchars($patientId); ?>
        </p>

        <p>
            <strong>Device ID:</strong>
            <?php echo htmlspecialchars($deviceId); ?>
        </p>

        <div class="navigation">
            <a href="index.php">Dashboard</a>
            <a href="history.php">Vital History</a>
            <a href="alerts.php">Alerts</a>
            <a href="logout.php">Logout</a>
        </div>

    </div>

    <div class="search-box">

        <h3>Search ECG by Date and Time</h3>

        <form method="GET" action="ecg_history.php">

            <div class="search-row">

                <div class="search-field">
                    <label for="start_datetime">
                        Start Date and Time
                    </label>

                    <input
                        type="datetime-local"
                        id="start_datetime"
                        name="start_datetime"
                        value="<?php echo htmlspecialchars($startInput); ?>"
                    >
                </div>

                <div class="search-field">
                    <label for="end_datetime">
                        End Date and Time
                    </label>

                    <input
                        type="datetime-local"
                        id="end_datetime"
                        name="end_datetime"
                        value="<?php echo htmlspecialchars($endInput); ?>"
                    >
                </div>

            </div>

            <button type="submit">Search ECG</button>

            <a class="clear-button" href="ecg_history.php">
                Show All
            </a>

        </form>

        <p>
            Leave both fields blank to search all available ECG records.
            The page displays the latest 20 matching records.
        </p>

    </div>

    <?php if ($errorMessage !== ""): ?>

        <div class="error">
            <?php echo htmlspecialchars($errorMessage); ?>
        </div>

    <?php else: ?>

        <div class="success">
            ECG records found: <?php echo count($records); ?>
        </div>

        <?php if (count($records) === 0): ?>

            <div class="empty">
                No ECG records found for this date and time range.
            </div>

        <?php else: ?>

            <?php foreach ($records as $record): ?>

                <?php
                    $samples = getEcgSamples($record["samples_json"]);

                    /*
                       Limit waveform data to 2000 samples per record
                       to keep the browser display manageable.
                    */
                    $displaySamples = array_slice($samples, 0, 2000);

                    $samplesForJavascript = json_encode(
                        $displaySamples,
                        JSON_HEX_TAG |
                        JSON_HEX_APOS |
                        JSON_HEX_QUOT |
                        JSON_HEX_AMP
                    );
                ?>

                <div class="record">

                    <h3>
                        ECG Record #<?php
                            echo htmlspecialchars((string)$record["id"]);
                        ?>
                    </h3>

                    <div class="record-details">

                        <div class="detail">
                            <strong>Recorded At:</strong><br>
                            <?php
                                echo htmlspecialchars(
                                    (string)$record["recorded_at"]
                                );
                            ?>
                        </div>

                        <div class="detail">
                            <strong>Sample Rate:</strong><br>
                            <?php
                                echo htmlspecialchars(
                                    (string)($record["sample_rate"] ?? "N/A")
                                );
                            ?>
                            Hz
                        </div>

                        <div class="detail">
                            <strong>Sample Count:</strong><br>
                            <?php
                                echo htmlspecialchars(
                                    (string)($record["sample_count"] ?? count($samples))
                                );
                            ?>
                        </div>

                        <div class="detail">
                            <strong>Mode:</strong><br>
                            <?php
                                echo htmlspecialchars(
                                    (string)($record["mode"] ?? "N/A")
                                );
                            ?>
                        </div>

                    </div>

                    <strong>ECG Waveform</strong>

                    <?php if (count($displaySamples) > 0): ?>

                        <canvas
                            class="waveform"
                            data-values="<?php
                                echo htmlspecialchars(
                                    $samplesForJavascript,
                                    ENT_QUOTES,
                                    "UTF-8"
                                );
                            ?>"
                        ></canvas>

                        <p>
                            <small>
                                Displaying <?php
                                    echo count($displaySamples);
                                ?> numeric ECG samples.
                            </small>
                        </p>

                    <?php else: ?>

                        <div class="no-waveform">
                            ECG samples could not be displayed.
                            The stored JSON does not contain a supported
                            numeric sample array.
                        </div>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
// Draw each ECG waveform on its canvas.
document.querySelectorAll(".waveform").forEach(function(canvas) {

    let samples = [];

    try {
        samples = JSON.parse(canvas.dataset.values);
    } catch (error) {
        return;
    }

    if (!Array.isArray(samples) || samples.length < 2) {
        return;
    }

    const context = canvas.getContext("2d");

    // Use the actual displayed canvas size.
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;

    // Make the drawing clear on high-resolution screens.
    const pixelRatio = window.devicePixelRatio || 1;

    canvas.width = width * pixelRatio;
    canvas.height = height * pixelRatio;

    context.scale(pixelRatio, pixelRatio);

    // Find the minimum and maximum ECG values.
    let minimum = Math.min(...samples);
    let maximum = Math.max(...samples);

    // Avoid division by zero when all values are equal.
    if (minimum === maximum) {
        minimum -= 1;
        maximum += 1;
    }

    const padding = 12;
    const usableHeight = height - (padding * 2);
    const usableWidth = width - (padding * 2);

    // Draw a light ECG grid.
    context.strokeStyle = "#f0dede";
    context.lineWidth = 0.5;

    for (let x = 0; x < width; x += 20) {
        context.beginPath();
        context.moveTo(x, 0);
        context.lineTo(x, height);
        context.stroke();
    }

    for (let y = 0; y < height; y += 20) {
        context.beginPath();
        context.moveTo(0, y);
        context.lineTo(width, y);
        context.stroke();
    }

    // Draw the ECG waveform.
    context.beginPath();
    context.strokeStyle = "#cc2222";
    context.lineWidth = 1.5;

    samples.forEach(function(value, index) {

        const x = padding +
            (index / (samples.length - 1)) * usableWidth;

        const y = padding +
            ((maximum - value) / (maximum - minimum)) * usableHeight;

        if (index === 0) {
            context.moveTo(x, y);
        } else {
            context.lineTo(x, y);
        }
    });

    context.stroke();

});
</script>

</body>
</html>
