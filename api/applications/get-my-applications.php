<?php

// ============================================================
// GET MY APPLICATIONS
// ============================================================
// IMPORTANT DATABASE RELATION:
//
// users.id
//    ↓
// candidates.user_id
//
// applications.candidate_id = users.id
//
// IMPORTANT:
// applications.candidate_id MEIN candidates.id nahi hai.
// ============================================================


// ============================================================
// CORS
// ============================================================

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
}

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");


// ============================================================
// DATABASE
// ============================================================

require_once "../../config/database.php";


// ============================================================
// RESPONSE HELPER
// ============================================================

function sendResponse(
    bool $success,
    string $message,
    array $extra = [],
    int $statusCode = 200
) {
    http_response_code($statusCode);

    $response = array_merge(
        [
            "success" => $success,
            "message" => $message
        ],
        $extra
    );

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


// ============================================================
// PREFLIGHT
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}


// ============================================================
// ONLY GET
// ============================================================

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    sendResponse(
        false,
        "Only GET request is allowed",
        [],
        405
    );
}


// ============================================================
// GET USER ID
// ============================================================
// Frontend kisi bhi naam se bhej sakta hai:
//
// ?userId=46
// ?user_id=46
// ?candidateId=46
// ?candidate_id=46
//
// Sabko internally USERS.ID maana jayega.
// ============================================================

$userId = 0;

if (isset($_GET["userId"])) {

    $userId = (int) $_GET["userId"];

} elseif (isset($_GET["user_id"])) {

    $userId = (int) $_GET["user_id"];

} elseif (isset($_GET["candidateId"])) {

    $userId = (int) $_GET["candidateId"];

} elseif (isset($_GET["candidate_id"])) {

    $userId = (int) $_GET["candidate_id"];
}


// ============================================================
// VALIDATE USER ID
// ============================================================

if ($userId <= 0) {

    sendResponse(
        false,
        "Valid userId is required",
        [],
        400
    );
}


// ============================================================
// VERIFY CANDIDATE USER
// ============================================================

$userSql = "
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

$userStmt = $conn->prepare($userSql);

if (!$userStmt) {

    sendResponse(
        false,
        "Failed to prepare user verification query",
        [
            "error" => $conn->error
        ],
        500
    );
}


$userStmt->bind_param(
    "i",
    $userId
);


if (!$userStmt->execute()) {

    $error = $userStmt->error;

    $userStmt->close();

    sendResponse(
        false,
        "Failed to verify candidate account",
        [
            "error" => $error
        ],
        500
    );
}


$userResult = $userStmt->get_result();

$user = $userResult->fetch_assoc();

$userStmt->close();


// ============================================================
// USER NOT FOUND
// ============================================================

if (!$user) {

    sendResponse(
        false,
        "Candidate account not found",
        [],
        404
    );
}


// ============================================================
// VERIFY ROLE
// ============================================================

$userRole = strtolower(
    trim($user["role"] ?? "")
);

$allowedCandidateRoles = [
    "candidate",
    "job-seeker",
    "job_seeker"
];

if (!in_array($userRole, $allowedCandidateRoles, true)) {

    sendResponse(
        false,
        "This user is not a candidate",
        [],
        403
    );
}


// ============================================================
// GET CANDIDATE PROFILE
// ============================================================
// candidates.id is separate from users.id.
//
// Example:
//
// users.id = 46
// candidates.id = 7
// candidates.user_id = 46
// ============================================================

$candidateProfileId = null;

$candidateProfile = null;


$candidateSql = "
    SELECT
        id,
        user_id,
        category_id,
        profile_image,
        headline,
        bio,
        location,
        date_of_birth,
        education,
        projects,
        skills,
        experience,
        experience_level,
        resume,
        linkedin,
        github,
        portfolio
    FROM candidates
    WHERE user_id = ?
    LIMIT 1
";


$candidateStmt = $conn->prepare($candidateSql);

if ($candidateStmt) {

    $candidateStmt->bind_param(
        "i",
        $userId
    );

    if ($candidateStmt->execute()) {

        $candidateResult =
            $candidateStmt->get_result();

        if ($candidateResult->num_rows > 0) {

            $candidateProfile =
                $candidateResult->fetch_assoc();

            $candidateProfileId =
                (int) $candidateProfile["id"];
        }
    }

    $candidateStmt->close();
}


