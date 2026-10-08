<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
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

$recruiters = [];

/*
|--------------------------------------------------------------------------
| FETCH ALL RECRUITERS
|--------------------------------------------------------------------------
*/

$recruiterSql = "
    SELECT
        id,
        name,
        email,
        phone,
        role
    FROM users
    WHERE role = 'recruiter'
    ORDER BY id DESC
";

$recruiterResult = $conn->query($recruiterSql);

if (!$recruiterResult) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch recruiters",
        "error" => $conn->error
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| GLOBAL TOTALS
|--------------------------------------------------------------------------
*/

$global = [
    "total_recruiters" => 0,
    "total_posts" => 0,
    "active_posts" => 0,
    "closed_posts" => 0,
    "total_applied" => 0,
    "unique_candidates" => 0,
    "interviews" => 0,
    "rejected" => 0,
    "selected" => 0
];

/*
|--------------------------------------------------------------------------
| LOOP RECRUITERS
|--------------------------------------------------------------------------
*/

while ($recruiter = $recruiterResult->fetch_assoc()) {

    $recruiterId = (int) $recruiter["id"];

    /*
    |--------------------------------------------------------------------------
    | JOB STATS
    |--------------------------------------------------------------------------
    */

    $jobSql = "
        SELECT
            COUNT(*) AS total_jobs,

            SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(status, ''))) IN ('active', 'open')
                    THEN 1
                    ELSE 0
                END
            ) AS active_jobs,

            SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(status, ''))) IN (
                        'closed',
                        'expired',
                        'inactive'
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS closed_jobs

        FROM jobs
        WHERE recruiter_id = ?
    ";

    $jobStmt = $conn->prepare($jobSql);

    if (!$jobStmt) {
        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare job statistics query",
            "error" => $conn->error
        ]);

        exit;
    }

    $jobStmt->bind_param("i", $recruiterId);
    $jobStmt->execute();

    $jobStats = $jobStmt->get_result()->fetch_assoc();

    /*
    |--------------------------------------------------------------------------
    | APPLICATION STATS
    |--------------------------------------------------------------------------
    */

    $applicationSql = "
        SELECT

            COUNT(a.id) AS total_applications,

            COUNT(DISTINCT a.candidate_id) AS unique_candidates,

            SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(a.status, ''))) IN (
                        'interview',
                        'interview scheduled',
                        'interview_scheduled',
                        'interviewed'
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS interviews,

            SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(a.status, ''))) IN (
                        'rejected',
                        'reject'
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS rejected,

            SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(a.status, ''))) IN (
                        'selected',
                        'hired',
                        'accepted'
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS selected

        FROM applications a

        INNER JOIN jobs j
            ON a.job_id = j.id

        WHERE j.recruiter_id = ?
    ";

    $applicationStmt = $conn->prepare($applicationSql);

    if (!$applicationStmt) {
        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare application statistics query",
            "error" => $conn->error
        ]);

        exit;
    }

    $applicationStmt->bind_param("i", $recruiterId);
    $applicationStmt->execute();

    $applicationStats =
        $applicationStmt->get_result()->fetch_assoc();

    /*
    |--------------------------------------------------------------------------
    | JOB TITLE / POSITION BREAKDOWN
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | Frontend Developer -> 5 Positions
    | PHP Developer      -> 3 Positions
    |
    |--------------------------------------------------------------------------
    */

    $breakdownSql = "
        SELECT

            COALESCE(
                NULLIF(TRIM(job_title), ''),
                'Other'
            ) AS name,

            /*
            |--------------------------------------------------------------
            | Number of job posts with this title
            |--------------------------------------------------------------
            */
            COUNT(*) AS total,

            /*
            |--------------------------------------------------------------
            | Total vacancies for this job title
            |
            | Example:
            | Frontend Developer
            | Job 1 = 3 vacancies
            | Job 2 = 2 vacancies
            |
            | Result = 5
            |--------------------------------------------------------------
            */
            COALESCE(
                SUM(
                    CASE
                        WHEN vacancies IS NULL OR TRIM(vacancies) = ''
                        THEN 0
                        ELSE CAST(vacancies AS UNSIGNED)
                    END
                ),
                0
            ) AS vacancies,

            /*
            |--------------------------------------------------------------
            | Active posts
            |--------------------------------------------------------------
            */
            SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(status, ''))) IN (
                        'active',
                        'open'
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS active,

            /*
            |--------------------------------------------------------------
            | Closed posts
            |--------------------------------------------------------------
            */
            SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(status, ''))) IN (
                        'closed',
                        'expired',
                        'inactive'
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS closed

        FROM jobs

        WHERE recruiter_id = ?

        GROUP BY
            COALESCE(
                NULLIF(TRIM(job_title), ''),
                'Other'
            )

        ORDER BY total DESC
    ";

    $breakdownStmt = $conn->prepare($breakdownSql);

    if (!$breakdownStmt) {
        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare job breakdown query",
            "error" => $conn->error
        ]);

        exit;
    }

    $breakdownStmt->bind_param("i", $recruiterId);
    $breakdownStmt->execute();

    $breakdownResult = $breakdownStmt->get_result();

    $jobBreakdown = [];

    while ($item = $breakdownResult->fetch_assoc()) {

        $jobBreakdown[] = [
            "name" => $item["name"],

            // Number of posts having this title
            "total" => (int) ($item["total"] ?? 0),

            // Actual number of positions/openings
            "vacancies" => (int) ($item["vacancies"] ?? 0),

            // Active job posts
            "active" => (int) ($item["active"] ?? 0),

            // Closed job posts
            "closed" => (int) ($item["closed"] ?? 0)
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | COMPANY
    |--------------------------------------------------------------------------
    |
    | Recruiter's latest company name
    |
    |--------------------------------------------------------------------------
    */

    $companySql = "
        SELECT company_name
        FROM jobs
        WHERE recruiter_id = ?
          AND company_name IS NOT NULL
          AND TRIM(company_name) != ''
        ORDER BY id DESC
        LIMIT 1
    ";

    $companyStmt = $conn->prepare($companySql);

    if (!$companyStmt) {
        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Failed to prepare company query",
            "error" => $conn->error
        ]);

        exit;
    }

    $companyStmt->bind_param("i", $recruiterId);
    $companyStmt->execute();

    $companyResult = $companyStmt->get_result();

    $companyName = null;

    if ($companyRow = $companyResult->fetch_assoc()) {
        $companyName = $companyRow["company_name"];
    }

    /*
    |--------------------------------------------------------------------------
    | CONVERT STATS
    |--------------------------------------------------------------------------
    */

    $totalJobs =
        (int) ($jobStats["total_jobs"] ?? 0);

    $activeJobs =
        (int) ($jobStats["active_jobs"] ?? 0);

    $closedJobs =
        (int) ($jobStats["closed_jobs"] ?? 0);

    $totalApplications =
        (int) ($applicationStats["total_applications"] ?? 0);

    $uniqueCandidates =
        (int) ($applicationStats["unique_candidates"] ?? 0);

    $interviews =
        (int) ($applicationStats["interviews"] ?? 0);

    $rejected =
        (int) ($applicationStats["rejected"] ?? 0);

    $selected =
        (int) ($applicationStats["selected"] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | RECRUITER RESULT
    |--------------------------------------------------------------------------
    */

    $recruiterData = [

        "id" =>
            $recruiterId,

        "name" =>
            $recruiter["name"],

        "email" =>
            $recruiter["email"],

        "phone" =>
            $recruiter["phone"],

        "role" =>
            $recruiter["role"],

        "company_name" =>
            $companyName,

        /*
        | Job statistics
        */

        "total_jobs" =>
            $totalJobs,

        "active_jobs" =>
            $activeJobs,

        "closed_jobs" =>
            $closedJobs,

        /*
        | Application statistics
        */

        "total_applications" =>
            $totalApplications,

        "total_candidates" =>
            $totalApplications,

        "unique_candidates" =>
            $uniqueCandidates,

        "interviews" =>
            $interviews,

        "rejected" =>
            $rejected,

        "selected" =>
            $selected,

        /*
        | Job title + vacancies
        */

        "job_breakdown" =>
            $jobBreakdown
    ];

    $recruiters[] = $recruiterData;

    /*
    |--------------------------------------------------------------------------
    | GLOBAL TOTALS
    |--------------------------------------------------------------------------
    */

    $global["total_recruiters"]++;

    $global["total_posts"] +=
        $totalJobs;

    $global["active_posts"] +=
        $activeJobs;

    $global["closed_posts"] +=
        $closedJobs;

    $global["total_applied"] +=
        $totalApplications;

    $global["unique_candidates"] +=
        $uniqueCandidates;

    $global["interviews"] +=
        $interviews;

    $global["rejected"] +=
        $rejected;

    $global["selected"] +=
        $selected;

    /*
    |--------------------------------------------------------------------------
    | CLOSE STATEMENTS
    |--------------------------------------------------------------------------
    */

    $jobStmt->close();
    $applicationStmt->close();
    $breakdownStmt->close();
    $companyStmt->close();
}

/*
|--------------------------------------------------------------------------
| FINAL RESPONSE
|--------------------------------------------------------------------------
*/

echo json_encode(
    [
        "success" => true,

        "summary" => $global,

        "total" => count($recruiters),

        "recruiters" => $recruiters
    ],
    JSON_PRETTY_PRINT
);

$conn->close();

?>