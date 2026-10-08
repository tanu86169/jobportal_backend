<?php

// ============================================================
// RECRUITER - FIND CANDIDATES API
// File:
// /api/recruiter/candidates.php
// ============================================================

header("Content-Type: application/json; charset=UTF-8");

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174",
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// ------------------------------------------------------------
// OPTIONS
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

// ------------------------------------------------------------
// ONLY GET
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET method is allowed."
    ]);

    exit;
}

// ------------------------------------------------------------
// DATABASE
// ------------------------------------------------------------
require_once "../../config/database.php";

// ------------------------------------------------------------
// RESPONSE HELPER
// ------------------------------------------------------------
function sendResponse(
    bool $success,
    string $message,
    $data = [],
    int $statusCode = 200
) {
    http_response_code($statusCode);

    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);

    exit;
}

// ------------------------------------------------------------
// GET PARAMETERS
// ------------------------------------------------------------
$recruiterId = isset($_GET["recruiterId"])
    ? (int) $_GET["recruiterId"]
    : 0;

$search = trim($_GET["search"] ?? "");
$categoryId = isset($_GET["category_id"])
    ? (int) $_GET["category_id"]
    : 0;

$location = trim($_GET["location"] ?? "");
$experienceLevel = trim($_GET["experience_level"] ?? "");
$education = trim($_GET["education"] ?? "");

$page = isset($_GET["page"])
    ? max(1, (int) $_GET["page"])
    : 1;

$limit = isset($_GET["limit"])
    ? max(1, min(50, (int) $_GET["limit"]))
    : 12;

$offset = ($page - 1) * $limit;

// ------------------------------------------------------------
// VALIDATE RECRUITER
// ------------------------------------------------------------
if ($recruiterId <= 0) {
    sendResponse(
        false,
        "Valid recruiterId is required.",
        [],
        400
    );
}

