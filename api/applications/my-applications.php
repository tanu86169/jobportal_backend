<?php

/* =========================================================
   CORS + JSON
========================================================= */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

/* =========================================================
   DATABASE
========================================================= */

require_once "../../config/database.php";

/* =========================================================
   OPTIONS / PREFLIGHT
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);

    echo json_encode([
        "success" => true,
        "message" => "CORS OK"
    ]);

    exit;
}

/* =========================================================
   JSON RESPONSE HELPER
========================================================= */

function responseJson(
    $success,
    $message = "",
    $data = [],
    $statusCode = 200
) {
    http_response_code($statusCode);

    echo json_encode(
        array_merge(
            [
                "success" => $success,
                "message" => $message
            ],
            $data
        ),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

/* =========================================================
   DATABASE CHECK
========================================================= */

if (!isset($conn) || !$conn) {
    responseJson(
        false,
        "Database connection failed.",
        [],
        500
    );
}

/* =========================================================
   CONSTANTS
========================================================= */

$allowedStatuses = [
    "Applied",
    "Screening",
    "Shortlisted",
    "Interview",
    "Hired",
    "Rejected"
];

/* =========================================================
   RESUME UPLOAD DIRECTORY
========================================================= */

$uploadDir =
    __DIR__ .
    "/../../uploads/resumes/";

/* =========================================================
   RESUME BASE URL
========================================================= */

$uploadBaseUrl =
    "http://localhost/job_portal/job-portal-api/uploads/resumes/";

/* =========================================================
   CREATE UPLOAD DIRECTORY
========================================================= */

if (!is_dir($uploadDir)) {

    if (!mkdir($uploadDir, 0777, true)) {

        responseJson(
            false,
            "Unable to create resume upload directory.",
            [],
            500
        );
    }
}

/* =========================================================
   NORMALIZE DATETIME
========================================================= */

function normalizeDateTime($value)
{
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    $value = str_replace("T", " ", $value);

    /*
       datetime-local usually returns:
       YYYY-MM-DD HH:mm
    */

    if (strlen($value) === 16) {
        $value .= ":00";
    }

    return $value;
}

/* =========================================================
   BUILD RESUME URL
========================================================= */

function buildResumeUrl($resume, $uploadBaseUrl)
{
    if (empty($resume)) {
        return null;
    }

    /*
       Already full URL
    */

    if (preg_match("/^https?:\/\//i", $resume)) {
        return $resume;
    }

    return $uploadBaseUrl .
        rawurlencode(
            basename($resume)
        );
}

/* =========================================================
   DELETE RESUME FILE
========================================================= */

function deleteResumeFile($resume, $uploadDir)
{
    if (empty($resume)) {
        return;
    }

    $fileName = basename($resume);

    $filePath =
        $uploadDir .
        $fileName;

    if (is_file($filePath)) {
        @unlink($filePath);
    }
}

/* =========================================================
   UPLOAD RESUME
========================================================= */

function uploadResume($uploadDir)
{
    /*
       No file uploaded
    */

    if (
        !isset($_FILES["resume"]) ||
        $_FILES["resume"]["error"] === UPLOAD_ERR_NO_FILE
    ) {
        return [
            "success" => true,
            "filename" => null
        ];
    }

    /*
       Upload error
    */

    if (
        $_FILES["resume"]["error"] !==
        UPLOAD_ERR_OK
    ) {
        return [
            "success" => false,
            "message" => "Resume upload failed."
        ];
    }

    /*
       Check file size
       Maximum 5MB
    */

    $maxSize = 5 * 1024 * 1024;

    if (
        $_FILES["resume"]["size"] <= 0
    ) {
        return [
            "success" => false,
            "message" => "Resume file is empty."
        ];
    }

    if (
        $_FILES["resume"]["size"] > $maxSize
    ) {
        return [
            "success" => false,
            "message" => "Resume must be less than 5MB."
        ];
    }

    /*
       Original file name
    */

    $originalName =
        $_FILES["resume"]["name"];

    /*
       Extension
    */

    $extension =
        strtolower(
            pathinfo(
                $originalName,
                PATHINFO_EXTENSION
            )
        );

    /*
       Allowed extensions
    */

    $allowedExtensions = [
        "pdf",
        "doc",
        "docx"
    ];

    if (
        !in_array(
            $extension,
            $allowedExtensions,
            true
        )
    ) {
        return [
            "success" => false,
            "message" =>
                "Only PDF, DOC and DOCX files are allowed."
        ];
    }

    /*
       Original file name without extension
    */

    $fileBaseName =
        pathinfo(
            $originalName,
            PATHINFO_FILENAME
        );

    /*
       Safe file name
    */

    $safeName =
        preg_replace(
            "/[^A-Za-z0-9_-]/",
            "_",
            $fileBaseName
        );

    if (empty($safeName)) {
        $safeName = "resume";
    }

    /*
       Unique file name
    */

    $newFileName =
        time() .
        "_" .
        uniqid("", true) .
        "_" .
        $safeName .
        "." .
        $extension;

    /*
       Destination
    */

    $destination =
        $uploadDir .
        $newFileName;

    /*
       Move uploaded file
    */

    if (
        !move_uploaded_file(
            $_FILES["resume"]["tmp_name"],
            $destination
        )
    ) {
        return [
            "success" => false,
            "message" =>
                "Unable to save resume file."
        ];
    }

    return [
        "success" => true,
        "filename" => $newFileName
    ];
}

/* =========================================================
   GET
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    /* =====================================================
       FORM DATA
       ?action=form-data
    ===================================================== */

    if (
        isset($_GET["action"]) &&
        $_GET["action"] === "form-data"
    ) {

        /* ================================================
           CANDIDATES
        ================================================ */

        $candidateSql = "
            SELECT
                id,
                name,
                email,
                phone
            FROM users
            WHERE LOWER(role) IN (
                'candidate',
                'job-seeker',
                'jobseeker',
                'job seeker'
            )
            ORDER BY name ASC
        ";

        $candidateResult =
            mysqli_query(
                $conn,
                $candidateSql
            );

        if (!$candidateResult) {

            responseJson(
                false,
                "Failed to load candidates: " .
                mysqli_error($conn),
                [],
                500
            );
        }

        $candidates = [];

        while (
            $row =
            mysqli_fetch_assoc(
                $candidateResult
            )
        ) {
            $candidates[] = $row;
        }

        /* ================================================
           JOBS
        ================================================ */

        $jobSql = "
            SELECT
                id,
                job_title,
                company_name,
                location,
                status
            FROM jobs
            ORDER BY id DESC
        ";

        $jobResult =
            mysqli_query(
                $conn,
                $jobSql
            );

        if (!$jobResult) {

            responseJson(
                false,
                "Failed to load jobs: " .
                mysqli_error($conn),
                [],
                500
            );
        }

        $jobs = [];

        while (
            $row =
            mysqli_fetch_assoc(
                $jobResult
            )
        ) {
            $jobs[] = $row;
        }

        responseJson(
            true,
            "Form data loaded successfully.",
            [
                "candidates" => $candidates,
                "jobs" => $jobs
            ]
        );
    }

    /* =====================================================
       GET APPLICATIONS

       Without candidate_id:
       Returns all applications.

       With candidate_id:
       Returns only that candidate's applications.

       Example:
       ?candidate_id=31
    ===================================================== */

    $candidateIdFilter =
        isset($_GET["candidate_id"])
            ? intval($_GET["candidate_id"])
            : 0;

    $sql = "
        SELECT
            a.id AS application_id,
            a.job_id,
            a.candidate_id,
            a.cover_letter,
            a.resume,
            a.status,
            a.applied_at,
            a.interview_note,
            a.interview_date,

            u.id AS user_id,
            u.name AS candidate_name,
            u.email AS candidate_email,
            u.phone AS candidate_phone,

            j.id AS job_id_detail,
            j.job_title,
            j.company_name,
            j.location,
            j.category,
            j.experience,
            j.salary,
            j.job_type,
            j.workplace_type,
            j.description,
            j.skills,
            j.deadline,
            j.status AS job_status

        FROM applications a

        LEFT JOIN users u
            ON u.id = a.candidate_id

        LEFT JOIN jobs j
            ON j.id = a.job_id
    ";

    /*
       Candidate-specific filter
    */

    if ($candidateIdFilter > 0) {
        $sql .= "
            WHERE a.candidate_id = ?
        ";
    }

    $sql .= "
        ORDER BY
            a.applied_at DESC,
            a.id DESC
    ";

    /* =====================================================
       PREPARE GET QUERY
    ===================================================== */

    $stmt =
        $conn->prepare($sql);

    if (!$stmt) {

        responseJson(
            false,
            "Failed to prepare applications query: " .
            $conn->error,
            [],
            500
        );
    }

    /*
       Bind candidate ID only when supplied
    */

    if ($candidateIdFilter > 0) {

        $stmt->bind_param(
            "i",
            $candidateIdFilter
        );
    }

    /* =====================================================
       EXECUTE
    ===================================================== */

    if (!$stmt->execute()) {

        responseJson(
            false,
            "Failed to fetch applications: " .
            $stmt->error,
            [],
            500
        );
    }

    $result =
        $stmt->get_result();

    $applications = [];

    /* =====================================================
       FORMAT APPLICATIONS
    ===================================================== */

    while (
        $row =
        $result->fetch_assoc()
    ) {

        /*
           Resume URL
        */

        $row["resume_url"] =
            buildResumeUrl(
                $row["resume"],
                $uploadBaseUrl
            );

        /*
           Skills formatting
        */

        if (!empty($row["skills"])) {

            $skills =
                trim(
                    $row["skills"]
                );

            $decodedSkills =
                json_decode(
                    $skills,
                    true
                );

            if (
                json_last_error() ===
                JSON_ERROR_NONE &&
                is_array($decodedSkills)
            ) {

                $row["skills"] =
                    array_values(
                        array_filter(
                            array_map(
                                "trim",
                                $decodedSkills
                            )
                        )
                    );

            } else {

                $row["skills"] =
                    array_values(
                        array_filter(
                            array_map(
                                "trim",
                                explode(
                                    ",",
                                    $skills
                                )
                            )
                        )
                    );
            }

        } else {

            $row["skills"] = [];
        }

        /*
           Safe default values
        */

        $row["cover_letter"] =
            $row["cover_letter"] ?? "";

        $row["candidate_name"] =
            $row["candidate_name"] ?? "";

        $row["candidate_email"] =
            $row["candidate_email"] ?? "";

        $row["candidate_phone"] =
            $row["candidate_phone"] ?? "";

        $row["status"] =
            $row["status"] ?? "Applied";

        $applications[] = $row;
    }

    /* =====================================================
       RESPONSE
    ===================================================== */

    responseJson(
        true,
        "Applications fetched successfully.",
        [
            "candidate_id" =>
                $candidateIdFilter > 0
                    ? $candidateIdFilter
                    : null,

            "total" =>
                count($applications),

            "applications" =>
                $applications
        ]
    );
}

/* =========================================================
   POST
   ADD + UPDATE
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /* =====================================================
       ACTION
    ===================================================== */

    $action =
        isset($_GET["action"])
            ? trim($_GET["action"])
            : "";

    /* =====================================================
       INPUT
    ===================================================== */

    $applicationId =
        isset($_POST["application_id"])
            ? intval(
                $_POST["application_id"]
            )
            : 0;

    $candidateId =
        isset($_POST["candidate_id"])
            ? intval(
                $_POST["candidate_id"]
            )
            : 0;

    $jobId =
        isset($_POST["job_id"])
            ? intval(
                $_POST["job_id"]
            )
            : 0;

    $status =
        isset($_POST["status"])
            ? trim(
                $_POST["status"]
            )
            : "Applied";

    $appliedAt =
        isset($_POST["applied_at"])
            ? trim(
                $_POST["applied_at"]
            )
            : "";

    $coverLetter =
        isset($_POST["cover_letter"])
            ? trim(
                $_POST["cover_letter"]
            )
            : "";

    $interviewDate =
        isset($_POST["interview_date"])
            ? trim(
                $_POST["interview_date"]
            )
            : "";

    $interviewNote =
        isset($_POST["interview_note"])
            ? trim(
                $_POST["interview_note"]
            )
            : "";

    /* =====================================================
       VALIDATION
    ===================================================== */

    if ($candidateId <= 0) {

        responseJson(
            false,
            "Candidate is required.",
            [],
            400
        );
    }

    if ($jobId <= 0) {

        responseJson(
            false,
            "Job is required.",
            [],
            400
        );
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {

        responseJson(
            false,
            "Invalid application status.",
            [],
            400
        );
    }

    /* =====================================================
       CANDIDATE CHECK
    ===================================================== */

    $candidateCheck =
        $conn->prepare(
            "
            SELECT
                id,
                name,
                email,
                role,
                status
            FROM users
            WHERE id = ?
            LIMIT 1
            "
        );

    if (!$candidateCheck) {

        responseJson(
            false,
            "Candidate check query failed: " .
            $conn->error,
            [],
            500
        );
    }

    $candidateCheck->bind_param(
        "i",
        $candidateId
    );

    if (!$candidateCheck->execute()) {

        responseJson(
            false,
            "Candidate check failed: " .
            $candidateCheck->error,
            [],
            500
        );
    }

    $candidateResult =
        $candidateCheck->get_result();

    if (
        $candidateResult->num_rows === 0
    ) {

        responseJson(
            false,
            "Selected candidate does not exist.",
            [],
            404
        );
    }

    $candidateRow =
        $candidateResult->fetch_assoc();

    /*
       Candidate role validation
    */

    $candidateRole =
        strtolower(
            trim(
                $candidateRow["role"] ?? ""
            )
        );

    $validCandidateRoles = [
        "candidate",
        "job-seeker",
        "jobseeker",
        "job seeker"
    ];

    if (
        !in_array(
            $candidateRole,
            $validCandidateRoles,
            true
        )
    ) {

        responseJson(
            false,
            "Selected user is not a candidate.",
            [],
            400
        );
    }

    /* =====================================================
       JOB CHECK
    ===================================================== */

    $jobCheck =
        $conn->prepare(
            "
            SELECT
                id,
                job_title,
                recruiter_id,
                status
            FROM jobs
            WHERE id = ?
            LIMIT 1
            "
        );

    if (!$jobCheck) {

        responseJson(
            false,
            "Job check query failed: " .
            $conn->error,
            [],
            500
        );
    }

    $jobCheck->bind_param(
        "i",
        $jobId
    );

    if (!$jobCheck->execute()) {

        responseJson(
            false,
            "Job check failed: " .
            $jobCheck->error,
            [],
            500
        );
    }

    $jobResult =
        $jobCheck->get_result();

    if (
        $jobResult->num_rows === 0
    ) {

        responseJson(
            false,
            "Selected job does not exist.",
            [],
            404
        );
    }

    $jobRow =
        $jobResult->fetch_assoc();

    /* =====================================================
       DATETIME
    ===================================================== */

    $appliedAt =
        normalizeDateTime(
            $appliedAt
        );

    if ($appliedAt === null) {

        $appliedAt =
            date("Y-m-d H:i:s");
    }

    $interviewDate =
        normalizeDateTime(
            $interviewDate
        );

    /* =====================================================
       RESUME UPLOAD
    ===================================================== */

    $uploadResult =
        uploadResume(
            $uploadDir
        );

    if (
        !$uploadResult["success"]
    ) {

        responseJson(
            false,
            $uploadResult["message"],
            [],
            400
        );
    }

    $newResumeFile =
        $uploadResult["filename"];

    /* =====================================================
       UPDATE APPLICATION
       ?action=update
    ===================================================== */

    if ($action === "update") {

        if ($applicationId <= 0) {

            if ($newResumeFile) {

                deleteResumeFile(
                    $newResumeFile,
                    $uploadDir
                );
            }

            responseJson(
                false,
                "Application ID is required for update.",
                [],
                400
            );
        }

        /* ================================================
           GET EXISTING APPLICATION
        ================================================ */

        $existingStmt =
            $conn->prepare(
                "
                SELECT
                    id,
                    resume
                FROM applications
                WHERE id = ?
                LIMIT 1
                "
            );

        if (!$existingStmt) {

            if ($newResumeFile) {

                deleteResumeFile(
                    $newResumeFile,
                    $uploadDir
                );
            }

            responseJson(
                false,
                "Failed to prepare application query: " .
                $conn->error,
                [],
                500
            );
        }

        $existingStmt->bind_param(
            "i",
            $applicationId
        );

        if (!$existingStmt->execute()) {

            if ($newResumeFile) {

                deleteResumeFile(
                    $newResumeFile,
                    $uploadDir
                );
            }

            responseJson(
                false,
                "Failed to fetch existing application: " .
                $existingStmt->error,
                [],
                500
            );
        }

        $existingResult =
            $existingStmt->get_result();

        if (
            $existingResult->num_rows === 0
        ) {

            if ($newResumeFile) {

                deleteResumeFile(
                    $newResumeFile,
                    $uploadDir
                );
            }

            responseJson(
                false,
                "Application not found.",
                [],
                404
            );
        }

        $existingRow =
            $existingResult->fetch_assoc();

        $oldResume =
            $existingRow["resume"] ?? null;

        /* ================================================
           DUPLICATE CHECK
        ================================================ */

        $duplicateStmt =
            $conn->prepare(
                "
                SELECT id
                FROM applications
                WHERE job_id = ?
                AND candidate_id = ?
                AND id != ?
                LIMIT 1
                "
            );

        if (!$duplicateStmt) {

            if ($newResumeFile) {

                deleteResumeFile(
                    $newResumeFile,
                    $uploadDir
                );
            }

            responseJson(
                false,
                "Duplicate check failed: " .
                $conn->error,
                [],
                500
            );
        }

        $duplicateStmt->bind_param(
            "iii",
            $jobId,
            $candidateId,
            $applicationId
        );

        if (!$duplicateStmt->execute()) {

            if ($newResumeFile) {

                deleteResumeFile(
                    $newResumeFile,
                    $uploadDir
                );
            }

            responseJson(
                false,
                "Duplicate check failed: " .
                $duplicateStmt->error,
                [],
                500
            );
        }

        $duplicateResult =
            $duplicateStmt->get_result();

        if (
            $duplicateResult->num_rows > 0
        ) {

            if ($newResumeFile) {

                deleteResumeFile(
                    $newResumeFile,
                    $uploadDir
                );
            }

            responseJson(
                false,
                "This candidate has already applied for this job.",
                [],
                409
            );
        }

        /* ================================================
           KEEP OLD RESUME IF NO NEW FILE
        ================================================ */

        $resumeValue =
            $newResumeFile
                ? $newResumeFile
                : $oldResume;

        /* ================================================
           UPDATE
        ================================================ */

        $updateSql = "
            UPDATE applications
            SET
                job_id = ?,
                candidate_id = ?,
                cover_letter = ?,
                resume = ?,
                status = ?,
                applied_at = ?,
                interview_note = ?,
                interview_date = ?
            WHERE id = ?
        ";

        $updateStmt =
            $conn->prepare(
                $updateSql
            );

        if (!$updateStmt) {

            if ($newResumeFile) {

                deleteResumeFile(
                    $newResumeFile,
                    $uploadDir
                );
            }

            responseJson(
                false,
                "Failed to prepare update query: " .
                $conn->error,
                [],
                500
            );
        }

        $updateStmt->bind_param(
            "iissssssi",
            $jobId,
            $candidateId,
            $coverLetter,
            $resumeValue,
            $status,
            $appliedAt,
            $interviewNote,
            $interviewDate,
            $applicationId
        );

        if (
            !$updateStmt->execute()
        ) {

            if ($newResumeFile) {

                deleteResumeFile(
                    $newResumeFile,
                    $uploadDir
                );
            }

            responseJson(
                false,
                "Failed to update application: " .
                $updateStmt->error,
                [],
                500
            );
        }

        /* ================================================
           DELETE OLD RESUME
           Only after successful DB update
        ================================================ */

        if (
            $newResumeFile &&
            !empty($oldResume)
        ) {

            deleteResumeFile(
                $oldResume,
                $uploadDir
            );
        }

        /* ================================================
           GET UPDATED APPLICATION
        ================================================ */

        $updatedStmt =
            $conn->prepare(
                "
                SELECT
                    a.id AS application_id,
                    a.job_id,
                    a.candidate_id,
                    a.cover_letter,
                    a.resume,
                    a.status,
                    a.applied_at,
                    a.interview_note,
                    a.interview_date,

                    u.id AS user_id,
                    u.name AS candidate_name,
                    u.email AS candidate_email,
                    u.phone AS candidate_phone,

                    j.id AS job_id_detail,
                    j.job_title,
                    j.company_name,
                    j.location,
                    j.category,
                    j.experience,
                    j.salary,
                    j.job_type,
                    j.workplace_type,
                    j.description,
                    j.skills,
                    j.deadline

                FROM applications a

                LEFT JOIN users u
                    ON u.id = a.candidate_id

                LEFT JOIN jobs j
                    ON j.id = a.job_id

                WHERE a.id = ?

                LIMIT 1
                "
            );

        if (!$updatedStmt) {

            responseJson(
                true,
                "Application updated successfully.",
                [
                    "application_id" =>
                        $applicationId,
                    "status" =>
                        $status,
                    "resume" =>
                        $resumeValue,
                    "resume_url" =>
                        buildResumeUrl(
                            $resumeValue,
                            $uploadBaseUrl
                        )
                ]
            );
        }

        $updatedStmt->bind_param(
            "i",
            $applicationId
        );

        $updatedStmt->execute();

        $updatedResult =
            $updatedStmt->get_result();

        $updatedApplication =
            $updatedResult->fetch_assoc();

        if ($updatedApplication) {

            $updatedApplication["resume_url"] =
                buildResumeUrl(
                    $updatedApplication["resume"],
                    $uploadBaseUrl
                );

            /*
               Skills as array
            */

            if (
                !empty(
                    $updatedApplication["skills"]
                )
            ) {

                $skills =
                    trim(
                        $updatedApplication["skills"]
                    );

                $decodedSkills =
                    json_decode(
                        $skills,
                        true
                    );

                if (
                    json_last_error() ===
                    JSON_ERROR_NONE &&
                    is_array($decodedSkills)
                ) {

                    $updatedApplication["skills"] =
                        array_values(
                            array_filter(
                                array_map(
                                    "trim",
                                    $decodedSkills
                                )
                            )
                        );

                } else {

                    $updatedApplication["skills"] =
                        array_values(
                            array_filter(
                                array_map(
                                    "trim",
                                    explode(
                                        ",",
                                        $skills
                                    )
                                )
                            )
                        );
                }

            } else {

                $updatedApplication["skills"] = [];
            }
        }

        responseJson(
            true,
            "Application updated successfully.",
            [
                "application" =>
                    $updatedApplication,

                "application_id" =>
                    $applicationId,

                "status" =>
                    $status
            ]
        );
    }

    /* =====================================================
       ADD APPLICATION
    ===================================================== */

    /* =====================================================
       DUPLICATE CHECK
    ===================================================== */

    $duplicateStmt =
        $conn->prepare(
            "
            SELECT id
            FROM applications
            WHERE job_id = ?
            AND candidate_id = ?
            LIMIT 1
            "
        );

    if (!$duplicateStmt) {

        if ($newResumeFile) {

            deleteResumeFile(
                $newResumeFile,
                $uploadDir
            );
        }

        responseJson(
            false,
            "Duplicate check failed: " .
            $conn->error,
            [],
            500
        );
    }

    $duplicateStmt->bind_param(
        "ii",
        $jobId,
        $candidateId
    );

    if (!$duplicateStmt->execute()) {

        if ($newResumeFile) {

            deleteResumeFile(
                $newResumeFile,
                $uploadDir
            );
        }

        responseJson(
            false,
            "Duplicate check failed: " .
            $duplicateStmt->error,
            [],
            500
        );
    }

    $duplicateResult =
        $duplicateStmt->get_result();

    if (
        $duplicateResult->num_rows > 0
    ) {

        if ($newResumeFile) {

            deleteResumeFile(
                $newResumeFile,
                $uploadDir
            );
        }

        responseJson(
            false,
            "This candidate has already applied for this job.",
            [],
            409
        );
    }

    /* =====================================================
       INSERT APPLICATION
    ===================================================== */

    $insertSql = "
        INSERT INTO applications
        (
            job_id,
            candidate_id,
            cover_letter,
            resume,
            status,
            applied_at,
            interview_note,
            interview_date
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $insertStmt =
        $conn->prepare(
            $insertSql
        );

    if (!$insertStmt) {

        if ($newResumeFile) {

            deleteResumeFile(
                $newResumeFile,
                $uploadDir
            );
        }

        responseJson(
            false,
            "Failed to prepare insert query: " .
            $conn->error,
            [],
            500
        );
    }

    $resumeValue =
        $newResumeFile ?: null;

    $insertStmt->bind_param(
        "iissssss",
        $jobId,
        $candidateId,
        $coverLetter,
        $resumeValue,
        $status,
        $appliedAt,
        $interviewNote,
        $interviewDate
    );

    if (
        !$insertStmt->execute()
    ) {

        if ($newResumeFile) {

            deleteResumeFile(
                $newResumeFile,
                $uploadDir
            );
        }

        responseJson(
            false,
            "Failed to add application: " .
            $insertStmt->error,
            [],
            500
        );
    }

    $newApplicationId =
        $insertStmt->insert_id;

    responseJson(
        true,
        "Application added successfully.",
        [
            "application_id" =>
                $newApplicationId,

            "resume" =>
                $newResumeFile,

            "resume_url" =>
                buildResumeUrl(
                    $newResumeFile,
                    $uploadBaseUrl
                )
        ],
        201
    );
}

/* =========================================================
   PUT
   STATUS ONLY UPDATE
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "PUT") {

    $rawInput =
        file_get_contents(
            "php://input"
        );

    $input =
        json_decode(
            $rawInput,
            true
        );

    if (!is_array($input)) {
        $input = [];
    }

    $applicationId =
        isset($input["application_id"])
            ? intval(
                $input["application_id"]
            )
            : 0;

    $status =
        isset($input["status"])
            ? trim(
                $input["status"]
            )
            : "";

    if ($applicationId <= 0) {

        responseJson(
            false,
            "Application ID is required.",
            [],
            400
        );
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {

        responseJson(
            false,
            "Invalid status.",
            [],
            400
        );
    }

    /* =====================================================
       CHECK APPLICATION EXISTS
    ===================================================== */

    $checkStmt =
        $conn->prepare(
            "
            SELECT id, status
            FROM applications
            WHERE id = ?
            LIMIT 1
            "
        );

    if (!$checkStmt) {

        responseJson(
            false,
            "Failed to check application: " .
            $conn->error,
            [],
            500
        );
    }

    $checkStmt->bind_param(
        "i",
        $applicationId
    );

    $checkStmt->execute();

    $checkResult =
        $checkStmt->get_result();

    if (
        $checkResult->num_rows === 0
    ) {

        responseJson(
            false,
            "Application not found.",
            [],
            404
        );
    }

    /* =====================================================
       UPDATE STATUS
    ===================================================== */

    $stmt =
        $conn->prepare(
            "
            UPDATE applications
            SET status = ?
            WHERE id = ?
            "
        );

    if (!$stmt) {

        responseJson(
            false,
            "Failed to prepare status update: " .
            $conn->error,
            [],
            500
        );
    }

    $stmt->bind_param(
        "si",
        $status,
        $applicationId
    );

    if (
        !$stmt->execute()
    ) {

        responseJson(
            false,
            "Failed to update status: " .
            $stmt->error,
            [],
            500
        );
    }

    /*
       Even if same status is submitted,
       application exists and request succeeded.
    */

    responseJson(
        true,
        "Application status updated successfully.",
        [
            "application_id" =>
                $applicationId,

            "status" =>
                $status
        ]
    );
}

/* =========================================================
   DELETE APPLICATION
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $rawInput =
        file_get_contents(
            "php://input"
        );

    $input =
        json_decode(
            $rawInput,
            true
        );

    if (!is_array($input)) {
        $input = [];
    }

    $applicationId =
        isset($input["application_id"])
            ? intval(
                $input["application_id"]
            )
            : 0;

    if ($applicationId <= 0) {

        responseJson(
            false,
            "Application ID is required.",
            [],
            400
        );
    }

    /* =====================================================
       GET RESUME
    ===================================================== */

    $resumeStmt =
        $conn->prepare(
            "
            SELECT resume
            FROM applications
            WHERE id = ?
            LIMIT 1
            "
        );

    if (!$resumeStmt) {

        responseJson(
            false,
            "Failed to get application resume: " .
            $conn->error,
            [],
            500
        );
    }

    $resumeStmt->bind_param(
        "i",
        $applicationId
    );

    $resumeStmt->execute();

    $resumeResult =
        $resumeStmt->get_result();

    if (
        $resumeResult->num_rows === 0
    ) {

        responseJson(
            false,
            "Application not found.",
            [],
            404
        );
    }

    $resumeRow =
        $resumeResult->fetch_assoc();

    $resume =
        $resumeRow["resume"] ?? null;

    /* =====================================================
       DELETE APPLICATION
    ===================================================== */

    $deleteStmt =
        $conn->prepare(
            "
            DELETE FROM applications
            WHERE id = ?
            "
        );

    if (!$deleteStmt) {

        responseJson(
            false,
            "Failed to prepare delete query: " .
            $conn->error,
            [],
            500
        );
    }

    $deleteStmt->bind_param(
        "i",
        $applicationId
    );

    if (
        !$deleteStmt->execute()
    ) {

        responseJson(
            false,
            "Failed to delete application: " .
            $deleteStmt->error,
            [],
            500
        );
    }

    if (
        $deleteStmt->affected_rows === 0
    ) {

        responseJson(
            false,
            "Application was not deleted.",
            [],
            404
        );
    }

    /* =====================================================
       DELETE RESUME FILE
    ===================================================== */

    if (!empty($resume)) {

        deleteResumeFile(
            $resume,
            $uploadDir
        );
    }

    responseJson(
        true,
        "Application deleted successfully.",
        [
            "application_id" =>
                $applicationId
        ]
    );
}

/* =========================================================
   METHOD NOT ALLOWED
========================================================= */

responseJson(
    false,
    "Method not allowed.",
    [],
    405
);

?>