// ============================================================
// FETCH APPLICATIONS
// ============================================================
//
// IMPORTANT:
//
// applications.candidate_id = users.id
//
// Therefore:
//
// WHERE applications.candidate_id = $userId
//
// NOT:
//
// WHERE applications.candidate_id = $candidateProfileId
// ============================================================

$sql = "
    SELECT

        /* ====================================================
           APPLICATION
           ==================================================== */

        applications.id AS application_id,

        applications.job_id,

        applications.candidate_id,

        applications.cover_letter,

        applications.resume,

        applications.status,

        applications.applied_at,


        /* ====================================================
           JOB
           ==================================================== */

        jobs.id AS job_id_detail,

        jobs.job_title,

        jobs.position,

        jobs.company_name,

        jobs.location,

        jobs.vacancies,

        jobs.job_type,

        jobs.workplace_type,

        jobs.category,

        jobs.education,

        jobs.experience,

        jobs.salary,

        jobs.salary_min,

        jobs.salary_max,

        jobs.salary_type,

        jobs.description,

        jobs.responsibilities,

        jobs.requirements,

        jobs.skills,

        jobs.deadline,

        jobs.status AS job_status,

        jobs.company_logo,

        jobs.created_at AS job_created_at

    FROM applications

    INNER JOIN jobs
        ON applications.job_id = jobs.id

    WHERE applications.candidate_id = ?

    ORDER BY applications.applied_at DESC
";


$stmt = $conn->prepare($sql);

if (!$stmt) {

    sendResponse(
        false,
        "Failed to prepare applications query",
        [
            "error" => $conn->error
        ],
        500
    );
}


// ============================================================
// BIND VERIFIED USERS.ID
// ============================================================

$stmt->bind_param(
    "i",
    $userId
);


// ============================================================
// EXECUTE
// ============================================================

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();
    $conn->close();

    sendResponse(
        false,
        "Failed to fetch applications",
        [
            "error" => $error
        ],
        500
    );
}


// ============================================================
// RESULT
// ============================================================

$result = $stmt->get_result();

$applications = [];


// ============================================================
// HELPER: NORMALIZE ARRAY / JSON / CSV
// ============================================================

