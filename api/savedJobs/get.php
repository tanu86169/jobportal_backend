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

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET request allowed"
    ]);

    exit;
}

$candidate_id = isset($_GET["candidate_id"])
    ? intval($_GET["candidate_id"])
    : 0;

if ($candidate_id <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "candidate_id is required"
    ]);

    exit;
}

$sql = "
    SELECT
        saved_jobs.id AS saved_job_id,
        saved_jobs.candidate_id,
        saved_jobs.job_id,
        saved_jobs.created_at AS saved_at,

        jobs.recruiter_id,
        jobs.job_title,
        jobs.company_name,
        jobs.location,
        jobs.job_type,
        jobs.experience,
        jobs.salary,
        jobs.description,
        jobs.skills,
        jobs.deadline,
        jobs.status,
        jobs.created_at

    FROM saved_jobs

    INNER JOIN jobs
        ON saved_jobs.job_id = jobs.id

    WHERE saved_jobs.candidate_id = ?

    ORDER BY saved_jobs.created_at DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare query",
        "error" => $conn->error
    ]);

    exit;
}

$stmt->bind_param("i", $candidate_id);
$stmt->execute();

$result = $stmt->get_result();

$savedJobs = [];

while ($row = $result->fetch_assoc()) {

    $row["skills"] = !empty($row["skills"])
        ? array_map("trim", explode(",", $row["skills"]))
        : [];

    $savedJobs[] = $row;
}

echo json_encode([
    "success" => true,
    "message" => "Saved jobs fetched successfully",
    "total" => count($savedJobs),
    "saved_jobs" => $savedJobs
]);

$stmt->close();
$conn->close();

?>