<?php

header("Content-Type: application/json; charset=UTF-8");

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

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET method is allowed.",
        "data" => null
    ]);

    exit;
}

require_once "../../config/database.php";

/* =========================================================
   HELPERS
========================================================= */

function decodeJsonValue($value)
{
    if ($value === null || $value === "") {
        return [];
    }

    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode($value, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $decoded;
    }

    return $value;
}


function normalizeText($value)
{
    if ($value === null) {
        return "";
    }

    return strtolower(trim((string)$value));
}


function normalizeSkills($value)
{
    $value = decodeJsonValue($value);

    if (is_array($value)) {

        $result = [];

        foreach ($value as $item) {

            if (is_array($item)) {

                foreach ($item as $nestedValue) {

                    if (is_string($nestedValue) && trim($nestedValue) !== "") {
                        $result[] = strtolower(trim($nestedValue));
                    }
                }

            } elseif (is_string($item)) {

                $item = trim($item);

                if ($item !== "") {
                    $result[] = strtolower($item);
                }
            }
        }

        return array_values(array_unique($result));
    }

    if (is_string($value)) {

        $parts = preg_split(
            '/[,|\/\n]+/',
            strtolower($value)
        );

        $parts = array_map("trim", $parts);

        return array_values(
            array_unique(
                array_filter($parts)
            )
        );
    }

    return [];
}


function normalizeList($value)
{
    $value = decodeJsonValue($value);

    if (is_array($value)) {

        $result = [];

        foreach ($value as $item) {

            if (is_array($item)) {

                foreach ($item as $nested) {

                    if (is_string($nested) && trim($nested) !== "") {
                        $result[] = strtolower(trim($nested));
                    }
                }

            } elseif (is_string($item)) {

                $item = trim($item);

                if ($item !== "") {
                    $result[] = strtolower($item);
                }
            }
        }

        return array_values(array_unique($result));
    }

    if (is_string($value)) {

        $parts = preg_split(
            '/[,|\/\n]+/',
            strtolower($value)
        );

        return array_values(
            array_unique(
                array_filter(
                    array_map("trim", $parts)
                )
            )
        );
    }

    return [];
}


/* =========================================================
   SKILL SCORE
   MAX = 50
========================================================= */

function calculateSkillScore($candidateSkills, $jobSkills)
{
    if (empty($jobSkills)) {
        return [
            "score" => 50,
            "matched" => [],
            "missing" => []
        ];
    }

    $matched = [];
    $missing = [];

    foreach ($jobSkills as $jobSkill) {

        $jobSkill = strtolower(trim($jobSkill));

        if ($jobSkill === "") {
            continue;
        }

        $found = false;

        foreach ($candidateSkills as $candidateSkill) {

            $candidateSkill = strtolower(trim($candidateSkill));

            if (
                $candidateSkill === $jobSkill ||
                strpos($candidateSkill, $jobSkill) !== false ||
                strpos($jobSkill, $candidateSkill) !== false
            ) {
                $found = true;
                break;
            }
        }

        if ($found) {
            $matched[] = $jobSkill;
        } else {
            $missing[] = $jobSkill;
        }
    }

    $total = count($jobSkills);
    $matchedCount = count($matched);

    $score = $total > 0
        ? round(($matchedCount / $total) * 50)
        : 50;

    return [
        "score" => $score,
        "matched" => $matched,
        "missing" => $missing
    ];
}


/* =========================================================
   EXPERIENCE SCORE
   MAX = 20
========================================================= */

function calculateExperienceScore($candidateExperience, $candidateLevel, $jobExperience)
{
    $jobExperience = normalizeText($jobExperience);
    $candidateLevel = normalizeText($candidateLevel);
    $candidateExperience = normalizeText($candidateExperience);

    if ($jobExperience === "") {
        return 20;
    }

    /*
       Fresher
       0-1 Years
       1-2 Years
       2-3 Years
       etc.
    */

    if (
        strpos($jobExperience, "fresher") !== false ||
        strpos($jobExperience, "0-1") !== false ||
        strpos($jobExperience, "0 - 1") !== false
    ) {

        if (
            strpos($candidateLevel, "fresher") !== false ||
            strpos($candidateExperience, "fresher") !== false ||
            strpos($candidateExperience, "0") !== false
        ) {
            return 20;
        }

        return 15;
    }

    preg_match('/(\d+)\s*-\s*(\d+)/', $jobExperience, $jobRange);

    if (!empty($jobRange)) {

        $minExperience = (int)$jobRange[1];
        $maxExperience = (int)$jobRange[2];

        preg_match('/(\d+)/', $candidateExperience, $candidateRange);

        $candidateYears = !empty($candidateRange)
            ? (int)$candidateRange[1]
            : 0;

        if (
            $candidateYears >= $minExperience &&
            $candidateYears <= $maxExperience
        ) {
            return 20;
        }

        if ($candidateYears >= $minExperience) {
            return 15;
        }
    }

    return 10;
}


