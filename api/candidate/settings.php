<?php

// =========================================================
// CORS
// =========================================================

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
}

header("Access-Control-Allow-Methods: GET, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// =========================================================
// OPTIONS
// =========================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =========================================================
// DATABASE
// =========================================================

require_once "../../config/database.php";

// =========================================================
// HELPER
// =========================================================

function sendResponse($statusCode, $data)
{
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

// =========================================================
// CHECK DATABASE CONNECTION
// =========================================================

if (!isset($conn) || !$conn) {
    sendResponse(500, [
        "success" => false,
        "message" => "Database connection failed."
    ]);
}

// =========================================================
// CREATE SETTINGS TABLE IF NOT EXISTS
// =========================================================
// This makes the API easier to set up.
// If you already created the table manually,
// this query simply does nothing.

$createTableSql = "
CREATE TABLE IF NOT EXISTS candidate_settings (
    id INT(11) NOT NULL AUTO_INCREMENT,
    candidate_id INT(11) NOT NULL,
    job_alerts TINYINT(1) NOT NULL DEFAULT 1,
    application_updates TINYINT(1) NOT NULL DEFAULT 1,
    interview_notifications TINYINT(1) NOT NULL DEFAULT 1,
    recruiter_messages TINYINT(1) NOT NULL DEFAULT 1,
    email_notifications TINYINT(1) NOT NULL DEFAULT 1,
    profile_visibility TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_candidate (candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$conn->query($createTableSql);

// =========================================================
// METHOD
// =========================================================

$method = $_SERVER["REQUEST_METHOD"];

// =========================================================
// GET SETTINGS
// =========================================================

if ($method === "GET") {

    $candidateId = isset($_GET["candidateId"])
        ? (int) $_GET["candidateId"]
        : 0;

    if ($candidateId <= 0) {
        sendResponse(400, [
            "success" => false,
            "message" => "Valid candidate ID is required."
        ]);
    }

    // -----------------------------------------------------
    // GET CANDIDATE
    // -----------------------------------------------------

    $stmt = $conn->prepare("
        SELECT
            id,
            name,
            email,
            phone,
            role
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        sendResponse(500, [
            "success" => false,
            "message" => "Failed to prepare candidate query."
        ]);
    }

    $stmt->bind_param("i", $candidateId);
    $stmt->execute();

    $result = $stmt->get_result();
    $candidate = $result->fetch_assoc();

    $stmt->close();

    if (!$candidate) {
        sendResponse(404, [
            "success" => false,
            "message" => "Candidate not found."
        ]);
    }

    // -----------------------------------------------------
    // ROLE CHECK
    // -----------------------------------------------------

    if (
        isset($candidate["role"]) &&
        strtolower($candidate["role"]) !== "candidate"
    ) {
        sendResponse(403, [
            "success" => false,
            "message" => "This account is not a candidate account."
        ]);
    }

    // -----------------------------------------------------
    // GET SETTINGS
    // -----------------------------------------------------

    $stmt = $conn->prepare("
        SELECT
            job_alerts,
            application_updates,
            interview_notifications,
            recruiter_messages,
            email_notifications,
            profile_visibility
        FROM candidate_settings
        WHERE candidate_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        sendResponse(500, [
            "success" => false,
            "message" => "Failed to prepare settings query."
        ]);
    }

    $stmt->bind_param("i", $candidateId);
    $stmt->execute();

    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();

    $stmt->close();

    // -----------------------------------------------------
    // CREATE DEFAULT SETTINGS
    // -----------------------------------------------------

    if (!$settings) {

        $stmt = $conn->prepare("
            INSERT INTO candidate_settings
            (
                candidate_id,
                job_alerts,
                application_updates,
                interview_notifications,
                recruiter_messages,
                email_notifications,
                profile_visibility
            )
            VALUES (?, 1, 1, 1, 1, 1, 1)
        ");

        if ($stmt) {

            $stmt->bind_param("i", $candidateId);
            $stmt->execute();
            $stmt->close();
        }

        $settings = [
            "job_alerts" => 1,
            "application_updates" => 1,
            "interview_notifications" => 1,
            "recruiter_messages" => 1,
            "email_notifications" => 1,
            "profile_visibility" => 1
        ];
    }

    // -----------------------------------------------------
    // RESPONSE
    // -----------------------------------------------------

    sendResponse(200, [
        "success" => true,
        "message" => "Candidate settings loaded successfully.",
        "data" => [
            "candidate" => [
                "id" => (int) $candidate["id"],
                "name" => $candidate["name"] ?? "",
                "email" => $candidate["email"] ?? "",
                "phone" => $candidate["phone"] ?? ""
            ],

            "settings" => [
                "job_alerts" =>
                    (int) ($settings["job_alerts"] ?? 1),

                "application_updates" =>
                    (int) ($settings["application_updates"] ?? 1),

                "interview_notifications" =>
                    (int) ($settings["interview_notifications"] ?? 1),

                "recruiter_messages" =>
                    (int) ($settings["recruiter_messages"] ?? 1),

                "email_notifications" =>
                    (int) ($settings["email_notifications"] ?? 1),

                "profile_visibility" =>
                    (int) ($settings["profile_visibility"] ?? 1)
            ]
        ]
    ]);
}

// =========================================================
// PUT
// =========================================================

if ($method === "PUT") {

    $rawInput = file_get_contents("php://input");

    $data = json_decode($rawInput, true);

    if (!is_array($data)) {
        sendResponse(400, [
            "success" => false,
            "message" => "Invalid JSON request."
        ]);
    }

    // -----------------------------------------------------
    // CANDIDATE ID
    // -----------------------------------------------------

    $candidateId = isset($data["candidate_id"])
        ? (int) $data["candidate_id"]
        : 0;

    if ($candidateId <= 0) {
        sendResponse(400, [
            "success" => false,
            "message" => "Valid candidate ID is required."
        ]);
    }

    // -----------------------------------------------------
    // CHECK CANDIDATE
    // -----------------------------------------------------

    $stmt = $conn->prepare("
        SELECT
            id,
            name,
            email,
            phone,
            role,
            password
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        sendResponse(500, [
            "success" => false,
            "message" => "Failed to prepare user query."
        ]);
    }

    $stmt->bind_param("i", $candidateId);
    $stmt->execute();

    $result = $stmt->get_result();
    $candidate = $result->fetch_assoc();

    $stmt->close();

    if (!$candidate) {
        sendResponse(404, [
            "success" => false,
            "message" => "Candidate not found."
        ]);
    }

    // -----------------------------------------------------
    // ROLE CHECK
    // -----------------------------------------------------

    if (
        isset($candidate["role"]) &&
        strtolower($candidate["role"]) !== "candidate"
    ) {
        sendResponse(403, [
            "success" => false,
            "message" => "Only candidate accounts can update these settings."
        ]);
    }

    // =====================================================
    // ACCOUNT INFORMATION
    // =====================================================

    $name = array_key_exists("name", $data)
        ? trim((string) $data["name"])
        : $candidate["name"];

    $email = array_key_exists("email", $data)
        ? trim((string) $data["email"])
        : $candidate["email"];

    $phone = array_key_exists("phone", $data)
        ? trim((string) $data["phone"])
        : ($candidate["phone"] ?? "");

    // -----------------------------------------------------
    // VALIDATE NAME
    // -----------------------------------------------------

    if ($name === "") {
        sendResponse(422, [
            "success" => false,
            "message" => "Name is required."
        ]);
    }

    if (mb_strlen($name) > 150) {
        sendResponse(422, [
            "success" => false,
            "message" => "Name is too long."
        ]);
    }

    // -----------------------------------------------------
    // VALIDATE EMAIL
    // -----------------------------------------------------

    if ($email === "") {
        sendResponse(422, [
            "success" => false,
            "message" => "Email is required."
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse(422, [
            "success" => false,
            "message" => "Please enter a valid email address."
        ]);
    }

    // -----------------------------------------------------
    // CHECK DUPLICATE EMAIL
    // -----------------------------------------------------

    $stmt = $conn->prepare("
        SELECT id
        FROM users
        WHERE email = ?
        AND id != ?
        LIMIT 1
    ");

    if (!$stmt) {
        sendResponse(500, [
            "success" => false,
            "message" => "Failed to check email."
        ]);
    }

    $stmt->bind_param(
        "si",
        $email,
        $candidateId
    );

    $stmt->execute();

    $result = $stmt->get_result();
    $existingUser = $result->fetch_assoc();

    $stmt->close();

    if ($existingUser) {
        sendResponse(409, [
            "success" => false,
            "message" => "This email address is already registered."
        ]);
    }

    // =====================================================
    // UPDATE ACCOUNT
    // =====================================================

    $stmt = $conn->prepare("
        UPDATE users
        SET
            name = ?,
            email = ?,
            phone = ?
        WHERE id = ?
        AND role = 'candidate'
    ");

    if (!$stmt) {
        sendResponse(500, [
            "success" => false,
            "message" => "Failed to prepare account update."
        ]);
    }

    $stmt->bind_param(
        "sssi",
        $name,
        $email,
        $phone,
        $candidateId
    );

    if (!$stmt->execute()) {
        $stmt->close();

        sendResponse(500, [
            "success" => false,
            "message" => "Failed to update account information."
        ]);
    }

    $stmt->close();

    // =====================================================
    // SETTINGS VALUES
    // =====================================================

    $jobAlerts = !empty($data["job_alerts"]) ? 1 : 0;

    $applicationUpdates =
        !empty($data["application_updates"])
            ? 1
            : 0;

    $interviewNotifications =
        !empty($data["interview_notifications"])
            ? 1
            : 0;

    $recruiterMessages =
        !empty($data["recruiter_messages"])
            ? 1
            : 0;

    $emailNotifications =
        !empty($data["email_notifications"])
            ? 1
            : 0;

    $profileVisibility =
        !empty($data["profile_visibility"])
            ? 1
            : 0;

    // =====================================================
    // UPSERT SETTINGS
    // =====================================================

    $stmt = $conn->prepare("
        INSERT INTO candidate_settings
        (
            candidate_id,
            job_alerts,
            application_updates,
            interview_notifications,
            recruiter_messages,
            email_notifications,
            profile_visibility
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)

        ON DUPLICATE KEY UPDATE
            job_alerts = VALUES(job_alerts),
            application_updates = VALUES(application_updates),
            interview_notifications = VALUES(interview_notifications),
            recruiter_messages = VALUES(recruiter_messages),
            email_notifications = VALUES(email_notifications),
            profile_visibility = VALUES(profile_visibility)
    ");

    if (!$stmt) {
        sendResponse(500, [
            "success" => false,
            "message" => "Failed to prepare settings update."
        ]);
    }

    $stmt->bind_param(
        "iiiiiii",
        $candidateId,
        $jobAlerts,
        $applicationUpdates,
        $interviewNotifications,
        $recruiterMessages,
        $emailNotifications,
        $profileVisibility
    );

    if (!$stmt->execute()) {
        $stmt->close();

        sendResponse(500, [
            "success" => false,
            "message" => "Failed to save candidate settings."
        ]);
    }

    $stmt->close();

    // =====================================================
    // CHANGE PASSWORD
    // =====================================================

    $passwordChanged = false;

    $hasPasswordRequest =
        isset($data["current_password"]) ||
        isset($data["new_password"]) ||
        isset($data["confirm_password"]);

    if ($hasPasswordRequest) {

        $currentPassword =
            (string) ($data["current_password"] ?? "");

        $newPassword =
            (string) ($data["new_password"] ?? "");

        $confirmPassword =
            (string) ($data["confirm_password"] ?? "");

        // -------------------------------------------------
        // REQUIRED
        // -------------------------------------------------

        if (
            $currentPassword === "" ||
            $newPassword === "" ||
            $confirmPassword === ""
        ) {
            sendResponse(422, [
                "success" => false,
                "message" => "All password fields are required."
            ]);
        }

        // -------------------------------------------------
        // NEW PASSWORD LENGTH
        // -------------------------------------------------

        if (strlen($newPassword) < 6) {
            sendResponse(422, [
                "success" => false,
                "message" => "New password must contain at least 6 characters."
            ]);
        }

        // -------------------------------------------------
        // CONFIRM PASSWORD
        // -------------------------------------------------

        if ($newPassword !== $confirmPassword) {
            sendResponse(422, [
                "success" => false,
                "message" => "New password and confirm password do not match."
            ]);
        }

        // -------------------------------------------------
        // VERIFY OLD PASSWORD
        // -------------------------------------------------

        $storedPassword = $candidate["password"] ?? "";

        if (
            !$storedPassword ||
            !password_verify(
                $currentPassword,
                $storedPassword
            )
        ) {
            sendResponse(401, [
                "success" => false,
                "message" => "Current password is incorrect."
            ]);
        }

        // -------------------------------------------------
        // HASH NEW PASSWORD
        // -------------------------------------------------

        $hashedPassword = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        // -------------------------------------------------
        // UPDATE PASSWORD
        // -------------------------------------------------

        $stmt = $conn->prepare("
            UPDATE users
            SET password = ?
            WHERE id = ?
            AND role = 'candidate'
        ");

        if (!$stmt) {
            sendResponse(500, [
                "success" => false,
                "message" => "Failed to prepare password update."
            ]);
        }

        $stmt->bind_param(
            "si",
            $hashedPassword,
            $candidateId
        );

        if (!$stmt->execute()) {
            $stmt->close();

            sendResponse(500, [
                "success" => false,
                "message" => "Failed to update password."
            ]);
        }

        $stmt->close();

        $passwordChanged = true;
    }

    // =====================================================
    // FINAL RESPONSE
    // =====================================================

    sendResponse(200, [
        "success" => true,
        "message" => $passwordChanged
            ? "Account settings and password updated successfully."
            : "Account settings updated successfully.",

        "password_changed" => $passwordChanged,

        "data" => [
            "candidate" => [
                "id" => $candidateId,
                "name" => $name,
                "email" => $email,
                "phone" => $phone
            ],

            "settings" => [
                "job_alerts" => $jobAlerts,
                "application_updates" => $applicationUpdates,
                "interview_notifications" => $interviewNotifications,
                "recruiter_messages" => $recruiterMessages,
                "email_notifications" => $emailNotifications,
                "profile_visibility" => $profileVisibility
            ]
        ]
    ]);
}

// =========================================================
// METHOD NOT ALLOWED
// =========================================================

sendResponse(405, [
    "success" => false,
    "message" => "Method not allowed."
]);