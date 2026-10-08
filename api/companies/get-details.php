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

/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

require_once "../../config/database.php";


/*
|--------------------------------------------------------------------------
| Only GET request allowed
|--------------------------------------------------------------------------
*/

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
| Get Company ID
|--------------------------------------------------------------------------
*/

$companyId = $_GET["id"] ?? "";

if (!is_numeric($companyId)) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid company ID is required"
    ]);

    exit;
}

$companyId = intval($companyId);


/*
|--------------------------------------------------------------------------
| Get Company Details
|--------------------------------------------------------------------------
*/

$companyStmt = $conn->prepare("
    SELECT
        id,
        recruiter_id,
        company_name,
        logo,
        description,
        website,
        location,
        industry,
        company_size,
        founded_year,
        status,
        created_at
    FROM companies
    WHERE id = ?
    AND status = 'approved'
    LIMIT 1
");

if (!$companyStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare company query",
        "error" => $conn->error
    ]);

    exit;
}

$companyStmt->bind_param("i", $companyId);

$companyStmt->execute();

$companyResult = $companyStmt->get_result();

if ($companyResult->num_rows === 0) {

    $companyStmt->close();

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Company not found"
    ]);

    exit;
}

$company = $companyResult->fetch_assoc();

$companyStmt->close();


/*
|--------------------------------------------------------------------------
| Get Open Jobs
|--------------------------------------------------------------------------
*/

$openJobs = [];

$jobStmt = $conn->prepare("
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
    WHERE recruiter_id = ?
    AND company_name = ?
    AND status = 'active'
    ORDER BY created_at DESC
");

if ($jobStmt) {

    $recruiterId = intval($company["recruiter_id"]);
    $companyName = $company["company_name"];

    $jobStmt->bind_param(
        "is",
        $recruiterId,
        $companyName
    );

    $jobStmt->execute();

    $jobResult = $jobStmt->get_result();

    while ($job = $jobResult->fetch_assoc()) {

        $job["id"] = intval($job["id"]);
        $job["recruiter_id"] = intval($job["recruiter_id"]);

        /*
        |--------------------------------------------------------------------------
        | Convert skills JSON into array
        |--------------------------------------------------------------------------
        */

        if (!empty($job["skills"])) {

            $decodedSkills = json_decode(
                $job["skills"],
                true
            );

            if (
                json_last_error() === JSON_ERROR_NONE &&
                is_array($decodedSkills)
            ) {
                $job["skills"] = $decodedSkills;
            } else {

                /*
                | If skills are stored as comma-separated text
                */
                $job["skills"] = array_values(
                    array_filter(
                        array_map(
                            "trim",
                            explode(",", $job["skills"])
                        )
                    )
                );
            }

        } else {

            $job["skills"] = [];
        }

        $openJobs[] = $job;
    }

    $jobStmt->close();
}


/*
|--------------------------------------------------------------------------
| Open Jobs Count
|--------------------------------------------------------------------------
*/

$company["id"] = intval($company["id"]);
$company["recruiter_id"] = intval($company["recruiter_id"]);
$company["open_jobs"] = count($openJobs);


/*
|--------------------------------------------------------------------------
| Get Similar Companies
|--------------------------------------------------------------------------
|
| Similar companies are selected from the same industry.
|
*/

$similarCompanies = [];

$industry = $company["industry"];

if (!empty($industry)) {

    $similarStmt = $conn->prepare("
        SELECT
            c.id,
            c.recruiter_id,
            c.company_name,
            c.logo,
            c.description,
            c.website,
            c.location,
            c.industry,
            c.company_size,
            c.founded_year,
            c.created_at
        FROM companies c
        WHERE c.status = 'approved'
        AND c.id != ?
        AND c.industry = ?
        ORDER BY c.created_at DESC
        LIMIT 3
    ");

    if ($similarStmt) {

        $similarStmt->bind_param(
            "is",
            $companyId,
            $industry
        );

        $similarStmt->execute();

        $similarResult = $similarStmt->get_result();

        while ($similar = $similarResult->fetch_assoc()) {

            $similarId = intval($similar["id"]);
            $similarRecruiterId = intval(
                $similar["recruiter_id"]
            );

            /*
            |--------------------------------------------------------------------------
            | Count active jobs for similar company
            |--------------------------------------------------------------------------
            */

            $similarJobStmt = $conn->prepare("
                SELECT COUNT(*) AS total_jobs
                FROM jobs
                WHERE recruiter_id = ?
                AND company_name = ?
                AND status = 'active'
            ");

            $similar["open_jobs"] = 0;

            if ($similarJobStmt) {

                $similarJobStmt->bind_param(
                    "is",
                    $similarRecruiterId,
                    $similar["company_name"]
                );

                $similarJobStmt->execute();

                $similarJobResult =
                    $similarJobStmt->get_result();

                $similarJobData =
                    $similarJobResult->fetch_assoc();

                $similar["open_jobs"] = intval(
                    $similarJobData["total_jobs"]
                );

                $similarJobStmt->close();
            }

            $similar["id"] = $similarId;
            $similar["recruiter_id"] =
                $similarRecruiterId;

            $similarCompanies[] = $similar;
        }

        $similarStmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| Final Response
|--------------------------------------------------------------------------
*/

echo json_encode([
    "success" => true,
    "message" => "Company details fetched successfully",
    "company" => $company,
    "open_jobs" => $openJobs,
    "similar_companies" => $similarCompanies
]);


$conn->close();

?>