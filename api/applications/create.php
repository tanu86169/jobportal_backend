 <?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "../../config/database.php";

// OPTIONS request
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Only POST allowed
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST request allowed"
    ]);

    exit;
}

// Get JSON data
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

// Get values
$job_id = intval($data["job_id"] ?? 0);
$candidate_id = intval($data["candidate_id"] ?? 0);
$cover_letter = trim($data["cover_letter"] ?? "");
$resume = trim($data["resume"] ?? "");

// Validation
if ($job_id <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Job ID is required"
    ]);

    exit;
}

if ($candidate_id <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Candidate ID is required"
    ]);

    exit;
}

// Check job exists and is active
$jobStmt = $conn->prepare("
    SELECT id, job_title, company_name
    FROM jobs
    WHERE id = ?
    AND status = 'active'
");

if (!$jobStmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query preparation failed",
        "error" => $conn->error
    ]);

    exit;
}

$jobStmt->bind_param("i", $job_id);
$jobStmt->execute();

$jobResult = $jobStmt->get_result();

if ($jobResult->num_rows === 0) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Job not found or job is closed"
    ]);

    $jobStmt->close();
    $conn->close();

    exit;
}

$job = $jobResult->fetch_assoc();

$jobStmt->close();

// Check duplicate application
$checkStmt = $conn->prepare("
    SELECT id
    FROM applications
    WHERE job_id = ?
    AND candidate_id = ?
");

if (!$checkStmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query preparation failed",
        "error" => $conn->error
    ]);

    exit;
}

$checkStmt->bind_param("ii", $job_id, $candidate_id);
$checkStmt->execute();

$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    http_response_code(409);

    echo json_encode([
        "success" => false,
        "message" => "You have already applied for this job"
    ]);

    $checkStmt->close();
    $conn->close();

    exit;
}

$checkStmt->close();

// Insert application
$stmt = $conn->prepare("
    INSERT INTO applications
    (
        job_id,
        candidate_id,
        cover_letter,
        resume,
        status
    )
    VALUES (?, ?, ?, ?, 'Applied')
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
    "iiss",
    $job_id,
    $candidate_id,
    $cover_letter,
    $resume
);

if (!$stmt->execute()) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to submit application",
        "error" => $stmt->error
    ]);

    $stmt->close();
    $conn->close();

    exit;
}

$applicationId = $stmt->insert_id;

echo json_encode([
    "success" => true,
    "message" => "Application submitted successfully",
    "applicationId" => $applicationId,
    "job" => [
        "id" => $job["id"],
        "title" => $job["job_title"],
        "company" => $job["company_name"]
    ]
]);

$stmt->close();
$conn->close();

?>