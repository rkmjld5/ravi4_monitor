<?php
/*
    PROJECT: ravi2_patient
    FILE: login.php

    PURPOSE:
    Patient login for the new patient monitoring application.

    IMPORTANT:
    1. Uses the existing web_users table.
    2. Only accounts with role = patient can log in here.
    3. Finds patient_id through patient_devices using device_id.
    4. Checks whether the login account is enabled and valid.
    5. Checks whether the assigned device is enabled and valid.
    6. Does not change any database tables.
    7. Does not modify patient_remote.onrender.com.
*/

// --------------------------------------------------
// 1. CONNECT TO THE DATABASE AND START THE SESSION
// --------------------------------------------------

require_once __DIR__ . "/config.php";

/*
   config.php must create the MySQLi connection in $conn
   and start the PHP session.
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is not available. Please check config.php.");
}

// --------------------------------------------------
// 2. IF PATIENT IS ALREADY LOGGED IN, OPEN DASHBOARD
// --------------------------------------------------

if (!empty($_SESSION["ravi2_patient_logged_in"])) {
    header("Location: index.php");
    exit;
}

// --------------------------------------------------
// 3. VARIABLES USED BY THE LOGIN PAGE
// --------------------------------------------------

$error_message = "";
$username = "";

// --------------------------------------------------
// 4. PROCESS THE LOGIN FORM
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Read the username and password entered by the patient.
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {

        $error_message = "Please enter both username and password.";

    } else {

        // ------------------------------------------
        // 5. FIND THE LOGIN ACCOUNT
        // ------------------------------------------

        $sql = "
            SELECT
                username,
                password_hash,
                role,
                enabled,
                device_id,
                valid_from,
                valid_until
            FROM web_users
            WHERE username = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {

            error_log("Patient login prepare error: " . $conn->error);
            $error_message = "Unable to process login. Please try again.";

        } else {

            $stmt->bind_param("s", $username);
            $stmt->execute();

            $stmt->bind_result(
                $db_username,
                $password_hash,
                $role,
                $login_enabled,
                $device_id,
                $valid_from,
                $valid_until
            );

            $account_found = $stmt->fetch();

            $stmt->close();

            // --------------------------------------
            // 6. VERIFY USERNAME AND PASSWORD
            // --------------------------------------

            if (!$account_found || !password_verify($password, $password_hash)) {

                $error_message = "Incorrect username or password.";

            } elseif ($role !== "patient") {

                // Administrator accounts cannot use patient login.
                $error_message = "This login is not a patient account.";

            } elseif ((int)$login_enabled !== 1) {

                $error_message = "Your account is disabled. Please contact the owner.";

            } else {

                // ----------------------------------
                // 7. CHECK LOGIN VALIDITY DATES
                // ----------------------------------

                $date_sql = "
                    SELECT
                        (
                            (? IS NULL OR ? <= NOW())
                            AND
                            (? IS NULL OR ? >= NOW())
                        ) AS login_valid
                ";

                $date_stmt = $conn->prepare($date_sql);

                if (!$date_stmt) {

                    error_log("Login date check error: " . $conn->error);
                    $error_message = "Unable to verify your account. Please try again.";

                } else {

                    $date_stmt->bind_param(
                        "ssss",
                        $valid_from,
                        $valid_from,
                        $valid_until,
                        $valid_until
                    );

                    $date_stmt->execute();
                    $date_stmt->bind_result($login_valid);
                    $date_stmt->fetch();
                    $date_stmt->close();

                    if ((int)$login_valid !== 1) {

                        $error_message = "Your login validity period has expired.";

                    } elseif (empty($device_id)) {

                        $error_message = "No monitoring device is assigned to your account.";

                    } else {

                        // ----------------------------------
                        // 8. FIND PATIENT THROUGH DEVICE ID
                        // ----------------------------------

                        /*
                           In your existing database:

                           Username: ravimahajan1
                           Device:   ESP32-01
                           Patient:  P1001

                           The patient_id field in web_users is empty.
                           Therefore, we obtain patient_id from
                           patient_devices.
                        */

                        $patient_sql = "
                            SELECT
                                p.patient_id,
                                p.full_name
                            FROM patient_devices d
                            INNER JOIN patients p
                                ON p.patient_id = d.patient_id
                            WHERE
                                d.device_id = ?
                                AND d.enabled = 1
                                AND (
                                    d.start_date_time IS NULL
                                    OR d.start_date_time <= NOW()
                                )
                                AND (
                                    d.end_date_time IS NULL
                                    OR d.end_date_time >= NOW()
                                )
                            LIMIT 1
                        ";

                        $patient_stmt = $conn->prepare($patient_sql);

                        if (!$patient_stmt) {

                            error_log("Patient mapping error: " . $conn->error);
                            $error_message = "Unable to verify your device assignment.";

                        } else {

                            $patient_stmt->bind_param("s", $device_id);
                            $patient_stmt->execute();

                            $patient_stmt->bind_result(
                                $patient_id,
                                $patient_name
                            );

                            $patient_found = $patient_stmt->fetch();

                            $patient_stmt->close();

                            // ----------------------------------
                            // 9. CHECK DEVICE AND PATIENT RECORD
                            // ----------------------------------

                            if (!$patient_found) {

                                $error_message =
                                    "Your monitoring device is disabled, expired, or not assigned. Please contact the owner.";

                            } else {

                                // ----------------------------------
                                // 10. LOGIN SUCCESSFUL
                                // ----------------------------------

                                /*
                                   Create a new session ID after login
                                   to improve session security.
                                */

                                session_regenerate_id(true);

                                // Save the verified patient details.
                                $_SESSION["ravi2_patient_logged_in"] = true;

                                $_SESSION["ravi2_username"] = $db_username;

                                $_SESSION["ravi2_patient_id"] = $patient_id;

                                $_SESSION["ravi2_patient_name"] = $patient_name;

                                $_SESSION["ravi2_device_id"] = $device_id;

                                $_SESSION["ravi2_role"] = "patient";

                                // Open the patient dashboard.
                                header("Location: index.php");
                                exit;
                            }
                        }
                    }
                }
            }
        }
    }
}