function normalizeArrayValue($value)
{
    if (
        $value === null ||
        $value === ""
    ) {
        return [];
    }


    // Already array
    if (is_array($value)) {
        return $value;
    }


    // Try JSON
    if (is_string($value)) {

        $decoded = json_decode(
            $value,
            true
        );

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($decoded)
        ) {

            return $decoded;
        }


        // Comma separated
        $items = explode(
            ",",
            $value
        );


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
// HELPER: RESUME URL
// ============================================================

function makeResumeUrl($resume)
{
    if (
        empty($resume)
    ) {
        return null;
    }


    $fileName = basename(
        $resume
    );


    return
        "http://localhost/job_portal/job-portal-api/uploads/resumes/"
        . rawurlencode($fileName);
}


// ============================================================
// HELPER: COMPANY LOGO URL
// ============================================================

function makeCompanyLogoUrl($logo)
{
    if (
        empty($logo)
    ) {
        return null;
    }


    $fileName = basename(
        $logo
    );


    return
        "http://localhost/job_portal/job-portal-api/uploads/company-logos/"
        . rawurlencode($fileName);
}


// ============================================================
// LOOP APPLICATIONS
// ============================================================

while ($row = $result->fetch_assoc()) {

    // ========================================================
    // INTEGER IDs
    // ========================================================

    $row["application_id"] =
        (int) $row["application_id"];

    $row["job_id"] =
        (int) $row["job_id"];

    $row["job_id_detail"] =
        (int) $row["job_id_detail"];

    $row["candidate_id"] =
        (int) $row["candidate_id"];


    // ========================================================
    // SKILLS
    // ========================================================

    $row["skills"] =
        normalizeArrayValue(
            $row["skills"] ?? ""
        );


    // ========================================================
    // CANDIDATE INFORMATION
    // ========================================================

    $row["candidate_user_id"] =
        (int) $userId;

    $row["candidate_profile_id"] =
        $candidateProfileId;


    $row["candidate_name"] =
        $user["name"] ?? "";

    $row["candidate_email"] =
        $user["email"] ?? "";

    $row["candidate_phone"] =
        $user["phone"] ?? "";

    $row["candidate_role"] =
        $user["role"] ?? "";

    $row["candidate_status"] =
        $user["status"] ?? "";


    // ========================================================
    // RESUME URL
    // ========================================================

    $row["resume_url"] =
        makeResumeUrl(
            $row["resume"] ?? ""
        );


    // ========================================================
    // COMPANY LOGO URL
    // ========================================================

    $row["company_logo_url"] =
        makeCompanyLogoUrl(
            $row["company_logo"] ?? ""
        );


    // ========================================================
    // DEFAULT VALUES
    // ========================================================

    $row["cover_letter"] =
        $row["cover_letter"] ?? "";

    $row["status"] =
        $row["status"] ?? "Applied";

    $row["job_title"] =
        $row["job_title"] ?? "";

    $row["position"] =
        $row["position"] ?? "";

    $row["company_name"] =
        $row["company_name"] ?? "";

    $row["location"] =
        $row["location"] ?? "";

    $row["job_type"] =
        $row["job_type"] ?? "";

    $row["workplace_type"] =
        $row["workplace_type"] ?? "";

    $row["experience"] =
        $row["experience"] ?? "";

    $row["salary"] =
        $row["salary"] ?? "";

    $row["salary_min"] =
        $row["salary_min"] ?? null;

    $row["salary_max"] =
        $row["salary_max"] ?? null;

    $row["salary_type"] =
        $row["salary_type"] ?? "";

    $row["description"] =
        $row["description"] ?? "";

    $row["responsibilities"] =
        $row["responsibilities"] ?? "";

    $row["requirements"] =
        $row["requirements"] ?? "";

    $row["deadline"] =
        $row["deadline"] ?? null;

    $row["job_status"] =
        $row["job_status"] ?? "";

    // ========================================================
    // APPLICATION TIMELINE
    // ========================================================
    //
    // Abhi applications table mein sirf applied_at
    // reliably available hai.
    //
    // Isliye initial timeline:
    //
    // Applied
    //
    // Future mein application_status_history table se
    // complete timeline yahan dynamically aa sakti hai.
    // ========================================================

    $row["timeline"] = [
        [
            "status" => "Applied",
            "date" => $row["applied_at"],
            "label" => "Application Submitted"
        ]
    ];


    // ========================================================
    // CURRENT STATUS TIMELINE
    // ========================================================
    //
    // Agar status Applied nahi hai to current status bhi
    // timeline mein show karenge.
    // ========================================================

    $currentStatus =
        trim(
            $row["status"] ?? "Applied"
        );


    if (
        $currentStatus !== "" &&
        strtolower($currentStatus) !== "applied"
    ) {

        $row["timeline"][] = [
            "status" => $currentStatus,
            "date" => $row["applied_at"],
            "label" => $currentStatus
        ];
    }


    // ========================================================
    // ADD APPLICATION
    // ========================================================

    $applications[] = $row;
}


// ============================================================
// TOTAL
// ============================================================

$total = count(
    $applications
);


// ============================================================
// CANDIDATE RESPONSE DATA
// ============================================================

$candidateData = [
    "user_id" => (int) $userId,

    "candidate_id" =>
        $candidateProfileId,

    "name" =>
        $user["name"] ?? "",

    "email" =>
        $user["email"] ?? "",

    "phone" =>
        $user["phone"] ?? "",

    "role" =>
        $user["role"] ?? "",

    "status" =>
        $user["status"] ?? ""
];


// ============================================================
// OPTIONAL CANDIDATE PROFILE DATA
// ============================================================

if ($candidateProfile) {

    $candidateData["profile"] = [
        "headline" =>
            $candidateProfile["headline"] ?? "",

        "bio" =>
            $candidateProfile["bio"] ?? "",

        "location" =>
            $candidateProfile["location"] ?? "",

        "experience_level" =>
            $candidateProfile["experience_level"] ?? "",

        "linkedin" =>
            $candidateProfile["linkedin"] ?? "",

        "github" =>
            $candidateProfile["github"] ?? "",

        "portfolio" =>
            $candidateProfile["portfolio"] ?? "",

        "resume" =>
            $candidateProfile["resume"] ?? "",

        "profile_image" =>
            $candidateProfile["profile_image"] ?? ""
    ];
}


// ============================================================
// CLOSE DATABASE
// ============================================================

$stmt->close();

$conn->close();


// ============================================================
// FINAL RESPONSE
// ============================================================

sendResponse(
    true,
    "Applications fetched successfully",
    [
        "candidate" =>
            $candidateData,

        "total" =>
            $total,

        "applications" =>
            $applications
    ],
    200
);

?>