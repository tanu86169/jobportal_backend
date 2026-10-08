
<?php

header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET request allowed"
    ]);

    exit;
}

$job_id = isset($_GET["id"])
    ? intval($_GET["id"])
    : 0;

if ($job_id <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid job ID is required"
    ]);

    exit;
}

$stmt = $conn->prepare("
    SELECT
        id,
        recruiter_id,
        job_title,
        company_name,
        location,
        job_type,
        experience,
        salary,
        description,
        skills,
        deadline,
        status,
        created_at
    FROM jobs
    WHERE id = ?
    AND status = 'active'
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare query",
        "error" => $conn->error
    ]);

    exit;
}

$stmt->bind_param("i", $job_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Job not found"
    ]);

    $stmt->close();
    $conn->close();
    exit;
}

$job = $result->fetch_assoc();

$job["skills"] = !empty($job["skills"])
    ? array_map("trim", explode(",", $job["skills"]))
    : [];

echo json_encode([
    "success" => true,
    "message" => "Job details fetched successfully",
    "job" => $job
]);

$stmt->close();
$conn->close();

?>