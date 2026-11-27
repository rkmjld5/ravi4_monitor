<?php
/*
=========================================================
RAVI4 PATIENT MONITORING SYSTEM
File: api_save_readings.php

PURPOSE:
1. Receive readings from ESP32.
2. Authenticate the ESP32 using API token.
3. Identify the owner-selected patient for the exact device_id.
4. Check device status and access dates.
5. Save monitoring readings.
6. Save optional ECG samples.
7. Generate dashboard alerts for abnormal readings.

NOT INCLUDED:
- Firebase
- Mobile push notifications
- SMS
- WhatsApp

Database: patient_monitor
Connection: config.php (MySQLi)
=========================================================
*/


// -------------------------------------------------------
// 1. LOAD DATABASE CONNECTION
// -------------------------------------------------------

require_once __DIR__ . "/config.php";

header("Content-Type: application/json; charset=utf-8");


// -------------------------------------------------------
// 2. HELPER FUNCTION FOR JSON RESPONSE
// -------------------------------------------------------

function ravi3_response($success, $message, $extra = [])
{
    echo json_encode(
        array_merge(
            [
                "success" => $success,
                "message" => $message
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


// -------------------------------------------------------
// 3. ACCEPT ONLY POST REQUESTS
// -------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);

    ravi3_response(false, "POST request required.");

}


// -------------------------------------------------------
// 4. VERIFY API TOKEN
// -------------------------------------------------------

// Configure API_TOKEN in the Ravi4 Render environment.
//
// ESP32 must send this token in the HTTP header:
// X-API-TOKEN: your_secret_token

$expectedToken = getenv("API_TOKEN");

$receivedToken = $_SERVER["HTTP_X_API_TOKEN"] ?? "";

// Also support Authorization: Bearer token.
if ($receivedToken === "" && !empty($_SERVER["HTTP_AUTHORIZATION"])) {

    $authorization = trim($_SERVER["HTTP_AUTHORIZATION"]);

    if (stripos($authorization, "Bearer ") === 0) {
        $receivedToken = trim(substr($authorization, 7));
    }
}

if (!$expectedToken || !$receivedToken ||
    !hash_equals($expectedToken, $receivedToken)) {

    http_response_code(401);

    ravi3_response(false, "Invalid API token.");

}


// -------------------------------------------------------
// 5. READ ESP32 JSON DATA
// -------------------------------------------------------

$rawInput = file_get_contents("php://input");

$data = json_decode($rawInput, true);

// Support ordinary POST form data as well.
if (!is_array($data)) {
    $data = $_POST;
}

if (!is_array($data) || empty($data)) {

    http_response_code(400);

    ravi3_response(false, "No valid reading data received.");

}


// -------------------------------------------------------
// 6. READ DEVICE INFORMATION
// -------------------------------------------------------

$device_id = trim((string)($data["device_id"] ?? ""));

if ($device_id === "") {

    http_response_code(400);

    ravi3_response(false, "Device ID is missing.");

}


// -------------------------------------------------------
// 7. READ VITAL PARAMETERS
// -------------------------------------------------------

$requiredFields = [
    "room_temperature",
    "humidity",
    "bpm",
    "spo2",
    "body_temperature"
];

$values = [];

foreach ($requiredFields as $field) {

    if (
        !isset($data[$field]) ||
        !is_numeric($data[$field])
    ) {

        http_response_code(400);

        ravi3_response(
            false,
            "Missing or invalid parameter: " . $field
        );
    }

    $values[$field] = (float)$data[$field];
}


// -------------------------------------------------------
// 8. BASIC VALUE VALIDATION
// -------------------------------------------------------

if (
    $values["humidity"] < 0 ||
    $values["humidity"] > 100 ||
    $values["bpm"] <= 0 ||
    $values["spo2"] < 0 ||
    $values["spo2"] > 100
) {

    http_response_code(400);

    ravi3_response(false, "One or more readings are outside valid limits.");

}


// -------------------------------------------------------
// 9. READ DEVICE MODE
// -------------------------------------------------------

$mode = strtoupper(
    trim((string)($data["mode"] ?? "SIMULATION"))
);

if (!in_array($mode, ["SIMULATION", "REAL"], true)) {
    $mode = "SIMULATION";
}


// -------------------------------------------------------
// 10. MATCH THIS CONTROLLER TO ITS OWNER-SELECTED PATIENT
// -------------------------------------------------------

// IMPORTANT:
// The ESP32 sends its own device_id in the JSON data.
// We use that exact device_id to find ONE patient selected
// by the owner in device_active_patients.
//
// We then verify that the same patient is still assigned
// to the same device in patient_devices, is enabled, and
// is within the assignment dates.
//
// There is NO fallback to another patient and NO saving
// to every patient assigned to the controller.

$activeSql = "
    SELECT
        ap.patient_id,
        pd.start_date_time AS valid_from,
        pd.end_date_time AS valid_until
    FROM device_active_patients ap
    INNER JOIN patient_devices pd
        ON pd.device_id = ap.device_id
       AND pd.patient_id = ap.patient_id
    WHERE ap.device_id = ?
      AND pd.enabled = 1
    LIMIT 1
";

$activeStmt = $db->prepare($activeSql);

if (!$activeStmt) {

    error_log("Ravi4 active-patient query preparation error: " . $db->error);

    http_response_code(500);

    ravi3_response(false, "Unable to verify the controller's active patient.");

}

$activeStmt->bind_param("s", $device_id);

if (!$activeStmt->execute()) {

    error_log("Ravi4 active-patient query execution error: " . $activeStmt->error);

    $activeStmt->close();

    http_response_code(500);

    ravi3_response(false, "Unable to verify the controller's active patient.");

}

$activeResult = $activeStmt->get_result();
$activeRow = $activeResult->fetch_assoc();
$activeStmt->close();

if (!$activeRow) {

    http_response_code(403);

    ravi3_response(
        false,
        "No owner-selected active patient is assigned to this controller, or its assignment is disabled. No data was saved.",
        ["device_id" => $device_id]
    );

}

// Check assignment dates using India local time.
date_default_timezone_set("Asia/Kolkata");

$now = new DateTime("now", new DateTimeZone("Asia/Kolkata"));
$dateAllowed = true;

if (!empty($activeRow["valid_from"])) {

    $validFrom = new DateTime(
        $activeRow["valid_from"],
        new DateTimeZone("Asia/Kolkata")
    );

    if ($now < $validFrom) {
        $dateAllowed = false;
    }
}

if (!empty($activeRow["valid_until"])) {

    $validUntil = new DateTime(
        $activeRow["valid_until"],
        new DateTimeZone("Asia/Kolkata")
    );

    if ($now > $validUntil) {
        $dateAllowed = false;
    }
}

if (!$dateAllowed) {

    http_response_code(403);

    ravi3_response(
        false,
        "The owner-selected patient's assignment for this controller is not currently valid. No data was saved.",
        ["device_id" => $device_id]
    );

}

// Keep the existing save loop below, but give it exactly
// ONE patient: the patient selected for this device.
$activePatientId = (string)$activeRow["patient_id"];
$patients = [$activePatientId];


// -------------------------------------------------------
// 11. PREPARE OPTIONAL ECG DATA
// -------------------------------------------------------

// ESP32 may send:
// ecg_samples: [sample1, sample2, sample3, ...]
//
// If no ECG data is supplied, monitoring readings
// will still be saved.

$ecgSamples = $data["ecg_samples"] ?? null;

$ecgJson = null;
$ecgCount = 0;

$sampleRate = (int)($data["sample_rate"] ?? 250);

if ($ecgSamples !== null) {

    if (!is_array($ecgSamples)) {

        http_response_code(400);

        ravi3_response(false, "ECG samples must be an array.");

    }

    if (count($ecgSamples) > 5000) {

        http_response_code(400);

        ravi3_response(false, "Too many ECG samples in one upload.");

    }

    $cleanSamples = [];

    foreach ($ecgSamples as $sample) {

        if (!is_numeric($sample)) {

            http_response_code(400);

            ravi3_response(false, "Invalid ECG sample.");

        }

        $cleanSamples[] = (float)$sample;

    }

    if ($sampleRate < 1 || $sampleRate > 2000) {

        http_response_code(400);

        ravi3_response(false, "Invalid ECG sample rate.");

    }

    $ecgCount = count($cleanSamples);

    $ecgJson = json_encode(
        $cleanSamples,
        JSON_UNESCAPED_UNICODE
    );

}


// -------------------------------------------------------
// 12. START DATABASE TRANSACTION
// -------------------------------------------------------

$db->begin_transaction();

try {

    $monitoringSaved = 0;
    $ecgSaved = 0;
    $alertsCreated = 0;


    // ---------------------------------------------------
    // 13. PREPARE MONITORING INSERT
    // ---------------------------------------------------

    $monitorSql = "
        INSERT INTO monitoring_readings
        (
            patient_id,
            device_id,
            room_temperature,
            humidity,
            bpm,
            spo2,
            body_temperature,
            mode,
            recorded_at,
            timezone
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Asia/Kolkata')
    ";

    $monitorStmt = $db->prepare($monitorSql);

    if (!$monitorStmt) {
        throw new Exception("Monitoring insert preparation failed.");
    }


    // ---------------------------------------------------
    // 14. PREPARE ECG INSERT
    // ---------------------------------------------------

    $ecgStmt = null;

    if ($ecgJson !== null && $ecgCount > 0) {

        $ecgSql = "
            INSERT INTO ecg_readings
            (
                patient_id,
                device_id,
                samples_json,
                mode,
                recorded_at,
                sample_rate,
                sample_count,
                timezone
            )
            VALUES
            (?, ?, ?, ?, NOW(), ?, ?, 'Asia/Kolkata')
        ";

        $ecgStmt = $db->prepare($ecgSql);

        if (!$ecgStmt) {
            throw new Exception("ECG insert preparation failed.");
        }

    }


    // ---------------------------------------------------
    // 15. PREPARE ALERT INSERT
    // ---------------------------------------------------

    $alertSql = "
        INSERT INTO alerts
        (
            patient_id,
            parameter_name,
            measured_value,
            alert_message,
            device_id,
            recorded_at,
            acknowledged
        )
        VALUES (?, ?, ?, ?, ?, NOW(), 0)
    ";

    $alertStmt = $db->prepare($alertSql);

    if (!$alertStmt) {
        throw new Exception("Alert insert preparation failed.");
    }


    // ---------------------------------------------------
    // 16. DEFINE ALERT THRESHOLDS
    // ---------------------------------------------------

    // These are example alert limits.
    // Confirm clinical thresholds before real patient use.

    $alertRules = [

        [
            "field" => "bpm",
            "name" => "BPM",
            "low" => 50,
            "high" => 120,
            "unit" => "BPM"
        ],

        [
            "field" => "spo2",
            "name" => "SpO2",
            "low" => 92,
            "high" => null,
            "unit" => "%"
        ],

        [
            "field" => "body_temperature",
            "name" => "Body Temperature",
            "low" => null,
            "high" => 38.0,
            "unit" => "°C"
        ]

    ];


    // ---------------------------------------------------
    // 17. SAVE DATA FOR THE ONE OWNER-SELECTED PATIENT
    // ---------------------------------------------------

    foreach ($patients as $assignedPatientId) {

        // Save monitoring readings.
        $monitorStmt->bind_param(
            "ssddddds",
            $assignedPatientId,
            $device_id,
            $values["room_temperature"],
            $values["humidity"],
            $values["bpm"],
            $values["spo2"],
            $values["body_temperature"],
            $mode
        );

        if (!$monitorStmt->execute()) {
            throw new Exception("Monitoring reading insert failed.");
        }

        $monitoringSaved++;


        // Save ECG data if supplied.
        if ($ecgStmt !== null) {

            $ecgStmt->bind_param(
                "ssssis",
                $assignedPatientId,
                $device_id,
                $ecgJson,
                $mode,
                $sampleRate,
                $ecgCount
            );

            if (!$ecgStmt->execute()) {
                throw new Exception("ECG recording insert failed.");
            }

            $ecgSaved++;
        }


        // ------------------------------------------------
        // 18. CHECK ALERT THRESHOLDS FOR THE SELECTED PATIENT
        // ------------------------------------------------

        foreach ($alertRules as $rule) {

            $field = $rule["field"];
            $value = $values[$field];

            $abnormal = false;
            $message = "";

            if (
                $rule["low"] !== null &&
                $value < $rule["low"]
            ) {

                $abnormal = true;

                $message =
                    $rule["name"] .
                    " is below the configured limit.";

            }

            if (
                $rule["high"] !== null &&
                $value > $rule["high"]
            ) {

                $abnormal = true;

                $message =
                    $rule["name"] .
                    " is above the configured limit.";

            }

            if (!$abnormal) {
                continue;
            }


            // --------------------------------------------
            // 19. CHECK FIVE-MINUTE ALERT COOLDOWN
            // --------------------------------------------

            // Avoid creating a new alert every five seconds
            // for the same abnormal parameter.

            $cooldownSql = "
                SELECT id
                FROM alerts
                WHERE patient_id = ?
                  AND device_id = ?
                  AND parameter_name = ?
                  AND acknowledged = 0
                  AND recorded_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                LIMIT 1
            ";

            $cooldownStmt = $db->prepare($cooldownSql);

            if (!$cooldownStmt) {
                throw new Exception("Alert cooldown check failed.");
            }

            $parameterName = $rule["name"];

            $cooldownStmt->bind_param(
                "sss",
                $assignedPatientId,
                $device_id,
                $parameterName
            );

            if (!$cooldownStmt->execute()) {

                $cooldownStmt->close();

                throw new Exception("Alert cooldown query failed.");

            }

            $cooldownResult = $cooldownStmt->get_result();

            $existingAlert = $cooldownResult->fetch_assoc();

            $cooldownStmt->close();

            if ($existingAlert) {
                continue;
            }


            // --------------------------------------------
            // 20. CREATE DASHBOARD ALERT
            // --------------------------------------------

            $measuredValue = (string)$value;

            $alertStmt->bind_param(
                "sssss",
                $assignedPatientId,
                $parameterName,
                $measuredValue,
                $message,
                $device_id
            );

            if (!$alertStmt->execute()) {
                throw new Exception("Dashboard alert insert failed.");
            }

            $alertsCreated++;

        }

    }


    // ---------------------------------------------------
    // 21. CLOSE PREPARED STATEMENTS
    // ---------------------------------------------------

    $monitorStmt->close();
    $alertStmt->close();

    if ($ecgStmt !== null) {
        $ecgStmt->close();
    }


    // ---------------------------------------------------
    // 22. COMMIT DATABASE TRANSACTION
    // ---------------------------------------------------

    $db->commit();


    // ---------------------------------------------------
    // 23. SEND SUCCESS RESPONSE TO ESP32
    // ---------------------------------------------------

    ravi3_response(
        true,
        "Readings saved successfully.",
        [
            "device_id" => $device_id,
            "active_patient_id" => $activePatientId,
            "patient_count" => count($patients),
            "monitoring_saved" => $monitoringSaved,
            "ecg_saved" => $ecgSaved,
            "ecg_sample_count" => $ecgCount,
            "alerts_created" => $alertsCreated,
            "mode" => $mode,
            "timezone" => "Asia/Kolkata"
        ]
    );


} catch (Throwable $e) {

    // ---------------------------------------------------
    // 24. ROLLBACK IF ANY DATABASE OPERATION FAILS
    // ---------------------------------------------------

    $db->rollback();

    error_log(
        "Ravi4 API save error: " . $e->getMessage()
    );

    http_response_code(500);

    ravi3_response(
        false,
        "Unable to save readings. Please check server configuration."
    );

}

?>
