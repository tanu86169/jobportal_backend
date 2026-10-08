<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    echo json_encode([
        "success" => false,
        "message" => "Only GET method is allowed"
    ]);
    exit;
}

require_once "../../config/database.php";

$userId = isset($_GET["userId"])
    ? (int)$_GET["userId"]
    : 0;

if ($userId <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid user ID"
    ]);
    exit;
}

$sql = "
    SELECT
        id,
        user_id,
        type,
        title,
        message,
        related_id,
        is_read,
        created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
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

$stmt->bind_param(
    "i",
    $userId
);

if (!$stmt->execute()) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch notifications",
        "error" => $stmt->error
    ]);

    $stmt->close();
    $conn->close();
    exit;
}

$result = $stmt->get_result();

$notifications = [];
$unreadCount = 0;

while ($row = $result->fetch_assoc()) {

    $isRead = (int)$row["is_read"];

    if ($isRead === 0) {
        $unreadCount++;
    }

    $notifications[] = [
        "id" => (int)$row["id"],
        "user_id" => (int)$row["user_id"],
        "type" => $row["type"],
        "title" => $row["title"],
        "message" => $row["message"],
        "related_id" =>
            $row["related_id"] !== null
                ? (int)$row["related_id"]
                : null,
        "is_read" => $isRead,
        "created_at" => $row["created_at"]
    ];
}

echo json_encode([
    "success" => true,
    "notifications" => $notifications,
    "unreadCount" => $unreadCount
]);

$stmt->close();
$conn->close();

?>