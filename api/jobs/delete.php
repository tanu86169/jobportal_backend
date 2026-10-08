<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "../../config/database.php";

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

$input = file_get_contents("php://input");
$data = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON data"
    ]);

    exit;
}

$job_id = intval($data["job_id"] ?? 0);
$recruiter_id = intval($data["recruiter_id"] ?? 0);

if ($job_id <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Job ID is required"
    ]);

    exit;
}

if ($recruiter_id <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter ID is required"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Delete only recruiter's own job
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    DELETE FROM jobs
    WHERE id = ? AND recruiter_id = ?
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query preparation failed",
        "error" => $conn->error
    ]);

    exit;
}

$stmt->bind_param(
    "ii",
    $job_id,
    $recruiter_id
);

if (!$stmt->execute()) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to delete job",
        "error" => $stmt->error
    ]);

    exit;
}

if ($stmt->affected_rows === 0) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Job not found or you are not authorized to delete this job"
    ]);

    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Job deleted successfully"
]);

$stmt->close();
$conn->close();

?>