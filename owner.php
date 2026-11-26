<?php
/*
|--------------------------------------------------------------------------
| PROJECT: ravi2_patient
| FILE: owner.php
|--------------------------------------------------------------------------
| OWNER / ADMINISTRATOR DASHBOARD
|
| Main functions:
| 1. Register patients
| 2. Assign devices to patients
| 3. Enable / Disable devices
| 4. Delete device assignments
| 5. Create and manage patient login accounts
| 6. Edit patient login accounts
|
| Database: patient_monitor
| Connection: config.php ($conn)
| Database access: MySQLi
| Time zone: Asia/Kolkata
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/config.php";

/*--------------------------------------------------------------
  1. CHECK DATABASE CONNECTION
--------------------------------------------------------------*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    exit("Database connection is not available.");
}

/*--------------------------------------------------------------
  2. CHECK OWNER / ADMINISTRATOR LOGIN
--------------------------------------------------------------*/

if (
    empty($_SESSION["owner_logged_in"]) ||
    empty($_SESSION["owner_id"])
) {
    header("Location: owner_login.php");
    exit;
}

/*--------------------------------------------------------------
  3. HELPER FUNCTIONS
--------------------------------------------------------------*/

function owner_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function owner_date_input($value)
{
    if (empty($value)) {
        return "";
    }

    $time = strtotime($value);

    if ($time === false) {
        return "";
    }

    return date("Y-m-d\TH:i", $time);
}

function owner_date_display($value)
{
    if (empty($value)) {
        return "Not specified";
    }

    $time = strtotime($value);

    if ($time === false) {
        return owner_escape($value);
    }

    return date("d-m-Y H:i", $time);
}

function owner_valid_date($value)
{
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    $date = DateTime::createFromFormat("Y-m-d\TH:i", $value);

    if (!$date || $date->format("Y-m-d\TH:i") !== $value) {
        return false;
    }

    return $date->format("Y-m-d H:i:s");
}

function owner_message($message)
{
    $_SESSION["owner_message"] = $message;
    header("Location: owner.php");
    exit;
}

/*--------------------------------------------------------------
  4. CSRF SECURITY TOKEN
--------------------------------------------------------------*/

