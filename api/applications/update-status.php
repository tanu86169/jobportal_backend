<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
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

$application_id = intval($data["application_id"] ?? 0);
$status = trim($data["status"] ?? "");

if ($application_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Application ID is required"
    ]);

    exit;
}

$allowedStatuses = [
    "Applied",
    "Shortlisted",
    "Rejected",
    "Hired"
];

if (!in_array($status, $allowedStatuses)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid application status"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| 1. Get Application Details
|--------------------------------------------------------------------------
*/

$applicationStmt = $conn->prepare("
    SELECT
        a.id,
        a.candidate_id,
        a.job_id,
        a.status AS old_status,
        u.name AS candidate_name,
        j.job_title,
        j.company_name
    FROM applications a
    INNER JOIN users u ON a.candidate_id = u.id
    INNER JOIN jobs j ON a.job_id = j.id
    WHERE a.id = ?
    LIMIT 1
");

if (!$applicationStmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare application query",
        "error" => $conn->error
    ]);

    exit;
}

$applicationStmt->bind_param(
    "i",
    $application_id
);

$applicationStmt->execute();

$result = $applicationStmt->get_result();

if ($result->num_rows === 0) {

    echo json_encode([
        "success" => false,
        "message" => "Application not found"
    ]);

    $applicationStmt->close();
    $conn->close();

    exit;
}

$application = $result->fetch_assoc();

$applicationStmt->close();


$candidateId = intval($application["candidate_id"]);
$candidateName = $application["candidate_name"];
$jobTitle = $application["job_title"];
$companyName = $application["company_name"];
$oldStatus = $application["old_status"];


/*
|--------------------------------------------------------------------------
| 2. Check Same Status
|--------------------------------------------------------------------------
*/

if ($oldStatus === $status) {

    echo json_encode([
        "success" => false,
        "message" => "Application status is already " . $status
    ]);

    $conn->close();

    exit;
}


/*
|--------------------------------------------------------------------------
| 3. Update Application Status
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    UPDATE applications
    SET status = ?
    WHERE id = ?
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare update query",
        "error" => $conn->error
    ]);

    exit;
}

$stmt->bind_param(
    "si",
    $status,
    $application_id
);

if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to update application status",
        "error" => $stmt->error
    ]);

    $stmt->close();
    $conn->close();

    exit;
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| 4. Create Candidate Notification
|--------------------------------------------------------------------------
*/

$notificationTitle = "";
$notificationMessage = "";

if ($status === "Shortlisted") {

    $notificationTitle = "Application Shortlisted";

    $notificationMessage =
        "Congratulations! Your application for " .
        $jobTitle .
        " at " .
        $companyName .
        " has been shortlisted.";

} elseif ($status === "Rejected") {

    $notificationTitle = "Application Rejected";

    $notificationMessage =
        "Your application for " .
        $jobTitle .
        " at " .
        $companyName .
        " was not selected.";

} elseif ($status === "Hired") {

    $notificationTitle = "Congratulations! You are Hired";

    $notificationMessage =
        "Congratulations! You have been selected for the " .
        $jobTitle .
        " position at " .
        $companyName .
        ".";

} elseif ($status === "Applied") {

    $notificationTitle = "Application Status Updated";

    $notificationMessage =
        "Your application for " .
        $jobTitle .
        " at " .
        $companyName .
        " is now marked as Applied.";
}


/*
|--------------------------------------------------------------------------
| 5. Insert Notification
|--------------------------------------------------------------------------
*/

if ($notificationTitle !== "") {

    $notificationType = "application";

    $notificationStmt = $conn->prepare("
        INSERT INTO notifications
        (
            user_id,
            type,
            title,
            message,
            related_id,
            is_read
        )
        VALUES (?, ?, ?, ?, ?, 0)
    ");

    if ($notificationStmt) {

        $notificationStmt->bind_param(
            "isssi",
            $candidateId,
            $notificationType,
            $notificationTitle,
            $notificationMessage,
            $application_id
        );

        $notificationStmt->execute();

        $notificationStmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| 6. Final Response
|--------------------------------------------------------------------------
*/

echo json_encode([
    "success" => true,
    "message" => "Application status updated successfully",
    "application_id" => $application_id,
    "old_status" => $oldStatus,
    "status" => $status,
    "candidate_id" => $candidateId
]);

$conn->close();

?>