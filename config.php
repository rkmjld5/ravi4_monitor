<?php
/*
=========================================================
RAVI4 PATIENT MONITORING SYSTEM
File: config.php

Purpose:
1. Start a separate Ravi4 session.
2. Connect to the existing TiDB Cloud database.
3. Require secure SSL/TLS database communication.
4. Set database timezone to India Standard Time.

IMPORTANT:
This file belongs to Ravi4 only.
Do not modify Ravi2.
=========================================================
*/


// -------------------------------------------------------
// 1. START RAVI4 SESSION
// -------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ||
        (
            !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
        )
    );

    session_name("RAVI4SESSID");

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
// 2. READ DATABASE SETTINGS FROM RENDER
// -------------------------------------------------------

$dbHost     = getenv("DB_HOST");
$dbPort     = getenv("DB_PORT");
$dbName     = getenv("DB_NAME");
$dbUser     = getenv("DB_USER");
$dbPassword = getenv("DB_PASSWORD");

$dbSsl      = getenv("DB_SSL");
$dbSslCa    = getenv("DB_SSL_CA");


// -------------------------------------------------------
// 3. CHECK REQUIRED SETTINGS
// -------------------------------------------------------

if (
    !$dbHost ||
    !$dbName ||
    !$dbUser ||
    $dbPassword === false ||
    $dbPassword === ''
) {
    error_log("Ravi4: Database environment settings are incomplete.");
    die("Database configuration error. Please contact the administrator.");
}

if (!$dbPort) {
    $dbPort = 4000;
}

$dbPort = (int)$dbPort;


// -------------------------------------------------------
// 4. SSL IS MANDATORY
// -------------------------------------------------------

if (
    $dbSsl === false ||
    strtolower(trim($dbSsl)) !== "true"
) {
    error_log("Ravi4: DB_SSL must be set to true.");
    die("Secure database connection is not configured.");
}


// -------------------------------------------------------
// 5. INITIALIZE MYSQLI
// -------------------------------------------------------

mysqli_report(MYSQLI_REPORT_OFF);

$db = mysqli_init();

if (!$db) {
    error_log("Ravi4: Could not initialize MySQLi.");
    die("Database connection could not be initialized.");
}


// -------------------------------------------------------
// 6. CONFIGURE SSL CERTIFICATE
// -------------------------------------------------------

// TiDB Cloud requires a secure connection.
// DB_SSL_CA should contain the path to the CA certificate
// supplied by TiDB Cloud, if required by its connection setup.

if ($dbSslCa !== false && trim($dbSslCa) !== '') {

    $dbSslCa = trim($dbSslCa);

    if (!file_exists($dbSslCa)) {
        error_log("Ravi4: SSL CA certificate file was not found.");
        die("Database SSL certificate configuration error.");
    }

    if (!$db->ssl_set(
        null,
        null,
        $dbSslCa,
        null,
        null
    )) {
        error_log("Ravi4: Could not configure SSL certificate.");
        die("Database SSL configuration error.");
    }
}


// -------------------------------------------------------
// 7. CONNECT USING SSL
// -------------------------------------------------------

$connectionFlags = MYSQLI_CLIENT_SSL;

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
        "Ravi4 database connection failed: " . $db->connect_error
    );

    die("Unable to connect securely to the database. Please contact the administrator.");
}


// -------------------------------------------------------
// 8. SET CHARACTER ENCODING
// -------------------------------------------------------

if (!$db->set_charset("utf8mb4")) {

    error_log("Ravi4: Could not set database character encoding.");

    die("Database character encoding error.");
}


// -------------------------------------------------------
// 9. SET INDIA TIMEZONE
// -------------------------------------------------------

$db->query("SET time_zone = '+05:30'");


// -------------------------------------------------------
// 10. CONNECTION ALIAS
// -------------------------------------------------------

$conn = $db;


// -------------------------------------------------------
// END OF RAVI4 config.php
// -------------------------------------------------------
?>