if (empty($_SESSION["owner_csrf"])) {
    $_SESSION["owner_csrf"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["owner_csrf"];

/*--------------------------------------------------------------
  5. PROCESS FORM SUBMISSIONS
--------------------------------------------------------------*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /* Verify security token */

    $posted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($posted_token) ||
        !hash_equals($csrf_token, $posted_token)
    ) {
        owner_message("Security check failed. Please try again.");
    }

    $action = $_POST["action"] ?? "";

    /*==========================================================
      ACTION 1: REGISTER NEW PATIENT
    ==========================================================*/

    if ($action === "register_patient") {

        $patient_id = trim($_POST["patient_id"] ?? "");
        $full_name  = trim($_POST["full_name"] ?? "");
        $age_input  = trim($_POST["age"] ?? "");
        $gender     = trim($_POST["gender"] ?? "");
        $blood_group = trim($_POST["blood_group"] ?? "");
        $contact    = trim($_POST["contact"] ?? "");
        $email      = trim($_POST["email"] ?? "");
        $notes      = trim($_POST["notes"] ?? "");

        if ($patient_id === "" || $full_name === "") {
            owner_message("Patient ID and patient name are required.");
        }

        $age = null;

        if ($age_input !== "") {
            if (!ctype_digit($age_input) || (int)$age_input > 120) {
                owner_message("Please enter a valid patient age.");
            }

            $age = (int)$age_input;
        }

        $check = $conn->prepare(
            "SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1"
        );

        $check->bind_param("s", $patient_id);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $check->close();
            owner_message("This Patient ID already exists.");
        }

        $check->close();

        $stmt = $conn->prepare(
            "INSERT INTO patients
            (patient_id, full_name, age, gender, blood_group, contact, email, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "ssisssss",
            $patient_id,
            $full_name,
            $age,
            $gender,
            $blood_group,
            $contact,
            $email,
            $notes
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            owner_message("Could not register patient: " . $error);
        }

        $stmt->close();

        owner_message("Patient registered successfully.");
    }

    /*==========================================================
      ACTION 2: ASSIGN OR UPDATE DEVICE
    ==========================================================*/

    if ($action === "assign_device") {

        $device_id = trim($_POST["device_id"] ?? "");
        $patient_id = trim($_POST["device_patient_id"] ?? "");
        $description = trim($_POST["description"] ?? "");

        $start_date = owner_valid_date($_POST["start_date_time"] ?? "");
        $end_date = owner_valid_date($_POST["end_date_time"] ?? "");

        if ($device_id === "" || $patient_id === "") {
            owner_message("Device ID and Patient ID are required.");
        }

        if ($start_date === false || $end_date === false) {
            owner_message("Please enter valid device assignment dates.");
        }

        if (
            $start_date !== null &&
            $end_date !== null &&
            strtotime($end_date) <= strtotime($start_date)
        ) {
            owner_message("Device end date must be later than start date.");
        }

        /* Confirm that the patient exists */

        $check = $conn->prepare(
            "SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1"
        );

        $check->bind_param("s", $patient_id);
        $check->execute();

        if ($check->get_result()->num_rows === 0) {
            $check->close();
            owner_message("Patient ID does not exist.");
        }

        $check->close();

        /* Check whether this device already has an assignment */

        $check = $conn->prepare(
            "SELECT device_id FROM patient_devices
             WHERE device_id = ? LIMIT 1"
        );

        $check->bind_param("s", $device_id);
        $check->execute();

        $existing_device = $check->get_result()->num_rows > 0;
        $check->close();

        if ($existing_device) {

            $stmt = $conn->prepare(
                "UPDATE patient_devices
                 SET patient_id = ?,
                     description = ?,
                     start_date_time = ?,
                     end_date_time = ?
                 WHERE device_id = ?"
            );

            $stmt->bind_param(
                "sssss",
                $patient_id,
                $description,
                $start_date,
                $end_date,
                $device_id
            );

        } else {

            $stmt = $conn->prepare(
                "INSERT INTO patient_devices
                (device_id, patient_id, description, enabled,
                 start_date_time, end_date_time)
                VALUES (?, ?, ?, 1, ?, ?)"
            );

            $stmt->bind_param(
                "sssss",
                $device_id,
                $patient_id,
                $description,
                $start_date,
                $end_date
            );
        }

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            owner_message("Could not save device assignment: " . $error);
        }

        $stmt->close();

        owner_message("Device assignment saved successfully.");
    }

    /*==========================================================
      ACTION 3: ENABLE OR DISABLE DEVICE
    ==========================================================*/

    if ($action === "toggle_device") {

        $device_id = trim($_POST["device_id"] ?? "");
        $new_status = (int)($_POST["new_status"] ?? 0);

        if ($device_id === "" || !in_array($new_status, [0, 1], true)) {
            owner_message("Invalid device status request.");
        }

        $stmt = $conn->prepare(
            "UPDATE patient_devices
             SET enabled = ?
             WHERE device_id = ?"
        );

        $stmt->bind_param("is", $new_status, $device_id);
        $stmt->execute();

        $changed = $stmt->affected_rows;
        $stmt->close();

        owner_message(
            $changed >= 0
                ? "Device status updated."
                : "Could not update device status."
        );
    }

    /*==========================================================
      ACTION 4: DELETE DEVICE ASSIGNMENT
    ==========================================================*/

    if ($action === "delete_device") {

        $device_id = trim($_POST["device_id"] ?? "");

        if ($device_id === "") {
            owner_message("Device ID is missing.");
        }

        $stmt = $conn->prepare(
            "DELETE FROM patient_devices WHERE device_id = ?"
        );

        $stmt->bind_param("s", $device_id);
        $stmt->execute();
        $stmt->close();

        owner_message(
            "Device assignment deleted. Historical readings have not been deleted."
        );
    }

    /*==========================================================
      ACTION 5: CREATE OR EDIT PATIENT LOGIN ACCOUNT
    ==========================================================*/

    if ($action === "save_patient_login") {

        $login_id = (int)($_POST["login_id"] ?? 0);

        $username = trim($_POST["username"] ?? "");
        $password = (string)($_POST["password"] ?? "");
        $device_id = trim($_POST["login_device_id"] ?? "");

        $valid_from = owner_valid_date($_POST["valid_from"] ?? "");
        $valid_until = owner_valid_date($_POST["valid_until"] ?? "");

        if ($username === "" || $device_id === "") {
            owner_message("Username and device assignment are required.");
        }

        if ($valid_from === false || $valid_until === false) {
            owner_message("Please enter valid login validity dates.");
        }

        if (
            $valid_from !== null &&
            $valid_until !== null &&
            strtotime($valid_until) <= strtotime($valid_from)
        ) {
            owner_message("Login expiry date must be later than start date.");
        }

        /* Check that the device is assigned and enabled */

        $device_check = $conn->prepare(
            "SELECT device_id
             FROM patient_devices
             WHERE device_id = ?
               AND enabled = 1
               AND (start_date_time IS NULL OR start_date_time <= NOW())
               AND (end_date_time IS NULL OR end_date_time >= NOW())
             LIMIT 1"
        );

        $device_check->bind_param("s", $device_id);
        $device_check->execute();

        $device_available = $device_check->get_result()->num_rows > 0;
        $device_check->close();

        if (!$device_available) {
            owner_message(
                "This device is not currently assigned and enabled. Please check Section 3."
            );
        }

        /* Check username uniqueness.
           During editing, exclude the current login account. */

        if ($login_id > 0) {

            $check = $conn->prepare(
                "SELECT id
                 FROM web_users
                 WHERE username = ?
                   AND id <> ?
                 LIMIT 1"
            );

            $check->bind_param("si", $username, $login_id);

        } else {

            $check = $conn->prepare(
                "SELECT id
                 FROM web_users
                 WHERE username = ?
                 LIMIT 1"
            );

            $check->bind_param("s", $username);
        }

        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $check->close();
            owner_message("This username is already in use.");
        }

        $check->close();

        /*------------------------------------------------------
          EDIT AN EXISTING PATIENT LOGIN
        ------------------------------------------------------*/

        if ($login_id > 0) {

            /* Confirm that this is a patient login */

            $check = $conn->prepare(
                "SELECT id
                 FROM web_users
                 WHERE id = ? AND role = 'patient'
                 LIMIT 1"
            );

            $check->bind_param("i", $login_id);
            $check->execute();

            $login_exists = $check->get_result()->num_rows > 0;
            $check->close();

            if (!$login_exists) {
                owner_message("Patient login account was not found.");
            }

            if ($password !== "") {

                if (strlen($password) < 8) {
                    owner_message("New password must contain at least 8 characters.");
                }

                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare(
                    "UPDATE web_users
                     SET username = ?,
                         device_id = ?,
                         valid_from = ?,
                         valid_until = ?,
                         password_hash = ?
                     WHERE id = ? AND role = 'patient'"
                );

                $stmt->bind_param(
                    "sssssi",
                    $username,
                    $device_id,
                    $valid_from,
                    $valid_until,
                    $password_hash,
                    $login_id
                );

            } else {

                /* Blank password means keep the old password */

                $stmt = $conn->prepare(
                    "UPDATE web_users
                     SET username = ?,
                         device_id = ?,
                         valid_from = ?,
                         valid_until = ?
                     WHERE id = ? AND role = 'patient'"
                );

                $stmt->bind_param(
                    "ssssi",
                    $username,
                    $device_id,
                    $valid_from,
                    $valid_until,
                    $login_id
                );
            }

            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                owner_message("Could not update login: " . $error);
            }

            $stmt->close();

            owner_message("Patient login account updated successfully.");
        }

        /*------------------------------------------------------
          CREATE A NEW PATIENT LOGIN
        ------------------------------------------------------*/

        if ($password === "" || strlen($password) < 8) {
            owner_message("New patient password must contain at least 8 characters.");
        }

        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $role = "patient";
        $enabled = 1;

        $stmt = $conn->prepare(
            "INSERT INTO web_users
            (username, password_hash, role, enabled,
             device_id, valid_from, valid_until)
            VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "sssisss",
            $username,
            $password_hash,
            $role,
            $enabled,
            $device_id,
            $valid_from,
            $valid_until
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            owner_message("Could not create patient login: " . $error);
        }

        $stmt->close();

        owner_message("New patient login account created successfully.");
    }

    /*==========================================================
      ACTION 6: ENABLE OR DISABLE PATIENT LOGIN
    ==========================================================*/

    if ($action === "toggle_patient_login") {

        $login_id = (int)($_POST["login_id"] ?? 0);
        $new_status = (int)($_POST["new_status"] ?? 0);

        if (
            $login_id <= 0 ||
            !in_array($new_status, [0, 1], true)
        ) {
            owner_message("Invalid patient login status request.");
        }

        $stmt = $conn->prepare(
            "UPDATE web_users
             SET enabled = ?
             WHERE id = ? AND role = 'patient'"
        );

        $stmt->bind_param("ii", $new_status, $login_id);
        $stmt->execute();
        $stmt->close();

        owner_message("Patient login status updated.");
    }

    /*==========================================================
      ACTION 7: DELETE PATIENT LOGIN
    ==========================================================*/

    if ($action === "delete_patient_login") {

        $login_id = (int)($_POST["login_id"] ?? 0);

        if ($login_id <= 0) {
            owner_message("Invalid patient login account.");
        }

        $stmt = $conn->prepare(
            "DELETE FROM web_users
             WHERE id = ? AND role = 'patient'"
        );

        $stmt->bind_param("i", $login_id);
        $stmt->execute();
        $stmt->close();

        owner_message(
            "Patient login account deleted. Patient details and historical readings remain unchanged."
        );
    }

    owner_message("Unknown request.");
}

