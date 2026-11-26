<?php
/*
=========================================================
RAVI4 PATIENT MONITORING SYSTEM
File: owner.php

ADMINISTRATOR FUNCTIONS:
1. Register, edit and delete patient records.
2. Assign one device to multiple patients.
3. Enable, disable and delete individual patient/device
   assignments without affecting other patients.
4. Create, edit, enable, disable and delete patient logins.
5. View patient monitoring readings and alerts.

IMPORTANT:
- Uses MySQLi and the existing $db connection.
- Uses Ravi4 owner session variables.
- Every device operation uses BOTH device_id and patient_id.
- Deleting a login does not delete patient history.
- Deleting an assignment does not delete patient history.
=========================================================
*/

require_once __DIR__ . "/config.php";
date_default_timezone_set("Asia/Kolkata");

/*-------------------------------------------------------
  1. CHECK DATABASE CONNECTION
-------------------------------------------------------*/

if (!isset($db) || !($db instanceof mysqli)) {
    exit("Ravi4 database connection is not available.");
}

/*-------------------------------------------------------
  2. CHECK OWNER LOGIN
-------------------------------------------------------*/

if (
    empty($_SESSION["ravi4_owner_logged_in"]) ||
    $_SESSION["ravi4_owner_logged_in"] !== true
) {
    header("Location: owner_login.php");
    exit;
}

/*-------------------------------------------------------
  3. HELPER FUNCTIONS
-------------------------------------------------------*/

function r4_h($value)
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function r4_message($message)
{
    $_SESSION["ravi4_owner_message"] = $message;
    header("Location: owner.php");
    exit;
}

function r4_date_input($value)
{
    if (empty($value)) {
        return "";
    }

    $time = strtotime($value);

    return ($time === false)
        ? ""
        : date("Y-m-d\TH:i", $time);
}

function r4_date_display($value)
{
    if (empty($value)) {
        return "Not specified";
    }

    $time = strtotime($value);

    return ($time === false)
        ? r4_h($value)
        : date("d-m-Y H:i", $time);
}

function r4_valid_date($value)
{
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    $date = DateTime::createFromFormat(
        "Y-m-d\TH:i",
        $value
    );

    if (
        !$date ||
        $date->format("Y-m-d\TH:i") !== $value
    ) {
        return false;
    }

    return $date->format("Y-m-d H:i:s");
}

function r4_table_exists($db, $table)
{
    $allowed = [
        "patients",
        "patient_devices",
        "monitoring_readings",
        "alerts",
        "ecg_readings",
        "monitor_recordings",
        "web_users"
    ];

    if (!in_array($table, $allowed, true)) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("s", $table);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row["total"] ?? 0) > 0);
}

function r4_count_patient_rows($db, $table, $patient_id)
{
    if (!r4_table_exists($db, $table)) {
        return 0;
    }

    $allowed = [
        "patient_devices",
        "monitoring_readings",
        "alerts",
        "ecg_readings",
        "monitor_recordings"
    ];

    if (!in_array($table, $allowed, true)) {
        return 0;
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM `$table`
         WHERE patient_id = ?"
    );

    if (!$stmt) {
        return -1;
    }

    $stmt->bind_param("s", $patient_id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row["total"] ?? 0);
}

function r4_patient_name($patient)
{
    return $patient["full_name"]
        ?? $patient["patient_name"]
        ?? $patient["name"]
        ?? $patient["patient_id"]
        ?? "Unknown";
}

function r4_value($row, $column, $default = "-")
{
    if (
        isset($row[$column]) &&
        $row[$column] !== ""
    ) {
        return $row[$column];
    }

    return $default;
}

