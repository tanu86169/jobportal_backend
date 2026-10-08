<?php

/*
|--------------------------------------------------------------------------
| REGISTER API
|--------------------------------------------------------------------------
| POST -> Candidate / Recruiter Registration
|
| Admin Settings:
| allow_registration = 1 -> Registration allowed
| allow_registration = 0 -> Registration disabled
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");


/*
|--------------------------------------------------------------------------
| OPTIONS / PREFLIGHT
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {

    http_response_code(200);

    echo json_encode([
        "success" => true,
        "message" => "Preflight request successful"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

require_once "../../config/database.php";


/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/

function sendResponse(
    bool $success,
    string $message,
    int $statusCode = 200,
    array $extra = []
) {

    http_response_code($statusCode);

    echo json_encode(
        array_merge(
            [
                "success" => $success,
                "message" => $message
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| ONLY POST REQUEST
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    sendResponse(
        false,
        "Only POST request allowed",
        405
    );

}


/*
|--------------------------------------------------------------------------
| CHECK REGISTRATION SETTING
|--------------------------------------------------------------------------
|
| Admin Settings -> allow_registration
|
| 1 = Registration allowed
| 0 = Registration disabled
|
|--------------------------------------------------------------------------
*/


$registrationAllowed = "1";


$settingStmt = $conn->prepare("
    SELECT setting_value
    FROM admin_settings
    WHERE setting_key = 'allow_registration'
    LIMIT 1
");


if ($settingStmt) {

    $settingStmt->execute();

    $settingResult = $settingStmt->get_result();

    if ($settingResult && $settingResult->num_rows > 0) {

        $settingRow = $settingResult->fetch_assoc();

        $registrationAllowed =
            (string) ($settingRow["setting_value"] ?? "1");

    }

    $settingStmt->close();

}


/*
|--------------------------------------------------------------------------
| REGISTRATION DISABLED
|--------------------------------------------------------------------------
*/

if (
    $registrationAllowed !== "1" &&
    strtolower($registrationAllowed) !== "true" &&
    strtolower($registrationAllowed) !== "on"
) {

    sendResponse(
        false,
        "Registration is currently disabled by administrator.",
        403,
        [
            "code" => "REGISTRATION_DISABLED"
        ]
    );

}


/*
|--------------------------------------------------------------------------
| GET JSON DATA
|--------------------------------------------------------------------------
*/

$input = file_get_contents("php://input");


if ($input === false || trim($input) === "") {

    sendResponse(
        false,
        "Request body is required",
        400
    );

}


$data = json_decode($input, true);


/*
|--------------------------------------------------------------------------
| JSON VALIDATION
|--------------------------------------------------------------------------
*/

if (
    !is_array($data) ||
    json_last_error() !== JSON_ERROR_NONE
) {

    sendResponse(
        false,
        "Invalid JSON data",
        400
    );

}


/*
|--------------------------------------------------------------------------
| GET FORM DATA
|--------------------------------------------------------------------------
*/

$name = trim(
    (string) ($data["name"] ?? "")
);

$email = trim(
    (string) ($data["email"] ?? "")
);

$phone = trim(
    (string) ($data["phone"] ?? "")
);

$password = (string) ($data["password"] ?? "");

$role = trim(
    (string) ($data["role"] ?? "candidate")
);


/*
|--------------------------------------------------------------------------
| NAME VALIDATION
|--------------------------------------------------------------------------
*/

if ($name === "") {

    sendResponse(
        false,
        "Full name is required",
        400
    );

}


/*
|--------------------------------------------------------------------------
| NAME LENGTH
|--------------------------------------------------------------------------
*/

if (mb_strlen($name) < 2) {

    sendResponse(
        false,
        "Full name must be at least 2 characters",
        400
    );

}

if (mb_strlen($name) > 100) {

    sendResponse(
        false,
        "Full name cannot exceed 100 characters",
        400
    );

}


/*
|--------------------------------------------------------------------------
| EMAIL VALIDATION
|--------------------------------------------------------------------------
*/

if ($email === "") {

    sendResponse(
        false,
        "Email is required",
        400
    );

}


if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

    sendResponse(
        false,
        "Invalid email address",
        400
    );

}


/*
|--------------------------------------------------------------------------
| NORMALIZE EMAIL
|--------------------------------------------------------------------------
*/

$email = strtolower($email);


