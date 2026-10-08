<?php

header("Content-Type: application/json; charset=UTF-8");

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
}

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET method is allowed.",
        "data" => null
    ]);

    exit;
}

require_once "../../config/database.php";


/* =========================================================
   RECRUITER ID
========================================================= */

$recruiterId = isset($_GET["recruiterId"])
    ? (int)$_GET["recruiterId"]
    : 0;

if ($recruiterId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid recruiterId is required.",
        "data" => null
    ]);

    exit;
}


/* =========================================================
   VERIFY RECRUITER
========================================================= */

$userStmt = $conn->prepare("
    SELECT id, name, email, role
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$userStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "User query failed.",
        "data" => null
    ]);

    exit;
}

$userStmt->bind_param("i", $recruiterId);
$userStmt->execute();

$userResult = $userStmt->get_result();

if ($userResult->num_rows === 0) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter account not found.",
        "data" => null
    ]);

    exit;
}

$user = $userResult->fetch_assoc();

$userStmt->close();

if ($user["role"] !== "recruiter") {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Only recruiters can access recruiter jobs.",
        "data" => null
    ]);

    exit;
}


/* =========================================================
   FETCH RECRUITER JOBS
========================================================= */

$sql = "
    SELECT
        id,
        recruiter_id,
        job_title,
        position,
        company_name,
        location,
        vacancies,
        job_type,
        workplace_type,
        category,
        education,
        experience,
        salary,
        description,
        responsibilities,
        requirements,
        skills,
        deadline,
        status,
        created_at,
        company_logo,
        salary_min,
        salary_max,
        salary_type
    FROM jobs
    WHERE recruiter_id = ?
    ORDER BY
        CASE
            WHEN status = 'active' THEN 0
            ELSE 1
        END,
        id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Jobs query preparation failed.",
        "error" => $conn->error,
        "data" => null
    ]);

    exit;
}

$stmt->bind_param("i", $recruiterId);
$stmt->execute();

$result = $stmt->get_result();

$jobs = [];

while ($row = $result->fetch_assoc()) {

    $jobs[] = [
        "id" => (int)$row["id"],
        "recruiter_id" => (int)$row["recruiter_id"],
        "job_title" => $row["job_title"],
        "position" => $row["position"],
        "company_name" => $row["company_name"],
        "location" => $row["location"],
        "vacancies" => (int)$row["vacancies"],
        "job_type" => $row["job_type"],
        "workplace_type" => $row["workplace_type"],
        "category" => $row["category"],
        "education" => $row["education"],
        "experience" => $row["experience"],
        "salary" => $row["salary"],
        "description" => $row["description"],
        "responsibilities" => $row["responsibilities"],
        "requirements" => $row["requirements"],
        "skills" => $row["skills"],
        "deadline" => $row["deadline"],
        "status" => $row["status"],
        "created_at" => $row["created_at"],
        "company_logo" => $row["company_logo"],
        "salary_min" => $row["salary_min"],
        "salary_max" => $row["salary_max"],
        "salary_type" => $row["salary_type"]
    ];
}

$stmt->close();


/* =========================================================
   RESPONSE
========================================================= */

echo json_encode([
    "success" => true,
    "message" => "Recruiter jobs fetched successfully.",
    "data" => [
        "jobs" => $jobs,
        "total" => count($jobs)
    ]
], JSON_UNESCAPED_UNICODE);