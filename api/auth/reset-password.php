<?php

require_once "../../config/cors.php";
require_once "../../config/database.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only POST request allowed"
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON data"
    ]);
    exit;
}

$email = trim($data["email"] ?? "");
$phone = trim($data["phone"] ?? "");
$newPassword = $data["new_password"] ?? "";

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Enter a valid email address"
    ]);
    exit;
}

if (!preg_match("/^[6-9][0-9]{9}$/", $phone)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Enter the registered 10-digit phone number"
    ]);
    exit;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Password must be at least 6 characters"
    ]);
    exit;
}

$findUser = $conn->prepare(
    "SELECT id FROM users WHERE email = ? AND phone = ? LIMIT 1"
);

if (!$findUser) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database query preparation failed"
    ]);
    exit;
}

$findUser->bind_param("ss", $email, $phone);
$findUser->execute();
$result = $findUser->get_result();
$user = $result->fetch_assoc();
$findUser->close();

if (!$user) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Email and phone number do not match our records"
    ]);
    exit;
}

$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
$updateUser = $conn->prepare(
    "UPDATE users SET password = ? WHERE id = ?"
);

if (!$updateUser) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Password update preparation failed"
    ]);
    exit;
}

$userId = intval($user["id"]);
$updateUser->bind_param("si", $hashedPassword, $userId);

if (!$updateUser->execute()) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to update password"
    ]);
    exit;
}

$updateUser->close();
$conn->close();

echo json_encode([
    "success" => true,
    "message" => "Password reset successfully. You can login now."
]);

?>
