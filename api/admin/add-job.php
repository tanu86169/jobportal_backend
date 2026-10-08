<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);

    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$job_title = trim($data["job_title"] ?? "");
$company_name = trim($data["company_name"] ?? "");
$recruiter_id = intval($data["recruiter_id"] ?? 0);
$location = trim($data["location"] ?? "");
$job_type = trim($data["job_type"] ?? "Full-time");
$salary = trim($data["salary"] ?? "");
$experience = trim($data["experience"] ?? "");
$skills = trim($data["skills"] ?? "");
$deadline = trim($data["deadline"] ?? "");
$status = trim($data["status"] ?? "Active");
$description = trim($data["description"] ?? "");

if ($job_title === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Job title is required."
    ]);

    exit;
}

if ($company_name === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Company name is required."
    ]);

    exit;
}

if ($recruiter_id <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter is required."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Check recruiter
|--------------------------------------------------------------------------
*/

$recruiterQuery = $conn->prepare(
    "SELECT id, name, email
     FROM users
     WHERE id = ?
     AND role = 'recruiter'
     LIMIT 1"
);

$recruiterQuery->bind_param("i", $recruiter_id);
$recruiterQuery->execute();

$recruiterResult = $recruiterQuery->get_result();

if ($recruiterResult->num_rows === 0) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Selected recruiter not found."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Insert Job
|--------------------------------------------------------------------------
*/

$sql = "INSERT INTO jobs
(
    job_title,
    company_name,
    recruiter_id,
    location,
    job_type,
    salary,
    experience,
    skills,
    deadline,
    status,
    description
)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "SQL prepare failed.",
        "error" => $conn->error
    ]);

    exit;
}

$stmt->bind_param(
    "ssissssssss",
    $job_title,
    $company_name,
    $recruiter_id,
    $location,
    $job_type,
    $salary,
    $experience,
    $skills,
    $deadline,
    $status,
    $description
);

if ($stmt->execute()) {

    echo json_encode([
        "success" => true,
        "message" => "Job added successfully.",
        "job_id" => $conn->insert_id
    ]);

} else {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to add job.",
        "error" => $stmt->error
    ]);
}

$stmt->close();
$conn->close();
?>