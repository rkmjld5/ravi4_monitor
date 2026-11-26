<?php
/*
=========================================================
RAVI3 PATIENT MONITORING SYSTEM
File: config.php

Purpose:
1. Start the Ravi3 patient-monitoring session.
2. Connect to the MySQL database using MySQLi.
3. Read database settings from Render environment variables.
4. Set the database timezone to India Standard Time.

IMPORTANT:
This file belongs to Ravi3 only.
Do not replace the config.php file in Ravi2.
=========================================================
*/


// -------------------------------------------------------
// 1. START RAVI3 SESSION
// -------------------------------------------------------

// Use a separate session name so Ravi3 does not interfere
// with the existing Ravi2 project.

if (session_status() === PHP_SESSION_NONE) {

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ||
        (
            !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
        )
    );

    session_name("RAVI3SESSID");

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}


// -------------------------------------------------------
// 2. DATABASE SETTINGS
// -------------------------------------------------------

// These values are obtained from Render environment
// variables. Do not put database passwords in this file.

$dbHost = getenv("DB_HOST");
$dbPort = getenv("DB_PORT");
$dbName = getenv("DB_NAME");
$dbUser = getenv("DB_USER");
$dbPassword = getenv("DB_PASSWORD");

// TiDB/Render SSL settings.
$dbSsl = getenv("DB_SSL");
$dbSslCa = getenv("DB_SSL_CA");


// -------------------------------------------------------
// 3. CHECK REQUIRED DATABASE SETTINGS
// -------------------------------------------------------

if (
    !$dbHost ||
    !$dbName ||
    !$dbUser ||
    $dbPassword === false
) {
    error_log("Ravi3: Database environment settings are incomplete.");
    die("Database configuration error. Please contact the administrator.");
}


// Use port 4000 if DB_PORT is not configured.
if (!$dbPort) {
    $dbPort = 4000;
}

$dbPort = (int)$dbPort;


// -------------------------------------------------------
// 4. CREATE MYSQLI CONNECTION
// -------------------------------------------------------

mysqli_report(MYSQLI_REPORT_OFF);

$db = mysqli_init();

if (!$db) {
    error_log("Ravi3: Could not initialize MySQLi.");
    die("Database connection could not be initialized.");
}


// -------------------------------------------------------
// 5. ENABLE SSL WHEN CONFIGURED
// -------------------------------------------------------

$useSsl = (
    $dbSsl !== false &&
    strtolower(trim($dbSsl)) === "true"
);

if ($useSsl) {

    // If Render provides a CA certificate path, use it.
    if ($dbSslCa && file_exists($dbSslCa)) {

        $db->ssl_set(
            null,
            null,
            $dbSslCa,
            null,
            null
        );
    }
}


// -------------------------------------------------------
// 6. CONNECT TO DATABASE
// -------------------------------------------------------

$connectionFlags = 0;

if ($useSsl) {
    $connectionFlags |= MYSQLI_CLIENT_SSL;
}

$connected = @$db->real_connect(
    $dbHost,
    $dbUser,
    $dbPassword,
    $dbName,
    $dbPort,
    null,
    $connectionFlags
);

if (!$connected) {

    error_log(
        "Ravi3 database connection failed: " . $db->connect_error
    );

    die("Unable to connect to the database. Please contact the administrator.");
}


// -------------------------------------------------------
// 7. SET CHARACTER ENCODING
// -------------------------------------------------------

if (!$db->set_charset("utf8mb4")) {

    error_log("Ravi3: Could not set database character encoding.");

    die("Database character encoding error.");
}


// -------------------------------------------------------
// 8. SET INDIA TIMEZONE
// -------------------------------------------------------

$db->query("SET time_zone = '+05:30'");


// -------------------------------------------------------
// 9. CONNECTION ALIAS
// -------------------------------------------------------

// Both $db and $conn refer to the same MySQLi connection.
// This helps compatibility with PHP files using either name.

$conn = $db;


// -------------------------------------------------------
// END OF config.php
// -------------------------------------------------------
?>
