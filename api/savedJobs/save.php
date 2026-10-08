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
// GET JSON DATA
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

$candidateId = isset($input["candidate_id"])
    ? (int)$input["candidate_id"]
    : 0;

$jobId = isset($input["job_id"])
    ? (int)$input["job_id"]
    : 0;

// ======================================================
// VALIDATION
// ======================================================

if ($candidateId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid candidate ID"
    ]);

    exit;
}

if ($jobId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid job ID"
    ]);

    exit;
}

// ======================================================
// CHECK CANDIDATE
// ======================================================

$stmt = $conn->prepare("
    SELECT id, role, status
    FROM users
    WHERE id = ?
      AND role = 'candidate'
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare candidate query"
    ]);

    exit;
}

$stmt->bind_param(
    "i",
    $candidateId
);

$stmt->execute();

$result = $stmt->get_result();

$candidate = $result->fetch_assoc();

$stmt->close();

if (!$candidate) {
    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Only logged-in candidates can save jobs"
    ]);

    exit;
}

// ======================================================
// CHECK CANDIDATE STATUS
// ======================================================

if (
    isset($candidate["status"]) &&
    strtolower($candidate["status"]) !== "active"
) {
    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Your candidate account is not active"
    ]);

    exit;
}

// ======================================================
// CHECK JOB
// ======================================================

$stmt = $conn->prepare("
    SELECT id
    FROM jobs
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare job query"
    ]);

    exit;
}

$stmt->bind_param(
    "i",
    $jobId
);

$stmt->execute();

$result = $stmt->get_result();

$job = $result->fetch_assoc();

$stmt->close();

if (!$job) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Job not found"
    ]);

    exit;
}

// ======================================================
// CHECK ALREADY SAVED
// ======================================================

$stmt = $conn->prepare("
    SELECT id
    FROM saved_jobs
    WHERE candidate_id = ?
      AND job_id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare saved job query"
    ]);

    exit;
}

$stmt->bind_param(
    "ii",
    $candidateId,
    $jobId
);

$stmt->execute();

$result = $stmt->get_result();

$existing = $result->fetch_assoc();

$stmt->close();

if ($existing) {
    echo json_encode([
        "success" => true,
        "message" => "Job is already saved",
        "already_saved" => true,
        "saved_job_id" => (int)$existing["id"]
    ]);

    exit;
}

// ======================================================
// INSERT SAVED JOB
// ======================================================

$stmt = $conn->prepare("
    INSERT INTO saved_jobs
    (
        candidate_id,
        job_id,
        saved_at
    )
    VALUES
    (
        ?,
        ?,
        NOW()
    )
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare save query"
    ]);

    exit;
}

$stmt->bind_param(
    "ii",
    $candidateId,
    $jobId
);

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to save job",
        "error" => $error
    ]);

    exit;
}

$savedJobId =
    $stmt->insert_id;

$stmt->close();

// ======================================================
// SUCCESS
// ======================================================

echo json_encode([
    "success" => true,
    "message" => "Job saved successfully",
    "saved_job_id" => (int)$savedJobId
]);

?>