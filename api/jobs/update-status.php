<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "../../config/database.php";

// =====================================================
// OPTIONS
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =====================================================
// ONLY POST
// =====================================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST request allowed"
    ]);

    exit;
}

// =====================================================
// GET JSON DATA
// =====================================================

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

// =====================================================
// GET VALUES
// =====================================================

$job_id = intval($data["job_id"] ?? 0);
$recruiter_id = intval($data["recruiter_id"] ?? 0);
$status = trim($data["status"] ?? "");

// =====================================================
// VALIDATION
// =====================================================

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

if (!in_array($status, ["active", "closed"])) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid status"
    ]);

    exit;
}

// =====================================================
// UPDATE STATUS
// =====================================================

$stmt = $conn->prepare("
    UPDATE jobs
    SET status = ?
    WHERE id = ?
    AND recruiter_id = ?
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
    "sii",
    $status,
    $job_id,
    $recruiter_id
);

// =====================================================
// EXECUTE
// =====================================================

if (!$stmt->execute()) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to update job status",
        "error" => $stmt->error
    ]);

    exit;
}

// =====================================================
// CHECK JOB
// =====================================================

if ($stmt->affected_rows === 0) {

    // Check whether job exists
    $checkStmt = $conn->prepare("
        SELECT id, status
        FROM jobs
        WHERE id = ?
        AND recruiter_id = ?
    ");

    $checkStmt->bind_param(
        "ii",
        $job_id,
        $recruiter_id
    );

    $checkStmt->execute();

    $result = $checkStmt->get_result();

    if ($result->num_rows === 0) {

        http_response_code(404);

        echo json_encode([
            "success" => false,
            "message" => "Job not found or you are not authorized"
        ]);

        $checkStmt->close();
        $stmt->close();
        $conn->close();

        exit;
    }

    // Job exists but status was already same
    echo json_encode([
        "success" => true,
        "message" => "Job status is already " . $status,
        "status" => $status
    ]);

    $checkStmt->close();
    $stmt->close();
    $conn->close();

    exit;
}

// =====================================================
// SUCCESS
// =====================================================

echo json_encode([
    "success" => true,
    "message" => "Job status updated successfully",
    "jobId" => $job_id,
    "status" => $status
]);

$stmt->close();
$conn->close();

?>