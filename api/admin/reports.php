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
| Users
|--------------------------------------------------------------------------
*/

$usersQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM users
");

$totalUsers = 0;

if ($usersQuery) {
    $row = $usersQuery->fetch_assoc();
    $totalUsers = (int) $row["total"];
}


/*
|--------------------------------------------------------------------------
| Candidates
|--------------------------------------------------------------------------
*/

$candidatesQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE role = 'candidate'
");

$totalCandidates = 0;

if ($candidatesQuery) {
    $row = $candidatesQuery->fetch_assoc();
    $totalCandidates = (int) $row["total"];
}


/*
|--------------------------------------------------------------------------
| Recruiters
|--------------------------------------------------------------------------
*/

$recruitersQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE role = 'recruiter'
");

$totalRecruiters = 0;

if ($recruitersQuery) {
    $row = $recruitersQuery->fetch_assoc();
    $totalRecruiters = (int) $row["total"];
}


/*
|--------------------------------------------------------------------------
| Admins
|--------------------------------------------------------------------------
*/

$adminsQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE role = 'admin'
");

$totalAdmins = 0;

if ($adminsQuery) {
    $row = $adminsQuery->fetch_assoc();
    $totalAdmins = (int) $row["total"];
}


/*
|--------------------------------------------------------------------------
| Jobs
|--------------------------------------------------------------------------
*/

$jobsQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM jobs
");

$totalJobs = 0;

if ($jobsQuery) {
    $row = $jobsQuery->fetch_assoc();
    $totalJobs = (int) $row["total"];
}


/*
|--------------------------------------------------------------------------
| Active Jobs
|--------------------------------------------------------------------------
*/

$activeJobsQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM jobs
    WHERE status = 'active'
");

$activeJobs = 0;

if ($activeJobsQuery) {
    $row = $activeJobsQuery->fetch_assoc();
    $activeJobs = (int) $row["total"];
}


/*
|--------------------------------------------------------------------------
| Closed Jobs
|--------------------------------------------------------------------------
*/

$closedJobsQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM jobs
    WHERE status = 'closed'
");

$closedJobs = 0;

if ($closedJobsQuery) {
    $row = $closedJobsQuery->fetch_assoc();
    $closedJobs = (int) $row["total"];
}


/*
|--------------------------------------------------------------------------
| Applications
|--------------------------------------------------------------------------
*/

$applicationsQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM applications
");

$totalApplications = 0;

if ($applicationsQuery) {
    $row = $applicationsQuery->fetch_assoc();
    $totalApplications = (int) $row["total"];
}


/*
|--------------------------------------------------------------------------
| Application Status
|--------------------------------------------------------------------------
*/

$applicationStatusQuery = $conn->query("
    SELECT
        status,
        COUNT(*) AS total
    FROM applications
    GROUP BY status
");

$applicationStatuses = [];

if ($applicationStatusQuery) {
    while ($row = $applicationStatusQuery->fetch_assoc()) {
        $applicationStatuses[] = [
            "status" => $row["status"],
            "total" => (int) $row["total"]
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Companies
|--------------------------------------------------------------------------
*/

$companiesQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM companies
");

$totalCompanies = 0;

if ($companiesQuery) {
    $row = $companiesQuery->fetch_assoc();
    $totalCompanies = (int) $row["total"];
}


/*
|--------------------------------------------------------------------------
| Company Status
|--------------------------------------------------------------------------
*/

$companyStatusQuery = $conn->query("
    SELECT
        status,
        COUNT(*) AS total
    FROM companies
    GROUP BY status
");

$companyStatuses = [];

if ($companyStatusQuery) {
    while ($row = $companyStatusQuery->fetch_assoc()) {
        $companyStatuses[] = [
            "status" => $row["status"],
            "total" => (int) $row["total"]
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

$categoriesQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM categories
");

$totalCategories = 0;

if ($categoriesQuery) {
    $row = $categoriesQuery->fetch_assoc();
    $totalCategories = (int) $row["total"];
}


$categoryStatuses = [
    [
        "status" => "active",
        "total" => $totalCategories
    ]
];


/*
|--------------------------------------------------------------------------
| Final Response
|--------------------------------------------------------------------------
*/

echo json_encode([
    "success" => true,

    "users" => [
        "total" => $totalUsers,
        "candidates" => $totalCandidates,
        "recruiters" => $totalRecruiters,
        "admins" => $totalAdmins
    ],

    "jobs" => [
        "total" => $totalJobs,
        "active" => $activeJobs,
        "closed" => $closedJobs
    ],

    "applications" => [
        "total" => $totalApplications,
        "statuses" => $applicationStatuses
    ],

    "companies" => [
        "total" => $totalCompanies,
        "statuses" => $companyStatuses
    ],

    "categories" => [
        "total" => $totalCategories,
        "statuses" => $categoryStatuses
    ]
]);

$conn->close();

?>