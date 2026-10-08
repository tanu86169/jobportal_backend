<?php

header("Access-Control-Allow-Origin: *");
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

/*
|--------------------------------------------------------------------------
| Get Candidate ID
|--------------------------------------------------------------------------
*/

$candidateId = isset($_GET["candidateId"])
    ? (int) $_GET["candidateId"]
    : 0;

if ($candidateId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid candidateId is required"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Candidate Applications
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        a.id AS application_id,
        a.job_id,
        a.candidate_id,
        a.cover_letter,
        a.resume,
        a.status,
        a.applied_at,

        j.job_title,
        j.company_name,
        j.location,
        j.job_type,
        j.experience,
        j.salary

    FROM applications a

    INNER JOIN jobs j
        ON a.job_id = j.id

    WHERE a.candidate_id = ?

    ORDER BY a.id DESC
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

$stmt->bind_param("i", $candidateId);

$stmt->execute();

$result = $stmt->get_result();

$applications = [];

while ($row = $result->fetch_assoc()) {

    $applications[] = [
        "application_id" => (int) $row["application_id"],
        "job_id" => (int) $row["job_id"],
        "candidate_id" => (int) $row["candidate_id"],

        "job_title" => $row["job_title"],
        "company_name" => $row["company_name"],
        "location" => $row["location"],
        "job_type" => $row["job_type"],
        "experience" => $row["experience"],
        "salary" => $row["salary"],

        "cover_letter" => $row["cover_letter"],
        "resume" => $row["resume"],

        "status" => $row["status"],
        "applied_at" => $row["applied_at"]
    ];
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

echo json_encode([
    "success" => true,
    "candidate_id" => $candidateId,
    "total" => count($applications),
    "applications" => $applications
]);

$stmt->close();
$conn->close();

?>