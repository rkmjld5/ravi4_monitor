<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: api_latest.php

PURPOSE:
1. Verify the Ravi3 patient login session.
2. Verify patient/device assignment.
3. Check whether the device is enabled.
4. Check device access dates.
5. Return latest monitoring readings as JSON.
6. Return latest ECG recording information.

SECURITY:
Patient ID and device ID are obtained from the
authenticated server session and database assignment.

They are NOT accepted from URL parameters.

No Firebase or mobile push notification code.
=========================================================
*/


// -------------------------------------------------------
// 1. LOAD PATIENT ACCESS CHECK
// -------------------------------------------------------

require_once __DIR__ . "/patient_access.php";


// -------------------------------------------------------
// 2. SET JSON RESPONSE HEADER
// -------------------------------------------------------

header("Content-Type: application/json; charset=utf-8");


// -------------------------------------------------------
// 3. GET LATEST MONITORING READING
// -------------------------------------------------------

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

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to prepare monitoring query."
    ]);

    exit;
}

$stmt->bind_param("ss", $patient_id, $device_id);

if (!$stmt->execute()) {

    error_log("Ravi3 api_latest monitoring error: " . $stmt->error);

    $stmt->close();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to retrieve monitoring readings."
    ]);

    exit;
}

$result = $stmt->get_result();

$reading = $result->fetch_assoc();

$stmt->close();


// -------------------------------------------------------
// 4. GET LATEST ECG RECORDING INFORMATION
// -------------------------------------------------------

$ecg = null;

$ecgSql = "
    SELECT
        id,
        device_id,
        mode,
        recorded_at,
        sample_rate,
        sample_count
    FROM ecg_readings
    WHERE patient_id = ?
      AND device_id = ?
    ORDER BY recorded_at DESC, id DESC
    LIMIT 1
";

$ecgStmt = $db->prepare($ecgSql);

if ($ecgStmt) {

    $ecgStmt->bind_param("ss", $patient_id, $device_id);

    if ($ecgStmt->execute()) {

        $ecgResult = $ecgStmt->get_result();

        $ecg = $ecgResult->fetch_assoc();

    } else {

        error_log("Ravi3 api_latest ECG query error: " . $ecgStmt->error);

    }

    $ecgStmt->close();

} else {

    error_log("Ravi3 api_latest ECG prepare error: " . $db->error);

}


// -------------------------------------------------------
// 5. RETURN JSON RESPONSE
// -------------------------------------------------------

echo json_encode(
    [
        "success" => true,

        "patient_id" => $patient_id,

        "patient_name" => $patient_name,

        "device_id" => $device_id,

        "reading_available" => ($reading !== null),

        "reading" => $reading,

        "ecg_available" => ($ecg !== null),

        "ecg" => $ecg,

        "server_time" => date("Y-m-d H:i:s"),

        "timezone" => "Asia/Kolkata"
    ],
    JSON_UNESCAPED_UNICODE |
    JSON_INVALID_UTF8_SUBSTITUTE
);

exit;

?>