/*
|--------------------------------------------------------------------------
| EMAIL LENGTH
|--------------------------------------------------------------------------
*/

if (strlen($email) > 150) {

    sendResponse(
        false,
        "Email address is too long",
        400
    );

}


/*
|--------------------------------------------------------------------------
| PHONE VALIDATION
|--------------------------------------------------------------------------
*/

if ($phone === "") {

    sendResponse(
        false,
        "Phone number is required",
        400
    );

}


if (!preg_match("/^[6-9][0-9]{9}$/", $phone)) {

    sendResponse(
        false,
        "Enter a valid 10-digit phone number",
        400
    );

}


/*
|--------------------------------------------------------------------------
| PASSWORD VALIDATION
|--------------------------------------------------------------------------
*/

if ($password === "") {

    sendResponse(
        false,
        "Password is required",
        400
    );

}


if (strlen($password) < 6) {

    sendResponse(
        false,
        "Password must be at least 6 characters",
        400
    );

}


/*
|--------------------------------------------------------------------------
| OPTIONAL STRONG PASSWORD CHECK
|--------------------------------------------------------------------------
|
| Currently minimum 6 characters is enough.
| You can make this stronger later.
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| ROLE VALIDATION
|--------------------------------------------------------------------------
*/

$allowedRoles = [
    "candidate",
    "recruiter"
];


if (!in_array($role, $allowedRoles, true)) {

    sendResponse(
        false,
        "Invalid role. Allowed roles are candidate and recruiter.",
        400
    );

}


/*
|--------------------------------------------------------------------------
| CHECK DUPLICATE EMAIL
|--------------------------------------------------------------------------
*/

$check = $conn->prepare("
    SELECT
        id,
        email
    FROM users
    WHERE email = ?
    LIMIT 1
");


if (!$check) {

    sendResponse(
        false,
        "Database query preparation failed",
        500
    );

}


$check->bind_param(
    "s",
    $email
);


if (!$check->execute()) {

    $check->close();

    sendResponse(
        false,
        "Unable to check email",
        500
    );

}


$result = $check->get_result();


if ($result && $result->num_rows > 0) {

    $check->close();

    sendResponse(
        false,
        "Email already registered",
        409,
        [
            "code" => "EMAIL_EXISTS"
        ]
    );

}


$check->close();


/*
|--------------------------------------------------------------------------
| HASH PASSWORD
|--------------------------------------------------------------------------
*/

$hashedPassword = password_hash(
    $password,
    PASSWORD_DEFAULT
);


if ($hashedPassword === false) {

    sendResponse(
        false,
        "Password encryption failed",
        500
    );

}


/*
|--------------------------------------------------------------------------
| START TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();


try {

    /*
    |--------------------------------------------------------------------------
    | INSERT USER
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        INSERT INTO users
        (
            name,
            email,
            password,
            role,
            phone
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");


    if (!$stmt) {

        throw new Exception(
            "Database insert preparation failed: " .
            $conn->error
        );

    }


    $stmt->bind_param(
        "sssss",
        $name,
        $email,
        $hashedPassword,
        $role,
        $phone
    );


    /*
    |--------------------------------------------------------------------------
    | EXECUTE INSERT
    |--------------------------------------------------------------------------
    */

    if (!$stmt->execute()) {

        /*
        |--------------------------------------------------------------
        | Duplicate email safety
        |--------------------------------------------------------------
        */

        if ($stmt->errno === 1062) {

            $stmt->close();

            $conn->rollback();

            sendResponse(
                false,
                "Email already registered",
                409,
                [
                    "code" => "EMAIL_EXISTS"
                ]
            );

        }


        throw new Exception(
            "Registration failed: " . $stmt->error
        );

    }


    /*
    |--------------------------------------------------------------------------
    | USER ID
    |--------------------------------------------------------------------------
    */

    $userId = $stmt->insert_id;


    $stmt->close();


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

    sendResponse(
        true,
        "Registration successful",
        201,
        [
            "userId" => $userId,
            "role" => $role
        ]
    );


} catch (Exception $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    $conn->rollback();


    sendResponse(
        false,
        $e->getMessage(),
        500
    );

}


/*
|--------------------------------------------------------------------------
| CLOSE DATABASE
|--------------------------------------------------------------------------
|
| sendResponse() exit karta hai, isliye normally yahan execution nahi aayega.
|--------------------------------------------------------------------------
*/

$conn->close();

?>