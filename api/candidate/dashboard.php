<?php

require_once "../../config/cors.php";
require_once "../../config/database.php";

header("Content-Type: application/json");

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


/*
|--------------------------------------------------------------------------
| Candidate ID
|--------------------------------------------------------------------------
*/

$userId = isset($_GET["userId"])
    ? intval($_GET["userId"])
    : 0;

if ($userId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid userId is required"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Get Candidate
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT id, name, email, phone, role
     FROM users
     WHERE id = ?
     AND role = 'candidate'
     LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query preparation failed"
    ]);

    exit;
}

$stmt->bind_param("i", $userId);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    $stmt->close();

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Candidate not found"
    ]);

    exit;
}

$user = $result->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| Profile Completion
|--------------------------------------------------------------------------
*/

$totalFields = 3;
$completedFields = 0;

if (!empty($user["name"])) {
    $completedFields++;
}

if (!empty($user["email"])) {
    $completedFields++;
}

if (!empty($user["phone"])) {
    $completedFields++;
}

$profileCompletion = round(
    ($completedFields / $totalFields) * 100
);


/*
|--------------------------------------------------------------------------
| Applied Jobs Count
|--------------------------------------------------------------------------
*/

$appliedJobs = 0;

$appliedStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM applications
     WHERE candidate_id = ?"
);

if ($appliedStmt) {

    $appliedStmt->bind_param("i", $userId);
    $appliedStmt->execute();

    $appliedResult = $appliedStmt->get_result();
    $appliedData = $appliedResult->fetch_assoc();

    $appliedJobs = intval($appliedData["total"]);

    $appliedStmt->close();
}


/*
|--------------------------------------------------------------------------
| Saved Jobs Count
|--------------------------------------------------------------------------
*/

$savedJobs = 0;

$savedStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM saved_jobs
     WHERE candidate_id = ?"
);

if ($savedStmt) {

    $savedStmt->bind_param("i", $userId);
    $savedStmt->execute();

    $savedResult = $savedStmt->get_result();
    $savedData = $savedResult->fetch_assoc();

    $savedJobs = intval($savedData["total"]);

    $savedStmt->close();
}


/*
|--------------------------------------------------------------------------
| Shortlisted Jobs Count
|--------------------------------------------------------------------------
*/

$shortlisted = 0;

$shortlistedStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM applications
     WHERE candidate_id = ?
     AND status = 'Shortlisted'"
);

if ($shortlistedStmt) {

    $shortlistedStmt->bind_param("i", $userId);
    $shortlistedStmt->execute();

    $shortlistedResult = $shortlistedStmt->get_result();
    $shortlistedData = $shortlistedResult->fetch_assoc();

    $shortlisted = intval($shortlistedData["total"]);

    $shortlistedStmt->close();
}


/*
|--------------------------------------------------------------------------
| Profile Views
|--------------------------------------------------------------------------
|
| Abhi profile views ka separate tracking system nahi hai.
|
*/

$profileViews = 0;


/*
|--------------------------------------------------------------------------
| Dashboard Response
|--------------------------------------------------------------------------
*/

echo json_encode([

    "success" => true,

    "message" =>
        "Candidate dashboard data fetched successfully",

    "data" => [

        "profile" => [

            "id" => $user["id"],

            "name" => $user["name"],

            "email" => $user["email"],

            "phone" => $user["phone"],

            "role" => $user["role"]
        ],

        "profileCompletion" => $profileCompletion,

        "stats" => [

            "appliedJobs" => $appliedJobs,

            "savedJobs" => $savedJobs,

            "shortlisted" => $shortlisted,

            "profileViews" => $profileViews
        ],

        "recommendedJobs" => [],

        "appliedJobs" => [],

        "recentSearches" => [],

        "notifications" => [],

        "recommendedCompanies" => []
    ]
]);


$conn->close();

?>