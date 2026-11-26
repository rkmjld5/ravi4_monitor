<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: api_save_readings.php
 *
 * Receives ESP32 monitoring readings and ECG samples.
 * Saves monitoring and ECG records.
 * Evaluates patient-specific alert settings.
 *
 * Existing tables:
 * patient_devices
 * patients
 * monitoring_readings
 * ecg_readings
 * alerts
 * patient_alert_settings
 *
 * Creates if missing:
 * alert_state
 *
 * Timezone: Asia/Kolkata
 *
 * IMPORTANT:
 * - No changes required to ESP32 app.ino.
 * - Email is not sent until SMTP is configured.
 * - SIMULATION alerts are labelled TEST ALERT.
 */

date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// =====================================================
// FIREBASE PUSH NOTIFICATION CONFIGURATION
// =====================================================

const FIREBASE_PROJECT_ID = 'patientmonitoringsystem-62f35';

// Render uses Secret Files and its system CA bundle. Environment variables
// can override these paths. Windows/XAMPP keeps the previous local paths.
$firebaseServiceAccountFile = getenv('FIREBASE_SERVICE_ACCOUNT_FILE');
if (!$firebaseServiceAccountFile) {
    $firebaseServiceAccountFile = (PHP_OS_FAMILY === 'Windows')
        ? 'C:/xampp/private/firebase-service-account.json'
        : '/etc/secrets/firebase-service-account.json';
}

$firebaseCaBundle = getenv('FIREBASE_CA_BUNDLE');
if (!$firebaseCaBundle) {
    $firebaseCaBundle = (PHP_OS_FAMILY === 'Windows')
        ? 'C:/xampp/php/extras/ssl/cacert-avast.pem'
        : '/etc/ssl/certs/ca-certificates.crt';
}

define('FIREBASE_SERVICE_ACCOUNT_FILE', $firebaseServiceAccountFile);
define('FIREBASE_CA_BUNDLE', $firebaseCaBundle);

function base64UrlEncode($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function getFirebaseAccessToken()
{
    if (!is_file(FIREBASE_SERVICE_ACCOUNT_FILE) || !is_readable(FIREBASE_SERVICE_ACCOUNT_FILE)) {
        throw new RuntimeException('Firebase service-account JSON file is missing or unreadable.');
    }

    if (!is_file(FIREBASE_CA_BUNDLE) || !is_readable(FIREBASE_CA_BUNDLE)) {
        throw new RuntimeException('Firebase CA certificate file is missing or unreadable.');
    }

    $serviceAccountJson = file_get_contents(FIREBASE_SERVICE_ACCOUNT_FILE);
    $serviceAccount = json_decode($serviceAccountJson, true);

    if (!is_array($serviceAccount) ||
        empty($serviceAccount['client_email']) ||
        empty($serviceAccount['private_key']) ||
        empty($serviceAccount['token_uri'])) {
        throw new RuntimeException('Firebase service-account JSON is invalid or incomplete.');
    }

    if (!empty($serviceAccount['project_id']) &&
        $serviceAccount['project_id'] !== FIREBASE_PROJECT_ID) {
        throw new RuntimeException('Firebase service-account project ID does not match the configured project.');
    }

    $now = time();

    $jwtHeader = base64UrlEncode(json_encode(array(
        'alg' => 'RS256',
        'typ' => 'JWT'
    )));

    $jwtClaims = base64UrlEncode(json_encode(array(
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $serviceAccount['token_uri'],
        'iat' => $now,
        'exp' => $now + 3600
    )));

    $unsignedJwt = $jwtHeader . '.' . $jwtClaims;

    $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);

    if ($privateKey === false) {
        throw new RuntimeException('Could not read the Firebase service-account private key.');
    }

    $signature = '';

    $signed = openssl_sign(
        $unsignedJwt,
        $signature,
        $privateKey,
        OPENSSL_ALGO_SHA256
    );

    openssl_free_key($privateKey);

    if (!$signed) {
        throw new RuntimeException('Could not sign the Firebase OAuth request.');
    }

    $assertion = $unsignedJwt . '.' . base64UrlEncode($signature);

    $postFields = http_build_query(array(
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $assertion
    ));

    $ch = curl_init($serviceAccount['token_uri']);

    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded'
        ),
        CURLOPT_CAINFO => FIREBASE_CA_BUNDLE,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ));

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Firebase OAuth connection failed: ' . $curlError);
    }

    $decoded = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300 || empty($decoded['access_token'])) {
        $detail = is_array($decoded)
            ? ($decoded['error_description'] ?? $decoded['error'] ?? '')
            : '';

        throw new RuntimeException(
            'Firebase OAuth failed (HTTP ' . $httpCode . '). ' . $detail
        );
    }

    return $decoded['access_token'];
}

