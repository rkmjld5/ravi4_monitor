<?php
/*
=========================================================
PROJECT: RAVI4 PATIENT MONITORING SYSTEM
FILE: login.php

PURPOSE:
1. Patient login using existing web_users table.
2. Verify password using password_hash.
3. Check patient account role and enabled status.
4. Check login validity dates.
5. Find patient through the assigned device ID.
6. Check device assignment and validity.
7. Create Ravi4-specific patient session.

IMPORTANT:
- Uses existing database records.
- Does not change passwords or database tables.
- Does not modify Ravi2.
=========================================================
*/


// -------------------------------------------------------
// 1. CONNECT TO DATABASE AND START SESSION
// -------------------------------------------------------

require_once __DIR__ . "/config.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is not available. Please check config.php.");
}


// -------------------------------------------------------
// 2. ALREADY LOGGED IN?
// -------------------------------------------------------

if (
    !empty($_SESSION["ravi4_patient_logged_in"]) &&
    !empty($_SESSION["ravi4_patient_id"]) &&
    !empty($_SESSION["ravi4_device_id"])
) {
    header("Location: index.php");
    exit;
}


// -------------------------------------------------------
// 3. PAGE VARIABLES
// -------------------------------------------------------

$error_message = "";
$username = "";


// -------------------------------------------------------
// 4. PROCESS LOGIN FORM
// -------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {

        $error_message = "Please enter both username and password.";

    } else {

        // ------------------------------------------------
        // 5. FIND USER ACCOUNT
        // ------------------------------------------------

        /*
           IMPORTANT:
           The correct password column is password_hash.
           Do not use the column named password.
        */

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

            error_log("Ravi4 patient login prepare error: " . $conn->error);

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


            // ------------------------------------------------
            // 6. VERIFY USERNAME AND PASSWORD
            // ------------------------------------------------

            if (
                !$account_found ||
                !password_verify($password, $password_hash)
            ) {

                $error_message = "Incorrect username or password.";

            } elseif ($role !== "patient") {

                $error_message = "This login is not a patient account.";

            } elseif ((int)$login_enabled !== 1) {

                $error_message = "Your account is disabled. Please contact the owner.";

            } else {

                // ------------------------------------------------
                // 7. CHECK LOGIN VALIDITY DATES
                // ------------------------------------------------

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

                    error_log("Ravi4 login date check error: " . $conn->error);

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

                        // ------------------------------------------------
                        // 8. FIND PATIENT USING DEVICE ID
                        // ------------------------------------------------

                        /*
                           This follows the working Ravi2 database logic.

                           Patient ID is obtained through patient_devices.
                           The patient name is obtained from patients.
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

                            error_log("Ravi4 patient mapping error: " . $conn->error);

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


                            // ------------------------------------------------
                            // 9. CHECK PATIENT AND DEVICE
                            // ------------------------------------------------

                            if (!$patient_found) {

                                $error_message =
                                    "Your monitoring device is disabled, expired, or not assigned. Please contact the owner.";

                            } else {

                                // ------------------------------------------------
                                // 10. PATIENT LOGIN SUCCESSFUL
                                // ------------------------------------------------

                                session_regenerate_id(true);

                                $_SESSION["ravi4_patient_logged_in"] = true;

                                $_SESSION["ravi4_username"] = $db_username;

                                $_SESSION["ravi4_patient_id"] = $patient_id;

                                $_SESSION["ravi4_patient_name"] = $patient_name;

                                $_SESSION["ravi4_device_id"] = $device_id;

                                $_SESSION["ravi4_role"] = "patient";

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


// -------------------------------------------------------
// 11. DISPLAY LOGIN PAGE
// -------------------------------------------------------

function ravi4_login_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Ravi4 Patient Login</title>

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
            Ravi4 Patient Monitoring System
        </p>

        <?php if ($error_message !== ""): ?>

            <div class="error">
                <?php echo ravi4_login_escape($error_message); ?>
            </div>

        <?php endif; ?>

        <form method="POST" action="login.php">

            <label>Username</label>

            <input
                type="text"
                name="username"
                value="<?php echo ravi4_login_escape($username); ?>"
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
            Ravi4 Patient Monitoring System
        </div>

    </div>

</body>

</html>