/*--------------------------------------------------------------
  6. READ ONE LOGIN ACCOUNT FOR EDITING
--------------------------------------------------------------*/

$edit_login = null;
$edit_login_id = (int)($_GET["edit_login"] ?? 0);

if ($edit_login_id > 0) {

    $stmt = $conn->prepare(
        "SELECT id, username, device_id, valid_from, valid_until
         FROM web_users
         WHERE id = ? AND role = 'patient'
         LIMIT 1"
    );

    $stmt->bind_param("i", $edit_login_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $edit_login = $result->fetch_assoc();

    $stmt->close();

    if (!$edit_login) {
        $_SESSION["owner_message"] = "Patient login account was not found.";
        header("Location: owner.php");
        exit;
    }
}

/*--------------------------------------------------------------
  7. LOAD DISPLAY MESSAGE
--------------------------------------------------------------*/

$message = $_SESSION["owner_message"] ?? "";
unset($_SESSION["owner_message"]);

/*--------------------------------------------------------------
  8. LOAD PATIENT LIST
--------------------------------------------------------------*/

$patients = [];

$result = $conn->query(
    "SELECT patient_id, full_name, age, gender, blood_group,
            contact, email, notes
     FROM patients
     ORDER BY patient_id"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $patients[] = $row;
    }
}

