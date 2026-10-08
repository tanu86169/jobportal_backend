<?php

// =========================================================
// CORS
// =========================================================

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// =========================================================
// OPTIONS REQUEST
// =========================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =========================================================
// ONLY POST REQUEST
// =========================================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST request allowed"
    ]);

    exit;
}

// =========================================================
// DATABASE
// =========================================================

require_once "../../config/database.php";

// =========================================================
// HELPER FUNCTION
// =========================================================

function postValue($key, $default = "")
{
    if (isset($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }

    return $default;
}

// =========================================================
// GET FORM DATA
// =========================================================

$job_id = intval(
    $_POST["job_id"] ?? 0
);

$recruiter_id = intval(
    $_POST["recruiter_id"] ?? 0
);

$job_title = postValue(
    "job_title"
);

$company_name = postValue(
    "company_name"
);

$workplace_type = postValue(
    "workplace_type"
);

$location = postValue(
    "location"
);

$vacancies = intval(
    $_POST["vacancies"] ?? 1
);

$job_type = postValue(
    "job_type"
);

$category = postValue(
    "category"
);

$experience = postValue(
    "experience"
);

$education = postValue(
    "education"
);

$salary_min = postValue(
    "salary_min"
);

$salary_max = postValue(
    "salary_max"
);

$salary_type = postValue(
    "salary_type",
    "Per Year"
);

$salary = postValue(
    "salary"
);

$deadline = postValue(
    "deadline"
);

$description = postValue(
    "description"
);

$responsibilities = postValue(
    "responsibilities"
);

$requirements = postValue(
    "requirements"
);

$skills = postValue(
    "skills"
);

$remove_company_logo = postValue(
    "remove_company_logo",
    "0"
);

// =========================================================
// DEBUG LOG
// =========================================================

error_log(
    "UPDATE JOB DATA: " .
    json_encode($_POST)
);

// =========================================================
// VALIDATION - JOB ID
// =========================================================

if ($job_id <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Job ID is required"
    ]);

    exit;
}

// =========================================================
// VALIDATION - RECRUITER ID
// =========================================================

if ($recruiter_id <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter ID is required"
    ]);

    exit;
}

// =========================================================
// VALIDATION - JOB TITLE
// =========================================================

if ($job_title === "") {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Job title is required"
    ]);

    exit;
}

// =========================================================
// VALIDATION - COMPANY NAME
// =========================================================

if ($company_name === "") {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Company name is required"
    ]);

    exit;
}

// =========================================================
// VALIDATION - LOCATION
// =========================================================

if ($location === "") {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Location is required"
    ]);

    exit;
}

// =========================================================
// VALIDATION - VACANCIES
// =========================================================

if ($vacancies < 1) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Number of openings must be at least 1"
    ]);

    exit;
}

// =========================================================
// VALIDATION - JOB TYPE
// =========================================================

$allowedJobTypes = [
    "Full Time",
    "Part Time",
    "Internship",
    "Contract",
    "Other"
];

if (
    $job_type !== "" &&
    !in_array(
        $job_type,
        $allowedJobTypes,
        true
    )
) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid job type"
    ]);

    exit;
}

// =========================================================
// CHECK JOB
// =========================================================

$checkStmt = $conn->prepare("
    SELECT
        id,
        recruiter_id,
        company_logo
    FROM jobs
    WHERE id = ?
    LIMIT 1
");

if (!$checkStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare job verification query",
        "error" => $conn->error
    ]);

    exit;
}

// =========================================================
// BIND JOB ID
// =========================================================

$checkStmt->bind_param(
    "i",
    $job_id
);

// =========================================================
// EXECUTE
// =========================================================

if (!$checkStmt->execute()) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to verify job",
        "error" => $checkStmt->error
    ]);

    $checkStmt->close();

    exit;
}

// =========================================================
// GET RESULT
// =========================================================

$result = $checkStmt->get_result();

if ($result->num_rows === 0) {

    $checkStmt->close();

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Job not found"
    ]);

    exit;
}

// =========================================================
// CURRENT JOB
// =========================================================

$currentJob = $result->fetch_assoc();

$checkStmt->close();

// =========================================================
// RECRUITER OWNERSHIP CHECK
// =========================================================

if (
    intval($currentJob["recruiter_id"]) !==
    $recruiter_id
) {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "You are not authorized to edit this job"
    ]);

    exit;
}

// =========================================================
// CURRENT LOGO
// =========================================================

$currentLogo = trim(
    (string) (
        $currentJob["company_logo"] ?? ""
    )
);

$newLogo = $currentLogo;

// =========================================================
// REMOVE EXISTING LOGO
// =========================================================

if (
    $remove_company_logo === "1"
) {

    $newLogo = "";

    if ($currentLogo !== "") {

        $oldLogoPaths = [];

        // If DB stores uploads/company_logos/file.jpg
        $oldLogoPaths[] =
            "../../" .
            ltrim(
                $currentLogo,
                "/"
            );

        // Fallback
        $oldLogoPaths[] =
            "../../uploads/" .
            basename(
                $currentLogo
            );

        // Another fallback
        $oldLogoPaths[] =
            "../../../" .
            ltrim(
                $currentLogo,
                "/"
            );

        foreach (
            $oldLogoPaths as $oldPath
        ) {

            if (
                file_exists($oldPath) &&
                is_file($oldPath)
            ) {

                @unlink($oldPath);

                break;
            }
        }
    }
}

// =========================================================
// NEW COMPANY LOGO
// =========================================================

