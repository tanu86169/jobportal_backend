<?php

// ============================================================
// CORS CONFIGURATION
// ============================================================

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
header("Content-Type: application/json; charset=UTF-8");

// ============================================================
// DATABASE
// ============================================================

require_once "../../config/database.php";

// ============================================================
// HELPER RESPONSE FUNCTION
// ============================================================

function sendResponse(
    bool $success,
    string $message,
    $data = null,
    int $statusCode = 200
) {
    http_response_code($statusCode);

    $response = [
        "success" => $success,
        "message" => $message
    ];

    if ($data !== null) {
        $response["application"] = $data;
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

// ============================================================
// HANDLE OPTIONS / PREFLIGHT
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ============================================================
// ONLY GET REQUEST ALLOWED
// ============================================================

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    sendResponse(
        false,
        "Only GET request is allowed",
        null,
        405
    );
}

// ============================================================
// GET APPLICATION ID
// ============================================================

$applicationId = isset($_GET["applicationId"])
    ? (int) $_GET["applicationId"]
    : 0;

// ============================================================
// GET CANDIDATE USER ID
//
// IMPORTANT:
// applications.candidate_id = users.id
//
// So here candidateId means USERS.ID,
// NOT candidates.id.
// ============================================================

$candidateId = 0;

// Support different frontend parameter names

if (!empty($_GET["candidateId"])) {

    $candidateId = (int) $_GET["candidateId"];

} elseif (!empty($_GET["userId"])) {

    $candidateId = (int) $_GET["userId"];

} elseif (!empty($_GET["user_id"])) {

    $candidateId = (int) $_GET["user_id"];

} elseif (!empty($_GET["candidate_id"])) {

    $candidateId = (int) $_GET["candidate_id"];
}

// ============================================================
// VALIDATE APPLICATION ID
// ============================================================

if ($applicationId <= 0) {

    sendResponse(
        false,
        "Valid Application ID is required",
        null,
        400
    );
}

// ============================================================
// VALIDATE CANDIDATE ID
// ============================================================

if ($candidateId <= 0) {

    sendResponse(
        false,
        "Valid Candidate User ID is required",
        null,
        400
    );
}

// ============================================================
// VERIFY CANDIDATE USER
//
// candidateId must be users.id
// ============================================================

$candidateSql = "
    SELECT
        id,
        name,
        email,
        phone,
        role,
        status
    FROM users
    WHERE id = ?
    LIMIT 1
";

$candidateStmt = $conn->prepare($candidateSql);

if (!$candidateStmt) {

    sendResponse(
        false,
        "Failed to prepare candidate query",
        null,
        500
    );
}

$candidateStmt->bind_param(
    "i",
    $candidateId
);

if (!$candidateStmt->execute()) {

    $candidateStmt->close();

    sendResponse(
        false,
        "Failed to verify candidate",
        null,
        500
    );
}

$candidateResult = $candidateStmt->get_result();

if ($candidateResult->num_rows === 0) {

    $candidateStmt->close();

    sendResponse(
        false,
        "Candidate account not found",
        null,
        404
    );
}

$candidateUser = $candidateResult->fetch_assoc();

$candidateStmt->close();

// ============================================================
// CHECK ROLE
// ============================================================

$candidateRole = strtolower(
    trim($candidateUser["role"] ?? "")
);

$allowedCandidateRoles = [
    "candidate",
    "job-seeker",
    "job_seeker"
];

if (!in_array($candidateRole, $allowedCandidateRoles, true)) {

    sendResponse(
        false,
        "The provided user is not a candidate",
        null,
        403
    );
}

// ============================================================
// MAIN APPLICATION QUERY
//
// IMPORTANT RELATION:
//
// applications.candidate_id
//          ↓
// users.id
//
// candidates table is connected separately using
// candidates.user_id = users.id
// ============================================================

$sql = "
    SELECT

        /* ====================================================
           APPLICATION
           ==================================================== */

        a.id AS application_id,
        a.job_id,
        a.candidate_id,
        a.cover_letter,
        a.resume,
        a.status,
        a.applied_at,

        /* ====================================================
           CANDIDATE USER
           ==================================================== */

        u.id AS user_id,
        u.name AS candidate_name,
        u.email AS candidate_email,
        u.phone AS candidate_phone,
        u.role AS candidate_role,
        u.status AS candidate_status,

        /* ====================================================
           CANDIDATE PROFILE
           ==================================================== */

        c.id AS candidate_profile_id,
        c.headline,
        c.bio,
        c.location AS candidate_location,
        c.education,
        c.projects,
        c.skills AS candidate_skills,
        c.experience AS candidate_experience,
        c.experience_level,
        c.profile_image,
        c.resume AS candidate_profile_resume,
        c.linkedin,
        c.github,
        c.portfolio,

        /* ====================================================
           CATEGORY
           ==================================================== */

        cat.id AS category_id,
        cat.name AS category_name,

        /* ====================================================
           JOB
           ==================================================== */

        j.id AS job_id_detail,
        j.job_title,
        j.position,
        j.company_name,
        j.location AS job_location,
        j.vacancies,
        j.job_type,
        j.workplace_type,
        j.category AS job_category,
        j.education AS job_education,
        j.experience AS job_experience,
        j.salary,
        j.description,
        j.responsibilities,
        j.skills AS job_skills,
        j.deadline,
        j.status AS job_status,
        j.company_logo,
        j.created_at AS job_created_at

    FROM applications a

    /* ========================================================
       JOB
       ======================================================== */

    INNER JOIN jobs j
        ON a.job_id = j.id

    /* ========================================================
       USER
       applications.candidate_id = users.id
       ======================================================== */

    INNER JOIN users u
        ON a.candidate_id = u.id

    /* ========================================================
       CANDIDATE PROFILE
       candidates.user_id = users.id
       ======================================================== */

    LEFT JOIN candidates c
        ON c.user_id = u.id

    /* ========================================================
       CATEGORY
       ======================================================== */

    LEFT JOIN categories cat
        ON c.category_id = cat.id

    WHERE
        a.id = ?
        AND a.candidate_id = ?
        AND u.id = ?
        AND (
            LOWER(u.role) = 'candidate'
            OR LOWER(u.role) = 'job-seeker'
            OR LOWER(u.role) = 'job_seeker'
        )

    LIMIT 1
";

// ============================================================
// PREPARE MAIN QUERY
// ============================================================

$stmt = $conn->prepare($sql);

if (!$stmt) {

    sendResponse(
        false,
        "Failed to prepare application query",
        null,
        500
    );
}

// ============================================================
// BIND
// ============================================================

$stmt->bind_param(
    "iii",
    $applicationId,
    $candidateId,
    $candidateId
);

// ============================================================
// EXECUTE
// ============================================================

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();

    sendResponse(
        false,
        "Failed to fetch application",
        [
            "error" => $error
        ],
        500
    );
}