/*--------------------------------------------------------------
  9. LOAD DEVICE ASSIGNMENTS
--------------------------------------------------------------*/

$devices = [];

$result = $conn->query(
    "SELECT d.device_id, d.patient_id, d.description,
            d.enabled, d.start_date_time, d.end_date_time,
            p.full_name
     FROM patient_devices d
     LEFT JOIN patients p ON p.patient_id = d.patient_id
     ORDER BY d.device_id"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $devices[] = $row;
    }
}

/*--------------------------------------------------------------
  10. LOAD PATIENT LOGIN ACCOUNTS
--------------------------------------------------------------*/

$patient_logins = [];

$result = $conn->query(
    "SELECT w.id, w.username, w.enabled,
            w.device_id, w.valid_from, w.valid_until,
            d.patient_id, p.full_name
     FROM web_users w
     LEFT JOIN patient_devices d ON d.device_id = w.device_id
     LEFT JOIN patients p ON p.patient_id = d.patient_id
     WHERE w.role = 'patient'
     ORDER BY w.username"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $patient_logins[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Owner / Administrator Dashboard</title>

<style>
    body {
        font-family: Arial, sans-serif;
        background: #f3f5f7;
        margin: 0;
        padding: 20px;
        color: #222;
    }

    .container {
        max-width: 1200px;
        margin: auto;
    }

    h1 {
        color: #17365d;
    }

    h2 {
        background: #e7eef7;
        padding: 12px;
        border-left: 5px solid #245a91;
        margin-top: 30px;
    }

    .section {
        background: white;
        padding: 18px;
        margin-bottom: 22px;
        border-radius: 7px;
        box-shadow: 0 1px 5px rgba(0,0,0,0.08);
    }

    label {
        display: block;
        font-weight: bold;
        margin-top: 10px;
    }

    input, select, textarea {
        width: 100%;
        max-width: 450px;
        padding: 9px;
        margin-top: 5px;
        box-sizing: border-box;
        border: 1px solid #bbb;
        border-radius: 4px;
    }

    textarea {
        min-height: 65px;
    }

    button, .button {
        display: inline-block;
        padding: 8px 13px;
        margin: 5px 3px 5px 0;
        border: 0;
        border-radius: 4px;
        cursor: pointer;
        text-decoration: none;
        color: white;
        background: #245a91;
        font-size: 14px;
    }

    button:hover, .button:hover {
        opacity: 0.85;
    }

    .green {
        background: #198754;
    }

    .red {
        background: #c0392b;
    }

    .orange {
        background: #d97706;
    }

    .grey {
        background: #555;
    }

    .message {
        background: #e4f5e8;
        border: 1px solid #8bc99a;
        padding: 12px;
        border-radius: 5px;
        margin: 15px 0;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 12px;
        background: white;
    }

    th, td {
        border: 1px solid #ddd;
        padding: 9px;
        text-align: left;
        vertical-align: top;
    }

    th {
        background: #e7eef7;
    }

    .status-enabled {
        color: green;
        font-weight: bold;
    }

    .status-disabled {
        color: red;
        font-weight: bold;
    }

    .small-form {
        display: inline;
    }

    .top-links {
        margin-bottom: 20px;
    }

    .top-links a {
        margin-right: 10px;
    }

    .note {
        color: #555;
        font-size: 13px;
    }

    .edit-panel {
        border: 2px solid #d97706;
        padding: 15px;
        border-radius: 6px;
        background: #fffaf0;
        margin-bottom: 15px;
    }

    @media(max-width: 750px) {
        body {
            padding: 8px;
        }

        table {
            display: block;
            overflow-x: auto;
        }
    }
</style>
</head>

<body>
<div class="container">

<h1>Owner / Administrator Dashboard</h1>

<p>
    Logged in as:
    <strong><?php echo owner_escape($_SESSION["owner_username"] ?? "Administrator"); ?></strong>
</p>

<div class="top-links">
    <a class="button grey" href="owner_logout.php">Logout</a>
    <a class="button" href="index.php">Patient Dashboard</a>
</div>

<?php if ($message !== ""): ?>
    <div class="message">
        <?php echo owner_escape($message); ?>
    </div>
<?php endif; ?>


<!--==========================================================
    SECTION 1: REGISTER PATIENT
===========================================================-->

<div class="section">

<h2>1. Register New Patient</h2>

<form method="post" action="owner.php">

    <input type="hidden" name="csrf_token"
           value="<?php echo owner_escape($csrf_token); ?>">

    <input type="hidden" name="action" value="register_patient">

    <label>Patient ID *</label>
    <input type="text" name="patient_id" required>

    <label>Patient Full Name *</label>
    <input type="text" name="full_name" required>

    <label>Age</label>
    <input type="number" name="age" min="0" max="120">

    <label>Gender</label>
    <input type="text" name="gender">

    <label>Blood Group</label>
    <input type="text" name="blood_group">

    <label>Contact / Mobile Number</label>
    <input type="text" name="contact">

    <label>Email Address</label>
    <input type="email" name="email">

    <label>Notes</label>
    <textarea name="notes"></textarea>

    <br>
    <button type="submit">Register Patient</button>

</form>
</div>


<!--==========================================================
    SECTION 2: PATIENT LIST
===========================================================-->

<div class="section">

<h2>2. Registered Patients</h2>

<?php if (count($patients) === 0): ?>

    <p>No patients found.</p>

<?php else: ?>

<table>
<tr>
    <th>Patient ID</th>
    <th>Name</th>
    <th>Age</th>
    <th>Gender</th>
    <th>Blood Group</th>
    <th>Contact</th>
    <th>Email</th>
</tr>

<?php foreach ($patients as $patient): ?>
<tr>
    <td><?php echo owner_escape($patient["patient_id"]); ?></td>
    <td><?php echo owner_escape($patient["full_name"]); ?></td>
    <td><?php echo owner_escape($patient["age"]); ?></td>
    <td><?php echo owner_escape($patient["gender"]); ?></td>
    <td><?php echo owner_escape($patient["blood_group"]); ?></td>
    <td><?php echo owner_escape($patient["contact"]); ?></td>
    <td><?php echo owner_escape($patient["email"]); ?></td>
</tr>
<?php endforeach; ?>

</table>

<?php endif; ?>
</div>


<!--==========================================================
    SECTION 3: ASSIGN DEVICE
===========================================================-->

<div class="section">

<h2>3. Assign / Update Device to Patient</h2>

<form method="post" action="owner.php">

    <input type="hidden" name="csrf_token"
           value="<?php echo owner_escape($csrf_token); ?>">

    <input type="hidden" name="action" value="assign_device">

    <label>Device ID *</label>
    <input type="text" name="device_id"
           placeholder="Example: ESP32-01" required>

    <label>Patient *</label>
    <select name="device_patient_id" required>
        <option value="">Select Patient</option>

        <?php foreach ($patients as $patient): ?>
        <option value="<?php echo owner_escape($patient["patient_id"]); ?>">
            <?php
            echo owner_escape(
                $patient["patient_id"] . " - " . $patient["full_name"]
            );
            ?>
        </option>
        <?php endforeach; ?>

    </select>

    <label>Device Description</label>
    <input type="text" name="description">

    <label>Assignment Start Date / Time</label>
    <input type="datetime-local" name="start_date_time">

    <label>Assignment End Date / Time</label>
    <input type="datetime-local" name="end_date_time">

    <p class="note">
        Leave dates blank if no date restriction is required.
        Updating an existing device assignment preserves its current
        enabled/disabled setting.
    </p>

    <button type="submit">Save Device Assignment</button>

</form>
</div>


<!--==========================================================
    SECTION 4: DEVICE LIST AND ENABLE / DISABLE
===========================================================-->

<div class="section">

<h2>4. Device Assignments - Enable / Disable</h2>

<?php if (count($devices) === 0): ?>

    <p>No device assignments found.</p>

<?php else: ?>

<table>
<tr>
    <th>Device ID</th>
    <th>Patient ID</th>
    <th>Patient Name</th>
    <th>Description</th>
    <th>Enabled</th>
    <th>Start</th>
    <th>End</th>
    <th>Actions</th>
</tr>

<?php foreach ($devices as $device): ?>

<tr>
    <td><?php echo owner_escape($device["device_id"]); ?></td>
    <td><?php echo owner_escape($device["patient_id"]); ?></td>
    <td><?php echo owner_escape($device["full_name"]); ?></td>
    <td><?php echo owner_escape($device["description"]); ?></td>

    <td>
        <?php if ((int)$device["enabled"] === 1): ?>
            <span class="status-enabled">Enabled</span>
        <?php else: ?>
            <span class="status-disabled">Disabled</span>
        <?php endif; ?>
    </td>

    <td><?php echo owner_date_display($device["start_date_time"]); ?></td>
    <td><?php echo owner_date_display($device["end_date_time"]); ?></td>

    <td>

        <form method="post" action="owner.php" class="small-form">

            <input type="hidden" name="csrf_token"
                   value="<?php echo owner_escape($csrf_token); ?>">

            <input type="hidden" name="action" value="toggle_device">

            <input type="hidden" name="device_id"
                   value="<?php echo owner_escape($device["device_id"]); ?>">

            <?php if ((int)$device["enabled"] === 1): ?>

                <input type="hidden" name="new_status" value="0">
                <button type="submit" class="orange">Disable</button>

            <?php else: ?>

                <input type="hidden" name="new_status" value="1">
                <button type="submit" class="green">Enable</button>

            <?php endif; ?>

        </form>

        <form method="post" action="owner.php" class="small-form"
              onsubmit="return confirm('Delete this device assignment? Historical readings will remain.');">

            <input type="hidden" name="csrf_token"
                   value="<?php echo owner_escape($csrf_token); ?>">

            <input type="hidden" name="action" value="delete_device">

            <input type="hidden" name="device_id"
                   value="<?php echo owner_escape($device["device_id"]); ?>">

            <button type="submit" class="red">Delete</button>

        </form>

    </td>
</tr>

<?php endforeach; ?>

</table>

<?php endif; ?>
</div>


<!--==========================================================
    SECTION 5: PATIENT LOGIN ACCOUNT FORM
===========================================================-->

<div class="section">

<h2>5. Create / Edit Patient Login Account</h2>

<?php if ($edit_login !== null): ?>

    <div class="edit-panel">
        <strong>Editing Patient Login Account</strong>
        <p>
            Login ID:
            <?php echo (int)$edit_login["id"]; ?>
            &nbsp; | &nbsp;
            Username:
            <?php echo owner_escape($edit_login["username"]); ?>
        </p>
        <a class="button grey" href="owner.php">Cancel Editing</a>
    </div>

<?php endif; ?>

<form method="post" action="owner.php">

    <input type="hidden" name="csrf_token"
           value="<?php echo owner_escape($csrf_token); ?>">

    <input type="hidden" name="action" value="save_patient_login">

    <input type="hidden" name="login_id"
           value="<?php echo $edit_login ? (int)$edit_login["id"] : 0; ?>">

    <label>Patient Login Username *</label>
    <input type="text" name="username"
           value="<?php echo owner_escape($edit_login["username"] ?? ""); ?>"
           required>

    <label>Assigned Device *</label>
    <select name="login_device_id" required>

        <option value="">Select Enabled Device</option>

        <?php foreach ($devices as $device): ?>

            <?php
            $device_is_enabled = (int)$device["enabled"] === 1;
            $selected_device = $edit_login["device_id"] ?? "";
            ?>

            <?php if ($device_is_enabled || $selected_device === $device["device_id"]): ?>

                <option
                    value="<?php echo owner_escape($device["device_id"]); ?>"
                    <?php
                    echo ($selected_device === $device["device_id"])
                        ? "selected"
                        : "";
                    ?>
                >
                    <?php
                    echo owner_escape(
                        $device["device_id"] .
                        " - " .
                        $device["patient_id"] .
                        " - " .
                        $device["full_name"]
                    );
                    ?>
                </option>

            <?php endif; ?>

        <?php endforeach; ?>

    </select>

    <label>
        <?php echo $edit_login ? "New Password (optional)" : "Password *"; ?>
    </label>

    <input type="password" name="password"
           <?php echo $edit_login ? "" : "required"; ?>
           minlength="8"
           autocomplete="new-password">

    <?php if ($edit_login): ?>
        <p class="note">
            Leave the password blank to keep the existing password.
            Enter a new password only if you want to change it.
        </p>
    <?php else: ?>
        <p class="note">
            Password must contain at least 8 characters.
        </p>
    <?php endif; ?>

    <label>Login Valid From</label>
    <input type="datetime-local" name="valid_from"
           value="<?php echo owner_escape(
               owner_date_input($edit_login["valid_from"] ?? "")
           ); ?>">

    <label>Login Valid Until / Expiry</label>
    <input type="datetime-local" name="valid_until"
           value="<?php echo owner_escape(
               owner_date_input($edit_login["valid_until"] ?? "")
           ); ?>">

    <p class="note">
        Leave validity dates blank if no login date restriction is required.
        The assigned device must also be enabled and within its assignment dates.
    </p>

    <?php if ($edit_login): ?>

        <button type="submit" class="orange">Save Patient Login Changes</button>
        <a href="owner.php" class="button grey">Cancel</a>

    <?php else: ?>

        <button type="submit">Create Patient Login</button>

    <?php endif; ?>

