<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle OPTIONS request
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Only POST allowed
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode([
        "success" => false,
        "message" => "Only POST method is allowed"
    ]);
    exit;
}

require_once "../../config/database.php";

// Get JSON input
$input = json_decode(
    file_get_contents("php://input"),
    true
);

// Check JSON
if (!is_array($input)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON data"
    ]);
    exit;
}

// Get values
$userId = isset($input["user_id"])
    ? (int)$input["user_id"]
    : 0;

$type = isset($input["type"])
    ? trim($input["type"])
    : "";

$title = isset($input["title"])
    ? trim($input["title"])
    : "";

$message = isset($input["message"])
    ? trim($input["message"])
    : "";

$relatedId = isset($input["related_id"])
    && $input["related_id"] !== ""
    ? (int)$input["related_id"]
    : null;

// Validation
if ($userId <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid user ID"
    ]);
    exit;
}

if ($type === "") {
    echo json_encode([
        "success" => false,
        "message" => "Notification type is required"
    ]);
    exit;
}

if ($title === "") {
    echo json_encode([
        "success" => false,
        "message" => "Notification title is required"
    ]);
    exit;
}

if ($message === "") {
    echo json_encode([
        "success" => false,
        "message" => "Notification message is required"
    ]);
    exit;
}

// Insert notification
$sql = "
    INSERT INTO notifications
    (
        user_id,
        type,
        title,
        message,
        related_id
    )
    VALUES (?, ?, ?, ?, ?)
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "SQL prepare failed",
        "error" => $conn->error
    ]);
    exit;
}

// related_id can be NULL
$stmt->bind_param(
    "isssi",
    $userId,
    $type,
    $title,
    $message,
    $relatedId
);

// Execute
if (!$stmt->execute()) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to create notification",
        "error" => $stmt->error
    ]);

    $stmt->close();
    $conn->close();
    exit;
}

$notificationId = $stmt->insert_id;

echo json_encode([
    "success" => true,
    "message" => "Notification created successfully",
    "notificationId" => (int)$notificationId
]);

$stmt->close();
$conn->close();

?>