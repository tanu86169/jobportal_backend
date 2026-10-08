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
        "message" => "Only POST request allowed"
    ]);

    exit;
}

$data = json_decode(
    file_get_contents("php://input"),
    true
);

if (!is_array($data)) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON data"
    ]);

    exit;
}

$recruiterId = intval(
    $data["recruiter_id"]
    ?? $data["id"]
    ?? 0
);

if ($recruiterId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid recruiter ID is required"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| CHECK RECRUITER
|--------------------------------------------------------------------------
*/

$checkStmt = $conn->prepare(
    "SELECT id, name, email
     FROM users
     WHERE id = ?
       AND role = 'recruiter'
     LIMIT 1"
);

if (!$checkStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to check recruiter",
        "error" => $conn->error
    ]);

    exit;
}

$checkStmt->bind_param(
    "i",
    $recruiterId
);

$checkStmt->execute();

$result =
    $checkStmt->get_result();

$recruiter =
    $result->fetch_assoc();

$checkStmt->close();

if (!$recruiter) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter not found"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | 1. GET RECRUITER JOB IDS
    |--------------------------------------------------------------------------
    */

    $jobIds = [];

    $jobStmt = $conn->prepare(
        "SELECT id
         FROM jobs
         WHERE recruiter_id = ?"
    );

    if (!$jobStmt) {
        throw new Exception(
            "Failed to fetch recruiter jobs: " .
            $conn->error
        );
    }

    $jobStmt->bind_param(
        "i",
        $recruiterId
    );

    $jobStmt->execute();

    $jobResult =
        $jobStmt->get_result();

    while (
        $job = $jobResult->fetch_assoc()
    ) {
        $jobIds[] =
            intval($job["id"]);
    }

    $jobStmt->close();

    /*
    |--------------------------------------------------------------------------
    | 2. DELETE APPLICATIONS
    |--------------------------------------------------------------------------
    */

    if (count($jobIds) > 0) {

        $placeholders =
            implode(
                ",",
                array_fill(
                    0,
                    count($jobIds),
                    "?"
                )
            );

        $types = str_repeat(
            "i",
            count($jobIds)
        );

        $applicationSql =
            "DELETE FROM applications
             WHERE job_id IN ($placeholders)";

        $applicationStmt =
            $conn->prepare(
                $applicationSql
            );

        if (!$applicationStmt) {
            throw new Exception(
                "Failed to delete applications: " .
                $conn->error
            );
        }

        $applicationStmt->bind_param(
            $types,
            ...$jobIds
        );

        if (
            !$applicationStmt->execute()
        ) {
            throw new Exception(
                "Failed to delete recruiter applications: " .
                $applicationStmt->error
            );
        }

        $applicationStmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | 3. DELETE JOBS
    |--------------------------------------------------------------------------
    */

    $deleteJobsStmt = $conn->prepare(
        "DELETE FROM jobs
         WHERE recruiter_id = ?"
    );

    if (!$deleteJobsStmt) {
        throw new Exception(
            "Failed to prepare job deletion: " .
            $conn->error
        );
    }

    $deleteJobsStmt->bind_param(
        "i",
        $recruiterId
    );

    if (!$deleteJobsStmt->execute()) {
        throw new Exception(
            "Failed to delete recruiter jobs: " .
            $deleteJobsStmt->error
        );
    }

    $deleteJobsStmt->close();

    /*
    |--------------------------------------------------------------------------
    | 4. DELETE COMPANY PROFILE
    |--------------------------------------------------------------------------
    */

    $profileStmt = $conn->prepare(
        "DELETE FROM company_profiles
         WHERE recruiter_id = ?"
    );

    if ($profileStmt) {

        $profileStmt->bind_param(
            "i",
            $recruiterId
        );

        $profileStmt->execute();

        $profileStmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | 5. DELETE COMPANY
    |--------------------------------------------------------------------------
    */

    $companyStmt = $conn->prepare(
        "DELETE FROM companies
         WHERE recruiter_id = ?"
    );

    if ($companyStmt) {

        $companyStmt->bind_param(
            "i",
            $recruiterId
        );

        $companyStmt->execute();

        $companyStmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | 6. DELETE USER
    |--------------------------------------------------------------------------
    */

    $userStmt = $conn->prepare(
        "DELETE FROM users
         WHERE id = ?
           AND role = 'recruiter'"
    );

    if (!$userStmt) {
        throw new Exception(
            "Failed to prepare recruiter deletion: " .
            $conn->error
        );
    }

    $userStmt->bind_param(
        "i",
        $recruiterId
    );

    if (!$userStmt->execute()) {
        throw new Exception(
            "Failed to delete recruiter: " .
            $userStmt->error
        );
    }

    $deletedRows =
        $userStmt->affected_rows;

    $userStmt->close();

    if ($deletedRows <= 0) {
        throw new Exception(
            "Recruiter could not be deleted"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    echo json_encode([
        "success" => true,
        "message" =>
            "Recruiter and related data deleted successfully",
        "deleted_recruiter" => [
            "id" => $recruiterId,
            "name" => $recruiter["name"],
            "email" => $recruiter["email"]
        ]
    ]);

} catch (Exception $e) {

    $conn->rollback();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}

$conn->close();

?>