</form>
</div>


<!--==========================================================
    SECTION 6: PATIENT LOGIN ACCOUNTS - ENABLE / DISABLE
===========================================================-->

<div class="section">

<h2>6. Patient Login Accounts - Enable / Disable / Edit / Delete</h2>

<p class="note">
    Use Edit to change a patient's username, assigned device,
    validity dates, or password. Enable / Disable controls whether
    the patient can log in. Delete removes only the login account,
    not the patient record or historical readings.
</p>

<?php if (count($patient_logins) === 0): ?>

    <p>No patient login accounts found.</p>

<?php else: ?>

<table>

<tr>
    <th>Login ID</th>
    <th>Username</th>
    <th>Patient ID</th>
    <th>Patient Name</th>
    <th>Device ID</th>
    <th>Login Status</th>
    <th>Valid From</th>
    <th>Valid Until</th>
    <th>Actions</th>
</tr>

<?php foreach ($patient_logins as $login): ?>

<tr>

    <td><?php echo (int)$login["id"]; ?></td>

    <td><?php echo owner_escape($login["username"]); ?></td>

    <td><?php echo owner_escape($login["patient_id"]); ?></td>

    <td><?php echo owner_escape($login["full_name"]); ?></td>

    <td><?php echo owner_escape($login["device_id"]); ?></td>

    <td>
        <?php if ((int)$login["enabled"] === 1): ?>
            <span class="status-enabled">Enabled</span>
        <?php else: ?>
            <span class="status-disabled">Disabled</span>
        <?php endif; ?>
    </td>

    <td><?php echo owner_date_display($login["valid_from"]); ?></td>

    <td><?php echo owner_date_display($login["valid_until"]); ?></td>

    <td>

        <!-- EDIT BUTTON -->

        <a
            class="button orange"
            href="owner.php?edit_login=<?php echo (int)$login["id"]; ?>"
        >
            Edit
        </a>

        <!-- ENABLE / DISABLE BUTTON -->

        <form method="post" action="owner.php" class="small-form">

            <input type="hidden" name="csrf_token"
                   value="<?php echo owner_escape($csrf_token); ?>">

            <input type="hidden" name="action"
                   value="toggle_patient_login">

            <input type="hidden" name="login_id"
                   value="<?php echo (int)$login["id"]; ?>">

            <?php if ((int)$login["enabled"] === 1): ?>

                <input type="hidden" name="new_status" value="0">
                <button type="submit" class="orange">Disable</button>

            <?php else: ?>

                <input type="hidden" name="new_status" value="1">
                <button type="submit" class="green">Enable</button>

            <?php endif; ?>

        </form>

        <!-- DELETE BUTTON -->

        <form method="post" action="owner.php" class="small-form"
              onsubmit="return confirm('Are you sure you want to delete this patient login account?');">

            <input type="hidden" name="csrf_token"
                   value="<?php echo owner_escape($csrf_token); ?>">

            <input type="hidden" name="action"
                   value="delete_patient_login">

            <input type="hidden" name="login_id"
                   value="<?php echo (int)$login["id"]; ?>">

            <button type="submit" class="red">Delete</button>

        </form>

    </td>

</tr>

<?php endforeach; ?>

</table>

<?php endif; ?>

</div>


<!--==========================================================
    END OF OWNER DASHBOARD
===========================================================-->

<div class="section">
    <p>
        <strong>Owner / Administrator Dashboard</strong>
    </p>

    <a class="button grey" href="owner_logout.php">Logout</a>
</div>

</div>
</body>
</html>
