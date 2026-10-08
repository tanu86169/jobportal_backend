<?php

// =====================================================
// CORS
// =====================================================

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");


// =====================================================
// DATABASE
// =====================================================

require_once "../../config/database.php";


// =====================================================
// OPTIONS
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}


// =====================================================
// ONLY POST
// =====================================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST request is allowed"
    ]);

    exit;
}


// =====================================================
// INITIALIZE
// =====================================================

$uploadPath = null;
$applicationInserted = false;


// =====================================================
// TRY
// =====================================================

try {

    // =================================================
    // GET FORM DATA
    // =================================================

    $job_id = isset($_POST["job_id"])
        ? (int) $_POST["job_id"]
        : 0;

    /*
     * IMPORTANT
     *
     * Current project architecture:
     *
     * applications.candidate_id = users.id
     *
     * Therefore candidate_id received here
     * must be users.id.
     */

    $candidate_id = isset($_POST["candidate_id"])
        ? (int) $_POST["candidate_id"]
        : 0;

    $cover_letter = trim(
        $_POST["cover_letter"] ?? ""
    );


    // =================================================
    // VALIDATE JOB ID
    // =================================================

    if ($job_id <= 0) {

        http_response_code(400);

        throw new Exception(
            "Valid job ID is required"
        );
    }


    // =================================================
    // VALIDATE CANDIDATE ID
    // =================================================

    if ($candidate_id <= 0) {

        http_response_code(400);

        throw new Exception(
            "Valid candidate ID is required"
        );
    }


    // =================================================
    // CHECK RESUME
    // =================================================

    if (
        !isset($_FILES["resume"]) ||
        !is_array($_FILES["resume"])
    ) {

        http_response_code(400);

        throw new Exception(
            "Resume is required"
        );
    }


    $resume = $_FILES["resume"];


    // =================================================
    // CHECK UPLOAD ERROR
    // =================================================

    $uploadError = $resume["error"] ?? UPLOAD_ERR_NO_FILE;

    if ($uploadError !== UPLOAD_ERR_OK) {

        http_response_code(400);

        $uploadErrorMessages = [
            UPLOAD_ERR_INI_SIZE =>
                "Resume exceeds server upload limit.",

            UPLOAD_ERR_FORM_SIZE =>
                "Resume exceeds allowed form size.",

            UPLOAD_ERR_PARTIAL =>
                "Resume upload was incomplete.",

            UPLOAD_ERR_NO_FILE =>
                "Resume is required.",

            UPLOAD_ERR_NO_TMP_DIR =>
                "Temporary upload folder is missing.",

            UPLOAD_ERR_CANT_WRITE =>
                "Unable to write uploaded resume.",

            UPLOAD_ERR_EXTENSION =>
                "Resume upload was blocked by server."
        ];

        throw new Exception(
            $uploadErrorMessages[$uploadError]
                ?? "Resume upload failed."
        );
    }


    // =================================================
    // GET JOB
    // =================================================

    $jobStmt = $conn->prepare("
        SELECT
            id,
            job_title,
            company_name,
            recruiter_id,
            status,
            deadline
        FROM jobs
        WHERE id = ?
        LIMIT 1
    ");

    if (!$jobStmt) {

        http_response_code(500);

        throw new Exception(
            "Job query preparation failed: " .
            $conn->error
        );
    }


    $jobStmt->bind_param(
        "i",
        $job_id
    );


    if (!$jobStmt->execute()) {

        $error = $jobStmt->error;

        $jobStmt->close();

        http_response_code(500);

        throw new Exception(
            "Job query failed: " . $error
        );
    }


    $jobResult = $jobStmt->get_result();

    $job = $jobResult
        ? $jobResult->fetch_assoc()
        : null;

    $jobStmt->close();


    if (!$job) {

        http_response_code(404);

        throw new Exception(
            "Job not found"
        );
    }


    // =================================================
    // CHECK JOB STATUS
    // =================================================

    $jobStatus = strtolower(
        trim(
            (string) ($job["status"] ?? "")
        )
    );


    if ($jobStatus !== "active") {

        http_response_code(400);

        throw new Exception(
            "This job is no longer accepting applications"
        );
    }


    // =================================================
    // CHECK DEADLINE
    // =================================================

    if (!empty($job["deadline"])) {

        $deadlineTimestamp = strtotime(
            $job["deadline"]
        );

        if (
            $deadlineTimestamp !== false &&
            $deadlineTimestamp < time()
        ) {

            http_response_code(400);

            throw new Exception(
                "Application deadline for this job has passed"
            );
        }
    }


    // =================================================
    // JOB INFORMATION
    // =================================================

    $recruiterId = (int) (
        $job["recruiter_id"] ?? 0
    );

    $jobTitle = trim(
        (string) (
            $job["job_title"] ?? ""
        )
    );

    $companyName = trim(
        (string) (
            $job["company_name"] ?? ""
        )
    );


    if ($recruiterId <= 0) {

        http_response_code(500);

        throw new Exception(
            "Recruiter not found for this job"
        );
    }


    if ($jobTitle === "") {
        $jobTitle = "Job";
    }


    if ($companyName === "") {
        $companyName = "Company";
    }


    // =================================================
    // VERIFY CANDIDATE
    // =================================================
    //
    // IMPORTANT:
    //
    // candidate_id = users.id
    //
    // We intentionally verify users.id.
    // We do NOT use candidates.id here.
    //

    $candidateStmt = $conn->prepare("
        SELECT
            id,
            name,
            email,
            role,
            status
        FROM users
        WHERE id = ?
          AND role = 'candidate'
        LIMIT 1
    ");


    if (!$candidateStmt) {

        http_response_code(500);

        throw new Exception(
            "Candidate query preparation failed: " .
            $conn->error
        );
    }


    $candidateStmt->bind_param(
        "i",
        $candidate_id
    );


    if (!$candidateStmt->execute()) {

        $error = $candidateStmt->error;

        $candidateStmt->close();

        http_response_code(500);

        throw new Exception(
            "Candidate verification failed: " .
            $error
        );
    }


    $candidateResult =
        $candidateStmt->get_result();


    $candidate =
        $candidateResult
            ? $candidateResult->fetch_assoc()
            : null;


    $candidateStmt->close();


    if (!$candidate) {

        http_response_code(404);

        throw new Exception(
            "Candidate account not found"
        );
    }


    // =================================================
    // CHECK USER STATUS
    // =================================================

    $candidateStatus = strtolower(
        trim(
            (string) (
                $candidate["status"] ?? ""
            )
        )
    );


    if (
        in_array(
            $candidateStatus,
            [
                "blocked",
                "banned",
                "suspended",
                "inactive"
            ],
            true
        )
    ) {

        http_response_code(403);

        throw new Exception(
            "Your candidate account is not allowed to apply for jobs"
        );
    }


    $candidateName = trim(
        (string) (
            $candidate["name"] ?? ""
        )
    );


    if ($candidateName === "") {
        $candidateName = "Candidate";
    }


    // =================================================
    // CHECK DUPLICATE APPLICATION
    // =================================================

    $checkStmt = $conn->prepare("
        SELECT
            id,
            status,
            applied_at
        FROM applications
        WHERE job_id = ?
          AND candidate_id = ?
        LIMIT 1
    ");


    if (!$checkStmt) {

        http_response_code(500);

        throw new Exception(
            "Application check preparation failed: " .
            $conn->error
        );
    }


    $checkStmt->bind_param(
        "ii",
        $job_id,
        $candidate_id
    );


    if (!$checkStmt->execute()) {

        $error = $checkStmt->error;

        $checkStmt->close();

        http_response_code(500);

        throw new Exception(
            "Application duplicate check failed: " .
            $error
        );
    }


    $checkResult =
        $checkStmt->get_result();


    $alreadyApplied =
        $checkResult &&
        $checkResult->num_rows > 0;


    $existingApplication =
        $alreadyApplied
            ? $checkResult->fetch_assoc()
            : null;


    $checkStmt->close();


    if ($alreadyApplied) {

        http_response_code(409);

        throw new Exception(
            "You have already applied for this job"
        );
    }


    // =================================================
    // RESUME DETAILS
    // =================================================

    $originalName =
        basename(
            (string) (
                $resume["name"] ?? ""
            )
        );

    $tmpName =
        $resume["tmp_name"] ?? "";

    $fileSize =
        (int) (
            $resume["size"] ?? 0
        );


    if ($originalName === "") {

        http_response_code(400);

        throw new Exception(
            "Invalid resume file"
        );
    }


    if ($tmpName === "") {

        http_response_code(400);

        throw new Exception(
            "Temporary resume file not found"
        );
    }


    // =================================================
    // FILE EXTENSION
    // =================================================

    $extension = strtolower(
        pathinfo(
            $originalName,
            PATHINFO_EXTENSION
        )
    );


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

        http_response_code(400);

        throw new Exception(
            "Only PDF, DOC and DOCX files are allowed"
        );
    }


    // =================================================
    // FILE SIZE
    // =================================================

    if ($fileSize <= 0) {

        http_response_code(400);

        throw new Exception(
            "Resume file is empty"
        );
    }


    $maxFileSize = 5 * 1024 * 1024;


    if ($fileSize > $maxFileSize) {

        http_response_code(400);

        throw new Exception(
            "Resume must be less than or equal to 5 MB"
        );
    }


    // =================================================
    // VERIFY UPLOADED FILE
    // =================================================

    if (!is_uploaded_file($tmpName)) {

        http_response_code(400);

        throw new Exception(
            "Invalid uploaded resume"
        );
    }


    // =================================================
    // MIME TYPE
    // =================================================

    $allowedMimeTypes = [
        "pdf" => [
            "application/pdf"
        ],

        "doc" => [
            "application/msword"
        ],

        "docx" => [
            "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
        ]
    ];


    $detectedMime = "";


    if (
        function_exists("finfo_open")
    ) {

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo) {

            $detectedMime =
                finfo_file(
                    $finfo,
                    $tmpName
                );

            finfo_close($finfo);
        }
    }


    /*
     * MIME check is performed when PHP can detect it.
     *
     * Some local XAMPP configurations can report
     * different DOC/DOCX MIME values, so extension
     * remains the primary compatibility check.
     */

    if (
        $detectedMime !== "" &&
        isset($allowedMimeTypes[$extension]) &&
        !in_array(
            $detectedMime,
            $allowedMimeTypes[$extension],
            true
        )
    ) {

        /*
         * Do not silently accept obvious mismatch.
         */

        http_response_code(400);

        throw new Exception(
            "Invalid resume file type"
        );
    }


    // =================================================
    // UPLOAD DIRECTORY
    // =================================================

    $uploadDir =
        dirname(__DIR__, 2) .
        "/uploads/resumes/";


    if (!is_dir($uploadDir)) {

        if (
            !mkdir(
                $uploadDir,
                0777,
                true
            )
        ) {

            http_response_code(500);

            throw new Exception(
                "Unable to create resume upload folder"
            );
        }
    }


    // =================================================
    // CHECK DIRECTORY WRITABLE
    // =================================================

    if (!is_writable($uploadDir)) {

        http_response_code(500);

        throw new Exception(
            "Resume upload folder is not writable"
        );
    }


    // =================================================
    // SAFE ORIGINAL FILE NAME
    // =================================================

    $safeName = preg_replace(
        "/[^a-zA-Z0-9._-]/",
        "_",
        $originalName
    );


    if (!$safeName) {

        $safeName =
            "resume." . $extension;
    }


    // =================================================
    // UNIQUE FILE NAME
    // =================================================

    $newFileName =
        date("YmdHis") .
        "_" .
        bin2hex(random_bytes(8)) .
        "_" .
        $safeName;


    $uploadPath =
        $uploadDir .
        $newFileName;


    // =================================================
    // MOVE FILE
    // =================================================

    if (
        !move_uploaded_file(
            $tmpName,
            $uploadPath
        )
    ) {

        http_response_code(500);

        throw new Exception(
            "Failed to save resume"
        );
    }


    // =================================================
    // INSERT APPLICATION
    // =================================================

    $applicationStmt = $conn->prepare("
        INSERT INTO applications
        (
            job_id,
            candidate_id,
            cover_letter,
            resume,
            status
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            'Applied'
        )
    ");


    if (!$applicationStmt) {

        http_response_code(500);

        throw new Exception(
            "Application insert preparation failed: " .
            $conn->error
        );
    }


    $applicationStmt->bind_param(
        "iiss",
        $job_id,
        $candidate_id,
        $cover_letter,
        $newFileName
    );


    if (!$applicationStmt->execute()) {

        $error =
            $applicationStmt->error;

        $applicationStmt->close();

        http_response_code(500);

        throw new Exception(
            "Database insert failed: " .
            $error
        );
    }


    $applicationId =
        (int) $applicationStmt->insert_id;


    $applicationStmt->close();


    $applicationInserted = true;


    // =================================================
    // RECRUITER NOTIFICATION
    // =================================================

    $newApplicationAlerts = 1;


    /*
     * Notification settings are optional.
     *
     * If recruiter_settings table/row exists,
     * use its value.
     *
     * If not, default = ON.
     */

    $settingsStmt = $conn->prepare("
        SELECT
            new_application_alerts
        FROM recruiter_settings
        WHERE recruiter_id = ?
        LIMIT 1
    ");


    if ($settingsStmt) {

        $settingsStmt->bind_param(
            "i",
            $recruiterId
        );


        if ($settingsStmt->execute()) {

            $settingsResult =
                $settingsStmt->get_result();


            if ($settingsResult) {

                $settings =
                    $settingsResult->fetch_assoc();


                if ($settings) {

                    $newApplicationAlerts =
                        (int) (
                            $settings[
                                "new_application_alerts"
                            ] ?? 1
                        );
                }
            }
        } else {

            error_log(
                "Recruiter settings query failed: " .
                $settingsStmt->error
            );
        }


        $settingsStmt->close();

    } else {

        error_log(
            "Recruiter settings preparation failed: " .
            $conn->error
        );
    }


    // =================================================
    // CREATE NOTIFICATION
    // =================================================

    if ($newApplicationAlerts === 1) {

        $notificationTitle =
            "New Application";


        $notificationMessage =
            $candidateName .
            " has applied for your " .
            $jobTitle .
            " job.";


        $notificationType =
            "application";


        $notificationStmt = $conn->prepare("
            INSERT INTO notifications
            (
                user_id,
                type,
                title,
                message,
                related_id,
                is_read
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                0
            )
        ");


        if ($notificationStmt) {

            $notificationStmt->bind_param(
                "isssi",
                $recruiterId,
                $notificationType,
                $notificationTitle,
                $notificationMessage,
                $applicationId
            );


            if (!$notificationStmt->execute()) {

                error_log(
                    "Notification insert failed: " .
                    $notificationStmt->error
                );
            }


            $notificationStmt->close();

        } else {

            error_log(
                "Notification preparation failed: " .
                $conn->error
            );
        }
    }


    // =================================================
    // SUCCESS
    // =================================================

    http_response_code(201);


    echo json_encode([
        "success" => true,

        "message" =>
            "Application submitted successfully",

        "applicationId" =>
            $applicationId,

        "job_id" =>
            $job_id,

        "candidate_id" =>
            $candidate_id,

        "job_title" =>
            $jobTitle,

        "company_name" =>
            $companyName,

        "status" =>
            "Applied",

        "notification" => [
            "type" => "application",
            "enabled" =>
                $newApplicationAlerts === 1
        ]
    ]);


} catch (Throwable $e) {

    // =================================================
    // REMOVE UPLOADED FILE ON FAILURE
    // =================================================

    if (
        !$applicationInserted &&
        $uploadPath &&
        file_exists($uploadPath)
    ) {

        @unlink($uploadPath);
    }


    // =================================================
    // ERROR RESPONSE
    // =================================================

    $currentStatus =
        http_response_code();


    if (
        $currentStatus === 200 ||
        $currentStatus < 400
    ) {
        $currentStatus = 500;
    }


    http_response_code(
        $currentStatus
    );


    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);

} finally {

    // =================================================
    // CLOSE DATABASE
    // =================================================

    if (
        isset($conn) &&
        $conn instanceof mysqli
    ) {

        $conn->close();
    }
}

?>