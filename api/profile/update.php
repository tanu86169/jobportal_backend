<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Vary: Origin");
header("Access-Control-Allow-Methods: POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "PUT") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST or PUT request allowed"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$input = file_get_contents("php://input");
$data = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON data"
    ]);
    exit;
}

$userId = intval($data["user_id"] ?? 0);

if ($userId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid user_id is required"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| USER DATA
|--------------------------------------------------------------------------
*/

$name = trim($data["name"] ?? "");
$phone = trim($data["phone"] ?? "");

/*
|--------------------------------------------------------------------------
| PROFILE DATA
|--------------------------------------------------------------------------
*/

$headline = trim($data["headline"] ?? "");
$bio = trim($data["bio"] ?? "");
$location = trim($data["location"] ?? "");
$dateOfBirth = trim($data["date_of_birth"] ?? "");

$categoryId = intval(
    $data["category_id"]
    ?? $data["career_field_id"]
    ?? 0
);

$skills = $data["skills"] ?? [];
$experience = $data["experience"] ?? [];
$education = $data["education"] ?? [];
$projects = $data["projects"] ?? [];

$linkedin = trim($data["linkedin"] ?? "");
$github = trim($data["github"] ?? "");
$portfolio = trim($data["portfolio"] ?? "");

/*
|--------------------------------------------------------------------------
| RESUME
|--------------------------------------------------------------------------
|
| Existing resume ko preserve karenge.
| Agar frontend resume bhejta hai to update karenge.
|
*/

$resume = trim($data["resume"] ?? "");

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if ($name === "") {
    echo json_encode([
        "success" => false,
        "message" => "Name is required"
    ]);
    exit;
}

if ($phone !== "" && !preg_match("/^[6-9][0-9]{9}$/", $phone)) {
    echo json_encode([
        "success" => false,
        "message" => "Enter a valid 10-digit phone number"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| CHECK USER
|--------------------------------------------------------------------------
*/

$checkUser = $conn->prepare(
    "SELECT id, role
     FROM users
     WHERE id = ?
     LIMIT 1"
);

if (!$checkUser) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "User query preparation failed",
        "error" => $conn->error
    ]);
    exit;
}

$checkUser->bind_param("i", $userId);
$checkUser->execute();

$userResult = $checkUser->get_result();

if ($userResult->num_rows === 0) {

    $checkUser->close();

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "User not found"
    ]);
    exit;
}

$user = $userResult->fetch_assoc();

$checkUser->close();

if ($user["role"] !== "candidate") {
    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Only candidate profile can be updated"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| CHECK EXISTING CANDIDATE PROFILE
|--------------------------------------------------------------------------
*/

$existingResume = "";

$checkCandidate = $conn->prepare(
    "SELECT id, resume
     FROM candidates
     WHERE user_id = ?
     LIMIT 1"
);

if (!$checkCandidate) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Candidate query preparation failed",
        "error" => $conn->error
    ]);
    exit;
}

$checkCandidate->bind_param("i", $userId);
$checkCandidate->execute();

$candidateResult = $checkCandidate->get_result();

$candidateExists = $candidateResult->num_rows > 0;

if ($candidateExists) {
    $candidateData = $candidateResult->fetch_assoc();
    $existingResume = $candidateData["resume"] ?? "";
}

$checkCandidate->close();

/*
|--------------------------------------------------------------------------
| RESUME
|--------------------------------------------------------------------------
|
| Agar new resume nahi aaya to old resume preserve hoga.
|
*/

if ($resume === "") {
    $resume = $existingResume;
}

/*
|--------------------------------------------------------------------------
| UPDATE USERS
|--------------------------------------------------------------------------
*/

$userStmt = $conn->prepare(
    "UPDATE users
     SET name = ?, phone = ?
     WHERE id = ?"
);

if (!$userStmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "User update preparation failed",
        "error" => $conn->error
    ]);
    exit;
}

$userStmt->bind_param(
    "ssi",
    $name,
    $phone,
    $userId
);

if (!$userStmt->execute()) {

    $error = $userStmt->error;

    $userStmt->close();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to update user",
        "error" => $error
    ]);
    exit;
}

$userStmt->close();

/*
|--------------------------------------------------------------------------
| ARRAY -> JSON
|--------------------------------------------------------------------------
*/

$skillsJson = json_encode(
    is_array($skills) ? $skills : [],
    JSON_UNESCAPED_UNICODE
);

$experienceJson = json_encode(
    is_array($experience) ? $experience : [],
    JSON_UNESCAPED_UNICODE
);

$educationJson = json_encode(
    is_array($education) ? $education : [],
    JSON_UNESCAPED_UNICODE
);

$projectsJson = json_encode(
    is_array($projects) ? $projects : [],
    JSON_UNESCAPED_UNICODE
);

/*
|--------------------------------------------------------------------------
| UPDATE / INSERT CANDIDATE
|--------------------------------------------------------------------------
*/

if ($candidateExists) {

    $stmt = $conn->prepare(
        "UPDATE candidates SET

            headline = ?,
            bio = ?,
            location = ?,
            date_of_birth = NULLIF(?, ''),
            category_id = NULLIF(?, 0),
            education = ?,
            skills = ?,
            experience = ?,
            projects = ?,
            resume = ?,
            linkedin = ?,
            github = ?,
            portfolio = ?,
            updated_at = CURRENT_TIMESTAMP

         WHERE user_id = ?"
    );

    if (!$stmt) {
        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Profile update preparation failed",
            "error" => $conn->error
        ]);
        exit;
    }

    $stmt->bind_param(
        "ssssissssssssi",
        $headline,
        $bio,
        $location,
        $dateOfBirth,
        $categoryId,
        $educationJson,
        $skillsJson,
        $experienceJson,
        $projectsJson,
        $resume,
        $linkedin,
        $github,
        $portfolio,
        $userId
    );

} else {

    $stmt = $conn->prepare(
        "INSERT INTO candidates
        (
            user_id,
            headline,
            bio,
            location,
            date_of_birth,
            category_id,
            education,
            skills,
            experience,
            projects,
            resume,
            linkedin,
            github,
            portfolio
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            NULLIF(?, ''),
            NULLIF(?, 0),
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )"
    );

    if (!$stmt) {
        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Profile insert preparation failed",
            "error" => $conn->error
        ]);
        exit;
    }

    $stmt->bind_param(
        "issssissssssss",
        $userId,
        $headline,
        $bio,
        $location,
        $dateOfBirth,
        $categoryId,
        $educationJson,
        $skillsJson,
        $experienceJson,
        $projectsJson,
        $resume,
        $linkedin,
        $github,
        $portfolio
    );
}

/*
|--------------------------------------------------------------------------
| EXECUTE PROFILE
|--------------------------------------------------------------------------
*/

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to update profile",
        "error" => $error
    ]);
    exit;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| SUCCESS
|--------------------------------------------------------------------------
*/

echo json_encode([
    "success" => true,
    "message" => "Profile updated successfully",
    "user_id" => $userId,
    "resume" => $resume
]);

$conn->close();

?>