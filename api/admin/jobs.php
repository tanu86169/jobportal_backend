
<?php
// File: job-portal-api/api/admin/jobs.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";

/* =========================================================
   JSON RESPONSE
========================================================= */

function responseJson($success, $message = "", $data = null, $statusCode = 200)
{
    http_response_code($statusCode);

    $response = [
        "success" => $success,
        "message" => $message
    ];

    if ($data !== null) {
        $response["data"] = $data;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean($value)
{
    return $value === null ? "" : trim((string)$value);
}

function cleanNullable($value)
{
    $value = clean($value);
    return $value === "" ? null : $value;
}

function getJsonInput()
{
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function tableExists($conn, $table)
{
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");

    return $result && $result->num_rows > 0;
}

function recruiterExists($conn, $recruiterId)
{
    if (!$recruiterId) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT id FROM users
         WHERE id = ? AND role = 'recruiter'
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $recruiterId);
    $stmt->execute();

    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;

    $stmt->close();

    return $exists;
}

/* =========================================================
   LOGO UPLOAD
========================================================= */

function uploadCompanyLogo($file)
{
    if (
        !isset($file["error"]) ||
        $file["error"] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ($file["error"] !== UPLOAD_ERR_OK) {
        throw new Exception("Company logo upload failed.");
    }

    if ($file["size"] > 5 * 1024 * 1024) {
        throw new Exception("Company logo must be less than 5 MB.");
    }

    $extension = strtolower(
        pathinfo($file["name"], PATHINFO_EXTENSION)
    );

    $allowedExtensions = ["jpg", "jpeg", "png", "webp"];

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new Exception(
            "Only JPG, JPEG, PNG and WEBP images are allowed."
        );
    }

    $mimeType = mime_content_type($file["tmp_name"]);
    $allowedMimeTypes = ["image/jpeg", "image/png", "image/webp"];

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        throw new Exception("Invalid company logo file.");
    }

    $uploadDirectory = dirname(__DIR__, 2) . "/uploads/";

    if (!is_dir($uploadDirectory)) {
        if (!mkdir($uploadDirectory, 0777, true) &&
            !is_dir($uploadDirectory)) {
            throw new Exception("Unable to create uploads directory.");
        }
    }

    $fileName = "company_" . uniqid("", true) . "." . $extension;
    $destination = $uploadDirectory . $fileName;

    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        throw new Exception("Unable to save company logo.");
    }

    return "uploads/" . $fileName;
}

