<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: api_save_readings.php

PURPOSE:
1. Receive readings from ESP32.
2. Authenticate the ESP32 using API token.
3. Identify patient assignments using device_id.
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

// Configure API_TOKEN in the Ravi3 Render environment.
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
// 10. CHECK DEVICE ASSIGNMENTS
// -------------------------------------------------------

// Find every enabled patient assignment for this device.
//
// This supports a device shared by multiple patient
// accounts, provided those accounts have the same
// device_id in patient_devices.
//
// Expected columns:
// patient_id, device_id, enabled, valid_from, valid_until.

$assignmentSql = "
    SELECT
        patient_id,
        device_id,
        enabled,
        valid_from,
        valid_until
    FROM patient_devices
    WHERE device_id = ?
      AND enabled = 1
";

$assignmentStmt = $db->prepare($assignmentSql);

if (!$assignmentStmt) {

    error_log("Ravi3 assignment query error: " . $db->error);

    http_response_code(500);

    ravi3_response(false, "Unable to check device assignment.");

}

$assignmentStmt->bind_param("s", $device_id);

if (!$assignmentStmt->execute()) {

    error_log("Ravi3 assignment execution error: " . $assignmentStmt->error);

    $assignmentStmt->close();

    http_response_code(500);

    ravi3_response(false, "Unable to check device assignment.");

}

$assignmentResult = $assignmentStmt->get_result();

$patients = [];

date_default_timezone_set("Asia/Kolkata");

$now = new DateTime(
    "now",
    new DateTimeZone("Asia/Kolkata")
);

while ($assignment = $assignmentResult->fetch_assoc()) {

    $dateAllowed = true;

    if (!empty($assignment["valid_from"])) {

        $validFrom = new DateTime(
            $assignment["valid_from"],
            new DateTimeZone("Asia/Kolkata")
        );

        if ($now < $validFrom) {
            $dateAllowed = false;
        }
    }

    if (!empty($assignment["valid_until"])) {

        $validUntil = new DateTime(
            $assignment["valid_until"],
            new DateTimeZone("Asia/Kolkata")
        );

        if ($now > $validUntil) {
            $dateAllowed = false;
        }
    }

    if ($dateAllowed) {
        $patients[] = $assignment["patient_id"];
    }
}

$assignmentStmt->close();

$patients = array_values(array_unique($patients));

if (count($patients) === 0) {

    http_response_code(403);

    ravi3_response(
        false,
        "No enabled patient assignment is valid for this device."
    );

}


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
    // 17. SAVE DATA FOR EACH PATIENT ASSIGNED TO DEVICE
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
        // 18. CHECK ALERT THRESHOLDS
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
        "Ravi3 API save error: " . $e->getMessage()
    );

    http_response_code(500);

    ravi3_response(
        false,
        "Unable to save readings. Please check server configuration."
    );

}

?>
