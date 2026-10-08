<?php

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Vary: Origin");
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST request allowed"
    ]);

    exit;
}

$data = json_decode(
    file_get_contents("php://input"),
    true
);

$candidate_id = isset($data["candidate_id"])
    ? intval($data["candidate_id"])
    : 0;

$job_id = isset($data["job_id"])
    ? intval($data["job_id"])
    : 0;

if ($candidate_id <= 0 || $job_id <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "candidate_id and job_id are required"
    ]);

    exit;
}

$check = $conn->prepare(
    "SELECT id
     FROM saved_jobs
     WHERE candidate_id = ?
     AND job_id = ?
     LIMIT 1"
);

if (!$check) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare query",
        "error" => $conn->error
    ]);

    exit;
}

$check->bind_param(
    "ii",
    $candidate_id,
    $job_id
);

$check->execute();

$result = $check->get_result();

if ($result->num_rows === 0) {
    $check->close();
    $conn->close();

    echo json_encode([
        "success" => false,
        "message" => "Saved job not found"
    ]);

    exit;
}

$check->close();

$stmt = $conn->prepare(
    "DELETE FROM saved_jobs
     WHERE candidate_id = ?
     AND job_id = ?"
);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare delete query",
        "error" => $conn->error
    ]);

    $conn->close();

    exit;
}

$stmt->bind_param(
    "ii",
    $candidate_id,
    $job_id
);

if ($stmt->execute()) {

    echo json_encode([
        "success" => true,
        "message" => "Job removed from saved jobs successfully"
    ]);

} else {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to remove saved job",
        "error" => $stmt->error
    ]);
}

$stmt->close();
$conn->close();

?>