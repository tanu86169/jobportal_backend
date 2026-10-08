<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

require_once "../../config/database.php";

// ==========================================
// OPTIONS REQUEST
// ==========================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ==========================================
// AUTO-VERIFY COLUMNS (status, created_at)
// ==========================================

$columns = [];
$colRes = $conn->query("SHOW COLUMNS FROM users");
if ($colRes) {
    while ($col = $colRes->fetch_assoc()) {
        $columns[] = $col['Field'];
    }
}

if (!in_array('status', $columns)) {
    @$conn->query("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
}

if (!in_array('created_at', $columns)) {
    @$conn->query("ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

// Check related tables
$hasApps = false;
$hasJobs = false;
$t1 = $conn->query("SHOW TABLES LIKE 'applications'");
if ($t1 && $t1->num_rows > 0) $hasApps = true;

$t2 = $conn->query("SHOW TABLES LIKE 'jobs'");
if ($t2 && $t2->num_rows > 0) $hasJobs = true;

$hasCandidates = false;
$t3 = $conn->query("SHOW TABLES LIKE 'candidates'");
if ($t3 && $t3->num_rows > 0) $hasCandidates = true;

$hasCompanyProfiles = false;
$t4 = $conn->query("SHOW TABLES LIKE 'company_profiles'");
if ($t4 && $t4->num_rows > 0) $hasCompanyProfiles = true;

$hasCategories = false;
$t5 = $conn->query("SHOW TABLES LIKE 'categories'");
if ($t5 && $t5->num_rows > 0) $hasCategories = true;

$appsSub = $hasApps ? "(SELECT COUNT(*) FROM applications WHERE candidate_id = u.id)" : "0";
$jobsSub = $hasJobs ? "(SELECT COUNT(*) FROM jobs WHERE recruiter_id = u.id)" : "0";

$method = $_SERVER["REQUEST_METHOD"];

// ==========================================
// 1. GET USERS / USER DETAILS
// ==========================================

if ($method === "GET") {
    // Check if single user requested (VIEW USER)
    if (isset($_GET["id"]) && !empty($_GET["id"])) {
        $userId = (int) $_GET["id"];

        $detailColumns = [];
        $detailColumnResult = $conn->query("SHOW COLUMNS FROM users");
        if ($detailColumnResult) {
            while ($column = $detailColumnResult->fetch_assoc()) {
                $field = $column["Field"];
                if (preg_match("/(password|token|secret|otp|credential)/i", $field)) {
                    continue;
                }
                $detailColumns[] = "u.`" . str_replace("`", "``", $field) . "`";
            }
        }

        $detailSelect = implode(",\n", $detailColumns);

        $sql = "
            SELECT
                $detailSelect,
                $appsSub AS applications_count,
                $jobsSub AS jobs_count
            FROM users u
            WHERE u.id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database prepare error: " . $conn->error]);
            exit;
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "User not found"]);
            exit;
        }

        $user = $result->fetch_assoc();
        $stmt->close();

        $extraData = [];

        // Include the role-specific profile stored outside the users table.
        if ($user["role"] === "candidate" && $hasCandidates) {
            $categoryJoin = $hasCategories
                ? "LEFT JOIN categories cat ON cat.id = c.category_id"
                : "";
            $categorySelect = $hasCategories
                ? ", cat.name AS category_name"
                : "";
            $profileStmt = $conn->prepare("
                SELECT
                    c.id AS candidate_profile_id,
                    c.profile_image,
                    c.headline,
                    c.bio,
                    c.location,
                    c.date_of_birth,
                    c.category_id,
                    c.education,
                    c.projects,
                    c.skills,
                    c.experience,
                    c.experience_level,
                    c.resume,
                    c.linkedin,
                    c.github,
                    c.portfolio,
                    c.created_at AS profile_created_at,
                    c.updated_at AS profile_updated_at
                    $categorySelect
                FROM candidates c
                $categoryJoin
                WHERE c.user_id = ?
                LIMIT 1
            ");
            if ($profileStmt) {
                $profileStmt->bind_param("i", $userId);
                $profileStmt->execute();
                $profile = $profileStmt->get_result()->fetch_assoc();
                if ($profile) $extraData["candidate_profile"] = $profile;
                $profileStmt->close();
            }
        }

        if ($user["role"] === "recruiter" && $hasCompanyProfiles) {
            $profileStmt = $conn->prepare("
                SELECT
                    company_name,
                    email,
                    phone,
                    location,
                    website
                FROM company_profiles
                WHERE recruiter_id = ?
                LIMIT 1
            ");
            if ($profileStmt) {
                $profileStmt->bind_param("i", $userId);
                $profileStmt->execute();
                $profile = $profileStmt->get_result()->fetch_assoc();
                if ($profile) $extraData["company_profile"] = $profile;
                $profileStmt->close();
            }
        }

        // If candidate, fetch full applications list
        if ($user["role"] === "candidate" && $hasApps) {
            $cStmt = $conn->prepare("
                SELECT
                    a.id AS application_id,
                    a.job_id,
                    a.status,
                    a.applied_at,
                    a.resume,
                    a.cover_letter,
                    a.interview_date,
                    a.interview_note,
                    j.job_title,
                    j.company_name,
                    j.location,
                    j.salary,
                    j.job_type
                FROM applications a
                LEFT JOIN jobs j ON a.job_id = j.id
                WHERE a.candidate_id = ?
                ORDER BY a.id DESC
            ");
            if ($cStmt) {
                $cStmt->bind_param("i", $userId);
                $cStmt->execute();
                $cRes = $cStmt->get_result();
                $appsList = [];
                while ($cRow = $cRes->fetch_assoc()) {
                    $appsList[] = [
                        "application_id" => (int) $cRow["application_id"],
                        "job_id" => (int) $cRow["job_id"],
                        "job_title" => $cRow["job_title"] ?? "N/A",
                        "company_name" => $cRow["company_name"] ?? "N/A",
                        "location" => $cRow["location"] ?? "",
                        "salary" => $cRow["salary"] ?? "",
                        "job_type" => $cRow["job_type"] ?? "",
                        "status" => $cRow["status"] ?? "Applied",
                        "applied_at" => $cRow["applied_at"] ?? "",
                        "resume" => $cRow["resume"] ?? "",
                        "cover_letter" => $cRow["cover_letter"] ?? "",
                        "interview_date" => $cRow["interview_date"] ?? null,
                        "interview_note" => $cRow["interview_note"] ?? ""
                    ];
                }
                $cStmt->close();
                $extraData["applications"] = $appsList;
            }
        }

        // If recruiter, fetch full jobs list & applicants received
        if ($user["role"] === "recruiter" && $hasJobs) {
            $rStmt = $conn->prepare("
                SELECT
                    j.id AS job_id,
                    j.job_title,
                    j.company_name,
                    j.location,
                    j.job_type,
                    j.salary,
                    j.experience,
                    j.skills,
                    j.status,
                    j.created_at,
                    (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applicants_count
                FROM jobs j
                WHERE j.recruiter_id = ?
                ORDER BY j.id DESC
            ");
            if ($rStmt) {
                $rStmt->bind_param("i", $userId);
                $rStmt->execute();
                $rRes = $rStmt->get_result();
                $jobsList = [];
                $totalReceived = 0;
                $activeJobsCount = 0;
                $closedJobsCount = 0;

                while ($rRow = $rRes->fetch_assoc()) {
                    $appCount = (int) $rRow["applicants_count"];
                    $totalReceived += $appCount;

                    $jobStatus = strtolower(trim($rRow["status"] ?? "active"));
                    if ($jobStatus === "active" || $jobStatus === "open") {
                        $activeJobsCount++;
                    } else {
                        $closedJobsCount++;
                    }

                    $jobsList[] = [
                        "job_id" => (int) $rRow["job_id"],
                        "job_title" => $rRow["job_title"] ?? "",
                        "company_name" => $rRow["company_name"] ?? "",
                        "location" => $rRow["location"] ?? "",
                        "job_type" => $rRow["job_type"] ?? "",
                        "salary" => $rRow["salary"] ?? "",
                        "experience" => $rRow["experience"] ?? "",
                        "skills" => $rRow["skills"] ?? "",
                        "status" => $rRow["status"] ?? "active",
                        "created_at" => $rRow["created_at"] ?? "",
                        "applicants_count" => $appCount
                    ];
                }
                $rStmt->close();
                $extraData["jobs"] = $jobsList;
                $extraData["total_applications_received"] = $totalReceived;
                $extraData["active_jobs_count"] = $activeJobsCount;
                $extraData["closed_jobs_count"] = $closedJobsCount;
            }
        }

        // If admin, provide superadmin privileges
        if ($user["role"] === "admin") {
            $extraData["admin_info"] = [
                "title" => "Platform Super Administrator",
                "privilege" => "Unique Root Access",
                "permissions" => [
                    "User Directory (Manage Candidates & Recruiters)",
                    "Job Moderation (Approve, Edit & Monitor Jobs)",
                    "Candidate Pipeline & Interview Oversight",
                    "Reports & Platform Analytics",
                    "Platform Settings & Security Policies"
                ],
                "system_status" => "Active Superadmin"
            ];
        }

        $user["id"] = (int) $user["id"];
        $user["status"] = $user["status"] ?? "active";
        $user["applications_count"] = (int) $user["applications_count"];
        $user["jobs_count"] = (int) $user["jobs_count"];
        $user["details"] = $extraData;

        echo json_encode([
            "success" => true,
            "user" => $user
        ]);
        exit;
    }

    // Role filter
    $roleFilter = isset($_GET["role"]) ? trim($_GET["role"]) : "";
    $whereClause = "";
    if ($roleFilter !== "" && in_array($roleFilter, ["candidate", "recruiter", "admin"])) {
        $whereClause = "WHERE u.role = '" . $conn->real_escape_string($roleFilter) . "'";
    }

    $sql = "
        SELECT
            u.id,
            u.name,
            u.email,
            u.phone,
            u.role,
            IFNULL(u.status, 'active') AS status,
            u.created_at,
            $appsSub AS applications_count,
            $jobsSub AS jobs_count
        FROM users u
        $whereClause
        ORDER BY u.id DESC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to fetch users",
            "error" => $conn->error
        ]);
        exit;
    }

    $users = [];
    $candidateCount = 0;
    $recruiterCount = 0;
    $adminCount = 0;
    $activeCount = 0;
    $inactiveCount = 0;

    while ($row = $result->fetch_assoc()) {
        $role = $row["role"];
        $status = strtolower($row["status"] ?? "active");

        if ($role === "candidate") $candidateCount++;
        elseif ($role === "recruiter") $recruiterCount++;
        elseif ($role === "admin") $adminCount++;

        if ($status === "active") $activeCount++;
        else $inactiveCount++;

        $users[] = [
            "id" => (int) $row["id"],
            "name" => $row["name"],
            "email" => $row["email"],
            "phone" => $row["phone"] ?? "",
            "role" => $row["role"],
            "status" => $row["status"] ?? "active",
            "created_at" => $row["created_at"] ?? null,
            "applications_count" => (int) $row["applications_count"],
            "jobs_count" => (int) $row["jobs_count"],
        ];
    }

    // Overall summary (always global counts)
    $summary = [
        "total" => count($users),
        "candidates" => $candidateCount,
        "recruiters" => $recruiterCount,
        "admins" => $adminCount,
        "active" => $activeCount,
        "inactive" => $inactiveCount
    ];

    echo json_encode([
        "success" => true,
        "total" => count($users),
        "summary" => $summary,
        "users" => $users
    ]);
    exit;
}

// ==========================================
// 2. CREATE USER (POST) - ONLY CANDIDATE OR RECRUITER
// ==========================================

if ($method === "POST") {
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);

    if (!is_array($data)) {
        $data = $_POST;
    }

    $name = trim($data["name"] ?? "");
    $email = trim($data["email"] ?? "");
    $phone = trim($data["phone"] ?? "");
    $role = trim($data["role"] ?? "candidate");
    $status = trim($data["status"] ?? "active");
    $password = $data["password"] ?? "";

    if ($name === "") {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Full name is required"]);
        exit;
    }

    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Valid email address is required"]);
        exit;
    }

    // ADMIN IS UNIQUE AND FIXED - ONLY CANDIDATE OR RECRUITER ALLOWED
    $allowedRoles = ["candidate", "recruiter"];
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Admin is unique and fixed. You can only create a Candidate or Recruiter."
        ]);
        exit;
    }

    if ($password === "" || strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Password must be at least 6 characters"]);
        exit;
    }

    // Check duplicate email
    $check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    if (!$check) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database check failed: " . $conn->error]);
        exit;
    }

    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $check->close();
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "This email is already registered"]);
        exit;
    }
    $check->close();

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "INSERT INTO users (name, email, phone, role, password, status) VALUES (?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Insert preparation failed: " . $conn->error]);
        exit;
    }

    $stmt->bind_param("ssssss", $name, $email, $phone, $role, $hashedPassword, $status);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Failed to create user: " . $stmt->error]);
        $stmt->close();
        exit;
    }

    $newId = $stmt->insert_id;
    $stmt->close();

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "User created successfully",
        "userId" => $newId,
        "user" => [
            "id" => $newId,
            "name" => $name,
            "email" => $email,
            "phone" => $phone,
            "role" => $role,
            "status" => $status,
            "created_at" => date("Y-m-d H:i:s"),
            "applications_count" => 0,
            "jobs_count" => 0
        ]
    ]);
    exit;
}

