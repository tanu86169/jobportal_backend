<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";


if ($_SERVER["REQUEST_METHOD"] !== "PUT") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only PUT method is allowed"
    ]);

    exit;
}


$input = json_decode(
    file_get_contents("php://input"),
    true
);


$messageId = intval(
    $input["id"] ?? 0
);

$userId = intval(
    $input["userId"] ?? 0
);

$message = trim(
    $input["message"] ?? ""
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


if ($message === "") {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Message cannot be empty"
    ]);

    exit;
}


if (mb_strlen($message) > 5000) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Message cannot exceed 5000 characters"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Check message ownership
|--------------------------------------------------------------------------
|
| Admin can edit ONLY their own message.
|
*/

$check = $conn->prepare("
    SELECT id, sender_id, receiver_id, message, created_at
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


$row =
    $result->fetch_assoc();


$check->close();


/*
|--------------------------------------------------------------------------
| Only sender can edit
|--------------------------------------------------------------------------
*/

if (
    intval($row["sender_id"]) !==
    $userId
) {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "You can edit only your own messages"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Update
|--------------------------------------------------------------------------
*/

$update = $conn->prepare("
    UPDATE messages
    SET message = ?
    WHERE id = ?
      AND sender_id = ?
");


if (!$update) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Update prepare failed",
        "error" => $conn->error
    ]);

    exit;
}


$update->bind_param(
    "sii",
    $message,
    $messageId,
    $userId
);


if (!$update->execute()) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Message update failed",
        "error" => $update->error
    ]);

    $update->close();

    exit;
}


$update->close();


/*
|--------------------------------------------------------------------------
| Return updated message
|--------------------------------------------------------------------------
*/

$get = $conn->prepare("
    SELECT
        id,
        sender_id,
        receiver_id,
        message,
        is_read,
        created_at
    FROM messages
    WHERE id = ?
    LIMIT 1
");


$get->bind_param(
    "i",
    $messageId
);

$get->execute();

$updated =
    $get->get_result()->fetch_assoc();

$get->close();


echo json_encode([
    "success" => true,
    "message" => "Message updated successfully",
    "data" => $updated
]);


$conn->close();