function sendFirebasePushNotifications($conn, $patientId, $deviceId, $alerts, $mode)
{
    $result = array(
        'attempted' => 0,
        'sent' => 0,
        'failed' => 0,
        'registered_tokens' => 0,
        'status' => 'NO_NEW_ALERTS',
        'error' => ''
    );

    if (count($alerts) === 0) {
        return $result;
    }

    $tokens = array();

    $tokenStmt = $conn->prepare(
        "SELECT DISTINCT fcm_token
         FROM patient_push_tokens
         WHERE patient_id = ?
           AND device_id = ?
           AND enabled = 1
           AND fcm_token IS NOT NULL
           AND fcm_token <> ''"
    );

    $tokenStmt->bind_param('ss', $patientId, $deviceId);
    $tokenStmt->execute();

    $tokenRows = $tokenStmt->get_result();

    while ($tokenRow = $tokenRows->fetch_assoc()) {
        $token = trim((string)$tokenRow['fcm_token']);

        if ($token !== '') {
            $tokens[] = $token;
        }
    }

    $tokenStmt->close();

    $result['registered_tokens'] = count($tokens);

    if (count($tokens) === 0) {
        $result['status'] = 'NO_REGISTERED_PHONES';
        return $result;
    }

    $accessToken = getFirebaseAccessToken();

    $title = ($mode === 'SIMULATION')
        ? 'TEST ALERT - Patient Monitoring'
        : 'PATIENT MONITORING ALERT';

    $parts = array();

    foreach ($alerts as $alert) {
        $parameter = (string)($alert['parameter'] ?? 'Alert');
        $value = (string)($alert['value'] ?? '');
        $unit = '';

        if ($parameter === 'SpO2') {
            $unit = '%';
        } elseif ($parameter === 'BODY_TEMP') {
            $unit = ' C';
        } elseif ($parameter === 'BPM') {
            $unit = ' BPM';
        }

        $parts[] = $parameter . ': ' . $value . $unit;
    }

    $body = implode(' | ', $parts);

    if ($body === '') {
        $body = 'An abnormal patient monitoring reading was detected.';
    }

    if (strlen($body) > 900) {
        $body = substr($body, 0, 897) . '...';
    }

    $endpoint = 'https://fcm.googleapis.com/v1/projects/' .
        FIREBASE_PROJECT_ID . '/messages:send';

    foreach ($tokens as $token) {

        $message = array(
            'message' => array(
                'token' => $token,

                'notification' => array(
                    'title' => $title,
                    'body' => $body
                ),

                'data' => array(
                    'title' => $title,
                    'body' => $body,
                    'patient_id' => (string)$patientId,
                    'device_id' => (string)$deviceId,
                    'mode' => (string)$mode,
                    'url' => '/index.php'
                ),

                'android' => array(
                    'priority' => 'high',
                    'notification' => array(
                        'channel_id' => 'patient_monitor_alerts'
                    )
                ),

                'webpush' => array(
                    'headers' => array('Urgency' => 'high'),
                    'notification' => array(
                        'title' => $title,
                        'body' => $body,
                        'icon' => '/favicon.ico',
                        'badge' => '/favicon.ico'
                    )
                )
            )
        );

        $json = json_encode(
            $message,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $ch = curl_init($endpoint);

        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=utf-8'
            ),
            CURLOPT_CAINFO => FIREBASE_CA_BUNDLE,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ));

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $result['attempted']++;

        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
            $result['sent']++;
        } else {
            $result['failed']++;

            $safeError = ($response === false)
                ? $curlError
                : ('HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 700));

            // Keep diagnostics useful without ever logging the FCM token.
            $safeError = substr((string)$safeError, 0, 700);
            $result['error'] = $safeError;

            error_log(
                'Firebase push failed for patient ' . $patientId .
                ', device ' . $deviceId . ': ' . $safeError
            );
        }
    }

    $result['status'] = ($result['failed'] === 0)
        ? 'SENT'
        : 'PARTIAL_OR_FAILED';

    return $result;
}

