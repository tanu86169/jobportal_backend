<?php

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Vary: Origin");
}

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";

try {

    // -----------------------------
    // Get company ID
    // -----------------------------
    $companyId = $_GET['id'] ?? '';

    if (!is_numeric($companyId)) {
        echo json_encode([
            "success" => false,
            "message" => "Valid company ID is required."
        ]);
        exit;
    }

    $companyId = (int) $companyId;


    // -----------------------------
    // Get company details
    // -----------------------------
    $companySql = "
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
            c.status,
            c.created_at,
            COUNT(j.id) AS open_jobs
        FROM companies c
        LEFT JOIN jobs j
            ON j.recruiter_id = c.recruiter_id
            AND j.company_name = c.company_name
            AND j.status = 'active'
        WHERE c.id = ?
        AND c.status = 'approved'
        GROUP BY
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
            c.status,
            c.created_at
    ";

    $stmt = $conn->prepare($companySql);

    if (!$stmt) {
        throw new Exception("Company query preparation failed.");
    }

    $stmt->bind_param("i", $companyId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Company not found."
        ]);
        exit;
    }

    $company = $result->fetch_assoc();

    $stmt->close();


    // Convert numeric value
    $company['id'] = (int) $company['id'];
    $company['recruiter_id'] = (int) $company['recruiter_id'];
    $company['open_jobs'] = (int) $company['open_jobs'];


    // -----------------------------
    // Get open jobs
    // -----------------------------
    $jobsSql = "
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
    ";

    $jobsStmt = $conn->prepare($jobsSql);

    if (!$jobsStmt) {
        throw new Exception("Jobs query preparation failed.");
    }

    $jobsStmt->bind_param(
        "is",
        $company['recruiter_id'],
        $company['company_name']
    );

    $jobsStmt->execute();

    $jobsResult = $jobsStmt->get_result();

    $openJobs = [];

    while ($job = $jobsResult->fetch_assoc()) {

        $job['id'] = (int) $job['id'];
        $job['recruiter_id'] = (int) $job['recruiter_id'];

        /*
         * skills may be stored as JSON.
         * Convert JSON string into an array.
         */
        if (!empty($job['skills'])) {

            $decodedSkills = json_decode($job['skills'], true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $job['skills'] = $decodedSkills;
            } else {
                $job['skills'] = [];
            }

        } else {
            $job['skills'] = [];
        }

        $openJobs[] = $job;
    }

    $jobsStmt->close();


    // -----------------------------
    // Get similar companies
    // -----------------------------
    $similarCompanies = [];

    if (!empty($company['industry'])) {

        $similarSql = "
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
                COUNT(j.id) AS open_jobs
            FROM companies c
            LEFT JOIN jobs j
                ON j.recruiter_id = c.recruiter_id
                AND j.company_name = c.company_name
                AND j.status = 'active'
            WHERE c.status = 'approved'
            AND c.id <> ?
            AND c.industry = ?
            GROUP BY
                c.id,
                c.recruiter_id,
                c.company_name,
                c.logo,
                c.description,
                c.website,
                c.location,
                c.industry,
                c.company_size,
                c.founded_year
            ORDER BY open_jobs DESC, c.created_at DESC
            LIMIT 3
        ";

        $similarStmt = $conn->prepare($similarSql);

        if ($similarStmt) {

            $similarStmt->bind_param(
                "is",
                $companyId,
                $company['industry']
            );

            $similarStmt->execute();

            $similarResult = $similarStmt->get_result();

            while ($similar = $similarResult->fetch_assoc()) {

                $similar['id'] = (int) $similar['id'];
                $similar['recruiter_id'] = (int) $similar['recruiter_id'];
                $similar['open_jobs'] = (int) $similar['open_jobs'];

                $similarCompanies[] = $similar;
            }

            $similarStmt->close();
        }
    }


    // -----------------------------
    // Final response
    // -----------------------------
    echo json_encode([
        "success" => true,
        "message" => "Company details fetched successfully",
        "company" => $company,
        "open_jobs" => $openJobs,
        "similar_companies" => $similarCompanies
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Something went wrong.",
        "error" => $e->getMessage()
    ]);
}

$conn->close();
?>