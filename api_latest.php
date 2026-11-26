<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: api_latest.php
 *
 * Secure latest monitoring and ECG API.
 * Compatible with the patient dashboard index.php.
 *
 * Database: patient_monitor
 * Platform: XAMPP + PHP + MySQL
 */

date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// --------------------------------------------------
// 1. Start the same session as index.php
// --------------------------------------------------

if (session_status() !== PHP_SESSION_ACTIVE) {

    session_name('PATIENTSESSID');

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (
            isset($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        ),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// --------------------------------------------------
// 2. Database connection
// --------------------------------------------------

require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// --------------------------------------------------
// 3. JSON response function
// --------------------------------------------------

function api_respond(
    bool $success,
    string $message,
    int $httpCode = 200,
    array $extra = []
): void {

    http_response_code($httpCode);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $extra
        ),
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// --------------------------------------------------
// 4. Allow GET only
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    header('Allow: GET');

    api_respond(false, 'GET requests only.', 405);
}

// --------------------------------------------------
// 5. Check patient login
// --------------------------------------------------

try {

    if (
        !isset($_SESSION['patient_logged_in']) ||
        $_SESSION['patient_logged_in'] !== true ||
        empty($_SESSION['patient_user_id']) ||
        empty($_SESSION['patient_id']) ||
        empty($_SESSION['patient_device_id'])
    ) {

        api_respond(
            false,
            'Patient login required. Please login again.',
            401
        );
    }

    $userId = (int) $_SESSION['patient_user_id'];

    $patientId = trim((string) $_SESSION['patient_id']);

    $deviceId = trim((string) $_SESSION['patient_device_id']);

    if (
        $userId <= 0 ||
        $patientId === '' ||
        $deviceId === ''
    ) {

        api_respond(
            false,
            'Invalid patient session.',
            401
        );
    }

    $now = date('Y-m-d H:i:s');

    // --------------------------------------------------
    // 6. Verify account and device assignment
    // --------------------------------------------------

    /*
     * IMPORTANT:
     *
     * This query follows the same relationship as
     * the working index.php.
     *
     * web_users.device_id must match the assigned device.
     * patient_devices.patient_id identifies the patient.
     *
     * Patient authorization is based on the administrator's
     * device assignment, not on an additional equality
     * requirement between web_users.patient_id and
     * patient_devices.patient_id.
     */

    $accessSql = "

        SELECT
            p.patient_id,
            p.full_name,
            p.age,
            p.gender,
            p.blood_group,
            p.contact,
            pd.device_id

        FROM web_users wu

        INNER JOIN patient_devices pd
            ON pd.device_id = wu.device_id

        INNER JOIN patients p
            ON p.patient_id = pd.patient_id

        WHERE
            wu.id = ?
            AND wu.device_id = ?

            AND pd.patient_id = ?
            AND pd.device_id = ?

            AND wu.role = 'patient'
            AND wu.enabled = 1

            AND pd.enabled = 1

            AND (
                wu.valid_from IS NULL
                OR wu.valid_from <= ?
            )

            AND (
                wu.valid_until IS NULL
                OR wu.valid_until >= ?
            )

            AND (
                pd.start_date_time IS NULL
                OR pd.start_date_time <= ?
            )

            AND (
                pd.end_date_time IS NULL
                OR pd.end_date_time >= ?
            )

        LIMIT 1
    ";

    $stmt = $conn->prepare($accessSql);

    $stmt->bind_param(
        'isssssss',
        $userId,
        $deviceId,
        $patientId,
        $deviceId,
        $now,
        $now,
        $now,
        $now
    );

    $stmt->execute();

    $patient = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$patient) {

        api_respond(
            false,
            'Patient account or device assignment is disabled, expired, or invalid.',
            403
        );
    }

    // Use verified database values.

    $patientId = (string) $patient['patient_id'];

    $deviceId = (string) $patient['device_id'];

    // --------------------------------------------------
    // 7. Retrieve latest monitoring readings
    // --------------------------------------------------

    $monitoring = null;

    $monitoringError = null;

    try {

        $monitorSql = "

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
                recorded_at,
                timezone

            FROM monitoring_readings

            WHERE
                patient_id = ?
                AND device_id = ?

            ORDER BY id DESC

            LIMIT 1
        ";

        $stmt = $conn->prepare($monitorSql);

        $stmt->bind_param(
            'ss',
            $patientId,
            $deviceId
        );

        $stmt->execute();

        $monitoring = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if ($monitoring !== null) {

            $numericFields = [
                'room_temperature',
                'humidity',
                'bpm',
                'spo2',
                'body_temperature'
            ];

            foreach ($numericFields as $field) {

                if ($monitoring[$field] !== null) {

                    $monitoring[$field] =
                        (float) $monitoring[$field];

                }
            }

            $monitoring['id'] = (int) $monitoring['id'];

        }

    } catch (Throwable $e) {

        $monitoringError = $e->getMessage();

        error_log(
            'Monitoring API error: ' . $e->getMessage()
        );

    }

    // --------------------------------------------------
    // 8. Retrieve latest ECG independently
    // --------------------------------------------------

    $ecg = null;

    $ecgError = null;

    try {

        $ecgSql = "

            SELECT
                id,
                patient_id,
                device_id,
                samples_json,
                sample_rate,
                sample_count,
                mode,
                recorded_at,
                timezone

            FROM ecg_readings

            WHERE
                patient_id = ?
                AND device_id = ?

            ORDER BY id DESC

            LIMIT 1
        ";

        $stmt = $conn->prepare($ecgSql);

        $stmt->bind_param(
            'ss',
            $patientId,
            $deviceId
        );

        $stmt->execute();

        $ecg = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if ($ecg !== null) {

            $samples = json_decode(
                $ecg['samples_json'],
                true
            );

            if (
                json_last_error() !== JSON_ERROR_NONE ||
                !is_array($samples)
            ) {

                $samples = [];

                error_log(
                    'Invalid ECG JSON. Record ID: ' .
                    (int) $ecg['id']
                );
            }

            $ecg['samples_json'] = $samples;

            $ecg['id'] = (int) $ecg['id'];

            $ecg['sample_count'] =
                (int) $ecg['sample_count'];

            $ecg['sample_rate'] =
                $ecg['sample_rate'] !== null
                ? (float) $ecg['sample_rate']
                : null;
        }

    } catch (Throwable $e) {

        $ecgError = $e->getMessage();

        $ecg = null;

        error_log(
            'ECG API error: ' . $e->getMessage()
        );
    }

    // --------------------------------------------------
    // 9. Return JSON
    // --------------------------------------------------

    api_respond(
        true,
        'Patient monitoring data retrieved.',
        200,
        [
            'patient' => [
                'patient_id' => $patient['patient_id'],
                'full_name' => $patient['full_name'],
                'age' => $patient['age'],
                'gender' => $patient['gender'],
                'blood_group' => $patient['blood_group'],
                'contact' => $patient['contact']
            ],

            'device_id' => $deviceId,

            'monitoring' => $monitoring,

            'monitoring_found' => ($monitoring !== null),

            'monitoring_error' => $monitoringError,

            'ecg' => $ecg,

            'ecg_found' => ($ecg !== null),

            'ecg_error' => $ecgError,

            'server_timezone' => 'Asia/Kolkata',

            'server_time' => date('Y-m-d H:i:s')
        ]
    );

} catch (Throwable $e) {

    error_log(
        'API latest fatal error: ' . $e->getMessage()
    );

    api_respond(
        false,
        'Unable to retrieve patient readings.',
        500
    );

}

?>