// =====================================================
// 1. JSON RESPONSE
// =====================================================

function sendResponse($success, $message, $extra = array(), $httpCode = 200)
{
    http_response_code($httpCode);

    echo json_encode(
        array_merge(
            array(
                'success' => $success,
                'message' => $message
            ),
            $extra
        ),
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

// =====================================================
// 2. DATABASE CONNECTION
// =====================================================

if (!isset($conn) || !($conn instanceof mysqli)) {
    sendResponse(false, 'Database connection unavailable.', array(), 500);
}

$conn->set_charset('utf8mb4');

// =====================================================
// 3. POST REQUEST ONLY
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'POST requests only.', array(), 405);
}

// =====================================================
// 4. API TOKEN AUTHENTICATION
// =====================================================

$headers = function_exists('getallheaders')
    ? getallheaders()
    : array();

$receivedToken = '';

foreach ($headers as $key => $value) {
    if (strtolower($key) === 'x-api-token') {
        $receivedToken = trim($value);
        break;
    }
}

if (
    $receivedToken === '' &&
    isset($_SERVER['HTTP_X_API_TOKEN'])
) {
    $receivedToken = trim($_SERVER['HTTP_X_API_TOKEN']);
}

if ($receivedToken === '') {

    $authorization = '';

    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $authorization = trim($value);
            break;
        }
    }

    if (
        $authorization === '' &&
        isset($_SERVER['HTTP_AUTHORIZATION'])
    ) {
        $authorization = trim($_SERVER['HTTP_AUTHORIZATION']);
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        $receivedToken = trim($matches[1]);
    }
}

$expectedToken = defined('ESP_API_TOKEN')
    ? ESP_API_TOKEN
    : (getenv('ESP_API_TOKEN') ?: '');

if ($expectedToken === '') {
    sendResponse(
        false,
        'Server API token is not configured.',
        array(),
        500
    );
}

if (
    $receivedToken === '' ||
    !hash_equals($expectedToken, $receivedToken)
) {
    sendResponse(false, 'Invalid API token.', array(), 401);
}

// =====================================================
// 5. READ JSON
// =====================================================

$rawInput = file_get_contents('php://input');

if ($rawInput === false || trim($rawInput) === '') {
    sendResponse(false, 'Empty request body.', array(), 400);
}

$data = json_decode($rawInput, true);

if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
    sendResponse(false, 'Invalid JSON.', array(), 400);
}

// =====================================================
// 6. DEVICE ID
// =====================================================

$deviceId = trim((string)($data['device_id'] ?? ''));

if ($deviceId === '' || strlen($deviceId) > 32) {
    sendResponse(false, 'Invalid device ID.', array(), 400);
}

// =====================================================
// 7. MAIN PROCESSING
// =====================================================

