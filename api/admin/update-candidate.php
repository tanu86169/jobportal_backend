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
| GET FORM DATA
|--------------------------------------------------------------------------
*/

$candidateId = (int) (
    $data["candidate_id"]
    ?? $data["id"]
    ?? 0
);

$name = trim(
    $data["name"]
    ?? $data["candidate_name"]
    ?? ""
);

$email = trim(
    $data["email"]
    ?? $data["candidate_email"]
    ?? ""
);

$phone = trim(
    $data["phone"]
    ?? $data["candidate_phone"]
    ?? ""
);

$status = trim(
    $data["status"]
    ?? $data["candidate_status"]
    ?? "active"
);

$categoryId = (
    isset($data["category_id"]) &&
    $data["category_id"] !== "" &&
    $data["category_id"] !== null
)
    ? (int) $data["category_id"]
    : 0;

/*
|--------------------------------------------------------------------------
| PASSWORD
|--------------------------------------------------------------------------
|
| Password optional hai.
|
| Password empty hai:
|     Old password same rahega.
|
| Password diya gaya hai:
|     New password hash hoga aur users.password me update hoga.
|
|--------------------------------------------------------------------------
*/

$password = trim(
    $data["password"]
    ?? ""
);

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if ($candidateId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid candidate ID is required"
    ]);

    exit;
}

if ($name === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Candidate name is required"
    ]);

    exit;
}

if ($email === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Email is required"
    ]);

    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid email address"
    ]);

    exit;
}

if ($phone === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Phone is required"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| PHONE VALIDATION
|--------------------------------------------------------------------------
*/

if (!preg_match('/^\d{10}$/', $phone)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Phone number must be exactly 10 digits"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| CATEGORY REQUIRED
|--------------------------------------------------------------------------
*/

if ($categoryId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Category is required"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| PASSWORD VALIDATION
|--------------------------------------------------------------------------
|
| Password tabhi validate hoga jab admin new password dega.
|
|--------------------------------------------------------------------------
*/

if ($password !== "" && strlen($password) < 6) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Password must be at least 6 characters"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

if (!in_array($status, ["active", "inactive"], true)) {
    $status = "active";
}

/*
|--------------------------------------------------------------------------
| CHECK CANDIDATE
|--------------------------------------------------------------------------
*/

$checkCandidate = $conn->prepare(
    "SELECT id, name, email, password
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

$candidateResult = $checkCandidate->get_result();

if ($candidateResult->num_rows === 0) {

    $checkCandidate->close();

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Candidate not found"
    ]);

    exit;
}

$existingCandidate = $candidateResult->fetch_assoc();

$checkCandidate->close();

/*
|--------------------------------------------------------------------------
| CHECK CATEGORY
|--------------------------------------------------------------------------
*/

$categoryCheck = $conn->prepare(
    "SELECT id, name
     FROM categories
     WHERE id = ?
     LIMIT 1"
);

if (!$categoryCheck) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Category check failed",
        "error" => $conn->error
    ]);

    exit;
}

$categoryCheck->bind_param(
    "i",
    $categoryId
);

$categoryCheck->execute();

$categoryResult = $categoryCheck->get_result();

if ($categoryResult->num_rows === 0) {

    $categoryCheck->close();

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Selected category does not exist"
    ]);

    exit;
}

$categoryRow = $categoryResult->fetch_assoc();

$categoryName = $categoryRow["name"];

$categoryCheck->close();

/*
|--------------------------------------------------------------------------
| CHECK DUPLICATE EMAIL
|--------------------------------------------------------------------------
*/

$checkEmail = $conn->prepare(
    "SELECT id
     FROM users
     WHERE email = ?
     AND id != ?
     LIMIT 1"
);

if (!$checkEmail) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Email check failed",
        "error" => $conn->error
    ]);

    exit;
}

$checkEmail->bind_param(
    "si",
    $email,
    $candidateId
);

$checkEmail->execute();

$emailResult = $checkEmail->get_result();

if ($emailResult->num_rows > 0) {

    $checkEmail->close();

    http_response_code(409);

    echo json_encode([
        "success" => false,
        "message" => "Email already belongs to another user"
    ]);

    exit;
}

$checkEmail->close();

