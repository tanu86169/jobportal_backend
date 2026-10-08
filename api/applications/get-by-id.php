<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";

// Get application ID
$applicationId = isset($_GET["applicationId"])
    ? intval($_GET["applicationId"])
    : 0;

// Validate application ID
if ($applicationId <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Application ID is required."
    ]);
    exit;
}


// =====================================================
// GET APPLICATION DETAILS
// =====================================================

$sql = "
    SELECT
        a.id AS application_id,
        a.job_id,
        a.candidate_id,
        a.cover_letter,
        a.resume,
        a.status,
        a.applied_at,

        u.name AS candidate_name,
        u.email AS candidate_email,
        u.phone AS candidate_phone,

        j.job_title,
        j.company_name,
        j.location,
        j.job_type,
        j.experience,
        j.salary,
        j.deadline,
        j.skills

    FROM applications a

    INNER JOIN users u
        ON a.candidate_id = u.id

    INNER JOIN jobs j
        ON a.job_id = j.id

    WHERE a.id = ?

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare database query.",
        "error" => $conn->error
    ]);
    exit;
}

$stmt->bind_param("i", $applicationId);

$stmt->execute();

$result = $stmt->get_result();


// =====================================================
// APPLICATION NOT FOUND
// =====================================================

if ($result->num_rows === 0) {

    echo json_encode([
        "success" => false,
        "message" => "Application not found."
    ]);

    $stmt->close();
    $conn->close();

    exit;
}


// =====================================================
// GET APPLICATION
// =====================================================

$application = $result->fetch_assoc();


// =====================================================
// JOB TITLE
// =====================================================

$application["job_title"] = $application["job_title"] ?? "";


// =====================================================
// SKILLS
// =====================================================

if (!empty($application["skills"])) {

    $skills = json_decode(
        $application["skills"],
        true
    );

    if (json_last_error() === JSON_ERROR_NONE && is_array($skills)) {

        $application["skills"] = $skills;

    } else {

        // If skills are stored like:
        // React, Node.js, MongoDB

        $application["skills"] = array_values(
            array_filter(
                array_map(
                    "trim",
                    explode(",", $application["skills"])
                )
            )
        );
    }

} else {

    $application["skills"] = [];
}


// =====================================================
// RESUME URL
// =====================================================

if (!empty($application["resume"])) {

    // Already a complete URL
    if (
        strpos($application["resume"], "http://") !== 0 &&
        strpos($application["resume"], "https://") !== 0
    ) {

        $application["resume"] =
            "http://localhost/job_portal/job-portal-api/uploads/resumes/"
            . basename($application["resume"]);
    }
}


// =====================================================
// RESPONSE
// =====================================================

echo json_encode([
    "success" => true,
    "application" => $application
]);

$stmt->close();
$conn->close();

?>