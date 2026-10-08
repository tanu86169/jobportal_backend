<?php

// =========================================================
// CORS
// =========================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =========================================================
// DATABASE
// =========================================================

require_once "../../config/database.php";

// =========================================================
// METHOD CHECK
// =========================================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);

    exit;
}

// =========================================================
// INPUT
// =========================================================

$input = json_decode(
    file_get_contents("php://input"),
    true
);

if (!is_array($input)) {
    $input = $_POST;
}

$senderId = isset($input["sender_id"])
    ? (int)$input["sender_id"]
    : 0;

$receiverId = isset($input["receiver_id"])
    ? (int)$input["receiver_id"]
    : 0;

$message = isset($input["message"])
    ? trim($input["message"])
    : "";

// =========================================================
// VALIDATION
// =========================================================

if ($senderId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid sender ID."
    ]);

    exit;
}

if ($receiverId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid receiver ID."
    ]);

    exit;
}

if ($senderId === $receiverId) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "You cannot send a message to yourself."
    ]);

    exit;
}

if ($message === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Message cannot be empty."
    ]);

    exit;
}

if (mb_strlen($message) > 5000) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Message cannot exceed 5000 characters."
    ]);

    exit;
}

// =========================================================
// GET SENDER
// =========================================================

$senderSql = "
    SELECT
        id,
        name,
        email,
        role
    FROM users
    WHERE id = ?
    LIMIT 1
";

$senderStmt = $conn->prepare($senderSql);

if (!$senderStmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare sender query."
    ]);

    exit;
}

$senderStmt->bind_param(
    "i",
    $senderId
);

$senderStmt->execute();

$senderResult = $senderStmt->get_result();

$sender = $senderResult->fetch_assoc();

$senderStmt->close();

if (!$sender) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Sender user not found."
    ]);

    exit;
}

// =========================================================
// GET RECEIVER
// =========================================================

$receiverSql = "
    SELECT
        id,
        name,
        email,
        role
    FROM users
    WHERE id = ?
    LIMIT 1
";

$receiverStmt = $conn->prepare($receiverSql);

if (!$receiverStmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare receiver query."
    ]);

    exit;
}

$receiverStmt->bind_param(
    "i",
    $receiverId
);

$receiverStmt->execute();

$receiverResult = $receiverStmt->get_result();

$receiver = $receiverResult->fetch_assoc();

$receiverStmt->close();

if (!$receiver) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Receiver user not found."
    ]);

    exit;
}

// =========================================================
// NORMALIZE ROLES
// =========================================================

$senderRole = strtolower(
    trim($sender["role"] ?? "")
);

$receiverRole = strtolower(
    trim($receiver["role"] ?? "")
);

// =========================================================
// VALID ROLES
// =========================================================

$validRoles = [
    "candidate",
    "recruiter"
];

if (!in_array($senderRole, $validRoles, true)) {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Only candidates and recruiters can send chat messages."
    ]);

    exit;
}

if (!in_array($receiverRole, $validRoles, true)) {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Messages can only be sent between candidates and recruiters."
    ]);

    exit;
}

// =========================================================
// ONLY CANDIDATE <-> RECRUITER
// =========================================================

$allowedConversation =
    (
        $senderRole === "candidate" &&
        $receiverRole === "recruiter"
    )
    ||
    (
        $senderRole === "recruiter" &&
        $receiverRole === "candidate"
    );

if (!$allowedConversation) {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Only candidate-recruiter conversations are allowed."
    ]);

    exit;
}

// =========================================================
// INSERT MESSAGE
// =========================================================

$insertSql = "
    INSERT INTO messages
    (
        sender_id,
        receiver_id,
        message,
        is_read
    )
    VALUES
    (
        ?,
        ?,
        ?,
        0
    )
";

$insertStmt = $conn->prepare($insertSql);

if (!$insertStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare message insert."
    ]);

    exit;
}

$insertStmt->bind_param(
    "iis",
    $senderId,
    $receiverId,
    $message
);

if (!$insertStmt->execute()) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to send message.",
        "error" => $insertStmt->error
    ]);

    $insertStmt->close();

    exit;
}

