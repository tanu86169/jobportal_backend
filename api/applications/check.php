<?php

// =====================================================
// CORS
// =====================================================

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
}

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");


// =====================================================
// DATABASE
// =====================================================

require_once "../../config/database.php";


// =====================================================
// OPTIONS
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}


// =====================================================
// ONLY GET
// =====================================================

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "applied" => false,
        "message" => "Only GET request is allowed"
    ]);

    exit;
}


// =====================================================
// GET PARAMETERS
// =====================================================

$job_id = isset($_GET["jobId"])
    ? (int) $_GET["jobId"]
    : 0;


/*
 * IMPORTANT:
 *
 * candidateId here = users.id
 *
 * Current architecture:
 *
 * users.id
 *     ↓
 * applications.candidate_id
 */

$candidate_id = isset($_GET["candidateId"])
    ? (int) $_GET["candidateId"]
    : 0;


// =====================================================
// VALIDATE JOB
// =====================================================

if ($job_id <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "applied" => false,
        "message" => "Valid jobId is required"
    ]);

    exit;
}


// =====================================================
// VALIDATE CANDIDATE
// =====================================================

if ($candidate_id <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "applied" => false,
        "message" => "Valid candidateId is required"
    ]);

    exit;
}


// =====================================================
// VERIFY CANDIDATE
// =====================================================
//
// candidate_id must be a real users.id
// and role must be candidate.
//

$userStmt = $conn->prepare("
    SELECT
        id,
        name,
        email,
        role,
        status
    FROM users
    WHERE id = ?
      AND role = 'candidate'
    LIMIT 1
");


if (!$userStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "applied" => false,
        "message" => "Candidate verification query failed",
        "error" => $conn->error
    ]);

    $conn->close();

    exit;
}


$userStmt->bind_param(
    "i",
    $candidate_id
);


if (!$userStmt->execute()) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "applied" => false,
        "message" => "Failed to verify candidate",
        "error" => $userStmt->error
    ]);

    $userStmt->close();
    $conn->close();

    exit;
}


$userResult =
    $userStmt->get_result();


$user =
    $userResult
        ? $userResult->fetch_assoc()
        : null;


$userStmt->close();


if (!$user) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "applied" => false,
        "message" => "Candidate account not found"
    ]);

    $conn->close();

    exit;
}


// =====================================================
// CHECK APPLICATION
// =====================================================

$stmt = $conn->prepare("
    SELECT
        id,
        job_id,
        candidate_id,
        cover_letter,
        resume,
        status,
        applied_at
    FROM applications
    WHERE job_id = ?
      AND candidate_id = ?
    LIMIT 1
");


if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "applied" => false,
        "message" =>
            "Application query preparation failed",
        "error" => $conn->error
    ]);

    $conn->close();

    exit;
}


// =====================================================
// BIND
// =====================================================

$stmt->bind_param(
    "ii",
    $job_id,
    $candidate_id
);


// =====================================================
// EXECUTE
// =====================================================

if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "applied" => false,
        "message" =>
            "Failed to check application",
        "error" => $stmt->error
    ]);

    $stmt->close();
    $conn->close();

    exit;
}


// =====================================================
// RESULT
// =====================================================

$result =
    $stmt->get_result();


// =====================================================
// FOUND
// =====================================================

if (
    $result &&
    $result->num_rows > 0
) {

    $application =
        $result->fetch_assoc();


    http_response_code(200);


    echo json_encode([
        "success" => true,

        "applied" => true,

        "message" =>
            "Candidate has already applied for this job",

        "application" => [
            "id" =>
                (int) $application["id"],

            "job_id" =>
                (int) $application["job_id"],

            "candidate_id" =>
                (int) $application["candidate_id"],

            "status" =>
                $application["status"],

            "applied_at" =>
                $application["applied_at"]
        ]
    ]);


// =====================================================
// NOT FOUND
// =====================================================

} else {

    http_response_code(200);


    echo json_encode([
        "success" => true,

        "applied" => false,

        "message" =>
            "Candidate has not applied for this job",

        "application" => null
    ]);
}


// =====================================================
// CLOSE
// =====================================================

$stmt->close();

$conn->close();

?>