// --------------------------------------------------
// 11. DISPLAY THE LOGIN PAGE
// --------------------------------------------------

function login_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Patient Login - Ravi2 Patient Monitoring</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f1f4f8;
            margin: 0;
            padding: 0;
        }

        .login-box {
            width: 90%;
            max-width: 400px;
            margin: 80px auto;
            padding: 25px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
        }

        h2 {
            text-align: center;
            color: #263b55;
        }

        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }

        input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px;
            margin-top: 6px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 15px;
        }

        button {
            width: 100%;
            padding: 12px;
            margin-top: 22px;
            background: #1769aa;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #10558c;
        }

        .error {
            background: #ffe6e6;
            color: #a00000;
            padding: 10px;
            border-radius: 5px;
            margin-top: 15px;
            text-align: center;
        }

        .footer {
            text-align: center;
            color: #777;
            font-size: 12px;
            margin-top: 20px;
        }

    </style>

</head>

<body>

    <div class="login-box">

        <h2>Patient Login</h2>

        <p style="text-align:center;">
            Patient Monitoring System
        </p>

        <?php if ($error_message !== ""): ?>

            <div class="error">
                <?php echo login_escape($error_message); ?>
            </div>

        <?php endif; ?>

        <form method="POST" action="login.php">

            <label>Username</label>

            <input
                type="text"
                name="username"
                value="<?php echo login_escape($username); ?>"
                maxlength="50"
                autocomplete="username"
                required
            >

            <label>Password</label>

            <input
                type="password"
                name="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">
                Login
            </button>

        </form>

        <div class="footer">
            Ravi2 Patient Monitoring System
        </div>

    </div>

</body>
</html>
