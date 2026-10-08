<?php

require_once "../../config/cors.php";
require_once "../../config/database.php";

/*
|--------------------------------------------------------------------------
| OPTIONS / Preflight
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| Only PUT allowed
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only PUT method is allowed"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Read JSON body
|--------------------------------------------------------------------------
*/
$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON data"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get data
|--------------------------------------------------------------------------
*/
$recruiterId = isset($input["recruiter_id"])
    ? (int)$input["recruiter_id"]
    : 0;

$currentPassword = trim($input["current_password"] ?? "");
$newPassword = trim($input["new_password"] ?? "");
$confirmPassword = trim($input["confirm_password"] ?? "");

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/
if ($recruiterId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid recruiter ID"
    ]);

    exit;
}

if ($currentPassword === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Current password is required"
    ]);

    exit;
}

if ($newPassword === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "New password is required"
    ]);

    exit;
}

if ($confirmPassword === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Confirm password is required"
    ]);

    exit;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "New password must be at least 6 characters"
    ]);

    exit;
}

if ($newPassword !== $confirmPassword) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "New password and confirm password do not match"
    ]);

    exit;
}

if ($currentPassword === $newPassword) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "New password must be different from current password"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get recruiter
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT id, password, role, status
    FROM users
    WHERE id = ?
      AND role = 'recruiter'
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database prepare error"
    ]);

    exit;
}

$stmt->bind_param("i", $recruiterId);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| Recruiter not found
|--------------------------------------------------------------------------
*/
if (!$user) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter account not found"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Check account status
|--------------------------------------------------------------------------
*/
if (isset($user["status"]) && $user["status"] !== "active") {
    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Your recruiter account is not active"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Verify current password
|--------------------------------------------------------------------------
*/
if (!password_verify($currentPassword, $user["password"])) {
    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Current password is incorrect"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Hash new password
|--------------------------------------------------------------------------
*/
$hashedPassword = password_hash(
    $newPassword,
    PASSWORD_DEFAULT
);

if (!$hashedPassword) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Password hashing failed"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Update password
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    UPDATE users
    SET password = ?
    WHERE id = ?
      AND role = 'recruiter'
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database prepare error"
    ]);

    exit;
}

$stmt->bind_param(
    "si",
    $hashedPassword,
    $recruiterId
);

if (!$stmt->execute()) {
    $stmt->close();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to update password"
    ]);

    exit;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Success
|--------------------------------------------------------------------------
*/
echo json_encode([
    "success" => true,
    "message" => "Password changed successfully"
]);

?>