<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);

    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$id = intval($data["id"] ?? 0);

if ($id <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid job ID."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Check job
|--------------------------------------------------------------------------
*/

$check = $conn->prepare(
    "SELECT id FROM jobs WHERE id = ? LIMIT 1"
);

$check->bind_param("i", $id);
$check->execute();

$result = $check->get_result();

if ($result->num_rows === 0) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Job not found."
    ]);

    exit;
}

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | Delete applications
    |--------------------------------------------------------------------------
    */

    $deleteApplications = $conn->prepare(
        "DELETE FROM applications WHERE job_id = ?"
    );

    $deleteApplications->bind_param("i", $id);
    $deleteApplications->execute();

    $deletedApplications = $deleteApplications->affected_rows;

    $deleteApplications->close();

    /*
    |--------------------------------------------------------------------------
    | Delete job
    |--------------------------------------------------------------------------
    */

    $deleteJob = $conn->prepare(
        "DELETE FROM jobs WHERE id = ?"
    );

    $deleteJob->bind_param("i", $id);

    if (!$deleteJob->execute()) {
        throw new Exception($deleteJob->error);
    }

    $deleteJob->close();

    $conn->commit();

    echo json_encode([
        "success" => true,
        "message" => "Job deleted successfully.",
        "deleted_applications" => $deletedApplications
    ]);

} catch (Exception $e) {

    $conn->rollback();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to delete job.",
        "error" => $e->getMessage()
    ]);
}

$conn->close();
?>