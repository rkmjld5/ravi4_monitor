<?php
/*
|--------------------------------------------------------------------------
| RAVI4 PATIENT LOGIN
|--------------------------------------------------------------------------
| A patient can log in only when:
| 1. The username and password are correct.
| 2. The account is enabled and within its validity dates.
| 3. The patient's exact patient_id + device_id assignment is active.
|
| IMPORTANT:
| One ESP32 device may be assigned to multiple patients.
| Therefore, we must check BOTH patient_id and device_id.
|--------------------------------------------------------------------------
*/

session_start();

require_once "config.php";

// Use the same MySQLi connection variable as your original login.php.
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection error. Please check config.php.");
}

$error = "";

// If patient is already logged in, open dashboard.
if (
    isset($_SESSION["ravi4_patient_logged_in"]) &&
    $_SESSION["ravi4_patient_logged_in"] === true
) {
    header("Location: index.php");
    exit;
}

// Process login form.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {

        $error = "Please enter username and password.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | STEP 1: Find the patient login account
        |--------------------------------------------------------------------------
        | patient_id is selected directly from web_users.
        | Do not identify a patient using device_id alone.
        |--------------------------------------------------------------------------
        */

        $sql = "
            SELECT
                username,
                password_hash,
                role,
                enabled,
                patient_id,
                device_id,
                valid_from,
                valid_until
            FROM web_users
            WHERE username = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            die("Login query error: " . $conn->error);
        }

        $stmt->bind_param("s", $username);
        $stmt->execute();

        $stmt->bind_result(
            $db_username,
            $password_hash,
            $role,
            $enabled,
            $patient_id,
            $device_id,
            $valid_from,
            $valid_until
        );

        $account_found = $stmt->fetch();

        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | STEP 2: Validate account and password
        |--------------------------------------------------------------------------
        */

        if (!$account_found) {

            $error = "Invalid username or password.";

        } elseif (!password_verify($password, $password_hash)) {

            $error = "Invalid username or password.";

        } elseif ($role !== "patient") {

            $error = "This login is not a patient account.";

        } elseif ((int)$enabled !== 1) {

            $error = "Your patient account has been disabled.";

        } elseif (empty($patient_id) || empty($device_id)) {

            $error = "Your account has no patient or device assignment.";

        } else {

            /*
            |--------------------------------------------------------------------------
            | STEP 3: Check account validity dates
            |--------------------------------------------------------------------------
            */

            $now = new DateTime("now", new DateTimeZone("Asia/Kolkata"));

            $account_valid = true;

            if (!empty($valid_from)) {

                $from = new DateTime(
                    $valid_from,
                    new DateTimeZone("Asia/Kolkata")
                );

                if ($now < $from) {
                    $account_valid = false;
                }
            }

            if (!empty($valid_until)) {

                $until = new DateTime(
                    $valid_until,
                    new DateTimeZone("Asia/Kolkata")
                );

                if ($now > $until) {
                    $account_valid = false;
                }
            }

            if (!$account_valid) {

                $error = "Your patient login account is outside its valid date range.";

            } else {

                /*
                |--------------------------------------------------------------------------
                | STEP 4: Verify the exact patient + device assignment
                |--------------------------------------------------------------------------
                | This is the important shared-device correction.
                |
                | Example:
                | P1001 + ESP32-01
                | P1004 + ESP32-01
                |
                | Each account must match its own patient_id and device_id.
                |--------------------------------------------------------------------------
                */

                $sql = "
                    SELECT
                        p.patient_id,
                        p.full_name
                    FROM patient_devices d
                    INNER JOIN patients p
                        ON p.patient_id = d.patient_id
                    WHERE d.device_id = ?
                      AND d.patient_id = ?
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

                $stmt = $conn->prepare($sql);

                if (!$stmt) {
                    die("Device assignment query error: " . $conn->error);
                }

                $stmt->bind_param("ss", $device_id, $patient_id);
                $stmt->execute();

                $stmt->bind_result(
                    $verified_patient_id,
                    $patient_name
                );

                $assignment_found = $stmt->fetch();

                $stmt->close();

                if (!$assignment_found) {

                    $error = "Your device assignment is disabled, expired, or does not match your patient account.";

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | STEP 5: Create patient login session
                    |--------------------------------------------------------------------------
                    */

                    session_regenerate_id(true);

                    $_SESSION["ravi4_patient_logged_in"] = true;
                    $_SESSION["ravi4_username"] = $db_username;
                    $_SESSION["ravi4_patient_id"] = $verified_patient_id;
                    $_SESSION["ravi4_patient_name"] = $patient_name;
                    $_SESSION["ravi4_device_id"] = $device_id;
                    $_SESSION["ravi4_role"] = "patient";

                    /*
                    |--------------------------------------------------------------------------
                    | STEP 6: Open patient dashboard
                    |--------------------------------------------------------------------------
                    */

                    header("Location: index.php");
                    exit;
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Ravi4 Patient Login</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f2f5f9;
            margin: 0;
            padding: 0;
        }

        .login-box {
            width: 350px;
            max-width: calc(100% - 40px);
            margin: 100px auto;
            padding: 25px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
            box-sizing: border-box;
        }

        h2 {
            text-align: center;
            color: #234;
            margin-top: 0;
        }

        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 11px;
            margin-top: 6px;
            box-sizing: border-box;
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
            color: #b00020;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
        }

        .footer {
            text-align: center;
            margin-top: 18px;
            color: #666;
            font-size: 13px;
        }
    </style>
</head>

<body>

<div class="login-box">

    <h2>Patient Login</h2>

    <?php if ($error !== ""): ?>
        <div class="error">
            <?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">

        <label for="username">Username</label>

        <input
            type="text"
            id="username"
            name="username"
            required
            autocomplete="username"
            value="<?php echo htmlspecialchars($_POST["username"] ?? "", ENT_QUOTES, "UTF-8"); ?>"
        >

        <label for="password">Password</label>

        <input
            type="password"
            id="password"
            name="password"
            required
            autocomplete="current-password"
        >

        <button type="submit">Login</button>

    </form>

    <div class="footer">
        Ravi4 Patient Monitoring System
    </div>

</div>

</body>
</html>
