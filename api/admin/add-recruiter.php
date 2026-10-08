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

$name = trim($data["name"] ?? "");
$email = trim($data["email"] ?? "");
$phone = trim($data["phone"] ?? "");
$password = $data["password"] ?? "";

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

if (strlen($password) < 6) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Password must be at least 6 characters"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| CHECK EMAIL
|--------------------------------------------------------------------------
*/

$checkStmt = $conn->prepare(
    "SELECT id
     FROM users
     WHERE email = ?
     LIMIT 1"
);

if (!$checkStmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare email check",
        "error" => $conn->error
    ]);

    exit;
}

$checkStmt->bind_param(
    "s",
    $email
);

$checkStmt->execute();

$result = $checkStmt->get_result();

if ($result->num_rows > 0) {

    $checkStmt->close();

    http_response_code(409);

    echo json_encode([
        "success" => false,
        "message" => "Email already exists"
    ]);

    exit;
}

$checkStmt->close();

/*
|--------------------------------------------------------------------------
| PASSWORD HASH
|--------------------------------------------------------------------------
*/

$hashedPassword = password_hash(
    $password,
    PASSWORD_DEFAULT
);

$role = "recruiter";

/*
|--------------------------------------------------------------------------
| TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | INSERT USER
    |--------------------------------------------------------------------------
    */

    $userStmt = $conn->prepare(
        "INSERT INTO users
        (
            name,
            email,
            phone,
            password,
            role
        )
        VALUES (?, ?, ?, ?, ?)"
    );

    if (!$userStmt) {
        throw new Exception(
            "Failed to prepare recruiter insert: " .
            $conn->error
        );
    }

    $userStmt->bind_param(
        "sssss",
        $name,
        $email,
        $phone,
        $hashedPassword,
        $role
    );

    if (!$userStmt->execute()) {
        throw new Exception(
            "Failed to create recruiter: " .
            $userStmt->error
        );
    }

    $recruiterId =
        $userStmt->insert_id;

    $userStmt->close();

    /*
    |--------------------------------------------------------------------------
    | COMPANY PROFILE
    |--------------------------------------------------------------------------
    */

    if ($companyName !== "") {

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
                "Failed to prepare company profile: " .
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
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    echo json_encode([
        "success" => true,
        "message" => "Recruiter added successfully",
        "recruiter" => [
            "id" => $recruiterId,
            "name" => $name,
            "email" => $email,
            "phone" => $phone,
            "role" => $role,
            "company_name" => $companyName
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