// ==========================================
// 3. UPDATE USER (PUT)
// ==========================================

if ($method === "PUT") {
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid JSON payload"]);
        exit;
    }

    $id = isset($data["id"]) ? (int) $data["id"] : 0;
    $name = trim($data["name"] ?? "");
    $email = trim($data["email"] ?? "");
    $phone = trim($data["phone"] ?? "");
    $role = trim($data["role"] ?? "candidate");
    $status = trim($data["status"] ?? "active");
    $password = trim($data["password"] ?? "");

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Valid user ID is required"]);
        exit;
    }

    if ($name === "") {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Name cannot be empty"]);
        exit;
    }

    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Valid email address is required"]);
        exit;
    }

    $allowedRoles = ["candidate", "recruiter", "admin"];
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid role selected"]);
        exit;
    }

    // Admin is fixed and unique: regular user cannot be made admin
    $roleCheck = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    if ($roleCheck) {
        $roleCheck->bind_param("i", $id);
        $roleCheck->execute();
        $currRoleRes = $roleCheck->get_result()->fetch_assoc();
        $currRole = $currRoleRes["role"] ?? "";
        $roleCheck->close();

        if ($role === "admin" && $currRole !== "admin") {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Admin is unique and fixed. Regular accounts cannot be changed to Admin."
            ]);
            exit;
        }

        // If existing user is admin, enforce keeping admin role
        if ($currRole === "admin") {
            $role = "admin";
        }
    }

    // Check duplicate email for another user
    $dupCheck = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    if (!$dupCheck) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Check query failed: " . $conn->error]);
        exit;
    }

    $dupCheck->bind_param("si", $email, $id);
    $dupCheck->execute();
    if ($dupCheck->get_result()->num_rows > 0) {
        $dupCheck->close();
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Email is already taken by another account"]);
        exit;
    }
    $dupCheck->close();

    // Check if updating password too
    if ($password !== "") {
        if (strlen($password) < 6) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Password must be at least 6 characters"]);
            exit;
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare(
            "UPDATE users SET name = ?, email = ?, phone = ?, role = ?, status = ?, password = ? WHERE id = ?"
        );
        if (!$updateStmt) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Update preparation failed: " . $conn->error]);
            exit;
        }
        $updateStmt->bind_param("ssssssi", $name, $email, $phone, $role, $status, $hashedPassword, $id);
    } else {
        $updateStmt = $conn->prepare(
            "UPDATE users SET name = ?, email = ?, phone = ?, role = ?, status = ? WHERE id = ?"
        );
        if (!$updateStmt) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Update preparation failed: " . $conn->error]);
            exit;
        }
        $updateStmt->bind_param("sssssi", $name, $email, $phone, $role, $status, $id);
    }

    if (!$updateStmt->execute()) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Failed to update user: " . $updateStmt->error]);
        $updateStmt->close();
        exit;
    }

    $updateStmt->close();

    echo json_encode([
        "success" => true,
        "message" => "User updated successfully",
        "user" => [
            "id" => $id,
            "name" => $name,
            "email" => $email,
            "phone" => $phone,
            "role" => $role,
            "status" => $status
        ]
    ]);
    exit;
}