try {

    // ========================================================
    // CHECK RECRUITER
    // ========================================================

    $userSql = "
        SELECT
            id,
            name,
            email,
            role
        FROM users
        WHERE id = ?
        LIMIT 1
    ";

    $userStmt = $conn->prepare($userSql);

    if (!$userStmt) {
        throw new Exception(
            "Failed to prepare recruiter query: " . $conn->error
        );
    }

    $userStmt->bind_param("i", $recruiterId);
    $userStmt->execute();

    $userResult = $userStmt->get_result();
    $recruiter = $userResult->fetch_assoc();

    $userStmt->close();

    if (!$recruiter) {
        sendResponse(
            false,
            "Recruiter account not found.",
            [],
            404
        );
    }

    if ($recruiter["role"] !== "recruiter") {
        sendResponse(
            false,
            "Only recruiters can access candidate search.",
            [],
            403
        );
    }

    // ========================================================
    // BUILD WHERE CONDITIONS
    // ========================================================

    $where = [];
    $params = [];
    $types = "";

    // Only candidate users
    $where[] = "u.role = 'candidate'";

    // --------------------------------------------------------
    // SEARCH
    // Name / headline / bio / skills / location
    // --------------------------------------------------------
    if ($search !== "") {

        $where[] = "
            (
                u.name LIKE ?
                OR c.headline LIKE ?
                OR c.bio LIKE ?
                OR c.skills LIKE ?
                OR c.location LIKE ?
                OR c.experience LIKE ?
                OR c.experience_level LIKE ?
                OR c.education LIKE ?
            )
        ";

        $searchValue = "%" . $search . "%";

        for ($i = 0; $i < 8; $i++) {
            $params[] = $searchValue;
            $types .= "s";
        }
    }

    // --------------------------------------------------------
    // CATEGORY
    // --------------------------------------------------------
    if ($categoryId > 0) {

        $where[] = "c.category_id = ?";

        $params[] = $categoryId;
        $types .= "i";
    }

    // --------------------------------------------------------
    // LOCATION
    // --------------------------------------------------------
    if ($location !== "") {

        $where[] = "c.location LIKE ?";

        $params[] = "%" . $location . "%";
        $types .= "s";
    }

    // --------------------------------------------------------
    // EXPERIENCE LEVEL
    // --------------------------------------------------------
    if ($experienceLevel !== "") {

        $where[] = "c.experience_level LIKE ?";

        $params[] = "%" . $experienceLevel . "%";
        $types .= "s";
    }

    // --------------------------------------------------------
    // EDUCATION
    // --------------------------------------------------------
    if ($education !== "") {

        $where[] = "c.education LIKE ?";

        $params[] = "%" . $education . "%";
        $types .= "s";
    }

    $whereSql = "";

    if (!empty($where)) {
        $whereSql = "WHERE " . implode(" AND ", $where);
    }

    // ========================================================
    // COUNT
    // ========================================================

    $countSql = "
        SELECT COUNT(*) AS total
        FROM candidates c
        INNER JOIN users u
            ON u.id = c.user_id
        LEFT JOIN categories cat
            ON cat.id = c.category_id
        $whereSql
    ";

    $countStmt = $conn->prepare($countSql);

    if (!$countStmt) {
        throw new Exception(
            "Failed to prepare count query: " . $conn->error
        );
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();

    $countResult = $countStmt->get_result();
    $countRow = $countResult->fetch_assoc();

    $totalCandidates = (int) ($countRow["total"] ?? 0);

    $countStmt->close();

    // ========================================================
    // GET CANDIDATES
    // ========================================================

    $candidateSql = "
        SELECT
            c.id AS candidate_id,
            c.user_id,
            c.category_id,
            c.profile_image,
            c.headline,
            c.bio,
            c.location,
            c.date_of_birth,
            c.education,
            c.projects,
            c.skills,
            c.experience,
            c.experience_level,
            c.resume,
            c.linkedin,
            c.github,
            c.portfolio,
            c.created_at,
            c.updated_at,

            u.name,
            u.email,
            u.phone,

            cat.name AS category_name

        FROM candidates c

        INNER JOIN users u
            ON u.id = c.user_id

        LEFT JOIN categories cat
            ON cat.id = c.category_id

        $whereSql

        ORDER BY
            c.updated_at DESC,
            c.id DESC

        LIMIT ? OFFSET ?
    ";

    $candidateParams = $params;
    $candidateTypes = $types . "ii";

    $candidateParams[] = $limit;
    $candidateParams[] = $offset;

    $candidateStmt = $conn->prepare($candidateSql);

    if (!$candidateStmt) {
        throw new Exception(
            "Failed to prepare candidate query: " . $conn->error
        );
    }

    $candidateStmt->bind_param(
        $candidateTypes,
        ...$candidateParams
    );

    $candidateStmt->execute();

    $candidateResult = $candidateStmt->get_result();

    $candidates = [];

    // ========================================================
    // JSON HELPER
    // ========================================================

    function decodeJsonField($value)
    {
        if ($value === null || $value === "") {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return [];
    }

    // ========================================================
    // BUILD CANDIDATE RESPONSE
    // ========================================================

    while ($row = $candidateResult->fetch_assoc()) {

        $skills = decodeJsonField($row["skills"]);
        $educationData = decodeJsonField($row["education"]);
        $projects = decodeJsonField($row["projects"]);

        // ----------------------------------------------------
        // Experience
        // ----------------------------------------------------
        $experienceText = trim(
            (string) ($row["experience"] ?? "")
        );

        if ($experienceText === "") {
            $experienceText = "Not specified";
        }

        // ----------------------------------------------------
        // Experience level
        // ----------------------------------------------------
        $experienceLevelText = trim(
            (string) ($row["experience_level"] ?? "")
        );

        if ($experienceLevelText === "") {
            $experienceLevelText = "Not specified";
        }

        // ----------------------------------------------------
        // Location
        // ----------------------------------------------------
        $locationText = trim(
            (string) ($row["location"] ?? "")
        );

        if ($locationText === "") {
            $locationText = "Location not specified";
        }

        // ----------------------------------------------------
        // Headline
        // ----------------------------------------------------
        $headline = trim(
            (string) ($row["headline"] ?? "")
        );

        if ($headline === "") {
            $headline = "Job Seeker";
        }

        // ----------------------------------------------------
        // Category
        // ----------------------------------------------------
        $categoryName = trim(
            (string) ($row["category_name"] ?? "")
        );

        if ($categoryName === "") {
            $categoryName = "General";
        }

        // ----------------------------------------------------
        // Profile Image
        // ----------------------------------------------------
        $profileImage = trim(
            (string) ($row["profile_image"] ?? "")
        );

        // ----------------------------------------------------
        // Resume
        // ----------------------------------------------------
        $resume = trim(
            (string) ($row["resume"] ?? "")
        );

        // ----------------------------------------------------
        // Candidate
        // ----------------------------------------------------
        $candidate = [
            "candidate_id" => (int) $row["candidate_id"],
            "user_id" => (int) $row["user_id"],

            "name" => $row["name"] ?? "",
            "email" => $row["email"] ?? "",
            "phone" => $row["phone"] ?? "",

            "category_id" => $row["category_id"]
                ? (int) $row["category_id"]
                : null,

            "category_name" => $categoryName,

            "profile_image" => $profileImage,

            "headline" => $headline,

            "bio" => $row["bio"] ?? "",

            "location" => $locationText,

            "date_of_birth" => $row["date_of_birth"] ?? null,

            "education" => $educationData,

            "projects" => $projects,

            "skills" => $skills,

            "experience" => $experienceText,

            "experience_level" => $experienceLevelText,

            "resume" => $resume,

            "linkedin" => $row["linkedin"] ?? "",

            "github" => $row["github"] ?? "",

            "portfolio" => $row["portfolio"] ?? "",

            "created_at" => $row["created_at"] ?? null,

            "updated_at" => $row["updated_at"] ?? null,
        ];

        $candidates[] = $candidate;
    }

    $candidateStmt->close();

    // ========================================================
    // GET CATEGORIES
    // ========================================================

    $categories = [];

    $categorySql = "
        SELECT
            id,
            name
        FROM categories
        ORDER BY name ASC
    ";

    $categoryResult = $conn->query($categorySql);

    if ($categoryResult) {

        while ($category = $categoryResult->fetch_assoc()) {

            $categories[] = [
                "id" => (int) $category["id"],
                "name" => $category["name"]
            ];
        }
    }

    // ========================================================
    // PAGINATION
    // ========================================================

    $totalPages = $totalCandidates > 0
        ? (int) ceil($totalCandidates / $limit)
        : 0;

    // ========================================================
    // RESPONSE
    // ========================================================

    sendResponse(
        true,
        "Candidates fetched successfully.",
        [
            "candidates" => $candidates,

            "categories" => $categories,

            "pagination" => [
                "page" => $page,
                "limit" => $limit,
                "total" => $totalCandidates,
                "total_pages" => $totalPages
            ],

            "filters" => [
                "search" => $search,
                "category_id" => $categoryId,
                "location" => $location,
                "experience_level" => $experienceLevel,
                "education" => $education
            ]
        ]
    );

} catch (Throwable $e) {

    error_log(
        "Recruiter candidates API error: " .
        $e->getMessage()
    );

    sendResponse(
        false,
        "Failed to fetch candidates.",
        [
            "error" => $e->getMessage()
        ],
        500
    );
}