<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "../../config/database.php";


// ===============================
// OPTIONS REQUEST
// ===============================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}


// ===============================
// ONLY GET REQUEST
// ===============================

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET request allowed"
    ]);

    exit;
}


// ===============================
// GET RECRUITER ID
// ===============================

$recruiter_id = intval($_GET["recruiterId"] ?? 0);


if ($recruiter_id <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter ID is required"
    ]);

    exit;
}


// ===============================
// FETCH APPLICATIONS
// ===============================

$stmt = $conn->prepare("

    SELECT

        applications.id AS application_id,

        applications.job_id,

        applications.candidate_id,

        applications.cover_letter,

        applications.resume,

        applications.status,

        applications.applied_at,


        users.name AS candidate_name,

        users.email AS candidate_email,

        users.phone AS candidate_phone,


        jobs.job_title,

        jobs.company_name,

        jobs.location,

        jobs.job_type,

        jobs.experience,

        jobs.salary,

        jobs.description,

        jobs.skills,

        jobs.deadline,

        jobs.status AS job_status,

        jobs.created_at AS job_created_at


    FROM applications


    INNER JOIN jobs

        ON applications.job_id = jobs.id


    INNER JOIN users

        ON applications.candidate_id = users.id


    WHERE jobs.recruiter_id = ?


    ORDER BY applications.applied_at DESC

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


// ===============================
// BIND RECRUITER ID
// ===============================

$stmt->bind_param("i", $recruiter_id);


// ===============================
// EXECUTE
// ===============================

if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" => "Failed to fetch recruiter applications",

        "error" => $stmt->error

    ]);

    $stmt->close();

    $conn->close();

    exit;
}


// ===============================
// GET RESULT
// ===============================

$result = $stmt->get_result();

$applications = [];


// ===============================
// LOOP DATA
// ===============================

while ($row = $result->fetch_assoc()) {

    $row["skills"] = !empty($row["skills"])
        ? array_map("trim", explode(",", $row["skills"]))
        : [];

    $applications[] = $row;
}


// ===============================
// RESPONSE
// ===============================

echo json_encode([

    "success" => true,

    "message" => "Recruiter applications fetched successfully",

    "total" => count($applications),

    "applications" => $applications

]);


// ===============================
// CLOSE
// ===============================

$stmt->close();

$conn->close();

?>