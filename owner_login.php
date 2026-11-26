<?php
/*
    PROJECT: ravi2_patient
    FILE: owner_login.php

    PURPOSE:
    Owner / Administrator login.

    IMPORTANT:
    1. Uses the existing web_users table.
    2. Only role = admin can log in.
    3. Account must be enabled.
    4. Password is checked using password_verify().
    5. Uses the existing config.php connection ($conn).
    6. Does not change any database tables.
    7. Patient login remains separate in login.php.
*/

// --------------------------------------------------
// 1. LOAD DATABASE CONNECTION AND SESSION
// --------------------------------------------------

require_once __DIR__ . "/config.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is not available. Please check config.php.");
}

// --------------------------------------------------
// 2. ALREADY LOGGED IN?
// --------------------------------------------------

if (!empty($_SESSION["owner_logged_in"])) {
    header("Location: owner.php");
    exit;
}

// --------------------------------------------------
// 3. CREATE CSRF SECURITY TOKEN
// --------------------------------------------------

if (empty($_SESSION["admin_login_csrf"])) {
    $_SESSION["admin_login_csrf"] = bin2hex(random_bytes(32));
}

$message = "";

// --------------------------------------------------
// 4. PROCESS LOGIN FORM
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $csrf = $_POST["csrf_token"] ?? "";

    if (
        empty($_SESSION["admin_login_csrf"]) ||
        !hash_equals($_SESSION["admin_login_csrf"], $csrf)
    ) {

        $message = "Security verification failed. Please reload the page.";

    } else {

        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";

        if ($username === "" || $password === "") {

            $message = "Please enter username and password.";

        } else {

            // ------------------------------------------
            // 5. FIND ADMINISTRATOR IN web_users
            // ------------------------------------------

            $sql = "
                SELECT
                    id,
                    username,
                    password_hash,
                    role,
                    enabled
                FROM web_users
                WHERE username = ?
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {

                error_log("Owner login prepare error: " . $conn->error);
                $message = "Database error. Please try again.";

            } else {

                $stmt->bind_param("s", $username);
                $stmt->execute();

                $stmt->bind_result(
                    $db_id,
                    $db_username,
                    $db_password_hash,
                    $db_role,
                    $db_enabled
                );

                $account_found = $stmt->fetch();

                $stmt->close();

                // --------------------------------------
                // 6. CHECK ADMINISTRATOR CREDENTIALS
                // --------------------------------------

                if (
                    $account_found &&
                    $db_role === "admin" &&
                    (int)$db_enabled === 1 &&
                    password_verify($password, $db_password_hash)
                ) {

                    // ----------------------------------
                    // 7. LOGIN SUCCESSFUL
                    // ----------------------------------

                    session_regenerate_id(true);

                    // These session names are required
                    // by the owner.php dashboard.

                    $_SESSION["owner_logged_in"] = true;
                    $_SESSION["owner_id"] = (int)$db_id;
                    $_SESSION["owner_username"] = $db_username;
                    $_SESSION["owner_name"] = $db_username;

                    // Remove the old login security token.
                    unset($_SESSION["admin_login_csrf"]);

                    header("Location: owner.php");
                    exit;

                } else {

                    $message =
                        "Incorrect username or password, or administrator access is disabled.";
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

    <title>Owner / Administrator Login</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #eef2f7;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-box {
            width: 100%;
            max-width: 430px;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.12);
        }

        h1 {
            text-align: center;
            color: #123b64;
            font-size: 25px;
        }

        .subtitle {
            text-align: center;
            color: #64748b;
            margin-bottom: 25px;
            font-size: 14px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 18px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 15px;
        }

        button {
            width: 100%;
            padding: 13px;
            background: #2563eb;
            border: none;
            border-radius: 6px;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        .message {
            padding: 12px;
            margin-bottom: 18px;
            background: #fee2e2;
            color: #991b1b;
            border-radius: 6px;
            font-size: 14px;
        }

        .footer {
            text-align: center;
            color: #64748b;
            margin-top: 22px;
            font-size: 12px;
        }

    </style>

</head>

<body>

<div class="login-box">

    <h1>Patient Monitoring System</h1>

    <div class="subtitle">
        Owner / Administrator Login
    </div>

    <?php if ($message !== ""): ?>

        <div class="message">
            <?php
            echo htmlspecialchars(
                $message,
                ENT_QUOTES,
                "UTF-8"
            );
            ?>
        </div>

    <?php endif; ?>

    <form method="POST" action="owner_login.php" autocomplete="off">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php
                echo htmlspecialchars(
                    $_SESSION["admin_login_csrf"],
                    ENT_QUOTES,
                    "UTF-8"
                );
            ?>"
        >

        <label>Administrator Username</label>

        <input
            type="text"
            name="username"
            maxlength="50"
            required
            autocomplete="username"
        >

        <label>Password</label>

        <input
            type="password"
            name="password"
            required
            autocomplete="current-password"
        >

        <button type="submit">
            Administrator Login
        </button>

    </form>

    <div class="footer">
        Ravi2 Patient Monitoring System
        <br><br>
        Authorized Administrator Access Only
    </div>

</div>

</body>
</html>