/* =========================================================
   LOCATION SCORE
   MAX = 15
========================================================= */

function calculateLocationScore($candidateLocation, $jobLocation)
{
    $candidateLocation = normalizeText($candidateLocation);
    $jobLocation = normalizeText($jobLocation);

    if ($jobLocation === "") {
        return 15;
    }

    if ($candidateLocation === "") {
        return 0;
    }

    $jobLocations = preg_split(
        '/[,|\/]+/',
        $jobLocation
    );

    foreach ($jobLocations as $location) {

        $location = trim($location);

        if ($location === "") {
            continue;
        }

        if (
            strpos($candidateLocation, $location) !== false ||
            strpos($location, $candidateLocation) !== false
        ) {
            return 15;
        }
    }

    return 0;
}


/* =========================================================
   EDUCATION SCORE
   MAX = 10
========================================================= */

function calculateEducationScore($candidateEducation, $jobEducation)
{
    $jobEducation = normalizeText($jobEducation);

    if ($jobEducation === "") {
        return 10;
    }

    $candidateEducation = decodeJsonValue($candidateEducation);

    if (is_array($candidateEducation)) {
        $candidateEducation = json_encode(
            $candidateEducation
        );
    }

    $candidateEducation = normalizeText($candidateEducation);

    if ($candidateEducation === "") {
        return 0;
    }

    $educationKeywords = [
        "bachelor",
        "bca",
        "b.tech",
        "btech",
        "b.e",
        "be",
        "mca",
        "m.tech",
        "mtech",
        "master",
        "degree",
        "mba",
        "bsc",
        "msc",
        "diploma"
    ];

    foreach ($educationKeywords as $keyword) {

        if (
            strpos($jobEducation, $keyword) !== false &&
            strpos($candidateEducation, $keyword) !== false
        ) {
            return 10;
        }
    }

    /*
       Generic text match
    */

    $jobWords = preg_split(
        '/[\s,\/\-]+/',
        $jobEducation
    );

    foreach ($jobWords as $word) {

        $word = trim($word);

        if (
            strlen($word) >= 4 &&
            strpos($candidateEducation, $word) !== false
        ) {
            return 10;
        }
    }

    return 0;
}


/* =========================================================
   CATEGORY SCORE
   MAX = 5
========================================================= */

function calculateCategoryScore($candidateCategory, $jobCategory)
{
    $candidateCategory = normalizeText($candidateCategory);
    $jobCategory = normalizeText($jobCategory);

    if ($jobCategory === "") {
        return 5;
    }

    if ($candidateCategory === "") {
        return 0;
    }

    if (
        $candidateCategory === $jobCategory ||
        strpos($candidateCategory, $jobCategory) !== false ||
        strpos($jobCategory, $candidateCategory) !== false
    ) {
        return 5;
    }

    return 0;
}


/* =========================================================
   INPUT
========================================================= */

$candidateId = isset($_GET["candidateId"])
    ? (int)$_GET["candidateId"]
    : 0;

$jobId = isset($_GET["jobId"])
    ? (int)$_GET["jobId"]
    : 0;


if ($candidateId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid candidateId is required.",
        "data" => null
    ]);

    exit;
}


if ($jobId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid jobId is required.",
        "data" => null
    ]);

    exit;
}


/* =========================================================
   CANDIDATE
========================================================= */

$sql = "
    SELECT
        c.id,
        c.user_id,
        c.category_id,
        c.headline,
        c.bio,
        c.location,
        c.education,
        c.skills,
        c.experience,
        c.experience_level,
        c.resume,
        c.linkedin,
        c.github,
        c.portfolio,
        c.profile_visibility,

        u.name,

        cat.name AS category_name

    FROM candidates c

    INNER JOIN users u
        ON u.id = c.user_id

    LEFT JOIN categories cat
        ON cat.id = c.category_id

    WHERE c.id = ?

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Candidate query preparation failed.",
        "error" => $conn->error
    ]);

    exit;
}