/*
|--------------------------------------------------------------------------
| START TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | UPDATE USERS
    |--------------------------------------------------------------------------
    */

    if ($password !== "") {

        /*
        |--------------------------------------------------------------
        | HASH NEW PASSWORD
        |--------------------------------------------------------------
        */

        $hashedPassword = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        if ($hashedPassword === false) {
            throw new Exception(
                "Password hashing failed"
            );
        }

        $updateUser = $conn->prepare(
            "UPDATE users
             SET name = ?,
                 email = ?,
                 phone = ?,
                 status = ?,
                 password = ?
             WHERE id = ?
             AND role = 'candidate'"
        );

        if (!$updateUser) {
            throw new Exception(
                "User update prepare failed: " .
                $conn->error
            );
        }

        $updateUser->bind_param(
            "sssssi",
            $name,
            $email,
            $phone,
            $status,
            $hashedPassword,
            $candidateId
        );

    } else {

        /*
        |--------------------------------------------------------------
        | PASSWORD BLANK
        |
        | Old password remains unchanged
        |--------------------------------------------------------------
        */

        $updateUser = $conn->prepare(
            "UPDATE users
             SET name = ?,
                 email = ?,
                 phone = ?,
                 status = ?
             WHERE id = ?
             AND role = 'candidate'"
        );

        if (!$updateUser) {
            throw new Exception(
                "User update prepare failed: " .
                $conn->error
            );
        }

        $updateUser->bind_param(
            "ssssi",
            $name,
            $email,
            $phone,
            $status,
            $candidateId
        );
    }

    if (!$updateUser->execute()) {
        throw new Exception(
            "Failed to update user: " .
            $updateUser->error
        );
    }

    $updateUser->close();

    /*
    |--------------------------------------------------------------------------
    | CHECK CANDIDATE PROFILE
    |--------------------------------------------------------------------------
    */

    $profileCheck = $conn->prepare(
        "SELECT id
         FROM candidates
         WHERE user_id = ?
         LIMIT 1"
    );

    if (!$profileCheck) {
        throw new Exception(
            "Candidate profile check failed: " .
            $conn->error
        );
    }

    $profileCheck->bind_param(
        "i",
        $candidateId
    );

    $profileCheck->execute();

    $profileResult = $profileCheck->get_result();

    $profileExists = $profileResult->num_rows > 0;

    $profileCheck->close();

    /*
    |--------------------------------------------------------------------------
    | UPDATE OR CREATE CANDIDATE PROFILE
    |--------------------------------------------------------------------------
    */

    if ($profileExists) {

        $updateProfile = $conn->prepare(
            "UPDATE candidates
             SET category_id = ?
             WHERE user_id = ?"
        );

        if (!$updateProfile) {
            throw new Exception(
                "Candidate profile update failed: " .
                $conn->error
            );
        }

        $updateProfile->bind_param(
            "ii",
            $categoryId,
            $candidateId
        );

        if (!$updateProfile->execute()) {
            throw new Exception(
                "Failed to update candidate category: " .
                $updateProfile->error
            );
        }

        $updateProfile->close();

    } else {

        $insertProfile = $conn->prepare(
            "INSERT INTO candidates
             (user_id, category_id)
             VALUES (?, ?)"
        );

        if (!$insertProfile) {
            throw new Exception(
                "Candidate profile insert failed: " .
                $conn->error
            );
        }

        $insertProfile->bind_param(
            "ii",
            $candidateId,
            $categoryId
        );

        if (!$insertProfile->execute()) {
            throw new Exception(
                "Failed to create candidate profile: " .
                $insertProfile->error
            );
        }

        $insertProfile->close();
    }

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
        "message" => $password !== ""
            ? "Candidate updated successfully and password changed"
            : "Candidate updated successfully",

        "candidate_id" => $candidateId,

        "password_updated" => $password !== "",

        "candidate" => [
            "candidate_id" => $candidateId,
            "candidate_name" => $name,
            "candidate_email" => $email,
            "candidate_phone" => $phone,
            "candidate_status" => $status,
            "category_id" => $categoryId,
            "category_name" => $categoryName,
            "role" => "candidate"
        ]
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
        "message" => "Failed to update candidate",
        "error" => $e->getMessage()
    ]);
}

$conn->close();

?>