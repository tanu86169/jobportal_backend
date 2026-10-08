<?php

require_once "../../config/cors.php";
require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST method is allowed"
    ]);

    exit;
}

// ======================================================
// GET JSON
// ======================================================

$input = json_decode(
    file_get_contents("php://input"),
    true
);

if (!is_array($input)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON data"
    ]);

    exit;
}

// ======================================================
// DATA
// ======================================================

$name = trim(
    $input["name"] ?? ""
);

$email = trim(
    $input["email"] ?? ""
);

$subject = trim(
    $input["subject"] ?? ""
);

$message = trim(
    $input["message"] ?? ""
);

// ======================================================
// VALIDATION
// ======================================================

if ($name === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Name is required"
    ]);

    exit;
}

if ($email === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Email is required"
    ]);

    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address"
    ]);

    exit;
}

if ($subject === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Subject is required"
    ]);

    exit;
}

if ($message === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Message is required"
    ]);

    exit;
}

// ======================================================
// INSERT
// ======================================================

$stmt = $conn->prepare("
    INSERT INTO contact_messages
    (
        name,
        email,
        subject,
        message,
        status,
        created_at
    )
    VALUES
    (
        ?,
        ?,
        ?,
        ?,
        'new',
        NOW()
    )
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare database query"
    ]);

    exit;
}

$stmt->bind_param(
    "ssss",
    $name,
    $email,
    $subject,
    $message
);

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to save contact message",
        "error" => $error
    ]);

    exit;
}

$messageId =
    $stmt->insert_id;

$stmt->close();

// ======================================================
// SUCCESS
// ======================================================

echo json_encode([
    "success" => true,
    "message" => "Your message has been received successfully",
    "id" => (int)$messageId
]);

?>