// ==========================================
// 4. DELETE USER (DELETE)
// ==========================================

if ($method === "DELETE") {
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);

    $id = 0;
    if (is_array($data) && isset($data["id"])) {
        $id = (int) $data["id"];
    } elseif (isset($_GET["id"])) {
        $id = (int) $_GET["id"];
    }

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Valid user ID is required to delete"]);
        exit;
    }

    // Verify user exists
    $checkUser = $conn->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
    if (!$checkUser) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Verification prepare failed"]);
        exit;
    }

    $checkUser->bind_param("i", $id);
    $checkUser->execute();
    $res = $checkUser->get_result();

    if ($res->num_rows === 0) {
        $checkUser->close();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "User not found"]);
        exit;
    }

    $userToDelete = $res->fetch_assoc();
    $checkUser->close();

    // Cascading cleanup of user's dependencies if tables exist
    if ($hasApps) {
        // Delete applications by this candidate
        $delApps = $conn->prepare("DELETE FROM applications WHERE candidate_id = ?");
        if ($delApps) {
            $delApps->bind_param("i", $id);
            $delApps->execute();
            $delApps->close();
        }
    }

    if ($hasJobs && $userToDelete["role"] === "recruiter") {
        // Find jobs of this recruiter
        $getJobs = $conn->prepare("SELECT id FROM jobs WHERE recruiter_id = ?");
        if ($getJobs) {
            $getJobs->bind_param("i", $id);
            $getJobs->execute();
            $jobRes = $getJobs->get_result();
            $jobIds = [];
            while ($jRow = $jobRes->fetch_assoc()) {
                $jobIds[] = (int) $jRow["id"];
            }
            $getJobs->close();

            if (!empty($jobIds) && $hasApps) {
                // Delete applications for those jobs
                $jobIdList = implode(",", $jobIds);
                @$conn->query("DELETE FROM applications WHERE job_id IN ($jobIdList)");
            }

            // Delete recruiter's jobs
            $delJobs = $conn->prepare("DELETE FROM jobs WHERE recruiter_id = ?");
            if ($delJobs) {
                $delJobs->bind_param("i", $id);
                $delJobs->execute();
                $delJobs->close();
            }
        }
    }

    // Delete user
    $delStmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    if (!$delStmt) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Delete prepare failed: " . $conn->error]);
        exit;
    }

    $delStmt->bind_param("i", $id);
    if (!$delStmt->execute()) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Failed to delete user: " . $delStmt->error]);
        $delStmt->close();
        exit;
    }

    $delStmt->close();

    echo json_encode([
        "success" => true,
        "message" => "User and related records deleted successfully",
        "deletedId" => $id
    ]);
    exit;
}

// Any other method
http_response_code(405);
echo json_encode([
    "success" => false,
    "message" => "Method not allowed"
]);
$conn->close();
?>