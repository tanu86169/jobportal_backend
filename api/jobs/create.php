<?php

/*
|--------------------------------------------------------------------------
| CREATE / POST JOB API
|--------------------------------------------------------------------------
|
| Job posting is allowed ONLY when:
|
| 1. Admin setting allow_job_posting = 1
| 2. User role = recruiter
| 3. Recruiter has a company
| 4. Company status = approved
|
|--------------------------------------------------------------------------
| COMPANY STATUS
|--------------------------------------------------------------------------
|
| pending   -> Cannot post job
| approved  -> Can post job
| rejected  -> Cannot post job
| suspended -> Cannot post job
| blocked   -> Cannot post job
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| CORS + DATABASE
|--------------------------------------------------------------------------
*/

require_once "../../config/cors.php";
require_once "../../config/database.php";

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
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| HELPER RESPONSE
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
| REQUEST METHOD
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    sendResponse(
        false,
        "Only POST request is allowed.",
        405
    );
}


/*
|--------------------------------------------------------------------------
| CHECK DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !$conn) {

    sendResponse(
        false,
        "Database connection failed.",
        500
    );
}


/*
|--------------------------------------------------------------------------
| CHECK GLOBAL JOB POSTING SETTING
|--------------------------------------------------------------------------
|
| admin_settings:
|
| setting_key   = allow_job_posting
| setting_value = 1 / 0
|
|--------------------------------------------------------------------------
*/

$jobPostingAllowed = "1";

