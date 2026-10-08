<?php

require_once "../../config/cors.php";
require_once "../../config/database.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only POST request allowed"
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$jobId = (int)($data["job_id"] ?? 0);
$viewKey = trim((string)($data["view_key"] ?? ""));

if ($jobId <= 0 || $viewKey === "" || strlen($viewKey) > 128) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "A valid job ID and view key are required"
    ]);
    exit;
}

$tableCreated = $conn->query("
    CREATE TABLE IF NOT EXISTS job_views (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        job_id INT NOT NULL,
        view_key VARCHAR(128) NOT NULL,
        viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_job_view (job_id, view_key),
        KEY idx_job_views_viewed_at (viewed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if (!$tableCreated) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to prepare job view tracking"
    ]);
    exit;
}

$jobStmt = $conn->prepare(
    "SELECT id FROM jobs WHERE id = ? AND status = 'active' LIMIT 1"
);

if (!$jobStmt) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to validate job"
    ]);
    exit;
}

$jobStmt->bind_param("i", $jobId);
$jobStmt->execute();
$jobResult = $jobStmt->get_result();
$jobStmt->close();

if (!$jobResult || $jobResult->num_rows === 0) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "Active job not found"
    ]);
    exit;
}

$viewStmt = $conn->prepare(
    "INSERT IGNORE INTO job_views (job_id, view_key) VALUES (?, ?)"
);

if (!$viewStmt) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to record job view"
    ]);
    exit;
}

$viewStmt->bind_param("is", $jobId, $viewKey);
$success = $viewStmt->execute();
$viewStmt->close();

if (!$success) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to record job view"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Job view recorded"
]);

$conn->close();

?>