function deleteOldLogo($logo)
{
    if (!$logo) {
        return;
    }

    $cleanLogo = ltrim($logo, "/");

    if (strpos($cleanLogo, "uploads/") !== 0) {
        return;
    }

    $fullPath = dirname(__DIR__, 2) . "/" . $cleanLogo;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/* =========================================================
   FORM DATA
   job_title = Job title/name
   position  = Designation/role
   vacancies = Number of openings
========================================================= */

function getJobFormData($source)
{
    $recruiterId = (
        isset($source["recruiter_id"]) &&
        $source["recruiter_id"] !== ""
    ) ? (int)$source["recruiter_id"] : null;

    $vacancies = (
        isset($source["vacancies"]) &&
        $source["vacancies"] !== ""
    ) ? filter_var($source["vacancies"], FILTER_VALIDATE_INT) : 1;

    if ($vacancies === false || $vacancies === null) {
        $vacancies = 0;
    }

    $jobTitle = clean($source["job_title"] ?? "");
    $position = clean($source["position"] ?? "");

    // Compatibility with older forms.
    if ($position === "") {
        $position = $jobTitle;
    }

    return [
        "recruiter_id" => $recruiterId,
        "job_title" => $jobTitle,
        "position" => $position,
        "company_name" => clean($source["company_name"] ?? ""),
        "location" => clean($source["location"] ?? ""),
        "vacancies" => (int)$vacancies,
        "job_type" => clean($source["job_type"] ?? ""),
        "workplace_type" => clean($source["workplace_type"] ?? ""),
        "category" => clean($source["category"] ?? ""),
        "education" => clean($source["education"] ?? ""),
        "experience" => clean($source["experience"] ?? ""),
        "salary" => clean($source["salary"] ?? ""),
        "description" => clean($source["description"] ?? ""),
        "responsibilities" => clean($source["responsibilities"] ?? ""),
        "requirements" => clean($source["requirements"] ?? ""),
        "skills" => clean($source["skills"] ?? ""),
        "deadline" => cleanNullable($source["deadline"] ?? ""),
        "status" => clean($source["status"] ?? "active")
    ];
}

function validateJob($conn, $job)
{
    if ($job["job_title"] === "") {
        responseJson(false, "Job title is required.", null, 400);
    }

    if ($job["position"] === "") {
        responseJson(false, "Position is required.", null, 400);
    }

    if ($job["company_name"] === "") {
        responseJson(false, "Company name is required.", null, 400);
    }

    if ($job["location"] === "") {
        responseJson(false, "Location is required.", null, 400);
    }

    if ($job["vacancies"] < 1) {
        responseJson(false, "Vacancies must be at least 1.", null, 400);
    }

    if (
        $job["recruiter_id"] !== null &&
        !recruiterExists($conn, $job["recruiter_id"])
    ) {
        responseJson(false, "Selected recruiter does not exist.", null, 400);
    }
}

/* =========================================================
   BIND PARAMETERS
========================================================= */

function bindCreateJob($stmt, $job, $companyLogo)
{
    // 19 parameters: 6 initial types + 13 strings.
    $types = "issssi" . str_repeat("s", 13);

    $stmt->bind_param(
        $types,
        $job["recruiter_id"],
        $job["job_title"],
        $job["position"],
        $job["company_name"],
        $job["location"],
        $job["vacancies"],
        $job["job_type"],
        $job["workplace_type"],
        $job["category"],
        $job["education"],
        $job["experience"],
        $job["salary"],
        $job["description"],
        $job["responsibilities"],
        $job["requirements"],
        $job["skills"],
        $job["deadline"],
        $job["status"],
        $companyLogo
    );
}

function bindUpdateJob($stmt, $job, $companyLogo, $id)
{
    // 19 SET values + job ID.
    $types = "issssi" . str_repeat("s", 13) . "i";

    $stmt->bind_param(
        $types,
        $job["recruiter_id"],
        $job["job_title"],
        $job["position"],
        $job["company_name"],
        $job["location"],
        $job["vacancies"],
        $job["job_type"],
        $job["workplace_type"],
        $job["category"],
        $job["education"],
        $job["experience"],
        $job["salary"],
        $job["description"],
        $job["responsibilities"],
        $job["requirements"],
        $job["skills"],
        $job["deadline"],
        $job["status"],
        $companyLogo,
        $id
    );
}

/* =========================================================
   GET: RECRUITERS / ONE JOB / ALL JOBS
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    if (($_GET["action"] ?? "") === "recruiters") {
        $result = $conn->query(
            "SELECT id, name, email, phone
             FROM users
             WHERE role = 'recruiter'
             ORDER BY name ASC"
        );

        if (!$result) {
            responseJson(
                false,
                "Unable to fetch recruiters: " . $conn->error,
                null,
                500
            );
        }

        $recruiters = [];

        while ($row = $result->fetch_assoc()) {
            $recruiters[] = $row;
        }

        responseJson(true, "Recruiters fetched successfully.", $recruiters);
    }

    $hasApplications = tableExists($conn, "applications");

    // Fetch one job and its applications.
    if (isset($_GET["id"]) && is_numeric($_GET["id"])) {
        $jobId = (int)$_GET["id"];

        $stmt = $conn->prepare("
            SELECT
                j.id, j.recruiter_id, j.job_title, j.position,
                j.company_name, j.location, j.vacancies,
                j.job_type, j.workplace_type, j.category,
                j.education, j.experience, j.salary,
                j.description, j.responsibilities, j.requirements,
                j.skills, j.deadline, j.status, j.created_at,
                j.company_logo,
                u.name AS recruiter_name,
                u.email AS recruiter_email,
                u.phone AS recruiter_phone
            FROM jobs j
            LEFT JOIN users u ON u.id = j.recruiter_id
            WHERE j.id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            responseJson(false, "Job query failed: " . $conn->error, null, 500);
        }

        $stmt->bind_param("i", $jobId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            responseJson(false, "Job not found.", null, 404);
        }

        $job = $result->fetch_assoc();
        $stmt->close();

        if (empty(trim($job["position"] ?? ""))) {
            $job["position"] = $job["job_title"] ?? "";
        }

        $job["applications"] = [];

        if ($hasApplications) {
            $applicationStmt = $conn->prepare("
                SELECT
                    a.id AS application_id,
                    a.candidate_id,
                    a.status,
                    a.applied_at,
                    a.resume,
                    a.cover_letter,
                    a.interview_date,
                    a.interview_note,
                    u.name AS candidate_name,
                    u.email AS candidate_email,
                    u.phone AS candidate_phone
                FROM applications a
                LEFT JOIN users u ON u.id = a.candidate_id
                WHERE a.job_id = ?
                ORDER BY a.id DESC
            ");

            if (!$applicationStmt) {
                responseJson(
                    false,
                    "Applications query failed: " . $conn->error,
                    null,
                    500
                );
            }

            $applicationStmt->bind_param("i", $jobId);
            $applicationStmt->execute();
            $applicationResult = $applicationStmt->get_result();

            while ($row = $applicationResult->fetch_assoc()) {
                $job["applications"][] = $row;
            }

            $applicationStmt->close();
        }

        responseJson(true, "Job fetched successfully.", $job);
    }

    $appliedSub = $hasApplications
        ? "(SELECT COUNT(*) FROM applications WHERE job_id = j.id)"
        : "0";

    $interviewSub = $hasApplications
        ? "(SELECT COUNT(*) FROM applications
            WHERE job_id = j.id
            AND LOWER(COALESCE(status, '')) LIKE '%interview%')"
        : "0";

    $rejectedSub = $hasApplications
        ? "(SELECT COUNT(*) FROM applications
            WHERE job_id = j.id
            AND LOWER(COALESCE(status, '')) LIKE '%reject%')"
        : "0";

    $hiredSub = $hasApplications
        ? "(SELECT COUNT(*) FROM applications
            WHERE job_id = j.id
            AND (
                LOWER(COALESCE(status, '')) LIKE '%hire%'
                OR LOWER(COALESCE(status, '')) LIKE '%select%'
            ))"
        : "0";

    $sql = "
        SELECT
            j.id, j.recruiter_id, j.job_title, j.position,
            j.company_name, j.location, j.vacancies,
            j.job_type, j.workplace_type, j.category,
            j.education, j.experience, j.salary,
            j.description, j.responsibilities, j.requirements,
            j.skills, j.deadline, j.status, j.created_at,
            j.company_logo,
            u.name AS recruiter_name,
            u.email AS recruiter_email,
            u.phone AS recruiter_phone,
            $appliedSub AS applied_count,
            $interviewSub AS interview_count,
            $rejectedSub AS rejected_count,
            $hiredSub AS hired_count
        FROM jobs j
        LEFT JOIN users u ON u.id = j.recruiter_id
        ORDER BY j.id DESC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        responseJson(false, "Unable to fetch jobs: " . $conn->error, null, 500);
    }

    $jobs = [];

    while ($row = $result->fetch_assoc()) {
        if (empty(trim($row["position"] ?? ""))) {
            $row["position"] = $row["job_title"] ?? "";
        }

        $row["id"] = (int)$row["id"];
        $row["vacancies"] = (int)$row["vacancies"];
        $row["applied_count"] = (int)$row["applied_count"];
        $row["interview_count"] = (int)$row["interview_count"];
        $row["rejected_count"] = (int)$row["rejected_count"];
        $row["hired_count"] = (int)$row["hired_count"];

        $jobs[] = $row;
    }

    responseJson(true, "Jobs fetched successfully.", $jobs);
}

/* =========================================================
   POST: CREATE OR UPDATE USING FORMDATA
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "create";
    $job = getJobFormData($_POST);

    validateJob($conn, $job);

    // UPDATE
    if ($action === "update") {
        $id = isset($_POST["id"]) ? (int)$_POST["id"] : 0;

        if ($id <= 0) {
            responseJson(false, "Valid job ID is required.", null, 400);
        }

        $existingStmt = $conn->prepare(
            "SELECT company_logo FROM jobs WHERE id = ? LIMIT 1"
        );

        if (!$existingStmt) {
            responseJson(false, "Job lookup failed: " . $conn->error, null, 500);
        }

        $existingStmt->bind_param("i", $id);
        $existingStmt->execute();
        $existingResult = $existingStmt->get_result();

        if ($existingResult->num_rows === 0) {
            $existingStmt->close();
            responseJson(false, "Job not found.", null, 404);
        }

        $existingJob = $existingResult->fetch_assoc();
        $existingStmt->close();

        $companyLogo = $existingJob["company_logo"];
        $newLogoUploaded = false;

        try {
            if (
                isset($_FILES["company_logo"]) &&
                $_FILES["company_logo"]["error"] !== UPLOAD_ERR_NO_FILE
            ) {
                $newLogo = uploadCompanyLogo($_FILES["company_logo"]);

                if ($newLogo !== null) {
                    $companyLogo = $newLogo;
                    $newLogoUploaded = true;
                }
            }
        } catch (Exception $e) {
            responseJson(false, $e->getMessage(), null, 400);
        }

        $stmt = $conn->prepare("
            UPDATE jobs SET
                recruiter_id = ?,
                job_title = ?,
                position = ?,
                company_name = ?,
                location = ?,
                vacancies = ?,
                job_type = ?,
                workplace_type = ?,
                category = ?,
                education = ?,
                experience = ?,
                salary = ?,
                description = ?,
                responsibilities = ?,
                requirements = ?,
                skills = ?,
                deadline = ?,
                status = ?,
                company_logo = ?
            WHERE id = ?
        ");

        if (!$stmt) {
            if ($newLogoUploaded) {
                deleteOldLogo($companyLogo);
            }

            responseJson(false, "Update prepare failed: " . $conn->error, null, 500);
        }

        bindUpdateJob($stmt, $job, $companyLogo, $id);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            if ($newLogoUploaded) {
                deleteOldLogo($companyLogo);
            }

            responseJson(false, "Unable to update job: " . $error, null, 500);
        }

        $stmt->close();

        if (
            $newLogoUploaded &&
            !empty($existingJob["company_logo"]) &&
            $existingJob["company_logo"] !== $companyLogo
        ) {
            deleteOldLogo($existingJob["company_logo"]);
        }

        responseJson(true, "Job updated successfully.", [
            "id" => $id,
            "position" => $job["position"],
            "company_logo" => $companyLogo
        ]);
    }

    // CREATE
    $companyLogo = null;

    try {
        if (
            isset($_FILES["company_logo"]) &&
            $_FILES["company_logo"]["error"] !== UPLOAD_ERR_NO_FILE
        ) {
            $companyLogo = uploadCompanyLogo($_FILES["company_logo"]);
        }
    } catch (Exception $e) {
        responseJson(false, $e->getMessage(), null, 400);
    }

    $stmt = $conn->prepare("
        INSERT INTO jobs (
            recruiter_id, job_title, position, company_name, location,
            vacancies, job_type, workplace_type, category, education,
            experience, salary, description, responsibilities, requirements,
            skills, deadline, status, company_logo
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        if ($companyLogo) {
            deleteOldLogo($companyLogo);
        }

        responseJson(false, "Insert prepare failed: " . $conn->error, null, 500);
    }

    bindCreateJob($stmt, $job, $companyLogo);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        if ($companyLogo) {
            deleteOldLogo($companyLogo);
        }

        responseJson(false, "Unable to create job: " . $error, null, 500);
    }

    $newJobId = $stmt->insert_id;
    $stmt->close();

    responseJson(true, "Job created successfully.", [
        "id" => $newJobId,
        "position" => $job["position"],
        "company_logo" => $companyLogo
    ], 201);
}

/* =========================================================
   PUT: JSON UPDATE
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "PUT") {
    $data = getJsonInput();
    $id = isset($data["id"]) ? (int)$data["id"] : 0;

    if ($id <= 0) {
        responseJson(false, "Valid job ID is required.", null, 400);
    }

    $job = getJobFormData($data);
    validateJob($conn, $job);

    $check = $conn->prepare(
        "SELECT company_logo FROM jobs WHERE id = ? LIMIT 1"
    );

    if (!$check) {
        responseJson(false, "Job lookup failed: " . $conn->error, null, 500);
    }

    $check->bind_param("i", $id);
    $check->execute();
    $existingResult = $check->get_result();

    if ($existingResult->num_rows === 0) {
        $check->close();
        responseJson(false, "Job not found.", null, 404);
    }

    $existing = $existingResult->fetch_assoc();
    $check->close();

    $companyLogo = array_key_exists("company_logo", $data)
        ? cleanNullable($data["company_logo"])
        : $existing["company_logo"];

    $stmt = $conn->prepare("
        UPDATE jobs SET
            recruiter_id = ?,
            job_title = ?,
            position = ?,
            company_name = ?,
            location = ?,
            vacancies = ?,
            job_type = ?,
            workplace_type = ?,
            category = ?,
            education = ?,
            experience = ?,
            salary = ?,
            description = ?,
            responsibilities = ?,
            requirements = ?,
            skills = ?,
            deadline = ?,
            status = ?,
            company_logo = ?
        WHERE id = ?
    ");

    if (!$stmt) {
        responseJson(false, "Update prepare failed: " . $conn->error, null, 500);
    }

    bindUpdateJob($stmt, $job, $companyLogo, $id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        responseJson(false, "Unable to update job: " . $error, null, 500);
    }

    $stmt->close();

    responseJson(true, "Job updated successfully.", [
        "id" => $id,
        "position" => $job["position"]
    ]);
}

/* =========================================================
   DELETE
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {
    $data = getJsonInput();
    $id = isset($data["id"]) ? (int)$data["id"] : 0;

    if ($id <= 0 && isset($_GET["id"])) {
        $id = (int)$_GET["id"];
    }

    if ($id <= 0) {
        responseJson(false, "Valid job ID is required.", null, 400);
    }

    $logoStmt = $conn->prepare(
        "SELECT company_logo FROM jobs WHERE id = ? LIMIT 1"
    );

    if (!$logoStmt) {
        responseJson(false, "Job lookup failed: " . $conn->error, null, 500);
    }

    $logoStmt->bind_param("i", $id);
    $logoStmt->execute();
    $logoResult = $logoStmt->get_result();

    if ($logoResult->num_rows === 0) {
        $logoStmt->close();
        responseJson(false, "Job not found.", null, 404);
    }

    $jobBeforeDelete = $logoResult->fetch_assoc();
    $logoStmt->close();

    try {
        $conn->begin_transaction();

        if (tableExists($conn, "applications")) {
            $deleteApplications = $conn->prepare(
                "DELETE FROM applications WHERE job_id = ?"
            );

            if (!$deleteApplications) {
                throw new Exception(
                    "Applications delete prepare failed: " . $conn->error
                );
            }

            $deleteApplications->bind_param("i", $id);

            if (!$deleteApplications->execute()) {
                throw new Exception($deleteApplications->error);
            }

            $deleteApplications->close();
        }

        $deleteJob = $conn->prepare("DELETE FROM jobs WHERE id = ?");

        if (!$deleteJob) {
            throw new Exception("Job delete prepare failed: " . $conn->error);
        }

        $deleteJob->bind_param("i", $id);

        if (!$deleteJob->execute()) {
            throw new Exception($deleteJob->error);
        }

        if ($deleteJob->affected_rows === 0) {
            throw new Exception("Job not found.");
        }

        $deleteJob->close();
        $conn->commit();

        if (!empty($jobBeforeDelete["company_logo"])) {
            deleteOldLogo($jobBeforeDelete["company_logo"]);
        }

        responseJson(true, "Job deleted successfully.");

    } catch (Exception $e) {
        $conn->rollback();
        responseJson(false, $e->getMessage(), null, 500);
    }
}

responseJson(false, "Unsupported request method.", null, 405);