if (
    isset($_FILES["company_logo"]) &&
    $_FILES["company_logo"]["error"] !== UPLOAD_ERR_NO_FILE
) {

    $file = $_FILES["company_logo"];

    // =====================================================
    // UPLOAD ERROR
    // =====================================================

    if (
        $file["error"] !== UPLOAD_ERR_OK
    ) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Company logo upload failed",
            "upload_error" => $file["error"]
        ]);

        exit;
    }

    // =====================================================
    // FILE SIZE
    // =====================================================

    if (
        $file["size"] > 2 * 1024 * 1024
    ) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Company logo must be less than 2 MB"
        ]);

        exit;
    }

    // =====================================================
    // MIME TYPE
    // =====================================================

    $allowedMimeTypes = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp"
    ];

    $finfo = finfo_open(
        FILEINFO_MIME_TYPE
    );

    if (!$finfo) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Unable to validate image file"
        ]);

        exit;
    }

    $mimeType = finfo_file(
        $finfo,
        $file["tmp_name"]
    );

    finfo_close($finfo);

    // =====================================================
    // INVALID MIME TYPE
    // =====================================================

    if (
        !isset(
            $allowedMimeTypes[$mimeType]
        )
    ) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Only JPG, PNG and WEBP images are allowed"
        ]);

        exit;
    }

    $extension =
        $allowedMimeTypes[$mimeType];

    // =====================================================
    // UPLOAD DIRECTORY
    // =====================================================

    $uploadDirectory =
        "../../uploads/company_logos/";

    if (
        !is_dir(
            $uploadDirectory
        )
    ) {

        if (
            !mkdir(
                $uploadDirectory,
                0777,
                true
            )
        ) {

            http_response_code(500);

            echo json_encode([
                "success" => false,
                "message" => "Unable to create logo upload directory"
            ]);

            exit;
        }
    }

    // =====================================================
    // GENERATE UNIQUE FILE NAME
    // =====================================================

    try {

        $randomPart =
            bin2hex(
                random_bytes(4)
            );

    } catch (Exception $e) {

        $randomPart =
            uniqid();
    }

    $fileName =
        "company_" .
        $job_id .
        "_" .
        time() .
        "_" .
        $randomPart .
        "." .
        $extension;

    $targetPath =
        $uploadDirectory .
        $fileName;

    // =====================================================
    // MOVE UPLOADED FILE
    // =====================================================

    if (
        !move_uploaded_file(
            $file["tmp_name"],
            $targetPath
        )
    ) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Failed to save company logo"
        ]);

        exit;
    }

    // =====================================================
    // DELETE OLD LOGO
    // =====================================================

    if (
        $currentLogo !== ""
    ) {

        $oldLogoPaths = [
            "../../" .
            ltrim(
                $currentLogo,
                "/"
            ),

            "../../uploads/" .
            basename(
                $currentLogo
            ),

            "../../../" .
            ltrim(
                $currentLogo,
                "/"
            )
        ];

        foreach (
            $oldLogoPaths as $oldPath
        ) {

            if (
                file_exists($oldPath) &&
                is_file($oldPath)
            ) {

                @unlink($oldPath);

                break;
            }
        }
    }

    // =====================================================
    // SAVE RELATIVE PATH IN DATABASE
    // =====================================================

    $newLogo =
        "uploads/company_logos/" .
        $fileName;
}

// =========================================================
// UPDATE JOB
// =========================================================

$stmt = $conn->prepare("
    UPDATE jobs
    SET
        job_title = ?,
        company_name = ?,
        workplace_type = ?,
        location = ?,
        vacancies = ?,
        job_type = ?,
        category = ?,
        experience = ?,
        education = ?,
        salary_min = ?,
        salary_max = ?,
        salary_type = ?,
        salary = ?,
        deadline = ?,
        description = ?,
        responsibilities = ?,
        requirements = ?,
        skills = ?,
        company_logo = ?
    WHERE id = ?
      AND recruiter_id = ?
");

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query preparation failed",
        "error" => $conn->error
    ]);

    exit;
}

// =========================================================
// BIND PARAMETERS
//
// 21 values:
//
// 1  job_title          s
// 2  company_name       s
// 3  workplace_type     s
// 4  location           s
// 5  vacancies          i
// 6  job_type           s
// 7  category           s
// 8  experience         s
// 9  education          s
// 10 salary_min         s
// 11 salary_max         s
// 12 salary_type        s
// 13 salary             s
// 14 deadline            s
// 15 description        s
// 16 responsibilities   s
// 17 requirements       s
// 18 skills             s
// 19 company_logo       s
// 20 job_id             i
// 21 recruiter_id       i
// =========================================================

$stmt->bind_param(
    "ssssissssssssssssssii",
    $job_title,
    $company_name,
    $workplace_type,
    $location,
    $vacancies,
    $job_type,
    $category,
    $experience,
    $education,
    $salary_min,
    $salary_max,
    $salary_type,
    $salary,
    $deadline,
    $description,
    $responsibilities,
    $requirements,
    $skills,
    $newLogo,
    $job_id,
    $recruiter_id
);

// =========================================================
// EXECUTE UPDATE
// =========================================================

if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to update job",
        "error" => $stmt->error
    ]);

    $stmt->close();
    $conn->close();

    exit;
}

// =========================================================
// CHECK AFFECTED ROWS
// =========================================================

$affectedRows = $stmt->affected_rows;

// =========================================================
// SUCCESS RESPONSE
// =========================================================

echo json_encode([
    "success" => true,
    "message" => "Job updated successfully",
    "job_id" => $job_id,
    "affected_rows" => $affectedRows
]);

// =========================================================
// CLOSE
// =========================================================

$stmt->close();
$conn->close();

?>