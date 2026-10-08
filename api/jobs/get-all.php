
<?php

require_once "../../config/cors.php";
require_once "../../config/database.php";

header("Content-Type: application/json; charset=UTF-8");

// Handle preflight request
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Only GET allowed
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
| API Configuration
|--------------------------------------------------------------------------
*/

$apiRoot = "http://localhost/job_portal/job-portal-api";

/*
|--------------------------------------------------------------------------
| Fetch Active Jobs
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        recruiter_id,
        job_title,
        position,
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
        created_at
    FROM jobs
    WHERE status = 'active'
    ORDER BY created_at DESC
";

$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch jobs",
        "error" => $conn->error
    ]);

    $conn->close();
    exit;
}

$jobs = [];

while ($row = $result->fetch_assoc()) {

    // Position fallback
    $row["position"] = !empty(trim($row["position"] ?? ""))
        ? trim($row["position"])
        : ($row["job_title"] ?? "");

    // Skills as an array
    $row["skills"] = !empty($row["skills"])
        ? array_values(
            array_filter(
                array_map("trim", explode(",", $row["skills"]))
            )
        )
        : [];

    // Vacancies
    $row["vacancies"] = isset($row["vacancies"])
        && (int) $row["vacancies"] > 0
        ? (int) $row["vacancies"]
        : 1;

    /*
    |--------------------------------------------------------------------------
    | Company Logo URL
    |--------------------------------------------------------------------------
    */

    $logo = trim($row["company_logo"] ?? "");

    if ($logo !== "") {

        // Logo already has a complete URL
        if (preg_match('/^(https?:)?\/\//i', $logo)) {

            $row["company_logo_url"] = $logo;

        } else {

            // Normalize the relative path
            $logoPath = ltrim(str_replace("\\", "/", $logo), "/");

            // Avoid duplicating the project folder in the URL
            if (strpos($logoPath, "job_portal/") === 0) {
                $row["company_logo_url"] =
                    "http://localhost/" . $logoPath;

            } elseif (strpos($logoPath, "job-portal-api/") === 0) {
                $row["company_logo_url"] =
                    "http://localhost/job_portal/" . $logoPath;

            } else {
                $row["company_logo_url"] =
                    $apiRoot . "/" . $logoPath;
            }
        }

    } else {
        $row["company_logo_url"] = null;
    }

    $jobs[] = $row;
}

/*
|--------------------------------------------------------------------------
| JSON Response
|--------------------------------------------------------------------------
*/

echo json_encode([
    "success" => true,
    "message" => "Jobs fetched successfully",
    "total" => count($jobs),
    "jobs" => $jobs
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$conn->close();

?>