try {

    // 7A. VERIFY DEVICE

    $stmt = $conn->prepare(
        "SELECT
            patient_id,
            enabled,
            start_date_time,
            end_date_time
         FROM patient_devices
         WHERE device_id = ?
         LIMIT 1"
    );

    $stmt->bind_param('s', $deviceId);
    $stmt->execute();

    $device = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$device) {
        sendResponse(false, 'Device is not registered.', array(), 403);
    }

    if ((int)$device['enabled'] !== 1) {
        sendResponse(
            false,
            'Device disabled by administrator.',
            array(),
            403
        );
    }

    $patientId = trim((string)$device['patient_id']);

    if ($patientId === '') {
        sendResponse(
            false,
            'No patient assigned to device.',
            array(),
            403
        );
    }

    // 7B. CHECK DEVICE ACCESS DATES

    $nowObject = new DateTime(
        'now',
        new DateTimeZone('Asia/Kolkata')
    );

    $serverNow = $nowObject->format('Y-m-d H:i:s');

    $startDate = trim((string)($device['start_date_time'] ?? ''));
    $endDate = trim((string)($device['end_date_time'] ?? ''));

    if (
        $startDate !== '' &&
        $startDate !== '0000-00-00 00:00:00'
    ) {

        $start = new DateTime(
            $startDate,
            new DateTimeZone('Asia/Kolkata')
        );

        if ($nowObject < $start) {
            sendResponse(
                false,
                'Device access has not started.',
                array('access_start' => $startDate),
                403
            );
        }
    }

    if (
        $endDate !== '' &&
        $endDate !== '0000-00-00 00:00:00'
    ) {

        $end = new DateTime(
            $endDate,
            new DateTimeZone('Asia/Kolkata')
        );

        if ($nowObject > $end) {

            $disable = $conn->prepare(
                "UPDATE patient_devices
                 SET enabled = 0
                 WHERE device_id = ?"
            );

            $disable->bind_param('s', $deviceId);
            $disable->execute();
            $disable->close();

            sendResponse(
                false,
                'Device access expired and device disabled.',
                array('access_end' => $endDate),
                403
            );
        }
    }

    // 7C. GET PATIENT EMAIL

    $stmt = $conn->prepare(
        "SELECT email
         FROM patients
         WHERE patient_id = ?
         LIMIT 1"
    );

    $stmt->bind_param('s', $patientId);
    $stmt->execute();

    $patient = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$patient) {
        sendResponse(
            false,
            'Patient record not found.',
            array(),
            403
        );
    }

    $patientEmail = trim((string)($patient['email'] ?? ''));

    if (
        $patientEmail !== '' &&
        !filter_var($patientEmail, FILTER_VALIDATE_EMAIL)
    ) {
        $patientEmail = '';
    }

    // 7D. VALIDATE SENSOR READINGS

    $requiredFields = array(
        'room_temperature',
        'humidity',
        'bpm',
        'spo2',
        'body_temperature'
    );

    foreach ($requiredFields as $field) {

        if (
            !isset($data[$field]) ||
            !is_numeric($data[$field]) ||
            !is_finite((float)$data[$field])
        ) {
            sendResponse(
                false,
                'Missing or invalid reading: ' . $field,
                array(),
                400
            );
        }
    }

    $roomTemperature = (float)$data['room_temperature'];
    $humidity        = (float)$data['humidity'];
    $bpm             = (float)$data['bpm'];
    $spo2            = (float)$data['spo2'];
    $bodyTemperature = (float)$data['body_temperature'];

    // 7E. VALIDATE MODE

    $mode = strtoupper(
        trim((string)($data['mode'] ?? 'SIMULATION'))
    );

    if (!in_array($mode, array('SIMULATION', 'REAL'), true)) {
        sendResponse(false, 'Invalid monitoring mode.', array(), 400);
    }

    $timezone = trim(
        (string)($data['timezone'] ?? 'Asia/Kolkata')
    );

    if ($timezone === '' || strlen($timezone) > 64) {
        $timezone = 'Asia/Kolkata';
    }

    // 7F. RECORDED DATE AND TIME

    $recordedAt = $serverNow;

    if (!empty($data['recorded_at'])) {

        $dateText = trim((string)$data['recorded_at']);

        $dateObject = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $dateText,
            new DateTimeZone('Asia/Kolkata')
        );

        if (
            !$dateObject ||
            $dateObject->format('Y-m-d H:i:s') !== $dateText
        ) {
            sendResponse(
                false,
                'Invalid recorded_at. Use YYYY-MM-DD HH:MM:SS.',
                array(),
                400
            );
        }

        $recordedAt = $dateObject->format('Y-m-d H:i:s');
    }

    // =================================================
    // 8. VALIDATE ECG DATA
    // =================================================

    $hasECG = array_key_exists('ecg_samples', $data);

    $ecgJson = '';
    $sampleRate = 250;
    $sampleCount = 0;

    if ($hasECG) {

        if (
            !is_array($data['ecg_samples']) ||
            count($data['ecg_samples']) === 0 ||
            count($data['ecg_samples']) > 10000
        ) {
            sendResponse(
                false,
                'Invalid ECG sample array.',
                array(),
                400
            );
        }

        foreach ($data['ecg_samples'] as $sample) {

            if (
                !is_numeric($sample) ||
                !is_finite((float)$sample)
            ) {
                sendResponse(
                    false,
                    'Invalid ECG sample.',
                    array(),
                    400
                );
            }
        }

        $ecgJson = json_encode($data['ecg_samples']);

        if ($ecgJson === false) {
            sendResponse(
                false,
                'ECG encoding failed.',
                array(),
                400
            );
        }

        $sampleCount = count($data['ecg_samples']);

        if (isset($data['sample_rate'])) {
            $sampleRate = (int)$data['sample_rate'];
        }

        if ($sampleRate <= 0 || $sampleRate > 10000) {
            sendResponse(
                false,
                'Invalid ECG sample rate.',
                array(),
                400
            );
        }
    }

    // =================================================
    // 9. READ PATIENT-SPECIFIC ALERT SETTINGS
    // =================================================

    $alertSettings = array();

    $settingsStmt = $conn->prepare(
        "SELECT
            id,
            parameter_name,
            minimum_value,
            maximum_value,
            alert_enabled,
            email_enabled,
            email_to,
            cooldown_minutes
         FROM patient_alert_settings
         WHERE patient_id = ?
         ORDER BY updated_at DESC, id DESC"
    );

    $settingsStmt->bind_param('s', $patientId);
    $settingsStmt->execute();

    $settingsResult = $settingsStmt->get_result();

    while ($setting = $settingsResult->fetch_assoc()) {

        $parameter = trim((string)$setting['parameter_name']);

        if (!isset($alertSettings[$parameter])) {
            $alertSettings[$parameter] = $setting;
        }
    }

    $settingsStmt->close();

    // =================================================
    // 10. CREATE ALERT STATE TABLE IF MISSING
    // =================================================

    // Updated to match the existing alert_state table.

    $conn->query(
        "CREATE TABLE IF NOT EXISTS alert_state (
            patient_id VARCHAR(30) NOT NULL,
            device_id VARCHAR(32) NOT NULL,
            parameter_name VARCHAR(40) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            last_alert_id BIGINT(20) UNSIGNED DEFAULT NULL,
            last_measured_value VARCHAR(40) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (
                patient_id,
                device_id,
                parameter_name
            )
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // =================================================
    // 11. PREPARE CURRENT READING VALUES
    // =================================================

    $readingValues = array(
        'BPM' => $bpm,
        'SpO2' => $spo2,
        'BODY_TEMP' => $bodyTemperature
    );

    $conditions = array();

    foreach ($readingValues as $parameter => $value) {

        if (!isset($alertSettings[$parameter])) {
            continue;
        }

        $setting = $alertSettings[$parameter];

        if ((int)$setting['alert_enabled'] !== 1) {
            continue;
        }

        $minimum = $setting['minimum_value'];
        $maximum = $setting['maximum_value'];

        $isLow = (
            $minimum !== null &&
            $minimum !== '' &&
            $value < (float)$minimum
        );

        $isHigh = (
            $maximum !== null &&
            $maximum !== '' &&
            $value > (float)$maximum
        );

        if (!$isLow && !$isHigh) {
            continue;
        }

        if ($parameter === 'BPM') {

            if ($isLow) {
                $message = 'LOW HEART RATE: BPM is below configured minimum of '
                    . $minimum . '.';
            } else {
                $message = 'HIGH HEART RATE: BPM is above configured maximum of '
                    . $maximum . '.';
            }

        } elseif ($parameter === 'SpO2') {

            if ($isLow) {
                $message = 'LOW OXYGEN LEVEL: SpO2 is below configured minimum of '
                    . $minimum . '%.';
            } else {
                $message = 'HIGH SpO2: Reading is above configured maximum of '
                    . $maximum . '%.';
            }

        } else {

            if ($isLow) {
                $message = 'LOW BODY TEMPERATURE: Below configured minimum of '
                    . $minimum . ' C.';
            } else {
                $message = 'HIGH BODY TEMPERATURE: Above configured maximum of '
                    . $maximum . ' C.';
            }
        }

        if ($mode === 'SIMULATION') {
            $message = 'TEST ALERT (SIMULATION): ' . $message;
        }

        $conditions[$parameter] = array(
            'parameter' => $parameter,
            'value' => (string)$value,
            'message' => $message,
            'setting' => $setting
        );
    }

    // =================================================
    // 12. SAVE MONITORING, ECG AND ALERT RECORDS
    // =================================================

    $conn->begin_transaction();

    // 12A. SAVE MONITORING READINGS

    $monitorStmt = $conn->prepare(
        "INSERT INTO monitoring_readings
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
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $monitorStmt->bind_param(
        'ssdddddsss',
        $patientId,
        $deviceId,
        $roomTemperature,
        $humidity,
        $bpm,
        $spo2,
        $bodyTemperature,
        $mode,
        $recordedAt,
        $timezone
    );

    $monitorStmt->execute();

    $monitorId = $conn->insert_id;

    $monitorStmt->close();

    // 12B. SAVE ECG READINGS

    $ecgId = null;

    if ($hasECG) {

        $ecgStmt = $conn->prepare(
            "INSERT INTO ecg_readings
            (
                patient_id,
                device_id,
                samples_json,
                sample_rate,
                sample_count,
                mode,
                recorded_at,
                timezone
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $ecgStmt->bind_param(
            'sssiisss',
            $patientId,
            $deviceId,
            $ecgJson,
            $sampleRate,
            $sampleCount,
            $mode,
            $recordedAt,
            $timezone
        );

        $ecgStmt->execute();

        $ecgId = $conn->insert_id;

        $ecgStmt->close();
    }

    // =================================================
    // 13. PROCESS ALERT CONDITIONS
    // =================================================

    $alertIds = array();

    $alertsCreated = 0;
    $alertsRepeated = 0;
    $alertsSuppressed = 0;

    $parameters = array('BPM', 'SpO2', 'BODY_TEMP');

    foreach ($parameters as $parameter) {

        $stateParameter = ($mode === 'SIMULATION')
            ? 'TEST_' . $parameter
            : $parameter;

        $currentCondition = $conditions[$parameter] ?? null;

        // Create state row if it does not exist.

        $stateInsert = $conn->prepare(
            "INSERT IGNORE INTO alert_state
            (
                patient_id,
                device_id,
                parameter_name,
                is_active
            )
            VALUES (?, ?, ?, 0)"
        );

        $stateInsert->bind_param(
            'sss',
            $patientId,
            $deviceId,
            $stateParameter
        );

        $stateInsert->execute();
        $stateInsert->close();

        // Lock the state row during processing.

        $stateStmt = $conn->prepare(
            "SELECT
                is_active,
                last_alert_id,
                last_measured_value
             FROM alert_state
             WHERE patient_id = ?
               AND device_id = ?
               AND parameter_name = ?
             FOR UPDATE"
        );

        $stateStmt->bind_param(
            'sss',
            $patientId,
            $deviceId,
            $stateParameter
        );

        $stateStmt->execute();

        $state = $stateStmt->get_result()->fetch_assoc();

        $stateStmt->close();

        $wasActive = (
            $state &&
            (int)$state['is_active'] === 1
        );

        // NORMAL READING: RESET ACTIVE CONDITION

        if ($currentCondition === null) {

            if ($wasActive) {

                $resetStmt = $conn->prepare(
                    "UPDATE alert_state
                     SET is_active = 0,
                         last_measured_value = NULL
                     WHERE patient_id = ?
                       AND device_id = ?
                       AND parameter_name = ?"
                );

                $resetStmt->bind_param(
                    'sss',
                    $patientId,
                    $deviceId,
                    $stateParameter
                );

                $resetStmt->execute();
                $resetStmt->close();
            }

            continue;
        }

        // ABNORMAL READING: CHECK COOLDOWN

        $setting = $currentCondition['setting'];

        $cooldownMinutes = max(
            1,
            min(10080, (int)$setting['cooldown_minutes'])
        );

        $shouldCreateAlert = !$wasActive;
        $isRepeatedAlert = false;

        if ($wasActive) {

            $lastAlertId = (int)($state['last_alert_id'] ?? 0);

            $lastAlertAt = null;

            if ($lastAlertId > 0) {

                $lastStmt = $conn->prepare(
                    "SELECT recorded_at
                     FROM alerts
                     WHERE id = ?
                       AND patient_id = ?
                     LIMIT 1"
                );

                $lastStmt->bind_param(
                    'is',
                    $lastAlertId,
                    $patientId
                );

                $lastStmt->execute();

                $lastAlert = $lastStmt->get_result()->fetch_assoc();

                $lastStmt->close();

                if ($lastAlert && !empty($lastAlert['recorded_at'])) {
                    $lastAlertAt = strtotime($lastAlert['recorded_at']);
                }
            }

            if ($lastAlertAt === null) {

                $shouldCreateAlert = true;

            } else {

                $elapsedSeconds = time() - $lastAlertAt;

                if ($elapsedSeconds >= ($cooldownMinutes * 60)) {
                    $shouldCreateAlert = true;
                    $isRepeatedAlert = true;
                } else {
                    $shouldCreateAlert = false;
                    $alertsSuppressed++;
                }
            }
        }

        // UPDATE CURRENT VALUE

        if (!$shouldCreateAlert) {

            $valueUpdate = $conn->prepare(
                "UPDATE alert_state
                 SET last_measured_value = ?
                 WHERE patient_id = ?
                   AND device_id = ?
                   AND parameter_name = ?"
            );

            $valueUpdate->bind_param(
                'ssss',
                $currentCondition['value'],
                $patientId,
                $deviceId,
                $stateParameter
            );

            $valueUpdate->execute();
            $valueUpdate->close();

            continue;
        }

        // DETERMINE EMAIL SETTINGS

        $emailEnabled = (
            (int)$setting['email_enabled'] === 1
        );

        $emailRecipient = '';

        if ($emailEnabled) {

            $configuredEmail = trim(
                (string)($setting['email_to'] ?? '')
            );

            if (
                $configuredEmail !== '' &&
                filter_var($configuredEmail, FILTER_VALIDATE_EMAIL)
            ) {
                $emailRecipient = $configuredEmail;

            } elseif ($patientEmail !== '') {

                $emailRecipient = $patientEmail;
            }
        }

        if (!$emailEnabled) {
            $emailStatus = 'DISABLED';
        } elseif ($emailRecipient === '') {
            $emailStatus = 'NO_EMAIL';
        } else {
            $emailStatus = 'NOT_CONFIGURED';
        }

        // CREATE ALERT RECORD

        $alertMessage = $currentCondition['message'];

        if ($isRepeatedAlert) {
            $alertMessage = 'REPEATED ALERT: ' . $alertMessage;
            $alertsRepeated++;
        }

        $alertStmt = $conn->prepare(
            "INSERT INTO alerts
            (
                patient_id,
                parameter_name,
                measured_value,
                alert_message,
                email_to,
                email_status,
                device_id,
                recorded_at,
                acknowledged
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)"
        );

        $alertStmt->bind_param(
            'ssssssss',
            $patientId,
            $parameter,
            $currentCondition['value'],
            $alertMessage,
            $emailRecipient,
            $emailStatus,
            $deviceId,
            $serverNow
        );

        $alertStmt->execute();

        $newAlertId = $conn->insert_id;

        $alertStmt->close();

        $alertsCreated++;

        $alertIds[] = array(
            'alert_id' => $newAlertId,
            'parameter' => $parameter,
            'value' => $currentCondition['value'],
            'message' => $alertMessage,
            'email_status' => $emailStatus,
            'email_recipient_configured' => (
                $emailRecipient !== ''
            ),
            'repeated' => $isRepeatedAlert
        );

        // UPDATE ALERT STATE

        $stateUpdate = $conn->prepare(
            "UPDATE alert_state
             SET is_active = 1,
                 last_alert_id = ?,
                 last_measured_value = ?
             WHERE patient_id = ?
               AND device_id = ?
               AND parameter_name = ?"
        );

        $stateUpdate->bind_param(
            'issss',
            $newAlertId,
            $currentCondition['value'],
            $patientId,
            $deviceId,
            $stateParameter
        );

        $stateUpdate->execute();
        $stateUpdate->close();
    }

    // =================================================
    // 14. COMMIT DATABASE TRANSACTION
    // =================================================

    $conn->commit();

    // =================================================
    // 15. EMAIL STATUS
    // =================================================

    $emailPendingConfiguration = 0;
    $emailRecipientsConfigured = 0;

    foreach ($alertIds as $alert) {

        if ($alert['email_status'] === 'NOT_CONFIGURED') {
            $emailPendingConfiguration++;
        }

        if ($alert['email_recipient_configured']) {
            $emailRecipientsConfigured++;
        }
    }

    // =================================================
    // 16. SEND FIREBASE PUSH NOTIFICATIONS
    // =================================================

    $pushResult = array(
        'attempted' => 0,
        'sent' => 0,
        'failed' => 0,
        'registered_tokens' => 0,
        'status' => 'NO_NEW_ALERTS'
    );

    if ($alertsCreated > 0) {

        try {

            $pushResult = sendFirebasePushNotifications(
                $conn,
                $patientId,
                $deviceId,
                $alertIds,
                $mode
            );

        } catch (Throwable $pushError) {

            $pushResult['status'] = 'ERROR';
            $pushResult['error'] = substr($pushError->getMessage(), 0, 700);

            error_log(
                'Firebase push notification error: ' .
                $pushResult['error']
            );
        }
    }

    // =================================================
    // 17. SUCCESS RESPONSE TO ESP32
    // =================================================

    sendResponse(
        true,
        'Monitoring readings saved successfully.',
        array(
            'patient_id' => $patientId,
            'device_id' => $deviceId,

            'monitoring_id' => $monitorId,

            'ecg_saved' => $hasECG,
            'ecg_id' => $ecgId,
            'sample_count' => $sampleCount,

            'mode' => $mode,
            'recorded_at' => $recordedAt,
            'timezone' => $timezone,

            'alerts_created' => $alertsCreated,
            'alerts_repeated' => $alertsRepeated,
            'alerts_suppressed_by_cooldown' => $alertsSuppressed,

            'push_notifications_attempted' => $pushResult['attempted'],
            'push_notifications_sent' => $pushResult['sent'],
            'push_notifications_failed' => $pushResult['failed'],
            'push_registered_tokens' => $pushResult['registered_tokens'],
            'push_status' => $pushResult['status'],
            'push_error' => $pushResult['error'] ?? '',

            'email_notifications_sent' => 0,
            'email_pending_smtp_configuration' => $emailPendingConfiguration,
            'email_recipients_configured' => $emailRecipientsConfigured,

            'alerts' => $alertIds
        ),
        200
    );

} catch (Throwable $e) {

    if (isset($conn) && $conn instanceof mysqli) {

        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // Ignore rollback failure.
        }
    }

    error_log(
        'api_save_readings.php error: ' . $e->getMessage()
    );

    sendResponse(
        false,
        'Database operation failed. Check PHP error log.',
        array(),
        500
    );
}
