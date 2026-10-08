<?php

require_once "../../config/cors.php";
require_once "../../config/database.php";

header("Content-Type: application/json");

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

// Get recruiter ID
$recruiter_id = intval($_GET["recruiterId"] ?? 0);

if ($recruiter_id <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter ID is required"
    ]);

    exit;
}

// Fetch jobs posted by this recruiter
$viewsTableResult = $conn->query("SHOW TABLES LIKE 'job_views'");
$hasViewsTable = $viewsTableResult && $viewsTableResult->num_rows > 0;
$viewsSelect = $hasViewsTable
    ? "(SELECT COUNT(*) FROM job_views WHERE job_id = jobs.id) AS views"
    : "0 AS views";

$stmt = $conn->prepare("
    SELECT
        id,
        recruiter_id,
        job_title,
        company_name,
        company_logo,
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
        $viewsSelect
    FROM jobs
    WHERE recruiter_id = ?
    ORDER BY created_at DESC
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

$stmt->bind_param("i", $recruiter_id);

if (!$stmt->execute()) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch jobs",
        "error" => $stmt->error
    ]);

    exit;
}

$result = $stmt->get_result();

$jobs = [];

while ($row = $result->fetch_assoc()) {

    // Skills ko array me convert karo
    $row["skills"] = !empty($row["skills"])
        ? array_map("trim", explode(",", $row["skills"]))
        : [];

    // Vacancies ko integer rakho
    $row["vacancies"] = !empty($row["vacancies"])
        ? (int)$row["vacancies"]
        : 1;

    // Company logo URL
    if (!empty($row["company_logo"])) {

        $logo = trim($row["company_logo"]);

        // Agar already full URL hai
        if (
            strpos($logo, "http://") === 0 ||
            strpos($logo, "https://") === 0
        ) {
            $row["company_logo_url"] = $logo;
        }

        // uploads/filename.jpg
        elseif (strpos($logo, "uploads/") === 0) {
            $row["company_logo_url"] =
                "http://localhost/job_portal/job-portal-api/" . $logo;
        }

        // Sirf filename stored hai
        else {
            $row["company_logo_url"] =
                "http://localhost/job_portal/job-portal-api/uploads/" . $logo;
        }

    } else {
        $row["company_logo_url"] = "";
    }

    $jobs[] = $row;
}

echo json_encode([
    "success" => true,
    "message" => "Recruiter jobs fetched successfully",
    "total" => count($jobs),
    "jobs" => $jobs
]);

$stmt->close();
$conn->close();
?>