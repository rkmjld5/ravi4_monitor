<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: owner_login.php

PURPOSE:
1. Allow the owner/admin to log in.
2. Use the same session started by config.php.
3. Verify the password securely.
4. Redirect the owner to owner.php.

No Firebase or mobile notification code.
=========================================================
*/

require_once __DIR__ . "/config.php";

date_default_timezone_set("Asia/Kolkata");

$error = "";

// If the owner is already logged in, open owner page.
if (
    !empty($_SESSION["ravi3_owner_logged_in"]) &&
    $_SESSION["ravi3_owner_logged_in"] === true
) {
    header("Location: owner.php");
    exit;
}


// -------------------------------------------------------
// PROCESS LOGIN FORM
// -------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {

        $error = "Please enter username and password.";

    } else {

        /*
         * IMPORTANT:
         * This query assumes web_users contains:
         * username
         * password
         *
         * It also assumes the owner account can be
         * identified by the username stored in the table.
         *
         * The role check must be adjusted if your database
         * uses a different column or owner-account method.
         */

        $sql = "
            SELECT *
            FROM web_users
            WHERE username = ?
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);

        if (!$stmt) {

            error_log("Ravi3 owner login prepare error: " . $db->error);

            $error = "Login is temporarily unavailable.";

        } else {

            $stmt->bind_param("s", $username);

            if (!$stmt->execute()) {

                error_log("Ravi3 owner login query error: " . $stmt->error);

                $error = "Login is temporarily unavailable.";

            } else {

                $result = $stmt->get_result();
                $user = $result->fetch_assoc();

                if (!$user) {

                    $error = "Invalid username or password.";

                } else {

                    // Check that the account has a password.
                    $storedPassword = $user["password"] ?? "";

                    // Check password_hash() format.
                    $passwordOK = false;

                    if ($storedPassword !== "") {

                        $passwordOK = password_verify(
                            $password,
                            $storedPassword
                        );

                    }

                    // Check whether the account is enabled.
                    $enabled = true;

                    if (array_key_exists("enabled", $user)) {
                        $enabled = (int)$user["enabled"] === 1;
                    }

                    // Check owner/admin role.
                    // Adjust these values if your database
                    // uses another role naming convention.
                    $role = strtolower(
                        trim((string)(
                            $user["role"] ??
                            $user["user_type"] ??
                            $user["account_type"] ??
                            ""
                        ))
                    );

                    $isOwner = in_array(
                        $role,
                        ["owner", "admin", "administrator"],
                        true
                    );

                    if (!$enabled) {

                        $error = "This account is disabled.";

                    } elseif (!$passwordOK) {

                        $error = "Invalid username or password.";

                    } elseif (!$isOwner) {

                        $error = "This account does not have owner access.";

                    } else {

                        // Create a fresh session ID after login.
                        session_regenerate_id(true);

                        $_SESSION["ravi3_owner_logged_in"] = true;

                        $_SESSION["ravi3_owner_username"] =
                            $user["username"];

                        $_SESSION["ravi3_owner_user_id"] =
                            $user["id"] ?? null;

                        $_SESSION["ravi3_owner_role"] = $role;

                        header("Location: owner.php");
                        exit;

                    }

                }

            }

            $stmt->close();

        }

    }

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Ravi3 Owner Login</title>

<style>

body {
    margin: 0;
    padding: 20px;
    font-family: Arial, sans-serif;
    background: #f2f5f9;
}

.login-box {
    max-width: 400px;
    margin: 70px auto;
    padding: 25px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.10);
}

h2 {
    text-align: center;
    color: #17365d;
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
    margin-top: 22px;
    padding: 12px;
    background: #1769aa;
    color: white;
    border: none;
    border-radius: 5px;
    font-size: 16px;
    cursor: pointer;
}

button:hover {
    background: #0d4f85;
}

.error {
    margin-top: 15px;
    padding: 10px;
    background: #ffe5e5;
    color: #a00000;
    border-radius: 5px;
    text-align: center;
}

.footer {
    margin-top: 20px;
    text-align: center;
    color: #666;
    font-size: 13px;
}

</style>

</head>

<body>

<div class="login-box">

    <h2>Ravi3 Owner Login</h2>

    <p style="text-align:center;">
        Patient Monitoring System
    </p>

    <?php if ($error !== ""): ?>

        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
        </div>

    <?php endif; ?>

    <form method="POST" action="">

        <label for="username">Owner Username</label>

        <input
            type="text"
            id="username"
            name="username"
            required
            autocomplete="username"
        >

        <label for="password">Password</label>

        <input
            type="password"
            id="password"
            name="password"
            required
            autocomplete="current-password"
        >

        <button type="submit">
            Owner Login
        </button>

    </form>

    <div class="footer">
        Ravi3 Patient Monitoring System
    </div>

</div>

</body>
</html>