// ============================================================
// GET RESULT
// ============================================================

$result = $stmt->get_result();

// ============================================================
// APPLICATION NOT FOUND
// ============================================================

if ($result->num_rows === 0) {

    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        "Application not found for this candidate",
        null,
        404
    );
}

// ============================================================
// FETCH APPLICATION
// ============================================================

$application = $result->fetch_assoc();

// ============================================================
// HELPER - CONVERT JSON / CSV TO ARRAY
// ============================================================

function convertToArray($value)
{
    if ($value === null || $value === "") {
        return [];
    }

    // Already array
    if (is_array($value)) {
        return $value;
    }

    // Try JSON
    if (is_string($value)) {

        $decoded = json_decode($value, true);

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($decoded)
        ) {
            return $decoded;
        }

        // Comma separated
        $items = explode(",", $value);

        $items = array_map(
            function ($item) {
                return trim($item);
            },
            $items
        );

        $items = array_filter(
            $items,
            function ($item) {
                return $item !== "";
            }
        );

        return array_values($items);
    }

    return [];
}

// ============================================================
// CANDIDATE SKILLS
// ============================================================

$application["candidate_skills"] = convertToArray(
    $application["candidate_skills"] ?? ""
);

// ============================================================
// JOB SKILLS
// ============================================================

$application["job_skills"] = convertToArray(
    $application["job_skills"] ?? ""
);

// ============================================================
// EDUCATION
// ============================================================

$application["education"] = convertToArray(
    $application["education"] ?? ""
);

// ============================================================
// PROJECTS
// ============================================================

$application["projects"] = convertToArray(
    $application["projects"] ?? ""
);

// ============================================================
// EXPERIENCE
// ============================================================

$application["candidate_experience"] = convertToArray(
    $application["candidate_experience"] ?? ""
);

// ============================================================
// RESUME URL
//
// applications.resume contains uploaded application resume.
// ============================================================

if (!empty($application["resume"])) {

    $resumeFileName = basename(
        $application["resume"]
    );

    $application["resume_url"] =
        "http://localhost/job_portal/job-portal-api/uploads/resumes/"
        . rawurlencode($resumeFileName);

} else {

    $application["resume_url"] = null;
}

// ============================================================
// CANDIDATE PROFILE RESUME URL
// ============================================================

if (!empty($application["candidate_profile_resume"])) {

    $profileResumeFileName = basename(
        $application["candidate_profile_resume"]
    );

    $application["candidate_profile_resume_url"] =
        "http://localhost/job_portal/job-portal-api/uploads/resumes/"
        . rawurlencode($profileResumeFileName);

} else {

    $application["candidate_profile_resume_url"] = null;
}

// ============================================================
// COMPANY LOGO URL
// ============================================================

if (!empty($application["company_logo"])) {

    $companyLogoFileName = basename(
        $application["company_logo"]
    );

    $application["company_logo_url"] =
        "http://localhost/job_portal/job-portal-api/uploads/company-logos/"
        . rawurlencode($companyLogoFileName);

} else {

    $application["company_logo_url"] = null;
}

// ============================================================
// DEFAULT VALUES
// ============================================================

$application["cover_letter"] =
    $application["cover_letter"] ?? "";

$application["status"] =
    $application["status"] ?? "Applied";

$application["candidate_name"] =
    $application["candidate_name"] ?? "";

$application["candidate_email"] =
    $application["candidate_email"] ?? "";

$application["candidate_phone"] =
    $application["candidate_phone"] ?? "";

$application["job_title"] =
    $application["job_title"] ?? "";

$application["company_name"] =
    $application["company_name"] ?? "";

$application["job_location"] =
    $application["job_location"] ?? "";

$application["salary"] =
    $application["salary"] ?? "";

$application["job_type"] =
    $application["job_type"] ?? "";

$application["workplace_type"] =
    $application["workplace_type"] ?? "";

$application["experience_level"] =
    $application["experience_level"] ?? "";

$application["category_name"] =
    $application["category_name"] ?? "";

// ============================================================
// ADD SOME CLEAR IDS
// ============================================================

$application["candidate_user_id"] =
    (int) $candidateId;

$application["candidate_id"] =
    (int) $application["candidate_id"];

$application["application_id"] =
    (int) $application["application_id"];

$application["job_id"] =
    (int) $application["job_id"];

// ============================================================
// FINAL RESPONSE
// ============================================================

$stmt->close();
$conn->close();

sendResponse(
    true,
    "Application details fetched successfully",
    $application,
    200
);

?>