/*
  Check whether the exact patient/device assignment exists.

  This does not require the assignment to be enabled or
  within its dates. It is used when editing a login while
  keeping its existing patient/device combination.
*/
function r4_device_assignment_exists(
    $db,
    $device_id,
    $patient_id
) {
    $stmt = $db->prepare(
        "SELECT device_id
         FROM patient_devices
         WHERE device_id = ?
           AND patient_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ss", $device_id, $patient_id);
    $stmt->execute();

    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $found;
}

/*
  Check that the exact patient/device assignment exists,
  is enabled and is within its assignment dates.
*/
function r4_device_belongs_to_patient(
    $db,
    $device_id,
    $patient_id
) {
    $stmt = $db->prepare(
        "SELECT device_id
         FROM patient_devices
         WHERE device_id = ?
           AND patient_id = ?
           AND enabled = 1
           AND (
                start_date_time IS NULL
                OR start_date_time <= NOW()
           )
           AND (
                end_date_time IS NULL
                OR end_date_time >= NOW()
           )
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ss", $device_id, $patient_id);
    $stmt->execute();

    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $found;
}

/*-------------------------------------------------------
  4. CSRF SECURITY TOKEN
-------------------------------------------------------*/

if (empty($_SESSION["ravi4_owner_csrf"])) {
    $_SESSION["ravi4_owner_csrf"] =
        bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["ravi4_owner_csrf"];

/*-------------------------------------------------------
  5. PROCESS ADMINISTRATOR FORMS
-------------------------------------------------------*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $posted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($posted_token) ||
        !hash_equals($csrf_token, $posted_token)
    ) {
        r4_message("Security check failed. Please try again.");
    }

    $action = $_POST["action"] ?? "";

    /*===================================================
      ACTION 1: REGISTER NEW PATIENT
    ===================================================*/

    if ($action === "register_patient") {

        $patient_id = trim($_POST["patient_id"] ?? "");
        $full_name = trim($_POST["full_name"] ?? "");
        $age_input = trim($_POST["age"] ?? "");
        $gender = trim($_POST["gender"] ?? "");
        $blood_group = trim($_POST["blood_group"] ?? "");
        $contact = trim($_POST["contact"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $notes = trim($_POST["notes"] ?? "");

        if ($patient_id === "" || $full_name === "") {
            r4_message("Patient ID and patient name are required.");
        }

        $age = null;

        if ($age_input !== "") {
            if (
                !ctype_digit($age_input) ||
                (int)$age_input > 120
            ) {
                r4_message("Please enter a valid age.");
            }

            $age = (int)$age_input;
        }

        $check = $db->prepare(
            "SELECT patient_id
             FROM patients
             WHERE patient_id = ?
             LIMIT 1"
        );

        if (!$check) {
            r4_message("Could not check Patient ID.");
        }

        $check->bind_param("s", $patient_id);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $check->close();
            r4_message("This Patient ID already exists.");
        }

        $check->close();

        $stmt = $db->prepare(
            "INSERT INTO patients
            (patient_id, full_name, age, gender,
             blood_group, contact, email, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            r4_message("Could not prepare patient registration.");
        }

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
            error_log("Ravi4 patient registration: " . $stmt->error);
            $stmt->close();
            r4_message("Could not register patient. Please check the database fields.");
        }

        $stmt->close();
        r4_message("Patient registered successfully.");
    }

    /*===================================================
      ACTION 2: EDIT PATIENT DETAILS
    ===================================================*/

    if ($action === "edit_patient") {

        $patient_id = trim($_POST["patient_id"] ?? "");
        $full_name = trim($_POST["full_name"] ?? "");
        $age_input = trim($_POST["age"] ?? "");
        $gender = trim($_POST["gender"] ?? "");
        $blood_group = trim($_POST["blood_group"] ?? "");
        $contact = trim($_POST["contact"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $notes = trim($_POST["notes"] ?? "");

        if ($patient_id === "" || $full_name === "") {
            r4_message("Patient ID and name are required.");
        }

        $age = null;

        if ($age_input !== "") {
            if (
                !ctype_digit($age_input) ||
                (int)$age_input > 120
            ) {
                r4_message("Please enter a valid age.");
            }

            $age = (int)$age_input;
        }

        $stmt = $db->prepare(
            "UPDATE patients
             SET full_name = ?,
                 age = ?,
                 gender = ?,
                 blood_group = ?,
                 contact = ?,
                 email = ?,
                 notes = ?
             WHERE patient_id = ?"
        );

        if (!$stmt) {
            r4_message("Could not prepare patient update.");
        }

        $stmt->bind_param(
            "sissssss",
            $full_name,
            $age,
            $gender,
            $blood_group,
            $contact,
            $email,
            $notes,
            $patient_id
        );

        if (!$stmt->execute()) {
            error_log("Ravi4 patient edit: " . $stmt->error);
            $stmt->close();
            r4_message("Could not update patient details.");
        }

        $stmt->close();
        r4_message("Patient details updated successfully.");
    }

    /*===================================================
      ACTION 3: DELETE PATIENT RECORD SAFELY
    ===================================================*/

    if ($action === "delete_patient") {

        $patient_id = trim($_POST["patient_id"] ?? "");

        if ($patient_id === "") {
            r4_message("Patient ID is missing.");
        }

        $linkedTables = [
            "patient_devices",
            "monitoring_readings",
            "alerts",
            "ecg_readings",
            "monitor_recordings"
        ];

        foreach ($linkedTables as $table) {

            $count = r4_count_patient_rows(
                $db,
                $table,
                $patient_id
            );

            if ($count < 0) {
                r4_message(
                    "Could not check linked records. Patient was not deleted."
                );
            }

            if ($count > 0) {
                r4_message(
                    "Patient was not deleted. Linked records exist in " .
                    $table .
                    ". Historical records have been protected."
                );
            }
        }

        $check = $db->prepare(
            "SELECT id
             FROM web_users
             WHERE patient_id = ?
               AND role = 'patient'
             LIMIT 1"
        );

        if (!$check) {
            r4_message("Could not check patient login accounts.");
        }

        $check->bind_param("s", $patient_id);
        $check->execute();

        $hasLogin = $check->get_result()->num_rows > 0;
        $check->close();

        if ($hasLogin) {
            r4_message(
                "Patient was not deleted because a patient login account is linked to it. Delete that login first if deletion is required."
            );
        }

        $stmt = $db->prepare(
            "DELETE FROM patients WHERE patient_id = ?"
        );

        if (!$stmt) {
            r4_message("Could not prepare patient deletion.");
        }

        $stmt->bind_param("s", $patient_id);

        if (!$stmt->execute()) {
            $stmt->close();
            r4_message("Patient could not be deleted. A linked database record may exist.");
        }

        $stmt->close();
        r4_message("Patient record deleted.");
    }

    /*===================================================
      ACTION 4: ASSIGN OR UPDATE DEVICE

      IMPORTANT:
      We check the combination of device_id AND patient_id.

      If ESP32-01 is already assigned to P1001, assigning
      ESP32-01 to P1004 creates another assignment row.

      P1001's assignment is not overwritten.
    ===================================================*/

    if ($action === "assign_device") {

        $device_id = trim($_POST["device_id"] ?? "");
        $patient_id = trim($_POST["device_patient_id"] ?? "");
        $description = trim($_POST["description"] ?? "");

        $start_date = r4_valid_date(
            $_POST["start_date_time"] ?? ""
        );

        $end_date = r4_valid_date(
            $_POST["end_date_time"] ?? ""
        );

        if ($device_id === "" || $patient_id === "") {
            r4_message("Device ID and Patient ID are required.");
        }

        if (strlen($device_id) > 32) {
            r4_message("Device ID must not exceed 32 characters.");
        }

        if ($start_date === false || $end_date === false) {
            r4_message("Please enter valid device assignment dates.");
        }

        if (
            $start_date !== null &&
            $end_date !== null &&
            strtotime($end_date) <= strtotime($start_date)
        ) {
            r4_message("Device end date must be later than start date.");
        }

        /* Confirm that the patient exists. */

        $check = $db->prepare(
            "SELECT patient_id
             FROM patients
             WHERE patient_id = ?
             LIMIT 1"
        );

        if (!$check) {
            r4_message("Could not check patient.");
        }

        $check->bind_param("s", $patient_id);
        $check->execute();

        if ($check->get_result()->num_rows === 0) {
            $check->close();
            r4_message("Patient ID does not exist.");
        }

        $check->close();

        /*
          Check the exact pair.

          Do not search by device_id alone.
        */

        $check = $db->prepare(
            "SELECT device_id
             FROM patient_devices
             WHERE device_id = ?
               AND patient_id = ?
             LIMIT 1"
        );

        if (!$check) {
            r4_message("Could not check existing patient/device assignment.");
        }

        $check->bind_param("ss", $device_id, $patient_id);
        $check->execute();

        $pairExists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($pairExists) {

            /*
              Update only this patient's assignment.

              The enabled status is deliberately not changed.
            */

            $stmt = $db->prepare(
                "UPDATE patient_devices
                 SET description = ?,
                     start_date_time = ?,
                     end_date_time = ?
                 WHERE device_id = ?
                   AND patient_id = ?"
            );

            if (!$stmt) {
                r4_message("Could not prepare device assignment update.");
            }

            $stmt->bind_param(
                "sssss",
                $description,
                $start_date,
                $end_date,
                $device_id,
                $patient_id
            );

        } else {

            /*
              This patient does not yet have this device.

              Insert a separate assignment row.
              The same device_id may belong to other patients.
            */

            $stmt = $db->prepare(
                "INSERT INTO patient_devices
                (device_id, patient_id, description, enabled,
                 start_date_time, end_date_time)
                VALUES (?, ?, ?, 1, ?, ?)"
            );

            if (!$stmt) {
                r4_message("Could not prepare new device assignment.");
            }

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
            error_log("Ravi4 device assignment: " . $stmt->error);
            $stmt->close();
            r4_message("Could not save device assignment.");
        }

        $stmt->close();

        r4_message(
            "Device assignment saved for patient " .
            $patient_id .
            ". Other patients assigned to this device were not changed."
        );
    }

    /*===================================================
      ACTION 5: ENABLE OR DISABLE ONE DEVICE ASSIGNMENT

      IMPORTANT:
      Only the selected patient/device pair is changed.
    ===================================================*/

    if ($action === "toggle_device") {

        $device_id = trim($_POST["device_id"] ?? "");
        $patient_id = trim($_POST["patient_id"] ?? "");
        $new_status = (int)($_POST["new_status"] ?? -1);

        if (
            $device_id === "" ||
            $patient_id === "" ||
            !in_array($new_status, [0, 1], true)
        ) {
            r4_message("Invalid device status request.");
        }

        $stmt = $db->prepare(
            "UPDATE patient_devices
             SET enabled = ?
             WHERE device_id = ?
               AND patient_id = ?"
        );

        if (!$stmt) {
            r4_message("Could not prepare device status update.");
        }

        $stmt->bind_param(
            "iss",
            $new_status,
            $device_id,
            $patient_id
        );

        if (!$stmt->execute()) {
            error_log("Ravi4 device status: " . $stmt->error);
            $stmt->close();
            r4_message("Could not update device status.");
        }

        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed < 0) {
            r4_message("Device status update failed.");
        }

        r4_message(
            "Device assignment status updated for " .
            $patient_id .
            " / " .
            $device_id .
            ". Other patients were not affected."
        );
    }

    /*===================================================
      ACTION 6: DELETE ONE DEVICE ASSIGNMENT

      IMPORTANT:
      Only the selected patient/device pair is deleted.
      Other patients using the same device are not affected.
    ===================================================*/

    if ($action === "delete_device") {

        $device_id = trim($_POST["device_id"] ?? "");
        $patient_id = trim($_POST["patient_id"] ?? "");

        if ($device_id === "" || $patient_id === "") {
            r4_message("Device ID or Patient ID is missing.");
        }

        /*
          Check whether this exact patient/device pair is
          being used by a patient login.
        */

        $check = $db->prepare(
            "SELECT id
             FROM web_users
             WHERE device_id = ?
               AND patient_id = ?
               AND role = 'patient'
             LIMIT 1"
        );

        if (!$check) {
            r4_message("Could not check linked patient login.");
        }

        $check->bind_param("ss", $device_id, $patient_id);
        $check->execute();

        $hasLogin = $check->get_result()->num_rows > 0;
        $check->close();

        if ($hasLogin) {
            r4_message(
                "Assignment was not deleted because a patient login uses this patient/device combination. Edit or delete that login first."
            );
        }

        $stmt = $db->prepare(
            "DELETE FROM patient_devices
             WHERE device_id = ?
               AND patient_id = ?"
        );

        if (!$stmt) {
            r4_message("Could not prepare device assignment deletion.");
        }

        $stmt->bind_param("ss", $device_id, $patient_id);

        if (!$stmt->execute()) {
            error_log("Ravi4 device deletion: " . $stmt->error);
            $stmt->close();
            r4_message("Could not delete device assignment.");
        }

        $stmt->close();

        r4_message(
            "Device assignment for " .
            $patient_id .
            " / " .
            $device_id .
            " deleted. Other patients and historical readings have not been deleted."
        );
    }

    /*===================================================
      ACTION 7: CREATE OR EDIT PATIENT LOGIN
    ===================================================*/

    if ($action === "save_patient_login") {

        $login_id = (int)($_POST["login_id"] ?? 0);

        $patient_id = trim($_POST["login_patient_id"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $password = (string)($_POST["password"] ?? "");
        $device_id = trim($_POST["login_device_id"] ?? "");

        $valid_from = r4_valid_date(
            $_POST["valid_from"] ?? ""
        );

        $valid_until = r4_valid_date(
            $_POST["valid_until"] ?? ""
        );

        if (
            $patient_id === "" ||
            $username === "" ||
            $device_id === ""
        ) {
            r4_message(
                "Patient, username and assigned device are required."
            );
        }

        if (
            $valid_from === false ||
            $valid_until === false
        ) {
            r4_message("Please enter valid login validity dates.");
        }

        if (
            $valid_from !== null &&
            $valid_until !== null &&
            strtotime($valid_until) <= strtotime($valid_from)
        ) {
            r4_message(
                "Login expiry must be later than login start date."
            );
        }

        /* Confirm that the selected patient exists. */

        $patientCheck = $db->prepare(
            "SELECT patient_id
             FROM patients
             WHERE patient_id = ?
             LIMIT 1"
        );

        if (!$patientCheck) {
            r4_message("Could not check selected patient.");
        }

        $patientCheck->bind_param("s", $patient_id);
        $patientCheck->execute();

        $patientExists =
            $patientCheck->get_result()->num_rows > 0;

        $patientCheck->close();

        if (!$patientExists) {
            r4_message("Selected patient does not exist.");
        }

        /*
          For an edit, load the old patient/device pair.
        */

        $old_patient_id = "";
        $old_device_id = "";
        $editingExistingLogin = false;

        if ($login_id > 0) {

            $oldCheck = $db->prepare(
                "SELECT patient_id, device_id
                 FROM web_users
                 WHERE id = ?
                   AND role = 'patient'
                 LIMIT 1"
            );

            if (!$oldCheck) {
                r4_message("Could not check existing patient login.");
            }

            $oldCheck->bind_param("i", $login_id);
            $oldCheck->execute();

            $oldLogin = $oldCheck->get_result()->fetch_assoc();
            $oldCheck->close();

            if (!$oldLogin) {
                r4_message("Patient login account was not found.");
            }

            $editingExistingLogin = true;
            $old_patient_id = (string)$oldLogin["patient_id"];
            $old_device_id = (string)$oldLogin["device_id"];
        }

        /*
          If the pair is unchanged, it only needs to exist.

          If the pair is new or changed, it must be enabled
          and within the assignment dates.
        */

        $sameAssignment = (
            $editingExistingLogin &&
            $old_patient_id === $patient_id &&
            $old_device_id === $device_id
        );

        if ($sameAssignment) {

            if (
                !r4_device_assignment_exists(
                    $db,
                    $device_id,
                    $patient_id
                )
            ) {
                r4_message(
                    "The existing patient/device assignment was not found. Please check the device assignment."
                );
            }

        } else {

            if (
                !r4_device_belongs_to_patient(
                    $db,
                    $device_id,
                    $patient_id
                )
            ) {
                r4_message(
                    "The selected device is not currently enabled and assigned to the selected patient, or its assignment dates are not valid."
                );
            }
        }

        /* Check username uniqueness. */

        if ($login_id > 0) {

            $check = $db->prepare(
                "SELECT id
                 FROM web_users
                 WHERE username = ?
                   AND id <> ?
                 LIMIT 1"
            );

            if (!$check) {
                r4_message("Could not check username.");
            }

            $check->bind_param("si", $username, $login_id);

        } else {

            $check = $db->prepare(
                "SELECT id
                 FROM web_users
                 WHERE username = ?
                 LIMIT 1"
            );

            if (!$check) {
                r4_message("Could not check username.");
            }

            $check->bind_param("s", $username);
        }

        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $check->close();
            r4_message("This username is already in use.");
        }

        $check->close();

        /* EDIT EXISTING LOGIN */

        if ($login_id > 0) {

            if ($password !== "") {

                if (strlen($password) < 8) {
                    r4_message(
                        "New password must contain at least 8 characters."
                    );
                }

                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $db->prepare(
                    "UPDATE web_users
                     SET patient_id = ?,
                         username = ?,
                         device_id = ?,
                         valid_from = ?,
                         valid_until = ?,
                         password_hash = ?
                     WHERE id = ?
                       AND role = 'patient'"
                );

                if (!$stmt) {
                    r4_message("Could not prepare patient login update.");
                }

                $stmt->bind_param(
                    "ssssssi",
                    $patient_id,
                    $username,
                    $device_id,
                    $valid_from,
                    $valid_until,
                    $password_hash,
                    $login_id
                );

            } else {

                $stmt = $db->prepare(
                    "UPDATE web_users
                     SET patient_id = ?,
                         username = ?,
                         device_id = ?,
                         valid_from = ?,
                         valid_until = ?
                     WHERE id = ?
                       AND role = 'patient'"
                );

                if (!$stmt) {
                    r4_message("Could not prepare patient login update.");
                }

                $stmt->bind_param(
                    "sssssi",
                    $patient_id,
                    $username,
                    $device_id,
                    $valid_from,
                    $valid_until,
                    $login_id
                );
            }

            if (!$stmt->execute()) {
                error_log("Ravi4 patient login update: " . $stmt->error);
                $stmt->close();
                r4_message("Could not update patient login.");
            }

            $stmt->close();
            r4_message("Patient login account updated successfully.");
        }

        /* CREATE NEW LOGIN */

        if ($password === "" || strlen($password) < 8) {
            r4_message(
                "New patient password must contain at least 8 characters."
            );
        }

        $password_hash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        $role = "patient";
        $enabled = 1;

        $stmt = $db->prepare(
            "INSERT INTO web_users
            (patient_id, username, password_hash, role, enabled,
             device_id, valid_from, valid_until)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            r4_message("Could not prepare new patient login.");
        }

        $stmt->bind_param(
            "ssssisss",
            $patient_id,
            $username,
            $password_hash,
            $role,
            $enabled,
            $device_id,
            $valid_from,
            $valid_until
        );

        if (!$stmt->execute()) {
            error_log("Ravi4 patient login creation: " . $stmt->error);
            $stmt->close();
            r4_message("Could not create patient login account.");
        }

        $stmt->close();
        r4_message("New patient login account created successfully.");
    }

    /*===================================================
      ACTION 8: ENABLE OR DISABLE PATIENT LOGIN
    ===================================================*/

    if ($action === "toggle_patient_login") {

        $login_id = (int)($_POST["login_id"] ?? 0);
        $new_status = (int)($_POST["new_status"] ?? -1);

        if (
            $login_id <= 0 ||
            !in_array($new_status, [0, 1], true)
        ) {
            r4_message("Invalid patient login status.");
        }

        $stmt = $db->prepare(
            "UPDATE web_users
             SET enabled = ?
             WHERE id = ?
               AND role = 'patient'"
        );

        if (!$stmt) {
            r4_message("Could not prepare patient login status update.");
        }

        $stmt->bind_param("ii", $new_status, $login_id);

        if (!$stmt->execute()) {
            $stmt->close();
            r4_message("Could not update patient login status.");
        }

        $stmt->close();
        r4_message("Patient login status updated.");
    }

    /*===================================================
      ACTION 9: DELETE PATIENT LOGIN
    ===================================================*/

    if ($action === "delete_patient_login") {

        $login_id = (int)($_POST["login_id"] ?? 0);

        if ($login_id <= 0) {
            r4_message("Invalid patient login account.");
        }

        $stmt = $db->prepare(
            "DELETE FROM web_users
             WHERE id = ?
               AND role = 'patient'"
        );

        if (!$stmt) {
            r4_message("Could not prepare patient login deletion.");
        }

        $stmt->bind_param("i", $login_id);

        if (!$stmt->execute()) {
            $stmt->close();
            r4_message("Could not delete patient login.");
        }

        $stmt->close();

        r4_message(
            "Patient login deleted. Patient details and historical readings remain."
        );
    }

    r4_message("Unknown administrator request.");
}

/*-------------------------------------------------------
  6. LOAD PATIENT LOGIN ACCOUNT FOR EDITING
-------------------------------------------------------*/

$edit_login = null;
$edit_login_id = (int)($_GET["edit_login"] ?? 0);

if ($edit_login_id > 0) {

    $stmt = $db->prepare(
        "SELECT id, patient_id, username, device_id,
                valid_from, valid_until
         FROM web_users
         WHERE id = ?
           AND role = 'patient'
         LIMIT 1"
    );

    if (!$stmt) {
        r4_message("Could not load patient login.");
    }

    $stmt->bind_param("i", $edit_login_id);
    $stmt->execute();

    $edit_login = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$edit_login) {
        r4_message("Patient login account was not found.");
    }
}

/*-------------------------------------------------------
  7. LOAD PATIENT RECORD FOR EDITING
-------------------------------------------------------*/

$edit_patient = null;
$edit_patient_id = trim($_GET["edit_patient"] ?? "");

if ($edit_patient_id !== "") {

    $stmt = $db->prepare(
        "SELECT patient_id, full_name, age, gender,
                blood_group, contact, email, notes
         FROM patients
         WHERE patient_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        r4_message("Could not load patient record.");
    }

    $stmt->bind_param("s", $edit_patient_id);
    $stmt->execute();

    $edit_patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$edit_patient) {
        r4_message("Patient record was not found.");
    }
}

/*-------------------------------------------------------
  8. DISPLAY MESSAGE
-------------------------------------------------------*/

$message = $_SESSION["ravi4_owner_message"] ?? "";
unset($_SESSION["ravi4_owner_message"]);

/*-------------------------------------------------------
  9. LOAD PATIENT LIST
-------------------------------------------------------*/

$patients = [];

$result = $db->query(
    "SELECT patient_id, full_name, age, gender,
            blood_group, contact, email, notes
     FROM patients
     ORDER BY patient_id"
);

if (!$result) {
    error_log("Ravi4 patient list: " . $db->error);
    exit("Unable to load patient records.");
}

while ($row = $result->fetch_assoc()) {
    $patients[] = $row;
}

$result->free();

/*-------------------------------------------------------
  10. LOAD ALL DEVICE ASSIGNMENTS

  The same device ID can appear more than once because
  it may be assigned to different patients.
-------------------------------------------------------*/

$devices = [];
$deviceAssignments = [];

$result = $db->query(
    "SELECT d.device_id, d.patient_id, d.description,
            d.enabled, d.start_date_time, d.end_date_time,
            p.full_name
     FROM patient_devices d
     LEFT JOIN patients p
       ON p.patient_id = d.patient_id
     ORDER BY d.device_id, d.patient_id"
);

if (!$result) {
    error_log("Ravi4 device list: " . $db->error);
    exit("Unable to load device assignments.");
}

while ($row = $result->fetch_assoc()) {

    $devices[] = $row;

    $key = (string)$row["patient_id"];

    if (!isset($deviceAssignments[$key])) {
        $deviceAssignments[$key] = [];
    }

    $deviceAssignments[$key][] = $row;
}

$result->free();

/*-------------------------------------------------------
  11. LOAD PATIENT LOGIN ACCOUNTS
-------------------------------------------------------*/

$patient_logins = [];

$result = $db->query(
    "SELECT
        w.id,
        w.patient_id,
        w.username,
        w.enabled,
        w.device_id,
        w.valid_from,
        w.valid_until,
        p.full_name
     FROM web_users w
     LEFT JOIN patients p
       ON p.patient_id = w.patient_id
     WHERE w.role = 'patient'
     ORDER BY w.username"
);

if (!$result) {
    error_log("Ravi4 patient logins: " . $db->error);
    exit("Unable to load patient login accounts.");
}

while ($row = $result->fetch_assoc()) {
    $patient_logins[] = $row;
}

$result->free();

/*-------------------------------------------------------
  12. LOAD SELECTED PATIENT FOR MONITORING REPORT
-------------------------------------------------------*/

$selectedPatientId = trim($_GET["patient_id"] ?? "");
$selectedPatient = null;
$selectedDevices = [];

foreach ($patients as $patient) {

    if (
        $selectedPatientId !== "" &&
        (string)$patient["patient_id"] === $selectedPatientId
    ) {
        $selectedPatient = $patient;
        break;
    }
}

if ($selectedPatient !== null) {
    $selectedDevices =
        $deviceAssignments[$selectedPatientId] ?? [];
}

/*-------------------------------------------------------
  13. LOAD RECENT MONITORING READINGS
-------------------------------------------------------*/

$monitoringReadings = [];
$monitoringError = "";

if ($selectedPatient !== null) {

    $stmt = $db->prepare(
        "SELECT *
         FROM monitoring_readings
         WHERE patient_id = ?
         ORDER BY recorded_at DESC
         LIMIT 50"
    );

    if ($stmt) {

        $stmt->bind_param("s", $selectedPatientId);

        if ($stmt->execute()) {

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $monitoringReadings[] = $row;
            }

            $result->free();

        } else {
            $monitoringError = "Unable to load monitoring readings.";
        }

        $stmt->close();

    } else {
        $monitoringError = "Unable to prepare monitoring query.";
    }
}

/*-------------------------------------------------------
  14. LOAD RECENT ALERTS
-------------------------------------------------------*/

$patientAlerts = [];
$alertError = "";

if ($selectedPatient !== null) {

    $stmt = $db->prepare(
        "SELECT *
         FROM alerts
         WHERE patient_id = ?
         ORDER BY recorded_at DESC
         LIMIT 50"
    );

    if ($stmt) {

        $stmt->bind_param("s", $selectedPatientId);

        if ($stmt->execute()) {

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $patientAlerts[] = $row;
            }

            $result->free();

        } else {
            $alertError = "Unable to load patient alerts.";
        }

        $stmt->close();

    } else {
        $alertError = "Unable to prepare alert query.";
    }
}

$ownerUsername =
    $_SESSION["ravi4_owner_username"] ?? "Administrator";

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ravi4 Administrator Dashboard</title>

<style>
* { box-sizing: border-box; }

body {
    font-family: Arial, sans-serif;
    background: #f3f5f7;
    margin: 0;
    padding: 18px;
    color: #222;
}

.container {
    max-width: 1250px;
    margin: auto;
}

.header {
    background: #17365d;
    color: white;
    padding: 18px;
    border-radius: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.header h1 {
    margin: 0 0 8px;
    font-size: 24px;
}

.header a {
    color: white;
    text-decoration: none;
    background: #b52b2b;
    padding: 9px 13px;
    border-radius: 5px;
}

.section {
    background: white;
    padding: 18px;
    margin: 20px 0;
    border-radius: 7px;
    box-shadow: 0 1px 5px rgba(0,0,0,0.08);
}

h2 {
    background: #e7eef7;
    padding: 12px;
    border-left: 5px solid #245a91;
    margin-top: 0;
    color: #17365d;
    font-size: 19px;
}

h3 { color: #17365d; }

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
    border: 1px solid #bbb;
    border-radius: 4px;
    background: white;
}

textarea { min-height: 65px; }

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

button:hover, .button:hover { opacity: 0.85; }

.green { background: #198754; }
.red { background: #c0392b; }
.orange { background: #d97706; }
.grey { background: #555; }

.message {
    background: #e4f5e8;
    border: 1px solid #8bc99a;
    padding: 12px;
    border-radius: 5px;
    margin: 15px 0;
}

.warning {
    background: #fff0d5;
    color: #795000;
    padding: 12px;
    border-radius: 5px;
    margin: 12px 0;
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
    font-size: 14px;
}

th { background: #e7eef7; }

.status-enabled {
    color: green;
    font-weight: bold;
}

.status-disabled {
    color: red;
    font-weight: bold;
}

.small-form { display: inline; }

.note, .small {
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

.report-link {
    display: inline-block;
    background: #1769aa;
    color: white;
    text-decoration: none;
    padding: 7px 11px;
    border-radius: 5px;
}

.info {
    background: #e8f2ff;
    padding: 12px;
    border-radius: 5px;
}

@media(max-width: 750px) {
    body { padding: 8px; }

    table {
        display: block;
        overflow-x: auto;
    }

    .header { display: block; }

    .header a {
        display: inline-block;
        margin-top: 12px;
    }
}
</style>
</head>

<body>
<div class="container">

<div class="header">
    <div>
        <h1>Ravi4 Administrator Dashboard</h1>
        Logged in as:
        <strong><?= r4_h($ownerUsername) ?></strong>
    </div>

    <div>
        <a href="owner_logout.php">Owner Logout</a>
    </div>
</div>

<?php if ($message !== ""): ?>
    <div class="message"><?= r4_h($message) ?></div>
<?php endif; ?>

<!--=====================================================
    SECTION 1: REGISTER OR EDIT PATIENT
======================================================-->

<div class="section">

<?php if ($edit_patient !== null): ?>

    <h2>1. Edit Patient Details</h2>

    <div class="edit-panel">
        Editing Patient:
        <strong><?= r4_h($edit_patient["patient_id"]) ?></strong>
        <br>
        <a class="button grey" href="owner.php">Cancel Editing</a>
    </div>

    <form method="post" action="owner.php">
        <input type="hidden" name="csrf_token"
               value="<?= r4_h($csrf_token) ?>">
        <input type="hidden" name="action" value="edit_patient">
        <input type="hidden" name="patient_id"
               value="<?= r4_h($edit_patient["patient_id"]) ?>">

        <label>Patient ID</label>
        <input type="text"
               value="<?= r4_h($edit_patient["patient_id"]) ?>"
               disabled>

        <label>Patient Full Name *</label>
        <input type="text" name="full_name"
               value="<?= r4_h($edit_patient["full_name"]) ?>"
               required>

        <label>Age</label>
        <input type="number" name="age" min="0" max="120"
               value="<?= r4_h($edit_patient["age"]) ?>">

        <label>Gender</label>
        <input type="text" name="gender"
               value="<?= r4_h($edit_patient["gender"]) ?>">

        <label>Blood Group</label>
        <input type="text" name="blood_group"
               value="<?= r4_h($edit_patient["blood_group"]) ?>">

        <label>Contact / Mobile Number</label>
        <input type="text" name="contact"
               value="<?= r4_h($edit_patient["contact"]) ?>">

        <label>Email Address</label>
        <input type="email" name="email"
               value="<?= r4_h($edit_patient["email"]) ?>">

        <label>Notes</label>
        <textarea name="notes"><?= r4_h($edit_patient["notes"]) ?></textarea>

        <button type="submit" class="orange">
            Save Patient Changes
        </button>

        <a class="button grey" href="owner.php">Cancel</a>
    </form>

<?php else: ?>

    <h2>1. Register New Patient</h2>

    <form method="post" action="owner.php">
        <input type="hidden" name="csrf_token"
               value="<?= r4_h($csrf_token) ?>">
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

        <button type="submit">Register Patient</button>
    </form>

<?php endif; ?>

</div>

<!--=====================================================
    SECTION 2: REGISTERED PATIENTS
======================================================-->

<div class="section">

<h2>2. Registered Patients - Edit / Delete</h2>

<p>
    Total registered patients:
    <strong><?= count($patients) ?></strong>
</p>

<?php if (count($patients) === 0): ?>

    <p>No patients found.</p>

<?php else: ?>

<div style="overflow-x:auto;">
<table>
<tr>
    <th>Patient ID</th>
    <th>Name</th>
    <th>Age</th>
    <th>Gender</th>
    <th>Blood Group</th>
    <th>Contact</th>
    <th>Email</th>
    <th>Actions</th>
</tr>

<?php foreach ($patients as $patient): ?>
<tr>
    <td><?= r4_h($patient["patient_id"]) ?></td>
    <td><?= r4_h($patient["full_name"]) ?></td>
    <td><?= r4_h($patient["age"]) ?></td>
    <td><?= r4_h($patient["gender"]) ?></td>
    <td><?= r4_h($patient["blood_group"]) ?></td>
    <td><?= r4_h($patient["contact"]) ?></td>
    <td><?= r4_h($patient["email"]) ?></td>

    <td>
        <a class="button orange"
           href="owner.php?edit_patient=<?= urlencode($patient["patient_id"]) ?>">
            Edit
        </a>

        <form method="post" action="owner.php"
              class="small-form"
              onsubmit="return confirm('Delete this patient record? This is allowed only if no linked records exist.');">

            <input type="hidden" name="csrf_token"
                   value="<?= r4_h($csrf_token) ?>">
            <input type="hidden" name="action" value="delete_patient">
            <input type="hidden" name="patient_id"
                   value="<?= r4_h($patient["patient_id"]) ?>">

            <button type="submit" class="red">Delete</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>

</table>
</div>

<?php endif; ?>

<p class="note">
    Delete is restricted when the patient has device assignments,
    login accounts or historical records.
</p>

</div>

<!--=====================================================
    SECTION 3: ASSIGN DEVICE
======================================================-->

<div class="section">

<h2>3. Assign / Update ESP32 Device</h2>

<div class="info">
    <strong>Shared device support:</strong>
    The same ESP32 Device ID can be assigned to multiple patients.
    For example, ESP32-01 can be assigned separately to P1001 and P1004.
    Saving one patient's assignment will not replace another patient's assignment.
</div>

<form method="post" action="owner.php">

    <input type="hidden" name="csrf_token"
           value="<?= r4_h($csrf_token) ?>">
    <input type="hidden" name="action" value="assign_device">

    <label>Device ID *</label>
    <input type="text" name="device_id"
           placeholder="Example: ESP32-01"
           maxlength="32"
           required>

    <label>Patient *</label>
    <select name="device_patient_id" required>
        <option value="">Select Patient</option>

        <?php foreach ($patients as $patient): ?>
            <option value="<?= r4_h($patient["patient_id"]) ?>">
                <?= r4_h(
                    $patient["patient_id"] . " - " . $patient["full_name"]
                ) ?>
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
        If this exact patient/device pair already exists, its description
        and dates will be updated. Its enabled/disabled status will be retained.
    </p>

    <button type="submit">Save Device Assignment</button>
</form>

</div>

<!--=====================================================
    SECTION 4: DEVICE ASSIGNMENTS
======================================================-->

<div class="section">

<h2>4. Device Assignments - Enable / Disable / Delete</h2>

<p class="note">
    Each row represents one patient/device assignment.
    The same Device ID may appear in several rows for different patients.
    The actions below affect only the patient shown in that row.
</p>

<?php if (count($devices) === 0): ?>

    <p>No device assignments found.</p>

<?php else: ?>

<div style="overflow-x:auto;">
<table>
<tr>
    <th>Device ID</th>
    <th>Patient ID</th>
    <th>Patient Name</th>
    <th>Description</th>
    <th>Status</th>
    <th>Start</th>
    <th>End</th>
    <th>Actions</th>
</tr>

<?php foreach ($devices as $device): ?>
<tr>
    <td><strong><?= r4_h($device["device_id"]) ?></strong></td>
    <td><?= r4_h($device["patient_id"]) ?></td>
    <td><?= r4_h($device["full_name"]) ?></td>
    <td><?= r4_h($device["description"]) ?></td>

    <td>
        <?php if ((int)$device["enabled"] === 1): ?>
            <span class="status-enabled">Enabled</span>
        <?php else: ?>
            <span class="status-disabled">Disabled</span>
        <?php endif; ?>
    </td>

    <td><?= r4_date_display($device["start_date_time"]) ?></td>
    <td><?= r4_date_display($device["end_date_time"]) ?></td>

    <td>
        <form method="post" action="owner.php" class="small-form">

            <input type="hidden" name="csrf_token"
                   value="<?= r4_h($csrf_token) ?>">
            <input type="hidden" name="action" value="toggle_device">

            <input type="hidden" name="device_id"
                   value="<?= r4_h($device["device_id"]) ?>">

            <input type="hidden" name="patient_id"
                   value="<?= r4_h($device["patient_id"]) ?>">

            <?php if ((int)$device["enabled"] === 1): ?>
                <input type="hidden" name="new_status" value="0">
                <button type="submit" class="orange">Disable</button>
            <?php else: ?>
                <input type="hidden" name="new_status" value="1">
                <button type="submit" class="green">Enable</button>
            <?php endif; ?>

        </form>

        <form method="post" action="owner.php"
              class="small-form"
              onsubmit="return confirm('Delete only this patient/device assignment? Other patients using this device will not be affected.');">

            <input type="hidden" name="csrf_token"
                   value="<?= r4_h($csrf_token) ?>">
            <input type="hidden" name="action" value="delete_device">

            <input type="hidden" name="device_id"
                   value="<?= r4_h($device["device_id"]) ?>">

            <input type="hidden" name="patient_id"
                   value="<?= r4_h($device["patient_id"]) ?>">

            <button type="submit" class="red">Delete</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>

</table>
</div>

<?php endif; ?>

</div>

<!--=====================================================
    SECTION 5: CREATE OR EDIT PATIENT LOGIN
======================================================-->

<div class="section">

<h2>5. Create / Edit Patient Login Account</h2>

<?php if ($edit_login !== null): ?>

    <div class="edit-panel">
        <strong>Editing Patient Login Account</strong>

        <p>
            Login ID: <?= (int)$edit_login["id"] ?><br>
            Patient ID: <?= r4_h($edit_login["patient_id"]) ?><br>
            Username: <?= r4_h($edit_login["username"]) ?><br>
            Device ID: <?= r4_h($edit_login["device_id"]) ?>
        </p>

        <a class="button grey" href="owner.php">Cancel Editing</a>
    </div>

<?php endif; ?>

<form method="post" action="owner.php">

    <input type="hidden" name="csrf_token"
           value="<?= r4_h($csrf_token) ?>">
    <input type="hidden" name="action" value="save_patient_login">
    <input type="hidden" name="login_id"
           value="<?= $edit_login ? (int)$edit_login["id"] : 0 ?>">

    <label>Select Patient *</label>

    <select name="login_patient_id" required>
        <option value="">-- Select Patient --</option>

        <?php foreach ($patients as $patient): ?>
            <option
                value="<?= r4_h($patient["patient_id"]) ?>"
                <?= (
                    ($edit_login["patient_id"] ?? "") ===
                    $patient["patient_id"]
                ) ? "selected" : "" ?>>

                <?= r4_h(
                    $patient["patient_id"] . " - " . $patient["full_name"]
                ) ?>

            </option>
        <?php endforeach; ?>
    </select>

    <p class="note">
        Select the patient who will use this login account.
    </p>

    <label>Patient Login Username *</label>

    <input type="text"
           name="username"
           value="<?= r4_h($edit_login["username"] ?? "") ?>"
           required>

    <label>Assigned Device *</label>

    <select name="login_device_id" required>
        <option value="">-- Select Patient's Device --</option>

        <?php foreach ($devices as $device): ?>

            <?php
            $deviceEnabled = (int)$device["enabled"] === 1;

            $selectedDevice = $edit_login["device_id"] ?? "";
            $selectedPatientForLogin = $edit_login["patient_id"] ?? "";

            /*
              The current device is selected only when BOTH
              patient_id and device_id match the existing login.
            */

            $isCurrentDevice = (
                $selectedDevice === $device["device_id"] &&
                $selectedPatientForLogin === $device["patient_id"]
            );

            if ($deviceEnabled || $isCurrentDevice):
            ?>

                <option
                    value="<?= r4_h($device["device_id"]) ?>"
                    <?= $isCurrentDevice ? "selected" : "" ?>>

                    <?= r4_h(
                        $device["device_id"] .
                        " - " .
                        $device["patient_id"] .
                        " - " .
                        $device["full_name"]
                    ) ?>

                </option>

            <?php endif; ?>

        <?php endforeach; ?>
    </select>

    <p class="note">
        Device choices show the patient/device assignment.
        If the same ESP32 is assigned to several patients, it will appear
        separately for each patient. The server checks the exact pair
        before creating or changing a login.
    </p>

    <label>
        <?= $edit_login
            ? "New Password (optional)"
            : "Password *" ?>
    </label>

    <input type="password"
           name="password"
           <?= $edit_login ? "" : "required" ?>
           minlength="8"
           autocomplete="new-password">

    <?php if ($edit_login): ?>
        <p class="note">
            Leave password blank to keep the existing password.
            Enter a new password only when you want to change it.
        </p>
    <?php else: ?>
        <p class="note">
            Password must contain at least 8 characters.
        </p>
    <?php endif; ?>

    <label>Login Valid From</label>

    <input type="datetime-local"
           name="valid_from"
           value="<?= r4_h(
               r4_date_input($edit_login["valid_from"] ?? "")
           ) ?>">

    <label>Login Valid Until / Expiry</label>

    <input type="datetime-local"
           name="valid_until"
           value="<?= r4_h(
               r4_date_input($edit_login["valid_until"] ?? "")
           ) ?>">

    <p class="note">
        Leave dates blank if no login date restriction is required.
        Patient access must still pass the login and device checks
        on the patient-facing pages.
    </p>

    <?php if ($edit_login): ?>

        <button type="submit" class="orange">
            Save Patient Login Changes
        </button>

        <a href="owner.php" class="button grey">Cancel</a>

    <?php else: ?>

        <button type="submit">Create Patient Login</button>

    <?php endif; ?>

</form>

</div>

<!--=====================================================
    SECTION 6: PATIENT LOGIN ACCOUNTS
======================================================-->

<div class="section">

<h2>6. Patient Login Accounts - Enable / Disable / Edit / Delete</h2>

<p class="note">
    Each login account is linked to its own patient ID and device ID.
    Enable / Disable controls whether the patient can log in.
    Deleting a login does not delete the patient record or readings.
</p>

<p>
    Total patient login accounts:
    <strong><?= count($patient_logins) ?></strong>
</p>

<?php if (count($patient_logins) === 0): ?>

    <p>No patient login accounts found.</p>

<?php else: ?>

<div style="overflow-x:auto;">
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
    <td><?= (int)$login["id"] ?></td>
    <td><?= r4_h($login["username"]) ?></td>
    <td><?= r4_h($login["patient_id"]) ?></td>
    <td><?= r4_h($login["full_name"] ?? "Patient not found") ?></td>
    <td><?= r4_h($login["device_id"]) ?></td>

    <td>
        <?php if ((int)$login["enabled"] === 1): ?>
            <span class="status-enabled">Enabled</span>
        <?php else: ?>
            <span class="status-disabled">Disabled</span>
        <?php endif; ?>
    </td>

    <td><?= r4_date_display($login["valid_from"]) ?></td>
    <td><?= r4_date_display($login["valid_until"]) ?></td>

    <td>
        <a class="button orange"
           href="owner.php?edit_login=<?= (int)$login["id"] ?>">
            Edit
        </a>

        <form method="post" action="owner.php" class="small-form">

            <input type="hidden" name="csrf_token"
                   value="<?= r4_h($csrf_token) ?>">
            <input type="hidden" name="action"
                   value="toggle_patient_login">
            <input type="hidden" name="login_id"
                   value="<?= (int)$login["id"] ?>">

            <?php if ((int)$login["enabled"] === 1): ?>
                <input type="hidden" name="new_status" value="0">
                <button type="submit" class="orange">Disable</button>
            <?php else: ?>
                <input type="hidden" name="new_status" value="1">
                <button type="submit" class="green">Enable</button>
            <?php endif; ?>

        </form>

        <form method="post" action="owner.php"
              class="small-form"
              onsubmit="return confirm('Delete this patient login account? Patient records and monitoring history will remain.');">

            <input type="hidden" name="csrf_token"
                   value="<?= r4_h($csrf_token) ?>">
            <input type="hidden" name="action"
                   value="delete_patient_login">
            <input type="hidden" name="login_id"
                   value="<?= (int)$login["id"] ?>">

            <button type="submit" class="red">Delete</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>

</table>
</div>

<?php endif; ?>

</div>

<!--=====================================================
    SECTION 7: SELECT PATIENT FOR MONITORING REPORT
======================================================-->

<div class="section">

<h2>7. Select Patient to View Monitoring Reports</h2>

<form method="get" action="owner.php">

    <label>Select patient</label>

    <select name="patient_id" required>
        <option value="">-- Select Patient --</option>

        <?php foreach ($patients as $patient): ?>
            <option
                value="<?= r4_h($patient["patient_id"]) ?>"
                <?= (
                    $selectedPatientId ===
                    (string)$patient["patient_id"]
                ) ? "selected" : "" ?>>

                <?= r4_h(
                    $patient["full_name"] .
                    " (" .
                    $patient["patient_id"] .
                    ")"
                ) ?>

            </option>
        <?php endforeach; ?>
    </select>

    <br>
    <button type="submit">View Patient Reports</button>

</form>

</div>

<?php if ($selectedPatient !== null): ?>

<!-- SELECTED PATIENT DETAILS -->

<div class="section">

<h2>Selected Patient Details</h2>

<div class="info">

    <strong>Patient ID:</strong>
    <?= r4_h($selectedPatient["patient_id"]) ?>

    <br><br>

    <strong>Patient Name:</strong>
    <?= r4_h(r4_patient_name($selectedPatient)) ?>

    <br><br>

    <strong>Assigned Device IDs:</strong>

    <?php if (count($selectedDevices) === 0): ?>

        No device assigned.

    <?php else: ?>

        <?php foreach ($selectedDevices as $device): ?>
            <?= r4_h($device["device_id"]) ?>

            <?php if ((int)$device["enabled"] === 1): ?>
                (Enabled)
            <?php else: ?>
                (Disabled)
            <?php endif; ?>

            <br>
        <?php endforeach; ?>

    <?php endif; ?>

    <br>

    <a class="report-link"
       href="owner_patient.php?patient_id=<?= urlencode($selectedPatientId) ?>">
        View Full Report
    </a>

</div>

</div>

<!-- RECENT MONITORING READINGS -->

<div class="section">

<h2>Recent Monitoring Readings</h2>

<?php if ($monitoringError !== ""): ?>

    <div class="warning"><?= r4_h($monitoringError) ?></div>

<?php elseif (count($monitoringReadings) === 0): ?>

    <p>No monitoring readings found for this patient.</p>

<?php else: ?>

<div style="overflow-x:auto;">
<table>
<tr>
    <th>Date and Time</th>
    <th>Device</th>
    <th>Room Temp.</th>
    <th>Humidity</th>
    <th>BPM</th>
    <th>SpO2</th>
    <th>Body Temp.</th>
    <th>Mode</th>
</tr>

<?php foreach ($monitoringReadings as $reading): ?>
<tr>
    <td><?= r4_h(r4_value($reading, "recorded_at")) ?></td>
    <td><?= r4_h(r4_value($reading, "device_id")) ?></td>
    <td><?= r4_h(r4_value($reading, "room_temperature")) ?></td>
    <td><?= r4_h(r4_value($reading, "humidity")) ?></td>
    <td><?= r4_h(r4_value($reading, "bpm")) ?></td>
    <td><?= r4_h(r4_value($reading, "spo2")) ?></td>
    <td><?= r4_h(r4_value($reading, "body_temperature")) ?></td>
    <td><?= r4_h(r4_value($reading, "mode")) ?></td>
</tr>
<?php endforeach; ?>

</table>
</div>

<?php endif; ?>

</div>

<!-- PATIENT ALERTS -->

<div class="section">

<h2>Patient Dashboard Alerts</h2>

<?php if ($alertError !== ""): ?>

    <div class="warning"><?= r4_h($alertError) ?></div>

<?php elseif (count($patientAlerts) === 0): ?>

    <p>No alerts found for this patient.</p>

<?php else: ?>

<div style="overflow-x:auto;">
<table>
<tr>
    <th>Date and Time</th>
    <th>Device</th>
    <th>Parameter</th>
    <th>Measured Value</th>
    <th>Alert Message</th>
    <th>Status</th>
</tr>

<?php foreach ($patientAlerts as $alert): ?>
<tr>
    <td><?= r4_h(r4_value($alert, "recorded_at")) ?></td>
    <td><?= r4_h(r4_value($alert, "device_id")) ?></td>
    <td><?= r4_h(r4_value($alert, "parameter_name")) ?></td>
    <td><?= r4_h(r4_value($alert, "measured_value")) ?></td>
    <td><?= r4_h(r4_value($alert, "alert_message")) ?></td>

    <td>
        <?php if (
            isset($alert["acknowledged"]) &&
            (int)$alert["acknowledged"] === 1
        ): ?>
            Acknowledged
        <?php else: ?>
            Pending
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>

</table>
</div>

<?php endif; ?>

</div>

<?php endif; ?>

<!-- FOOTER -->

<div class="section">

    <strong>Ravi4 Administrator Dashboard</strong>

    <p class="note">
        Patient administration, shared device assignments,
        login accounts, monitoring readings and alerts.
    </p>

    <a class="button grey" href="owner_logout.php">
        Owner Logout
    </a>

</div>

</div>
</body>
</html>
