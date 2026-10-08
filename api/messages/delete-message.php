<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";


if ($_SERVER["REQUEST_METHOD"] !== "DELETE") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only DELETE method is allowed"
    ]);

    exit;
}


$messageId = intval(
    $_GET["id"] ?? 0
);

$userId = intval(
    $_GET["userId"] ?? 0
);


if ($messageId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid message id is required"
    ]);

    exit;
}


if ($userId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid userId is required"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Check message
|--------------------------------------------------------------------------
*/

$check = $conn->prepare("
    SELECT
        id,
        sender_id,
        receiver_id,
        message
    FROM messages
    WHERE id = ?
    LIMIT 1
");


if (!$check) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database prepare failed",
        "error" => $conn->error
    ]);

    exit;
}


$check->bind_param(
    "i",
    $messageId
);

$check->execute();

$result =
    $check->get_result();


if ($result->num_rows === 0) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Message not found"
    ]);

    $check->close();

    exit;
}


$messageData =
    $result->fetch_assoc();

$check->close();


/*
|--------------------------------------------------------------------------
| Only sender can delete
|--------------------------------------------------------------------------
*/

if (
    intval(
        $messageData["sender_id"]
    ) !== $userId
) {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "You can delete only your own messages"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/

$delete = $conn->prepare("
    DELETE FROM messages
    WHERE id = ?
      AND sender_id = ?
");


if (!$delete) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Delete prepare failed",
        "error" => $conn->error
    ]);

    exit;
}


$delete->bind_param(
    "ii",
    $messageId,
    $userId
);


if (!$delete->execute()) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Message delete failed",
        "error" => $delete->error
    ]);

    $delete->close();

    exit;
}


$delete->close();


echo json_encode([
    "success" => true,
    "message" => "Message deleted successfully"
]);


$conn->close();