
<?php
// job-portal-api/api/recruiter/update-job.php

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
}

header("Vary: Origin");
header("Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";

function respond($success, $message, $extra = [], $statusCode = 200)
{
    http_response_code($statusCode);

    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra));

    exit;
}

function cleanValue($value)
{
    return trim((string)($value ?? ""));
}

function getJobById($conn, $jobId, $recruiterId)
{
    $sql = "SELECT
                id,
                recruiter_id,
                job_title,
                company_name,
                location,
                vacancies,
                job_type,
                workplace_type,
                category,
                education,
                experience,
                salary,
                description,
                responsibilities,
                requirements,
                skills,
                deadline,
                status,
                positions,
                company_logo,
                created_at
            FROM jobs
            WHERE id = ? AND recruiter_id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        respond(false, "Unable to prepare job query.", [], 500);
    }

    $stmt->bind_param("ii", $jobId, $recruiterId);
    $stmt->execute();

    $result = $stmt->get_result();
    $job = $result->fetch_assoc();

    $stmt->close();

    return $job;
}

try {
    $method = $_SERVER["REQUEST_METHOD"];

    // =========================
    // GET: LOAD JOB FOR EDIT
    // =========================
    if ($method === "GET") {
        $jobId = (int)($_GET["id"] ?? 0);
        $recruiterId = (int)($_GET["recruiter_id"] ?? 0);

        if ($jobId <= 0 || $recruiterId <= 0) {
            respond(false, "Job ID or recruiter ID is missing.", [], 400);
        }

        $job = getJobById($conn, $jobId, $recruiterId);

        if (!$job) {
            respond(
                false,
                "Job not found, or you do not have permission to edit it.",
                [],
                404
            );
        }

        respond(true, "Job loaded successfully.", ["job" => $job]);
    }

    // =========================
    // PUT / POST: UPDATE JOB
    // =========================
    if ($method === "PUT" || $method === "POST") {
        $rawInput = file_get_contents("php://input");
        $input = json_decode($rawInput, true);

        if (!is_array($input)) {
            respond(false, "Invalid JSON request.", [], 400);
        }

        $jobId = (int)($input["id"] ?? $input["job_id"] ?? 0);
        $recruiterId = (int)($input["recruiter_id"] ?? 0);

        if ($jobId <= 0 || $recruiterId <= 0) {
            respond(false, "Job ID or recruiter ID is missing.", [], 400);
        }

        // Confirm that this job belongs to this recruiter.
        $existingJob = getJobById($conn, $jobId, $recruiterId);

        if (!$existingJob) {
            respond(
                false,
                "Job not found, or you do not have permission to edit it.",
                [],
                404
            );
        }

        $jobTitle = cleanValue($input["job_title"] ?? "");
        $companyName = cleanValue($input["company_name"] ?? "");
        $location = cleanValue($input["location"] ?? "");
        $jobType = cleanValue($input["job_type"] ?? "");
        $workplaceType = cleanValue($input["workplace_type"] ?? "");
        $category = cleanValue($input["category"] ?? "");
        $education = cleanValue($input["education"] ?? "");
        $experience = cleanValue($input["experience"] ?? "");
        $salary = cleanValue($input["salary"] ?? "");
        $description = cleanValue($input["description"] ?? "");
        $responsibilities = cleanValue($input["responsibilities"] ?? "");
        $requirements = cleanValue($input["requirements"] ?? "");
        $skills = cleanValue($input["skills"] ?? "");
        $deadline = cleanValue($input["deadline"] ?? "");
        $status = strtolower(cleanValue($input["status"] ?? $existingJob["status"]));

        $vacancies = (int)($input["vacancies"] ?? $input["positions"] ?? 1);

        if (
            $jobTitle === "" ||
            $companyName === "" ||
            $location === "" ||
            $jobType === "" ||
            $category === "" ||
            $description === ""
        ) {
            respond(
                false,
                "Please fill all required fields: job title, company, location, job type, category and description.",
                [],
                422
            );
        }

        if ($vacancies < 1) {
            respond(false, "Vacancies must be at least 1.", [], 422);
        }

        $allowedJobTypes = [
            "Full Time",
            "Part Time",
            "Internship",
            "Contract",
            "Temporary",
            "Freelance"
        ];

        if (!in_array($jobType, $allowedJobTypes, true)) {
            respond(false, "Invalid job type selected.", [], 422);
        }

        $allowedWorkplaces = [
            "On-site",
            "Remote",
            "Hybrid"
        ];

        if (
            $workplaceType !== "" &&
            !in_array($workplaceType, $allowedWorkplaces, true)
        ) {
            respond(false, "Invalid workplace type selected.", [], 422);
        }

        if (!in_array($status, ["active", "closed", "pending"], true)) {
            $status = "active";
        }

        // Empty deadline is stored as NULL.
        $deadlineValue = $deadline !== "" ? $deadline : null;

        $sql = "UPDATE jobs SET
                    job_title = ?,
                    company_name = ?,
                    location = ?,
                    vacancies = ?,
                    positions = ?,
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
                    status = ?
                WHERE id = ? AND recruiter_id = ?";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            respond(false, "Unable to prepare update query.", [], 500);
        }

        $stmt->bind_param(
            "sssiissssssssssssii",
            $jobTitle,
            $companyName,
            $location,
            $vacancies,
            $vacancies,
            $jobType,
            $workplaceType,
            $category,
            $education,
            $experience,
            $salary,
            $description,
            $responsibilities,
            $requirements,
            $skills,
            $deadlineValue,
            $status,
            $jobId,
            $recruiterId
        );

        if (!$stmt->execute()) {
            $stmt->close();
            respond(false, "Database update failed.", [], 500);
        }

        $stmt->close();

        $updatedJob = getJobById($conn, $jobId, $recruiterId);

        respond(
            true,
            "Job updated successfully.",
            ["job" => $updatedJob]
        );
    }

    respond(false, "Method not allowed.", [], 405);

} catch (Throwable $e) {
    error_log("Update Job API Error: " . $e->getMessage());

    respond(
        false,
        "A server error occurred while processing the job.",
        [],
        500
    );
}