$messageId = $insertStmt->insert_id;

$insertStmt->close();

// =========================================================
// MESSAGE NOTIFICATION
// =========================================================
//
// Notification should be created for the RECEIVER.
//
// Example:
// Candidate -> Recruiter
// Receiver = recruiter
// Check recruiter_settings.message_notifications
//
// Recruiter -> Candidate
// Candidate receives notification as well.
//

$notificationCreated = false;
$notificationEnabled = true;

// =========================================================
// CHECK RECRUITER MESSAGE NOTIFICATION
// =========================================================
//
// If receiver is recruiter, use recruiter settings.
//
// If receiver is candidate, candidate notification settings
// are not currently defined in the provided recruiter_settings
// table, so notification remains enabled.
//

if ($receiverRole === "recruiter") {

    $settingsSql = "
        SELECT
            message_notifications
        FROM recruiter_settings
        WHERE recruiter_id = ?
        LIMIT 1
    ";

    $settingsStmt = $conn->prepare($settingsSql);

    if ($settingsStmt) {

        $settingsStmt->bind_param(
            "i",
            $receiverId
        );

        $settingsStmt->execute();

        $settingsResult = $settingsStmt->get_result();

        $settings = $settingsResult->fetch_assoc();

        $settingsStmt->close();

        // If settings row exists, respect the setting.
        if ($settings) {

            $notificationEnabled =
                (int)$settings["message_notifications"] === 1;
        }
    }
}

// =========================================================
// CREATE NOTIFICATION
// =========================================================

if ($notificationEnabled) {

    $senderName = trim(
        $sender["name"] ?? "User"
    );

    if ($senderRole === "candidate") {

        $notificationType = "message";

        $notificationTitle = "New Candidate Message";

        $notificationMessage =
            $senderName .
            " sent you a new message.";
    } else {

        $notificationType = "message";

        $notificationTitle = "New Recruiter Message";

        $notificationMessage =
            $senderName .
            " sent you a new message.";
    }

    $notificationSql = "
        INSERT INTO notifications
        (
            user_id,
            type,
            title,
            message,
            related_id,
            is_read
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            0
        )
    ";

    $notificationStmt =
        $conn->prepare($notificationSql);

    if ($notificationStmt) {

        $notificationStmt->bind_param(
            "isssi",
            $receiverId,
            $notificationType,
            $notificationTitle,
            $notificationMessage,
            $messageId
        );

        if ($notificationStmt->execute()) {
            $notificationCreated = true;
        }

        $notificationStmt->close();
    }
}

// =========================================================
// GET CREATED MESSAGE
// =========================================================

$getSql = "
    SELECT
        m.id,
        m.sender_id,
        m.receiver_id,
        m.message,
        m.is_read,
        m.created_at,

        s.name AS sender_name,
        s.email AS sender_email,
        s.role AS sender_role,

        r.name AS receiver_name,
        r.email AS receiver_email,
        r.role AS receiver_role

    FROM messages m

    LEFT JOIN users s
        ON s.id = m.sender_id

    LEFT JOIN users r
        ON r.id = m.receiver_id

    WHERE m.id = ?

    LIMIT 1
";

$getStmt = $conn->prepare($getSql);

if (!$getStmt) {

    echo json_encode([
        "success" => true,
        "message" => "Message sent successfully.",
        "message_id" => $messageId,
        "notification_created" => $notificationCreated
    ]);

    exit;
}

$getStmt->bind_param(
    "i",
    $messageId
);

$getStmt->execute();

$result = $getStmt->get_result();

$row = $result->fetch_assoc();

$getStmt->close();

// =========================================================
// FINAL RESPONSE
// =========================================================

echo json_encode([
    "success" => true,
    "message" => "Message sent successfully.",

    "message_id" => $messageId,

    "notification_created" => $notificationCreated,

    "notification_enabled" => $notificationEnabled,

    "data" => $row ?: [
        "id" => $messageId,
        "sender_id" => $senderId,
        "receiver_id" => $receiverId,
        "message" => $message,
        "is_read" => 0
    ]
]);

?>