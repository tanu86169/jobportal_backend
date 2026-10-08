<?php

// ============================================================
// CORS
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";

// ============================================================
// ONLY POST
// ============================================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST method is allowed"
    ]);

    exit;
}

// ============================================================
// READ JSON
// ============================================================

$data = json_decode(file_get_contents("php://input"), true);

$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";

if ($email === "" || $password === "") {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Email and password are required"
    ]);

    exit;
}

// ============================================================
// GET USER
// ============================================================

$sql = "
    SELECT
        u.id,
        u.name,
        u.email,
        u.password,
        u.role,
        u.phone,
        c.id AS candidate_id,
        c.profile_image
    FROM users u
    LEFT JOIN candidates c
        ON c.user_id = u.id
    WHERE u.email = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query preparation failed"
    ]);

    exit;
}

$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Invalid email or password"
    ]);

    exit;
}

$user = $result->fetch_assoc();

// ============================================================
// VERIFY PASSWORD
// ============================================================

if (!password_verify($password, $user["password"])) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Invalid email or password"
    ]);

    exit;
}

// ============================================================
// NORMALIZE ROLE
// ============================================================

$user["role"] = strtolower(trim($user["role"]));

$allowedRoles = [
    "admin",
    "recruiter",
    "candidate"
];

if (!in_array($user["role"], $allowedRoles, true)) {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Invalid user role"
    ]);

    exit;
}

// ============================================================
// COMPANY STATUS FOR RECRUITER
// ============================================================

$companyStatus = null;
$companyId = null;
$companyName = null;
$companyMessage = null;

if ($user["role"] === "recruiter") {

    $companySql = "
        SELECT
            id,
            company_name,
            status
        FROM companies
        WHERE recruiter_id = ?
        ORDER BY id DESC
        LIMIT 1
    ";

    $companyStmt = $conn->prepare($companySql);

    if ($companyStmt) {

        $companyStmt->bind_param("i", $user["id"]);
        $companyStmt->execute();

        $companyResult = $companyStmt->get_result();

        if ($companyResult->num_rows > 0) {

            $company = $companyResult->fetch_assoc();

            $companyId = (int)$company["id"];
            $companyName = $company["company_name"];
            $companyStatus = strtolower(trim($company["status"]));

            // =================================================
            // STATUS MESSAGE
            // =================================================

            switch ($companyStatus) {

                case "pending":
                    $companyMessage =
                        "Your company is waiting for admin approval. You cannot post jobs until your company is approved.";
                    break;

                case "approved":
                    $companyMessage =
                        "Your company has been approved. You can now post jobs.";
                    break;

                case "rejected":
                    $companyMessage =
                        "Your company application has been rejected by admin. Please contact admin for more information.";
                    break;

                case "suspended":
                    $companyMessage =
                        "Your company is temporarily suspended by admin. You cannot post jobs until the suspension is removed.";
                    break;

                case "blocked":
                    $companyMessage =
                        "Your company has been blocked by admin. You cannot post jobs.";
                    break;

                default:
                    $companyMessage =
                        "Your company status is not approved. You cannot post jobs.";
                    break;
            }
        } else {

            // Recruiter has not created company yet
            $companyStatus = "pending";

            $companyMessage =
                "Please add your company and wait for admin approval before posting jobs.";
        }
    }
}

// ============================================================
// PROFILE IMAGE
// ============================================================

$profileImage = $user["profile_image"] ?? null;

if (!empty($profileImage)) {

    if (
        !str_starts_with($profileImage, "http://") &&
        !str_starts_with($profileImage, "https://")
    ) {

        $profileImage = "http://localhost/job_portal/job-portal-api/" .
                        ltrim($profileImage, "/");
    }
}

// ============================================================
// REMOVE PASSWORD
// ============================================================

unset($user["password"]);

// ============================================================
// ADD COMPANY DATA
// ============================================================

$user["company_id"] = $companyId;
$user["company_name"] = $companyName;
$user["company_status"] = $companyStatus;
$user["company_message"] = $companyMessage;
$user["can_post_job"] = ($user["role"] === "recruiter" && $companyStatus === "approved");

$user["profile_image"] = $profileImage;

// ============================================================
// SUCCESS
// ============================================================

http_response_code(200);

echo json_encode([
    "success" => true,
    "message" => "Login successful",
    "user" => $user
]);

$stmt->close();

?>