$stmt->bind_param("i", $candidateId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Candidate not found.",
        "data" => null
    ]);

    exit;
}

$candidate = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   PROFILE VISIBILITY
========================================================= */

if (
    isset($candidate["profile_visibility"]) &&
    (int)$candidate["profile_visibility"] !== 1
) {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Candidate profile is private.",
        "data" => null
    ]);

    exit;
}


/* =========================================================
   JOB
========================================================= */

$jobSql = "
    SELECT
        id,
        recruiter_id,
        job_title,
        position,
        company_name,
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
        company_logo,
        salary_min,
        salary_max,
        salary_type

    FROM jobs

    WHERE id = ?

    LIMIT 1
";

$jobStmt = $conn->prepare($jobSql);

if (!$jobStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Job query preparation failed.",
        "error" => $conn->error
    ]);

    exit;
}

$jobStmt->bind_param("i", $jobId);
$jobStmt->execute();

$jobResult = $jobStmt->get_result();

if ($jobResult->num_rows === 0) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Job not found.",
        "data" => [
            "requested_job_id" => $jobId
        ]
    ]);

    exit;
}

$job = $jobResult->fetch_assoc();

$jobStmt->close();


/* =========================================================
   PREPARE DATA
========================================================= */

$candidateSkills = normalizeSkills(
    $candidate["skills"] ?? ""
);

$jobSkills = normalizeSkills(
    $job["skills"] ?? ""
);


/* =========================================================
   CALCULATE SCORES
========================================================= */

$skillResult = calculateSkillScore(
    $candidateSkills,
    $jobSkills
);

$skillScore = $skillResult["score"];

$experienceScore = calculateExperienceScore(
    $candidate["experience"] ?? "",
    $candidate["experience_level"] ?? "",
    $job["experience"] ?? ""
);

$locationScore = calculateLocationScore(
    $candidate["location"] ?? "",
    $job["location"] ?? ""
);

$educationScore = calculateEducationScore(
    $candidate["education"] ?? "",
    $job["education"] ?? ""
);

$categoryScore = calculateCategoryScore(
    $candidate["category_name"] ?? "",
    $job["category"] ?? ""
);


/* =========================================================
   TOTAL
========================================================= */

$totalScore =
    $skillScore +
    $experienceScore +
    $locationScore +
    $educationScore +
    $categoryScore;

$totalScore = max(
    0,
    min(100, $totalScore)
);


/* =========================================================
   MATCH LABEL
========================================================= */

if ($totalScore >= 80) {
    $matchLabel = "Excellent Match";
} elseif ($totalScore >= 60) {
    $matchLabel = "Good Match";
} elseif ($totalScore >= 40) {
    $matchLabel = "Partial Match";
} else {
    $matchLabel = "Low Match";
}


/* =========================================================
   RESPONSE
========================================================= */

echo json_encode([
    "success" => true,

    "message" => "Candidate match calculated successfully.",

    "data" => [

        "candidate" => [
            "id" => (int)$candidate["id"],
            "user_id" => (int)$candidate["user_id"],
            "name" => $candidate["name"],
            "headline" => $candidate["headline"],
            "location" => $candidate["location"],
            "experience_level" => $candidate["experience_level"],
            "category" => $candidate["category_name"],
            "skills" => $candidateSkills
        ],

        "job" => [
            "id" => (int)$job["id"],
            "job_title" => $job["job_title"],
            "position" => $job["position"],
            "company_name" => $job["company_name"],
            "location" => $job["location"],
            "category" => $job["category"],
            "experience" => $job["experience"],
            "education" => $job["education"],
            "skills" => $jobSkills
        ],

        "match_score" => $totalScore,

        "match_label" => $matchLabel,

        "breakdown" => [
            "skills" => [
                "score" => $skillScore,
                "max" => 50
            ],

            "experience" => [
                "score" => $experienceScore,
                "max" => 20
            ],

            "location" => [
                "score" => $locationScore,
                "max" => 15
            ],

            "education" => [
                "score" => $educationScore,
                "max" => 10
            ],

            "category" => [
                "score" => $categoryScore,
                "max" => 5
            ]
        ],

        "matched_skills" => $skillResult["matched"],

        "missing_skills" => $skillResult["missing"]
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);