<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/database.php";

/*
|--------------------------------------------------------------------------
| CORS / OPTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| ONLY POST
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
| READ JSON
|--------------------------------------------------------------------------
*/

$data = json_decode(file_get_contents("php://input"), true);

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
| GET DATA
|--------------------------------------------------------------------------
*/

$name = trim($data["name"] ?? "");
$email = trim($data["email"] ?? "");
$phone = trim($data["phone"] ?? "");
$password = $data["password"] ?? "";

$categoryId = isset($data["category_id"]) && $data["category_id"] !== ""
    ? (int)$data["category_id"]
    : 0;

$skills = trim($data["skills"] ?? "");

$experience = trim(
    is_string($data["experience"] ?? "")
        ? $data["experience"]
        : ""
);

$status = strtolower(
    trim($data["status"] ?? "active")
);

/*
|--------------------------------------------------------------------------
| DEFAULT EXPERIENCE
|--------------------------------------------------------------------------
|
| Agar admin experience nahi deta to Fresher save hoga.
|
*/

if ($experience === "") {
    $experience = "Fresher";
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
| VALIDATION
|--------------------------------------------------------------------------
*/

if ($name === "") {

    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Candidate name is required."
    ]);

    exit;
}

if ($email === "") {

    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Email is required."
    ]);

    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address."
    ]);

    exit;
}

if ($phone === "") {

    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Phone number is required."
    ]);

    exit;
}

if (!preg_match("/^[0-9]{10}$/", $phone)) {

    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Phone number must contain exactly 10 digits."
    ]);

    exit;
}

if ($password === "") {

    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Password is required."
    ]);

    exit;
}

if (strlen($password) < 6) {

    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Password must be at least 6 characters."
    ]);

    exit;
}

if ($categoryId <= 0) {

    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Category is required."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| DATABASE CHECK
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !$conn) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection failed."
    ]);

    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | CHECK CATEGORY
    |--------------------------------------------------------------------------
    */

    $categoryCheck = $conn->prepare("
        SELECT id, name
        FROM categories
        WHERE id = ?
        LIMIT 1
    ");

    if (!$categoryCheck) {
        throw new Exception(
            "Category query failed: " . $conn->error
        );
    }

    $categoryCheck->bind_param(
        "i",
        $categoryId
    );

    $categoryCheck->execute();

    $categoryResult = $categoryCheck->get_result();

    if ($categoryResult->num_rows === 0) {

        $categoryCheck->close();

        http_response_code(422);

        echo json_encode([
            "success" => false,
            "message" => "Selected category does not exist."
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

    $checkEmail = $conn->prepare("
        SELECT id
        FROM users
        WHERE email = ?
        LIMIT 1
    ");

    if (!$checkEmail) {
        throw new Exception(
            "Email check query failed: " . $conn->error
        );
    }

    $checkEmail->bind_param(
        "s",
        $email
    );

    $checkEmail->execute();

    $emailResult = $checkEmail->get_result();

    if ($emailResult->num_rows > 0) {

        $checkEmail->close();

        http_response_code(409);

        echo json_encode([
            "success" => false,
            "message" => "A user with this email already exists."
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

    /*
    |--------------------------------------------------------------------------
    | HASH PASSWORD
    |--------------------------------------------------------------------------
    */

    $hashedPassword = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    if (!$hashedPassword) {
        throw new Exception(
            "Password hashing failed."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT USER
    |--------------------------------------------------------------------------
    */

    $role = "candidate";

    $userQuery = $conn->prepare("
        INSERT INTO users
        (
            name,
            email,
            phone,
            password,
            role,
            status
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");

    if (!$userQuery) {
        throw new Exception(
            "User insert query failed: " . $conn->error
        );
    }

    $userQuery->bind_param(
        "ssssss",
        $name,
        $email,
        $phone,
        $hashedPassword,
        $role,
        $status
    );

    if (!$userQuery->execute()) {
        throw new Exception(
            "Unable to create user: " . $userQuery->error
        );
    }

    $userId = $conn->insert_id;

    $userQuery->close();

    if (!$userId) {
        throw new Exception(
            "User ID was not generated."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT CANDIDATE PROFILE
    |--------------------------------------------------------------------------
    */

    $candidateQuery = $conn->prepare("
        INSERT INTO candidates
        (
            user_id,
            category_id,
            skills,
            experience
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?
        )
    ");

    if (!$candidateQuery) {
        throw new Exception(
            "Candidate insert query failed: " . $conn->error
        );
    }

    $candidateQuery->bind_param(
        "iiss",
        $userId,
        $categoryId,
        $skills,
        $experience
    );

    if (!$candidateQuery->execute()) {
        throw new Exception(
            "Unable to create candidate profile: " .
            $candidateQuery->error
        );
    }

    $candidateProfileId = $conn->insert_id;

    $candidateQuery->close();

    if (!$candidateProfileId) {
        throw new Exception(
            "Candidate ID was not generated."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    http_response_code(201);

    echo json_encode([
        "success" => true,
        "message" => "Candidate added successfully.",

        "candidate" => [
            "candidate_id" => (int)$userId,
            "candidate_profile_id" => (int)$candidateProfileId,
            "user_id" => (int)$userId,

            "candidate_name" => $name,
            "candidate_email" => $email,
            "candidate_phone" => $phone,

            "category_id" => (int)$categoryId,
            "category_name" => $categoryName,

            "skills" => $skills,
            "experience" => $experience,

            "candidate_status" => $status,
            "role" => "candidate"
        ]
    ]);

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // Ignore rollback error
    }

    error_log(
        "Add Candidate Error: " . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to add candidate.",
        "error" => $e->getMessage()
    ]);
}

$conn->close();

?>