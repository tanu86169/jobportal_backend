<?php

// ============================================================
// CORS
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// ============================================================
// OPTIONS
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ============================================================
// ONLY POST
// ============================================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);

    exit;
}

// ============================================================
// DATABASE
// ============================================================

require_once "../../config/database.php";

// ============================================================
// GET JSON BODY
// ============================================================

$input = json_decode(
    file_get_contents("php://input"),
    true
);

if (!is_array($input)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON request."
    ]);

    exit;
}

// ============================================================
// VALUES
// ============================================================

$userId = isset($input["user_id"])
    ? (int)$input["user_id"]
    : 0;

$notificationId = isset($input["notification_id"])
    ? (int)$input["notification_id"]
    : 0;

// ============================================================
// VALIDATION
// ============================================================

if ($userId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid user_id is required."
    ]);

    exit;
}

if ($notificationId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid notification_id is required."
    ]);

    exit;
}

// ============================================================
// CHECK NOTIFICATION
// IMPORTANT:
// Notification should belong to logged-in user
// ============================================================

$checkStmt = $conn->prepare("
    SELECT id, user_id, is_read
    FROM notifications
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");

if (!$checkStmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare notification check.",
        "error" => $conn->error
    ]);

    exit;
}

$checkStmt->bind_param(
    "ii",
    $notificationId,
    $userId
);

$checkStmt->execute();

$result = $checkStmt->get_result();

$notification = $result->fetch_assoc();

$checkStmt->close();

// ============================================================
// NOT FOUND
// ============================================================

if (!$notification) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Notification not found for this user."
    ]);

    exit;
}

// ============================================================
// ALREADY READ
// ============================================================

if ((int)$notification["is_read"] === 1) {

    echo json_encode([
        "success" => true,
        "message" => "Notification is already marked as read.",
        "notification_id" => $notificationId,
        "user_id" => $userId,
        "is_read" => 1
    ]);

    exit;
}

// ============================================================
// MARK AS READ
// ============================================================

$updateStmt = $conn->prepare("
    UPDATE notifications
    SET is_read = 1
    WHERE id = ?
      AND user_id = ?
");

if (!$updateStmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare update query.",
        "error" => $conn->error
    ]);

    exit;
}

$updateStmt->bind_param(
    "ii",
    $notificationId,
    $userId
);

$updated = $updateStmt->execute();

$affectedRows = $updateStmt->affected_rows;

$updateStmt->close();

// ============================================================
// RESPONSE
// ============================================================

if ($updated) {

    echo json_encode([
        "success" => true,
        "message" => "Notification marked as read.",
        "notification_id" => $notificationId,
        "user_id" => $userId,
        "is_read" => 1,
        "affected_rows" => $affectedRows
    ]);

    exit;
}

// ============================================================
// ERROR
// ============================================================

http_response_code(500);

echo json_encode([
    "success" => false,
    "message" => "Unable to mark notification as read."
]);

exit;