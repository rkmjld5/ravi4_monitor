<?php
/*
=========================================================
PROJECT: RAVI4 PATIENT MONITORING SYSTEM
FILE: owner_login.php

PURPOSE:
1. Owner/administrator login.
2. Use existing web_users table.
3. Verify password using password_hash.
4. Allow only role = admin.
5. Check account enabled status.
6. Create Ravi4-specific owner session.

IMPORTANT:
- Uses existing owner credentials.
- Does not change database records.
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

date_default_timezone_set("Asia/Kolkata");


// -------------------------------------------------------
// 2. CHECK EXISTING OWNER SESSION
// -------------------------------------------------------

if (!empty($_SESSION["ravi4_owner_logged_in"])) {

    header("Location: owner.php");
    exit;
}


// -------------------------------------------------------
// 3. CREATE CSRF SECURITY TOKEN
// -------------------------------------------------------

if (empty($_SESSION["ravi4_admin_login_csrf"])) {

    $_SESSION["ravi4_admin_login_csrf"] =
        bin2hex(random_bytes(32));
}


$message = "";
$username = "";


// -------------------------------------------------------
// 4. PROCESS OWNER LOGIN FORM
// -------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $csrf = $_POST["csrf_token"] ?? "";

    if (
        empty($_SESSION["ravi4_admin_login_csrf"]) ||
        !hash_equals($_SESSION["ravi4_admin_login_csrf"], $csrf)
    ) {

        $message = "Security verification failed. Please reload the page.";

    } else {

        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";

        if ($username === "" || $password === "") {

            $message = "Please enter username and password.";

        } else {

            // ------------------------------------------------
            // 5. FIND OWNER ACCOUNT IN web_users
            // ------------------------------------------------

            /*
               Correct password column:
               password_hash

               This is the same database query used by
               the working Ravi2 owner login.
            */

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

                error_log("Ravi4 owner login prepare error: " . $conn->error);

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


                // ------------------------------------------------
                // 6. VERIFY OWNER CREDENTIALS
                // ------------------------------------------------

                if (
                    $account_found &&
                    $db_role === "admin" &&
                    (int)$db_enabled === 1 &&
                    password_verify($password, $db_password_hash)
                ) {

                    // ------------------------------------------------
                    // 7. OWNER LOGIN SUCCESSFUL
                    // ------------------------------------------------

                    session_regenerate_id(true);

                    $_SESSION["ravi4_owner_logged_in"] = true;

                    $_SESSION["ravi4_owner_id"] = (int)$db_id;

                    $_SESSION["ravi4_owner_username"] = $db_username;

                    $_SESSION["ravi4_owner_name"] = $db_username;

                    $_SESSION["ravi4_owner_role"] = "admin";


                    // Remove login CSRF token after successful login.
                    unset($_SESSION["ravi4_admin_login_csrf"]);


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

    <title>Ravi4 Owner Login</title>

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

    <h1>Ravi4 Patient Monitoring System</h1>

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
                    $_SESSION["ravi4_admin_login_csrf"],
                    ENT_QUOTES,
                    "UTF-8"
                );
            ?>"
        >

        <label>Administrator Username</label>

        <input
            type="text"
            name="username"
            value="<?php echo htmlspecialchars($username, ENT_QUOTES, "UTF-8"); ?>"
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
        Ravi4 Patient Monitoring System
        <br><br>
        Authorized Administrator Access Only
    </div>

</div>

</body>
</html>
