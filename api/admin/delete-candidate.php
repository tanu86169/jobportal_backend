<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/database.php";

/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| METHOD
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST request allowed"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| GET JSON DATA
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| GET CANDIDATE ID
|--------------------------------------------------------------------------
*/

$candidateId = (int) (
    $data["candidate_id"] ??
    $data["id"] ??
    0
);

if ($candidateId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid candidate ID is required"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| CHECK CANDIDATE
|--------------------------------------------------------------------------
*/

$checkCandidate = $conn->prepare(
    "SELECT
        id,
        name,
        email,
        phone,
        status
     FROM users
     WHERE id = ?
     AND role = 'candidate'
     LIMIT 1"
);

if (!$checkCandidate) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Candidate check failed",
        "error" => $conn->error
    ]);

    exit;
}

$checkCandidate->bind_param(
    "i",
    $candidateId
);

$checkCandidate->execute();

$result = $checkCandidate->get_result();

if ($result->num_rows === 0) {

    $checkCandidate->close();

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Candidate not found"
    ]);

    exit;
}

$candidate = $result->fetch_assoc();

$checkCandidate->close();

/*
|--------------------------------------------------------------------------
| START TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | DELETE APPLICATIONS
    |--------------------------------------------------------------------------
    |
    | Candidate ki applications pehle delete karenge
    | taaki foreign key constraint ka problem na aaye.
    |
    */

    $deleteApplications = $conn->prepare(
        "DELETE FROM applications
         WHERE candidate_id = ?"
    );

    if (!$deleteApplications) {

        throw new Exception(
            "Unable to prepare application delete: " .
            $conn->error
        );
    }

    $deleteApplications->bind_param(
        "i",
        $candidateId
    );

    if (!$deleteApplications->execute()) {

        throw new Exception(
            "Failed to delete candidate applications: " .
            $deleteApplications->error
        );
    }

    $deletedApplications =
        $deleteApplications->affected_rows;

    $deleteApplications->close();

    /*
    |--------------------------------------------------------------------------
    | DELETE CANDIDATE PROFILE
    |--------------------------------------------------------------------------
    */

    $deleteProfile = $conn->prepare(
        "DELETE FROM candidates
         WHERE user_id = ?"
    );

    if (!$deleteProfile) {

        throw new Exception(
            "Unable to prepare candidate profile delete: " .
            $conn->error
        );
    }

    $deleteProfile->bind_param(
        "i",
        $candidateId
    );

    if (!$deleteProfile->execute()) {

        throw new Exception(
            "Failed to delete candidate profile: " .
            $deleteProfile->error
        );
    }

    $deletedProfile =
        $deleteProfile->affected_rows;

    $deleteProfile->close();

    /*
    |--------------------------------------------------------------------------
    | DELETE SAVED JOBS
    |--------------------------------------------------------------------------
    |
    | Agar saved_jobs table me candidate_id hai to
    | candidate ke saved jobs bhi remove karenge.
    |
    */

    $checkSavedJobsTable = $conn->query(
        "SHOW TABLES LIKE 'saved_jobs'"
    );

    if (
        $checkSavedJobsTable &&
        $checkSavedJobsTable->num_rows > 0
    ) {

        $deleteSavedJobs = $conn->prepare(
            "DELETE FROM saved_jobs
             WHERE candidate_id = ?"
        );

        if ($deleteSavedJobs) {

            $deleteSavedJobs->bind_param(
                "i",
                $candidateId
            );

            if (!$deleteSavedJobs->execute()) {

                throw new Exception(
                    "Failed to delete saved jobs: " .
                    $deleteSavedJobs->error
                );
            }

            $deleteSavedJobs->close();
        }
    }

    if ($checkSavedJobsTable) {
        $checkSavedJobsTable->free();
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE USER
    |--------------------------------------------------------------------------
    */

    $deleteUser = $conn->prepare(
        "DELETE FROM users
         WHERE id = ?
         AND role = 'candidate'"
    );

    if (!$deleteUser) {

        throw new Exception(
            "Unable to prepare user delete: " .
            $conn->error
        );
    }

    $deleteUser->bind_param(
        "i",
        $candidateId
    );

    if (!$deleteUser->execute()) {

        throw new Exception(
            "Failed to delete candidate user: " .
            $deleteUser->error
        );
    }

    if ($deleteUser->affected_rows === 0) {

        $deleteUser->close();

        throw new Exception(
            "Candidate could not be deleted"
        );
    }

    $deleteUser->close();

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        "success" => true,
        "message" => "Candidate deleted successfully",

        "candidate_id" => $candidateId,

        "candidate_name" =>
            $candidate["name"],

        "candidate_email" =>
            $candidate["email"],

        "deleted_applications" =>
            $deletedApplications,

        "deleted_profile" =>
            $deletedProfile > 0
    ]);

} catch (Exception $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    $conn->rollback();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to delete candidate",
        "error" => $e->getMessage()
    ]);
}

$conn->close();

?>