$settingStmt = $conn->prepare("
    SELECT setting_value
    FROM admin_settings
    WHERE setting_key = 'allow_job_posting'
    LIMIT 1
");

if ($settingStmt) {

    if ($settingStmt->execute()) {

        $settingResult = $settingStmt->get_result();

        if (
            $settingResult &&
            $settingResult->num_rows > 0
        ) {

            $settingRow = $settingResult->fetch_assoc();

            $jobPostingAllowed = (string) (
                $settingRow["setting_value"] ?? "1"
            );
        }
    }

    $settingStmt->close();
}


/*
|--------------------------------------------------------------------------
| NORMALIZE SETTING
|--------------------------------------------------------------------------
*/

$normalizedJobPostingAllowed =
    strtolower(
        trim($jobPostingAllowed)
    );

$isJobPostingAllowed =
    in_array(
        $normalizedJobPostingAllowed,
        [
            "1",
            "true",
            "on",
            "yes"
        ],
        true
    );


/*
|--------------------------------------------------------------------------
| JOB POSTING DISABLED
|--------------------------------------------------------------------------
*/

if (!$isJobPostingAllowed) {

    sendResponse(
        false,
        "Job posting is currently disabled by administrator.",
        403,
        [
            "code" => "JOB_POSTING_DISABLED",
            "can_post_job" => false
        ]
    );
}


/*
|--------------------------------------------------------------------------
| READ REQUEST DATA
|--------------------------------------------------------------------------
|
| Supports:
|
| 1. FormData / multipart/form-data
| 2. application/json
|
|--------------------------------------------------------------------------
*/

$data = [];


/*
|--------------------------------------------------------------------------
| FORM DATA
|--------------------------------------------------------------------------
*/

if (!empty($_POST)) {

    $data = $_POST;

}


/*
|--------------------------------------------------------------------------
| JSON DATA
|--------------------------------------------------------------------------
*/

else {

    $input = file_get_contents("php://input");

    if (
        $input !== false &&
        trim($input) !== ""
    ) {

        $jsonData = json_decode(
            $input,
            true
        );

        if (
            is_array($jsonData) &&
            json_last_error() === JSON_ERROR_NONE
        ) {

            $data = $jsonData;
        }
    }
}


/*
|--------------------------------------------------------------------------
| DATA VALIDATION
|--------------------------------------------------------------------------
*/

if (empty($data)) {

    sendResponse(
        false,
        "Invalid or missing request data.",
        400
    );
}


/*
|--------------------------------------------------------------------------
| GET RECRUITER ID
|--------------------------------------------------------------------------
|
| Supported aliases:
|
| recruiter_id
| recruiterId
|
|--------------------------------------------------------------------------
*/

$recruiter_id = intval(
    $data["recruiter_id"]
    ?? $data["recruiterId"]
    ?? 0
);


if ($recruiter_id <= 0) {

    sendResponse(
        false,
        "Recruiter ID is required.",
        400
    );
}


/*
|--------------------------------------------------------------------------
| GET JOB FIELDS
|--------------------------------------------------------------------------
*/

$job_title = trim(
    (string) (
        $data["job_title"] ?? ""
    )
);

$company_name = trim(
    (string) (
        $data["company_name"] ?? ""
    )
);

$location = trim(
    (string) (
        $data["location"] ?? ""
    )
);

$vacancies = intval(
    $data["vacancies"] ?? 1
);

$job_type = trim(
    (string) (
        $data["job_type"] ?? "Full Time"
    )
);

$workplace_type = trim(
    (string) (
        $data["workplace_type"] ?? ""
    )
);

$category = trim(
    (string) (
        $data["category"] ?? ""
    )
);

$education = trim(
    (string) (
        $data["education"] ?? ""
    )
);

$experience = trim(
    (string) (
        $data["experience"] ?? "Fresher"
    )
);

$salary = trim(
    (string) (
        $data["salary"] ?? ""
    )
);

$description = trim(
    (string) (
        $data["description"] ?? ""
    )
);

$responsibilities = trim(
    (string) (
        $data["responsibilities"] ?? ""
    )
);

$requirements = trim(
    (string) (
        $data["requirements"] ?? ""
    )
);

$skills = trim(
    (string) (
        $data["skills"] ?? ""
    )
);

$deadline = trim(
    (string) (
        $data["deadline"] ?? ""
    )
);


/*
|--------------------------------------------------------------------------
| VERIFY RECRUITER
|--------------------------------------------------------------------------
*/

$recruiterCheck = $conn->prepare("
    SELECT
        id,
        name,
        email,
        role
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$recruiterCheck) {

    error_log(
        "Recruiter verification prepare failed: " .
        $conn->error
    );

    sendResponse(
        false,
        "Unable to verify recruiter.",
        500
    );
}


$recruiterCheck->bind_param(
    "i",
    $recruiter_id
);


if (!$recruiterCheck->execute()) {

    error_log(
        "Recruiter verification execute failed: " .
        $recruiterCheck->error
    );

    $recruiterCheck->close();

    sendResponse(
        false,
        "Unable to verify recruiter.",
        500
    );
}


$recruiterResult =
    $recruiterCheck->get_result();


if (
    !$recruiterResult ||
    $recruiterResult->num_rows === 0
) {

    $recruiterCheck->close();

    sendResponse(
        false,
        "Recruiter account not found.",
        404
    );
}


$recruiter =
    $recruiterResult->fetch_assoc();

$recruiterCheck->close();


/*
|--------------------------------------------------------------------------
| ROLE CHECK
|--------------------------------------------------------------------------
*/

$recruiterRole = strtolower(
    trim(
        (string) (
            $recruiter["role"] ?? ""
        )
    )
);


if ($recruiterRole !== "recruiter") {

    sendResponse(
        false,
        "Only recruiters can post jobs.",
        403,
        [
            "code" => "RECRUITER_ONLY",
            "can_post_job" => false
        ]
    );
}


/*
|--------------------------------------------------------------------------
| CHECK RECRUITER COMPANY
|--------------------------------------------------------------------------
*/

$companyStmt = $conn->prepare("
    SELECT
        id,
        company_name,
        status
    FROM companies
    WHERE recruiter_id = ?
    ORDER BY id DESC
    LIMIT 1
");


if (!$companyStmt) {

    error_log(
        "Company approval prepare failed: " .
        $conn->error
    );

    sendResponse(
        false,
        "Unable to check company approval.",
        500
    );
}


$companyStmt->bind_param(
    "i",
    $recruiter_id
);


if (!$companyStmt->execute()) {

    error_log(
        "Company approval execute failed: " .
        $companyStmt->error
    );

    $companyStmt->close();

    sendResponse(
        false,
        "Unable to check company approval.",
        500
    );
}


$companyResult =
    $companyStmt->get_result();


/*
|--------------------------------------------------------------------------
| COMPANY NOT FOUND
|--------------------------------------------------------------------------
*/

if (
    !$companyResult ||
    $companyResult->num_rows === 0
) {

    $companyStmt->close();

    sendResponse(
        false,
        "Please add your company and wait for admin approval before posting jobs.",
        403,
        [
            "code" => "NO_COMPANY",
            "company_status" => "no_company",
            "company_id" => null,
            "company_name" => "",
            "can_post_job" => false
        ]
    );
}


$company =
    $companyResult->fetch_assoc();

$companyStmt->close();


/*
|--------------------------------------------------------------------------
| COMPANY DETAILS
|--------------------------------------------------------------------------
*/

$approvedCompanyId = intval(
    $company["id"] ?? 0
);

$approvedCompanyName = trim(
    (string) (
        $company["company_name"] ?? ""
    )
);

$companyStatus = strtolower(
    trim(
        (string) (
            $company["status"] ?? "pending"
        )
    )
);


/*
|--------------------------------------------------------------------------
| VALID COMPANY STATUS
|--------------------------------------------------------------------------
*/

$allowedCompanyStatuses = [
    "pending",
    "approved",
    "rejected",
    "suspended",
    "blocked"
];


if (
    !in_array(
        $companyStatus,
        $allowedCompanyStatuses,
        true
    )
) {

    sendResponse(
        false,
        "Your company has an invalid approval status. Please contact administrator.",
        403,
        [
            "code" => "INVALID_COMPANY_STATUS",
            "company_status" => $companyStatus,
            "company_id" => $approvedCompanyId,
            "company_name" => $approvedCompanyName,
            "can_post_job" => false
        ]
    );
}


/*
|--------------------------------------------------------------------------
| COMPANY APPROVAL CHECK
|--------------------------------------------------------------------------
*/

if ($companyStatus !== "approved") {

    $messages = [

        "pending" =>
            "Your company is waiting for admin approval. You cannot post jobs until your company is approved.",

        "rejected" =>
            "Your company application has been rejected by admin. You cannot post jobs. Please contact admin for more information.",

        "suspended" =>
            "Your company is temporarily suspended by admin. You cannot post jobs until the suspension is removed.",

        "blocked" =>
            "Your company has been blocked by admin. You cannot post jobs."
    ];


    sendResponse(
        false,
        $messages[$companyStatus]
            ?? "Your company is not approved. You cannot post jobs.",
        403,
        [
            "code" => "COMPANY_NOT_APPROVED",
            "company_status" => $companyStatus,
            "company_id" => $approvedCompanyId,
            "company_name" => $approvedCompanyName,
            "can_post_job" => false
        ]
    );
}


/*
|--------------------------------------------------------------------------
| USE DATABASE COMPANY NAME
|--------------------------------------------------------------------------
|
| Frontend se aaye company_name ko trust nahi karenge.
|
|--------------------------------------------------------------------------
*/

$company_name = $approvedCompanyName;


/*
|--------------------------------------------------------------------------
| BASIC JOB VALIDATION
|--------------------------------------------------------------------------
*/

if ($job_title === "") {

    sendResponse(
        false,
        "Job title is required.",
        400
    );
}


if (mb_strlen($job_title) > 255) {

    sendResponse(
        false,
        "Job title cannot exceed 255 characters.",
        400
    );
}


if ($company_name === "") {

    sendResponse(
        false,
        "Approved company name was not found.",
        400
    );
}


if ($location === "") {

    sendResponse(
        false,
        "Location is required.",
        400
    );
}


if ($vacancies < 1) {

    sendResponse(
        false,
        "Number of openings must be at least 1.",
        400
    );
}


if ($vacancies > 100000) {

    sendResponse(
        false,
        "Number of openings is too large.",
        400
    );
}


/*
|--------------------------------------------------------------------------
| JOB TYPE
|--------------------------------------------------------------------------
*/

$allowedJobTypes = [
    "Full Time",
    "Part Time",
    "Internship",
    "Contract",
    "Temporary",
    "Freelance"
];

if (
    $job_type === ""
) {

    $job_type = "Full Time";

}


/*
|--------------------------------------------------------------------------
| WORKPLACE TYPE
|--------------------------------------------------------------------------
*/

$allowedWorkplaceTypes = [
    "Remote",
    "Hybrid",
    "On-site",
    "Onsite",
    ""
];


if (
    !in_array(
        $workplace_type,
        $allowedWorkplaceTypes,
        true
    )
) {

    sendResponse(
        false,
        "Invalid workplace type.",
        400
    );
}


/*
|--------------------------------------------------------------------------
| DEADLINE VALIDATION
|--------------------------------------------------------------------------
*/

if ($deadline !== "") {

    $deadlineDate =
        DateTime::createFromFormat(
            "Y-m-d",
            $deadline
        );


    $deadlineErrors =
        DateTime::getLastErrors();


    /*
    |----------------------------------------------------------------------
    | PHP can return false when there are no errors.
    |----------------------------------------------------------------------
    */

    $hasDateErrors = false;


    if (is_array($deadlineErrors)) {

        $hasDateErrors =
            (
                ($deadlineErrors["warning_count"] ?? 0) > 0 ||
                ($deadlineErrors["error_count"] ?? 0) > 0
            );
    }


    /*
    |----------------------------------------------------------------------
    | Make sure formatted date exactly matches input.
    |----------------------------------------------------------------------
    */

    $isExactDate =
        (
            $deadlineDate !== false &&
            $deadlineDate->format("Y-m-d") === $deadline
        );


    if (
        $deadlineDate === false ||
        $hasDateErrors ||
        !$isExactDate
    ) {

        sendResponse(
            false,
            "Invalid deadline date. Use YYYY-MM-DD format.",
            400
        );
    }
}


/*
|--------------------------------------------------------------------------
| COMPANY LOGO
|--------------------------------------------------------------------------
*/

$company_logo = "";

$uploadedLogoPath = "";


/*
|--------------------------------------------------------------------------
| HANDLE COMPANY LOGO
|--------------------------------------------------------------------------
|
| Only available when request is multipart/form-data.
|
|--------------------------------------------------------------------------
*/

if (
    isset($_FILES["company_logo"]) &&
    is_array($_FILES["company_logo"])
) {

    $uploadError =
        intval(
            $_FILES["company_logo"]["error"]
        );


    /*
    |--------------------------------------------------------------------------
    | NO FILE
    |--------------------------------------------------------------------------
    */

    if (
        $uploadError !== UPLOAD_ERR_NO_FILE
    ) {

        /*
        |--------------------------------------------------------------------------
        | UPLOAD ERROR
        |--------------------------------------------------------------------------
        */

        if (
            $uploadError !== UPLOAD_ERR_OK
        ) {

            sendResponse(
                false,
                "Company logo upload failed.",
                400,
                [
                    "upload_error" => $uploadError
                ]
            );
        }


        /*
        |--------------------------------------------------------------------------
        | TEMP FILE
        |--------------------------------------------------------------------------
        */

        $fileTmpPath =
            $_FILES["company_logo"]["tmp_name"];


        /*
        |--------------------------------------------------------------------------
        | ORIGINAL NAME
        |--------------------------------------------------------------------------
        */

        $originalFileName =
            $_FILES["company_logo"]["name"];


        /*
        |--------------------------------------------------------------------------
        | FILE SIZE
        |--------------------------------------------------------------------------
        */

        $fileSize =
            intval(
                $_FILES["company_logo"]["size"]
            );


        /*
        |--------------------------------------------------------------------------
        | MAX SIZE = 2 MB
        |--------------------------------------------------------------------------
        */

        $maxFileSize =
            2 * 1024 * 1024;


        if ($fileSize <= 0) {

            sendResponse(
                false,
                "Invalid company logo file.",
                400
            );
        }


        if ($fileSize > $maxFileSize) {

            sendResponse(
                false,
                "Company logo must be less than 2 MB.",
                400
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CHECK TEMP FILE
        |--------------------------------------------------------------------------
        */

        if (
            !is_uploaded_file(
                $fileTmpPath
            )
        ) {

            sendResponse(
                false,
                "Invalid uploaded file.",
                400
            );
        }


        /*
        |--------------------------------------------------------------------------
        | FILE EXTENSION
        |--------------------------------------------------------------------------
        */

        $fileExtension =
            strtolower(
                pathinfo(
                    $originalFileName,
                    PATHINFO_EXTENSION
                )
            );


        /*
        |--------------------------------------------------------------------------
        | ALLOWED EXTENSIONS
        |--------------------------------------------------------------------------
        */

        $allowedExtensions = [
            "jpg",
            "jpeg",
            "png",
            "webp"
        ];


        if (
            !in_array(
                $fileExtension,
                $allowedExtensions,
                true
            )
        ) {

            sendResponse(
                false,
                "Only JPG, JPEG, PNG and WEBP logos are allowed.",
                400
            );
        }


        /*
        |--------------------------------------------------------------------------
        | MIME TYPE
        |--------------------------------------------------------------------------
        */

        $finfo =
            finfo_open(
                FILEINFO_MIME_TYPE
            );


        if (!$finfo) {

            sendResponse(
                false,
                "Unable to validate company logo.",
                500
            );
        }


        $mimeType =
            finfo_file(
                $finfo,
                $fileTmpPath
            );


        finfo_close($finfo);


        $allowedMimeTypes = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];


        if (
            !in_array(
                $mimeType,
                $allowedMimeTypes,
                true
            )
        ) {

            sendResponse(
                false,
                "Invalid company logo file type.",
                400
            );
        }


        /*
        |--------------------------------------------------------------------------
        | MAKE UPLOAD DIRECTORY
        |--------------------------------------------------------------------------
        |
        | Current file:
        | api/jobs/create.php
        |
        | ../../uploads/
        |
        | points to:
        | job-portal-api/uploads/
        |
        |--------------------------------------------------------------------------
        */

        $uploadFileDir =
            dirname(__DIR__, 2) .
            DIRECTORY_SEPARATOR .
            "uploads" .
            DIRECTORY_SEPARATOR;


        /*
        |--------------------------------------------------------------------------
        | CREATE DIRECTORY
        |--------------------------------------------------------------------------
        */

        if (
            !is_dir(
                $uploadFileDir
            )
        ) {

            if (
                !mkdir(
                    $uploadFileDir,
                    0777,
                    true
                )
            ) {

                sendResponse(
                    false,
                    "Unable to create upload directory.",
                    500
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | GENERATE UNIQUE NAME
        |--------------------------------------------------------------------------
        */

        $newFileName =
            "company_" .
            bin2hex(
                random_bytes(16)
            ) .
            "." .
            $fileExtension;


        /*
        |--------------------------------------------------------------------------
        | DESTINATION
        |--------------------------------------------------------------------------
        */

        $destinationPath =
            $uploadFileDir .
            $newFileName;


        /*
        |--------------------------------------------------------------------------
        | MOVE FILE
        |--------------------------------------------------------------------------
        */

        if (
            !move_uploaded_file(
                $fileTmpPath,
                $destinationPath
            )
        ) {

            sendResponse(
                false,
                "Failed to upload company logo.",
                500
            );
        }


        /*
        |--------------------------------------------------------------------------
        | SAVE PATH
        |--------------------------------------------------------------------------
        */

        $company_logo =
            "uploads/" .
            $newFileName;


        /*
        |--------------------------------------------------------------------------
        | REMEMBER PHYSICAL FILE
        |--------------------------------------------------------------------------
        |
        | If database insertion fails, this file will be deleted.
        |
        |--------------------------------------------------------------------------
        */

        $uploadedLogoPath =
            $destinationPath;
    }
}


/*
|--------------------------------------------------------------------------
| DATABASE TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();


try {

    /*
    |--------------------------------------------------------------------------
    | INSERT JOB
    |--------------------------------------------------------------------------
    |
    | Actual jobs table fields:
    |
    | recruiter_id
    | job_title
    | company_name
    | company_logo
    | location
    | vacancies
    | job_type
    | workplace_type
    | category
    | education
    | experience
    | salary
    | description
    | responsibilities
    | requirements
    | skills
    | deadline
    | status
    |
    |--------------------------------------------------------------------------
    */

    $sql = "
        INSERT INTO jobs
        (
            recruiter_id,
            job_title,
            company_name,
            company_logo,
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
            status
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'active'
        )
    ";


    /*
    |--------------------------------------------------------------------------
    | PREPARE
    |--------------------------------------------------------------------------
    */

    $stmt =
        $conn->prepare(
            $sql
        );


    if (!$stmt) {

        throw new Exception(
            "Database query preparation failed: " .
            $conn->error
        );
    }


    /*
    |--------------------------------------------------------------------------
    | BIND PARAMETERS
    |--------------------------------------------------------------------------
    |
    | 17 parameters:
    |
    | 1  recruiter_id       = i
    | 2  job_title          = s
    | 3  company_name       = s
    | 4  company_logo       = s
    | 5  location           = s
    | 6  vacancies          = i
    | 7  job_type           = s
    | 8  workplace_type     = s
    | 9  category           = s
    | 10 education          = s
    | 11 experience         = s
    | 12 salary             = s
    | 13 description        = s
    | 14 responsibilities   = s
    | 15 requirements       = s
    | 16 skills             = s
    | 17 deadline           = s
    |
    |--------------------------------------------------------------------------
    */

    $stmt->bind_param(
        "issssisssssssssss",
        $recruiter_id,
        $job_title,
        $company_name,
        $company_logo,
        $location,
        $vacancies,
        $job_type,
        $workplace_type,
        $category,
        $education,
        $experience,
        $salary,
        $description,
        $responsibilities,
        $requirements,
        $skills,
        $deadline
    );


    /*
    |--------------------------------------------------------------------------
    | EXECUTE
    |--------------------------------------------------------------------------
    */

    if (!$stmt->execute()) {

        throw new Exception(
            "Failed to insert job: " .
            $stmt->error
        );
    }


    /*
    |--------------------------------------------------------------------------
    | JOB ID
    |--------------------------------------------------------------------------
    */

    $jobId =
        intval(
            $stmt->insert_id
        );


    /*
    |--------------------------------------------------------------------------
    | CLOSE STATEMENT
    |--------------------------------------------------------------------------
    */

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $conn->commit();


    /*
    |--------------------------------------------------------------------------
    | BUILD API BASE URL
    |--------------------------------------------------------------------------
    */

    $scheme =
        (
            !empty($_SERVER["HTTPS"]) &&
            $_SERVER["HTTPS"] !== "off"
        )
        ? "https"
        : "http";


    $host =
        $_SERVER["HTTP_HOST"]
        ?? "localhost";


    /*
    |--------------------------------------------------------------------------
    | LOGO URL
    |--------------------------------------------------------------------------
    */

    $logoUrl = "";


    if (
        !empty($company_logo)
    ) {

        $logoUrl =
            $scheme .
            "://" .
            $host .
            "/job_portal/job-portal-api/" .
            ltrim(
                $company_logo,
                "/"
            );
    }


    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    sendResponse(
        true,
        "Job posted successfully.",
        201,
        [
            "jobId" =>
                $jobId,

            "job_id" =>
                $jobId,

            "recruiter_id" =>
                $recruiter_id,

            "company_id" =>
                $approvedCompanyId,

            "company_status" =>
                "approved",

            "can_post_job" =>
                true,

            "job_title" =>
                $job_title,

            "company_name" =>
                $company_name,

            "vacancies" =>
                $vacancies,

            "status" =>
                "active",

            "company_logo" =>
                $company_logo,

            "company_logo_url" =>
                $logoUrl
        ]
    );


} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    $conn->rollback();


    /*
    |--------------------------------------------------------------------------
    | DELETE UPLOADED FILE IF DB INSERT FAILED
    |--------------------------------------------------------------------------
    */

    if (
        !empty($uploadedLogoPath) &&
        file_exists($uploadedLogoPath)
    ) {

        @unlink(
            $uploadedLogoPath
        );
    }


    /*
    |--------------------------------------------------------------------------
    | LOG REAL ERROR
    |--------------------------------------------------------------------------
    */

    error_log(
        "Job posting error: " .
        $e->getMessage()
    );


    /*
    |--------------------------------------------------------------------------
    | SAFE ERROR RESPONSE
    |--------------------------------------------------------------------------
    */

    sendResponse(
        false,
        "Unable to post job. Please try again.",
        500
    );
}


/*
|--------------------------------------------------------------------------
| CLOSE DATABASE
|--------------------------------------------------------------------------
*/

$conn->close();

?>