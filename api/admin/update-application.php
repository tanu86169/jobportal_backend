<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST request allowed"
    ]);

    exit;
}

$data = json_decode(
    file_get_contents("php://input"),
    true
);

$applicationId = (int) (
    $data["application_id"] ??
    0
);

$status = trim(
    $data["status"] ??
    "Applied"
);

$interviewNote =
    trim(
        $data["interview_note"] ?? ""
    );

$interviewDate =
    $data["interview_date"] ?? null;

if ($applicationId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid application ID is required"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| ALLOWED STATUSES
|--------------------------------------------------------------------------
*/

$allowedStatuses = [
    "Applied",
    "Screening",
    "Interview Scheduled",
    "Interviewed",
    "Shortlisted",
    "Hired",
    "Rejected"
];

if (!in_array(
    $status,
    $allowedStatuses,
    true
)) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid application status"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| CHECK APPLICATION
|--------------------------------------------------------------------------
*/

$check = $conn->prepare(
    "SELECT id
     FROM applications
     WHERE id = ?
     LIMIT 1"
);

$check->bind_param(
    "i",
    $applicationId
);

$check->execute();

$result = $check->get_result();

if ($result->num_rows === 0) {

    $check->close();

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Application not found"
    ]);

    exit;
}

$check->close();

/*
|--------------------------------------------------------------------------
| DATE
|--------------------------------------------------------------------------
*/

if (
    $interviewDate === "" ||
    $interviewDate === null
) {
    $interviewDate = null;
}

/*
|--------------------------------------------------------------------------
| UPDATE
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "UPDATE applications
     SET status = ?,
         interview_note = ?,
         interview_date = ?
     WHERE id = ?"
);

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database prepare failed",
        "error" => $conn->error
    ]);

    exit;
}

$stmt->bind_param(
    "sssi",
    $status,
    $interviewNote,
    $interviewDate,
    $applicationId
);

if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to update application",
        "error" => $stmt->error
    ]);

    $stmt->close();
    $conn->close();

    exit;
}

$stmt->close();

echo json_encode([
    "success" => true,
    "message" => "Application updated successfully",
    "application_id" => $applicationId,
    "status" => $status
]);

$conn->close();

?>