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

$name = trim(
    $data["name"] ?? ""
);

$email = trim(
    $data["email"] ?? ""
);

$phone = trim(
    $data["phone"] ?? ""
);

$password =
    $data["password"] ?? "";

$companyName = trim(
    $data["company_name"] ?? ""
);

$location = trim(
    $data["location"] ?? ""
);

$website = trim(
    $data["website"] ?? ""
);

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if ($recruiterId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid recruiter ID is required"
    ]);

    exit;
}

if ($name === "") {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter name is required"
    ]);

    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid email is required"
    ]);

    exit;
}

if (!preg_match("/^[0-9]{10}$/", $phone)) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Phone number must contain exactly 10 digits"
    ]);

    exit;
}

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
| CHECK RECRUITER
|--------------------------------------------------------------------------
*/

$checkStmt = $conn->prepare(
    "SELECT id
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

$result = $checkStmt->get_result();

if ($result->num_rows === 0) {

    $checkStmt->close();

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter not found"
    ]);

    exit;
}

$checkStmt->close();

/*
|--------------------------------------------------------------------------
| CHECK EMAIL DUPLICATE
|--------------------------------------------------------------------------
*/

$emailStmt = $conn->prepare(
    "SELECT id
     FROM users
     WHERE email = ?
       AND id != ?
     LIMIT 1"
);

if (!$emailStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to check email",
        "error" => $conn->error
    ]);

    exit;
}

$emailStmt->bind_param(
    "si",
    $email,
    $recruiterId
);

$emailStmt->execute();

$emailResult =
    $emailStmt->get_result();

if ($emailResult->num_rows > 0) {

    $emailStmt->close();

    http_response_code(409);

    echo json_encode([
        "success" => false,
        "message" => "Another user already uses this email"
    ]);

    exit;
}

$emailStmt->close();

/*
|--------------------------------------------------------------------------
| TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | UPDATE USER WITHOUT PASSWORD
    |--------------------------------------------------------------------------
    */

    if ($password === "") {

        $updateStmt = $conn->prepare(
            "UPDATE users
             SET
                name = ?,
                email = ?,
                phone = ?
             WHERE id = ?
               AND role = 'recruiter'"
        );

        if (!$updateStmt) {
            throw new Exception(
                "Failed to prepare recruiter update: " .
                $conn->error
            );
        }

        $updateStmt->bind_param(
            "sssi",
            $name,
            $email,
            $phone,
            $recruiterId
        );

    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE USER WITH PASSWORD
    |--------------------------------------------------------------------------
    */

    else {

        $hashedPassword =
            password_hash(
                $password,
                PASSWORD_DEFAULT
            );

        $updateStmt = $conn->prepare(
            "UPDATE users
             SET
                name = ?,
                email = ?,
                phone = ?,
                password = ?
             WHERE id = ?
               AND role = 'recruiter'"
        );

        if (!$updateStmt) {
            throw new Exception(
                "Failed to prepare recruiter update: " .
                $conn->error
            );
        }

        $updateStmt->bind_param(
            "ssssi",
            $name,
            $email,
            $phone,
            $hashedPassword,
            $recruiterId
        );
    }

    if (!$updateStmt->execute()) {
        throw new Exception(
            "Failed to update recruiter: " .
            $updateStmt->error
        );
    }

    $updateStmt->close();

    /*
    |--------------------------------------------------------------------------
    | COMPANY PROFILE
    |--------------------------------------------------------------------------
    */

    if ($companyName !== "") {

        $profileCheck = $conn->prepare(
            "SELECT id
             FROM company_profiles
             WHERE recruiter_id = ?
             LIMIT 1"
        );

        if (!$profileCheck) {
            throw new Exception(
                "Failed to check company profile: " .
                $conn->error
            );
        }

        $profileCheck->bind_param(
            "i",
            $recruiterId
        );

        $profileCheck->execute();

        $profileResult =
            $profileCheck->get_result();

        $existingProfile =
            $profileResult->fetch_assoc();

        $profileCheck->close();

        /*
        |--------------------------------------------------------------------------
        | UPDATE COMPANY
        |--------------------------------------------------------------------------
        */

        if ($existingProfile) {

            $profileStmt = $conn->prepare(
                "UPDATE company_profiles
                 SET
                    company_name = ?,
                    email = ?,
                    phone = ?,
                    location = ?,
                    website = ?
                 WHERE recruiter_id = ?"
            );

            if (!$profileStmt) {
                throw new Exception(
                    "Failed to prepare company profile update: " .
                    $conn->error
                );
            }

            $profileStmt->bind_param(
                "sssssi",
                $companyName,
                $email,
                $phone,
                $location,
                $website,
                $recruiterId
            );

        }

        /*
        |--------------------------------------------------------------------------
        | INSERT COMPANY
        |--------------------------------------------------------------------------
        */

        else {

            $profileStmt = $conn->prepare(
                "INSERT INTO company_profiles
                (
                    recruiter_id,
                    company_name,
                    email,
                    phone,
                    location,
                    website
                )
                VALUES (?, ?, ?, ?, ?, ?)"
            );

            if (!$profileStmt) {
                throw new Exception(
                    "Failed to prepare company profile insert: " .
                    $conn->error
                );
            }

            $profileStmt->bind_param(
                "isssss",
                $recruiterId,
                $companyName,
                $email,
                $phone,
                $location,
                $website
            );
        }

        if (!$profileStmt->execute()) {
            throw new Exception(
                "Failed to save company profile: " .
                $profileStmt->error
            );
        }

        $profileStmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE JOB COMPANY NAME
    |--------------------------------------------------------------------------
    */

    if ($companyName !== "") {

        $jobCompanyStmt = $conn->prepare(
            "UPDATE jobs
             SET company_name = ?
             WHERE recruiter_id = ?"
        );

        if ($jobCompanyStmt) {

            $jobCompanyStmt->bind_param(
                "si",
                $companyName,
                $recruiterId
            );

            $jobCompanyStmt->execute();

            $jobCompanyStmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    echo json_encode([
        "success" => true,
        "message" => "Recruiter updated successfully",
        "recruiter" => [
            "id" => $recruiterId,
            "name" => $name,
            "email" => $email,
            "phone" => $phone,
            "company_name" => $companyName,
            "location" => $location,
            "website" => $website
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