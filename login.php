<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: login.php

PURPOSE:
1. Display patient login form.
2. Verify username and password.
3. Check whether the patient account is enabled.
4. Check the assigned patient/device record.
5. Create Ravi3 patient session variables.

SESSION VARIABLES:
    ravi3_patient_id
    ravi3_patient_name
    ravi3_device_id

This file is for Ravi3 only.
=========================================================
*/

require_once __DIR__ . "/config.php";


// -------------------------------------------------------
// 1. IF ALREADY LOGGED IN, OPEN DASHBOARD
// -------------------------------------------------------

if (
    !empty($_SESSION["ravi3_patient_id"]) &&
    !empty($_SESSION["ravi3_device_id"])
) {
    header("Location: index.php");
    exit;
}


$error = "";


// -------------------------------------------------------
// 2. PROCESS LOGIN FORM
// -------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {

        $error = "Please enter your username and password.";

    } else {

        // Find the user account.
        $sql = "
            SELECT *
            FROM web_users
            WHERE username = ?
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);

        if (!$stmt) {

            error_log("Ravi3 login prepare error: " . $db->error);
            $error = "Login is temporarily unavailable.";

        } else {

            $stmt->bind_param("s", $username);
            $stmt->execute();

            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            $stmt->close();


            // ------------------------------------------------
            // 3. VERIFY USER ACCOUNT
            // ------------------------------------------------

            if (!$user) {

                $error = "Invalid username or password.";

            } else {

                /*
                 * Expected password column: password
                 * Password should be generated using:
                 * password_hash($password, PASSWORD_DEFAULT)
                 */

                $storedPassword = $user["password"] ?? "";

                $passwordCorrect = (
                    $storedPassword !== "" &&
                    password_verify($password, $storedPassword)
                );


                // Check account enabled status if the column exists.
                $accountEnabled = true;

                if (array_key_exists("enabled", $user)) {
                    $accountEnabled = ((int)$user["enabled"] === 1);
                }

                if (!$passwordCorrect) {

                    $error = "Invalid username or password.";

                } elseif (!$accountEnabled) {

                    $error = "Your account has been disabled.";

                } else {

                    // ----------------------------------------
                    // 4. GET PATIENT AND DEVICE INFORMATION
                    // ----------------------------------------

                    $patient_id = trim($user["patient_id"] ?? "");
                    $device_id = trim($user["device_id"] ?? "");

                    if ($patient_id === "" || $device_id === "") {

                        $error = "No patient device has been assigned to this account.";

                    } else {

                        // ------------------------------------
                        // 5. CHECK DEVICE ASSIGNMENT
                        // ------------------------------------

                        $checkSql = "
                            SELECT *
                            FROM patient_devices
                            WHERE patient_id = ?
                              AND device_id = ?
                            LIMIT 1
                        ";

                        $checkStmt = $db->prepare($checkSql);

                        if (!$checkStmt) {

                            error_log(
                                "Ravi3 device check prepare error: " . $db->error
                            );

                            $error = "Unable to verify device assignment.";

                        } else {

                            $checkStmt->bind_param(
                                "ss",
                                $patient_id,
                                $device_id
                            );

                            $checkStmt->execute();

                            $checkResult = $checkStmt->get_result();
                            $assignment = $checkResult->fetch_assoc();

                            $checkStmt->close();


                            if (!$assignment) {

                                $error = "No valid device assignment was found.";

                            } elseif (
                                array_key_exists("enabled", $assignment) &&
                                (int)$assignment["enabled"] !== 1
                            ) {

                                $error = "Your assigned device is disabled.";

                            } else {

                                // ----------------------------
                                // 6. CHECK ACCESS DATES
                                // ----------------------------

                                date_default_timezone_set("Asia/Kolkata");

                                $now = new DateTime(
                                    "now",
                                    new DateTimeZone("Asia/Kolkata")
                                );

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


                                if (!$dateAllowed) {

                                    $error = "Your device access period is not currently valid.";

                                } else {

                                    // ------------------------
                                    // 7. CREATE RAVI3 SESSION
                                    // ------------------------

                                    session_regenerate_id(true);

                                    $_SESSION["ravi3_patient_id"] = $patient_id;

                                    $_SESSION["ravi3_patient_name"] =
                                        $user["patient_name"]
                                        ?? $user["name"]
                                        ?? $username;

                                    $_SESSION["ravi3_device_id"] = $device_id;

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
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Ravi3 Patient Login</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f1f4f8;
            margin: 0;
            padding: 20px;
        }

        .login-box {
            width: 100%;
            max-width: 380px;
            margin: 70px auto;
            padding: 25px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
        }

        h2 {
            text-align: center;
            color: #174a75;
        }

        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }

        input {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            margin-top: 6px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 16px;
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
            background: #104d80;
        }

        .error {
            background: #ffe5e5;
            color: #a00000;
            padding: 10px;
            border-radius: 5px;
            margin-top: 15px;
            text-align: center;
        }
    </style>
</head>

<body>

<div class="login-box">

    <h2>Patient Login</h2>

    <p style="text-align:center;">
        Ravi3 Patient Monitoring System
    </p>

    <?php if ($error !== ""): ?>
        <div class="error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">

        <label for="username">Username</label>

        <input
            type="text"
            id="username"
            name="username"
            autocomplete="username"
            required
        >

        <label for="password">Password</label>

        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit">Login</button>

    </form>

</div>

</body>
</html>
