<?php

// =========================================================
// CORS CONFIGURATION
// =========================================================

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
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// =========================================================
// OPTIONS REQUEST
// =========================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =========================================================
// ONLY GET ALLOWED
// =========================================================

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET method is allowed.",
        "data" => []
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

// =========================================================
// DATABASE
// =========================================================

require_once "../../config/database.php";

// =========================================================
// RESPONSE HELPER
// =========================================================

function responseJson(
    bool $success,
    string $message = "",
    array $data = [],
    int $statusCode = 200
): void {

    http_response_code($statusCode);

    echo json_encode(
        [
            "success" => $success,
            "message" => $message,
            "data" => $data
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

// =========================================================
// LOGO URL HELPER
// =========================================================

function buildLogoUrl(?string $logo): ?string
{
    $logo = trim((string)$logo);

    if ($logo === "") {
        return null;
    }

    // -----------------------------------------------------
    // Already a full HTTP/HTTPS URL
    // -----------------------------------------------------

    if (preg_match('/^https?:\/\//i', $logo)) {
        return $logo;
    }

    // -----------------------------------------------------
    // Normalize slashes
    // -----------------------------------------------------

    $logo = str_replace("\\", "/", $logo);

    // Remove leading slash
    $logo = ltrim($logo, "/");

    $baseUrl = "http://localhost/job_portal/job-portal-api/";

    // -----------------------------------------------------
    // If complete project path already exists
    // -----------------------------------------------------

    $projectPath = "job_portal/job-portal-api/";

    $position = strpos($logo, $projectPath);

    if ($position !== false) {

        $logoPart = substr(
            $logo,
            $position + strlen($projectPath)
        );

        return $baseUrl . ltrim($logoPart, "/");
    }

    // -----------------------------------------------------
    // If logo starts with job-portal-api/
    // -----------------------------------------------------

    if (strpos($logo, "job-portal-api/") === 0) {

        $logoPart = substr(
            $logo,
            strlen("job-portal-api/")
        );

        return $baseUrl . ltrim($logoPart, "/");
    }

    // -----------------------------------------------------
    // If logo starts with uploads/
    // -----------------------------------------------------

    if (strpos($logo, "uploads/") === 0) {
        return $baseUrl . $logo;
    }

    // -----------------------------------------------------
    // If only filename/path is stored
    // -----------------------------------------------------

    return $baseUrl . ltrim($logo, "/");
}

// =========================================================
// MAIN
// =========================================================

try {

    // =====================================================
    // CHECK DATABASE CONNECTION
    // =====================================================

    if (!isset($conn) || !$conn) {

        responseJson(
            false,
            "Database connection failed.",
            [],
            500
        );
    }

    // =====================================================
    // FETCH COMPANIES
    // =====================================================

    $sql = "
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

            COUNT(
                CASE
                    WHEN j.id IS NOT NULL
                    AND LOWER(COALESCE(j.status, '')) = 'active'
                    THEN 1
                    ELSE NULL
                END
            ) AS open_jobs

        FROM companies c

        LEFT JOIN jobs j
            ON j.recruiter_id = c.recruiter_id

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

        ORDER BY c.id DESC
    ";

    // =====================================================
    // EXECUTE QUERY
    // =====================================================

    $result = $conn->query($sql);

    if (!$result) {

        throw new Exception(
            "Database query failed: " . $conn->error
        );
    }

    // =====================================================
    // COMPANIES ARRAY
    // =====================================================

    $companies = [];

    while ($row = $result->fetch_assoc()) {

        // =================================================
        // BASIC VALUES
        // =================================================

        $companyId = isset($row["id"])
            ? (int)$row["id"]
            : 0;

        $recruiterId = isset($row["recruiter_id"])
            ? (int)$row["recruiter_id"]
            : null;

        $companyName = trim(
            (string)($row["company_name"] ?? "")
        );

        $description = trim(
            (string)($row["description"] ?? "")
        );

        $website = trim(
            (string)($row["website"] ?? "")
        );

        $location = trim(
            (string)($row["location"] ?? "")
        );

        $industry = trim(
            (string)($row["industry"] ?? "")
        );

        $companySize = trim(
            (string)($row["company_size"] ?? "")
        );

        // =================================================
        // FOUNDED YEAR
        // =================================================

        $foundedYear = null;

        if (
            isset($row["founded_year"]) &&
            $row["founded_year"] !== null &&
            $row["founded_year"] !== ""
        ) {
            $foundedYear = (int)$row["founded_year"];
        }

        // =================================================
        // STATUS
        // =================================================

        $status = trim(
            (string)($row["status"] ?? "")
        );

        // =================================================
        // OPEN JOBS
        // =================================================

        $openJobs = isset($row["open_jobs"])
            ? (int)$row["open_jobs"]
            : 0;

        // =================================================
        // LOGO URL
        // =================================================

        $logoUrl = buildLogoUrl(
            $row["logo"] ?? null
        );

        // =================================================
        // COMPANY OBJECT
        // =================================================

        $companies[] = [

            "id" => $companyId,

            "company_id" => $companyId,

            "recruiter_id" => $recruiterId,

            // Company name
            "company_name" => $companyName,
            "name" => $companyName,

            // Logo
            "logo" => $logoUrl,
            "logo_url" => $logoUrl,

            // Description
            "description" => $description,

            // Website
            "website" => $website,

            // Location
            "location" => $location,

            // Company information
            "industry" => $industry,

            "company_size" => $companySize,

            "companySize" => $companySize,

            "founded_year" => $foundedYear,

            "foundedYear" => $foundedYear,

            // Status
            "status" => $status,

            // Jobs
            "open_jobs" => $openJobs,

            "openJobs" => $openJobs,

            // Created date
            "created_at" => $row["created_at"] ?? null
        ];
    }

    // =====================================================
    // FREE RESULT
    // =====================================================

    $result->free();

    // =====================================================
    // RESPONSE
    // =====================================================

    responseJson(
        true,
        "Companies fetched successfully.",
        [
            "companies" => $companies,
            "total" => count($companies)
        ],
        200
    );

} catch (Throwable $e) {

    // =====================================================
    // ERROR LOG
    // =====================================================

    error_log(
        "Companies API Error: " .
        $e->getMessage()
    );

    // =====================================================
    // ERROR RESPONSE
    // =====================================================

    responseJson(
        false,
        "Unable to fetch companies.",
        [
            "error" => $e->getMessage()
        ],
        500
    );
}