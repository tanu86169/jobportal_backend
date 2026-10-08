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

$id = intval($data["id"] ?? 0);

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

if ($id <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid job ID."
    ]);

    exit;
}

if ($job_title === "" || $company_name === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Job title and company name are required."
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
| Check Job
|--------------------------------------------------------------------------
*/

$checkJob = $conn->prepare(
    "SELECT id FROM jobs WHERE id = ? LIMIT 1"
);

$checkJob->bind_param("i", $id);
$checkJob->execute();

$jobResult = $checkJob->get_result();

if ($jobResult->num_rows === 0) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Job not found."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Check Recruiter
|--------------------------------------------------------------------------
*/

$checkRecruiter = $conn->prepare(
    "SELECT id
     FROM users
     WHERE id = ?
     AND role = 'recruiter'
     LIMIT 1"
);

$checkRecruiter->bind_param("i", $recruiter_id);
$checkRecruiter->execute();

$recruiterResult = $checkRecruiter->get_result();

if ($recruiterResult->num_rows === 0) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter not found."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Update
|--------------------------------------------------------------------------
*/

$sql = "UPDATE jobs SET
    job_title = ?,
    company_name = ?,
    recruiter_id = ?,
    location = ?,
    job_type = ?,
    salary = ?,
    experience = ?,
    skills = ?,
    deadline = ?,
    status = ?,
    description = ?
    WHERE id = ?";

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
    "ssissssssssi",
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
    $description,
    $id
);

if ($stmt->execute()) {

    echo json_encode([
        "success" => true,
        "message" => "Job updated successfully."
    ]);

} else {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to update job.",
        "error" => $stmt->error
    ]);
}

$stmt->close();
$conn->close();
?>