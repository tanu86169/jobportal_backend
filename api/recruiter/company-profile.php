<?php

/*
|--------------------------------------------------------------------------
| RECRUITER COMPANY PROFILE API
|--------------------------------------------------------------------------
| GET  -> Get company profile
| POST -> Create / Update company profile
|
| Main company data:
| companies
|
| Recruiter contact:
| users
|
| Additional profile:
| company_profiles
|--------------------------------------------------------------------------
*/

require_once "../../config/database.php";
require_once "../../config/cors.php";

header("Content-Type: application/json; charset=UTF-8");

/*
|--------------------------------------------------------------------------
| METHOD
|--------------------------------------------------------------------------
*/

$method = strtoupper($_SERVER["REQUEST_METHOD"]);

/*
|--------------------------------------------------------------------------
| RESPONSE HELPER
|--------------------------------------------------------------------------
*/

function sendResponse($statusCode, $payload)
{
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

if ($method === "OPTIONS") {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| ALLOWED COMPANY STATUS
|--------------------------------------------------------------------------
*/

$allowedStatuses = [
    "pending",
    "approved",
    "rejected",
    "suspended",
    "blocked"
];

/*
|--------------------------------------------------------------------------
| COMPANY STATUS MESSAGE
|--------------------------------------------------------------------------
*/

function getCompanyStatusMessage($status)
{
    $messages = [

        "pending" =>
            "Your company is waiting for admin approval. You cannot post jobs until your company is approved.",

        "approved" =>
            "Your company has been approved by admin. You can now post jobs.",

        "rejected" =>
            "Your company application has been rejected by admin. You cannot post jobs.",

        "suspended" =>
            "Your company is temporarily suspended by admin. You cannot post jobs until the suspension is removed.",

        "blocked" =>
            "Your company has been blocked by admin. You cannot post jobs."
    ];

    return $messages[$status]
        ?? "Your company is not approved. You cannot post jobs.";
}

/*
|--------------------------------------------------------------------------
| CLEAN STRING
|--------------------------------------------------------------------------
*/

function cleanString($value)
{
    if ($value === null) {
        return "";
    }

    return trim((string) $value);
}

/*
|--------------------------------------------------------------------------
| GET VALUE WITH ALIASES
|--------------------------------------------------------------------------
|
| Supports:
| company_size / companySize
| founded_year / foundedYear
| recruiter_id / recruiterId / userId / user_id
|--------------------------------------------------------------------------
*/

function getRequestValue($data, $keys, $default = "")
{
    foreach ($keys as $key) {

        if (
            isset($data[$key]) &&
            $data[$key] !== ""
        ) {
            return $data[$key];
        }
    }

    return $default;
}

/*
|--------------------------------------------------------------------------
| GET RECRUITER ID
|--------------------------------------------------------------------------
*/

function getRecruiterIdFromRequest()
{
    $possibleIds = [
        $_GET["recruiterId"] ?? null,
        $_GET["recruiter_id"] ?? null,
        $_GET["userId"] ?? null,
        $_GET["user_id"] ?? null
    ];

    foreach ($possibleIds as $id) {

        if (
            $id !== null &&
            $id !== "" &&
            is_numeric($id)
        ) {
            $id = (int) $id;

            if ($id > 0) {
                return $id;
            }
        }
    }

    return 0;
}

/*
|--------------------------------------------------------------------------
| LOGO UPLOAD
|--------------------------------------------------------------------------
*/

function uploadCompanyLogo($file)
{
    if (
        !isset($file) ||
        !is_array($file) ||
        !isset($file["error"])
    ) {
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | NO FILE
    |--------------------------------------------------------------------------
    */

    if ($file["error"] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | UPLOAD ERROR
    |--------------------------------------------------------------------------
    */

    if ($file["error"] !== UPLOAD_ERR_OK) {

        throw new Exception(
            "Company logo upload failed. Upload error code: " .
            $file["error"]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MAX SIZE 2MB
    |--------------------------------------------------------------------------
    */

    $maxSize = 2 * 1024 * 1024;

    if ((int) $file["size"] > $maxSize) {

        throw new Exception(
            "Company logo must be smaller than 2MB."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TEMP FILE
    |--------------------------------------------------------------------------
    */

    if (
        !isset($file["tmp_name"]) ||
        !is_uploaded_file($file["tmp_name"])
    ) {

        throw new Exception(
            "Invalid uploaded logo file."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MIME VALIDATION
    |--------------------------------------------------------------------------
    */

    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if (!$finfo) {

        throw new Exception(
            "Unable to validate logo file."
        );
    }

    $mimeType = finfo_file(
        $finfo,
        $file["tmp_name"]
    );

    finfo_close($finfo);

    $allowedMimeTypes = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp",
        "image/gif"  => "gif"
    ];

    if (!isset($allowedMimeTypes[$mimeType])) {

        throw new Exception(
            "Invalid logo format. Only JPG, PNG, WEBP and GIF are allowed."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | IMAGE VALIDATION
    |--------------------------------------------------------------------------
    */

    $imageInfo = @getimagesize(
        $file["tmp_name"]
    );

    if ($imageInfo === false) {

        throw new Exception(
            "Uploaded logo is not a valid image."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPLOAD DIRECTORY
    |--------------------------------------------------------------------------
    */

    $uploadDirectory =
        dirname(__DIR__, 2) .
        DIRECTORY_SEPARATOR .
        "uploads" .
        DIRECTORY_SEPARATOR .
        "company";

    if (!is_dir($uploadDirectory)) {

        if (!mkdir(
            $uploadDirectory,
            0775,
            true
        )) {

            throw new Exception(
                "Unable to create company logo upload directory."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UNIQUE FILE NAME
    |--------------------------------------------------------------------------
    */

    $extension =
        $allowedMimeTypes[$mimeType];

    $fileName =
        "company_" .
        bin2hex(random_bytes(10)) .
        "_" .
        time() .
        "." .
        $extension;

    $destination =
        $uploadDirectory .
        DIRECTORY_SEPARATOR .
        $fileName;

    /*
    |--------------------------------------------------------------------------
    | MOVE FILE
    |--------------------------------------------------------------------------
    */

    if (!move_uploaded_file(
        $file["tmp_name"],
        $destination
    )) {

        throw new Exception(
            "Unable to save company logo."
        );
    }

    return "uploads/company/" . $fileName;
}

/*
|--------------------------------------------------------------------------
| GET COMPANY PROFILE
|--------------------------------------------------------------------------
*/

if ($method === "GET") {

    $recruiterId =
        getRecruiterIdFromRequest();

    /*
    |--------------------------------------------------------------------------
    | VALIDATE ID
    |--------------------------------------------------------------------------
    */

    if ($recruiterId <= 0) {

        sendResponse(400, [
            "success" => false,
            "message" =>
                "Valid recruiter ID is required."
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GET USER
    |--------------------------------------------------------------------------
    */

    $userStmt = $conn->prepare(
        "SELECT
            id,
            name,
            email,
            phone,
            role
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    if (!$userStmt) {

        sendResponse(500, [
            "success" => false,
            "message" =>
                "Failed to prepare user query.",
            "error" => $conn->error
        ]);
    }

    $userStmt->bind_param(
        "i",
        $recruiterId
    );

    $userStmt->execute();

    $userResult =
        $userStmt->get_result();

    $user =
        $userResult->fetch_assoc();

    $userStmt->close();

    /*
    |--------------------------------------------------------------------------
    | USER NOT FOUND
    |--------------------------------------------------------------------------
    */

    if (!$user) {

        sendResponse(404, [
            "success" => false,
            "message" =>
                "Recruiter does not exist."
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ROLE CHECK
    |--------------------------------------------------------------------------
    */

    if (
        !isset($user["role"]) ||
        strtolower(trim($user["role"])) !== "recruiter"
    ) {

        sendResponse(403, [
            "success" => false,
            "message" =>
                "Only recruiters can access company profile."
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GET COMPANY
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    | industry
    | company_size
    | founded_year
    | are explicitly selected here.
    |--------------------------------------------------------------------------
    */

    $companyStmt = $conn->prepare(
        "SELECT
            id,
            recruiter_id,
            company_name,
            logo,
            description,
            website,
            location,
            industry,
            company_size,
            founded_year,
            status,
            created_at
         FROM companies
         WHERE recruiter_id = ?
         ORDER BY id DESC
         LIMIT 1"
    );

    if (!$companyStmt) {

        sendResponse(500, [
            "success" => false,
            "message" =>
                "Failed to prepare company query.",
            "error" => $conn->error
        ]);
    }

    $companyStmt->bind_param(
        "i",
        $recruiterId
    );

    $companyStmt->execute();

    $companyResult =
        $companyStmt->get_result();

    $company =
        $companyResult->fetch_assoc();

    $companyStmt->close();

    /*
    |--------------------------------------------------------------------------
    | NO COMPANY
    |--------------------------------------------------------------------------
    */

    if (!$company) {

        sendResponse(200, [

            "success" => true,

            "message" =>
                "Company profile not created yet.",

            "data" => [

                "id" => null,

                "company_id" => null,

                "recruiter_id" =>
                    $recruiterId,

                "company_name" => "",

                "logo" => null,

                "description" => "",

                "email" =>
                    $user["email"] ?? "",

                "phone" =>
                    $user["phone"] ?? "",

                "location" => "",

                "website" => "",

                "industry" => "",

                "company_size" => "",

                "founded_year" => "",

                "status" => "pending",

                "company_status" =>
                    "pending",

                "can_post_job" => false,

                "company_message" =>
                    "Please add your company information and wait for admin approval before posting jobs.",

                "created_at" => null
            ]
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE STATUS
    |--------------------------------------------------------------------------
    */

    $status =
        strtolower(
            trim(
                (string) (
                    $company["status"]
                    ?? "pending"
                )
            )
        );

    if (
        !in_array(
            $status,
            $GLOBALS["allowedStatuses"],
            true
        )
    ) {
        $status = "pending";
    }

    /*
    |--------------------------------------------------------------------------
    | CAN POST
    |--------------------------------------------------------------------------
    */

    $canPostJob =
        ($status === "approved");

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    sendResponse(200, [

        "success" => true,

        "message" =>
            "Company profile loaded successfully.",

        "data" => [

            "id" =>
                (int) $company["id"],

            "company_id" =>
                (int) $company["id"],

            "recruiter_id" =>
                (int) $company["recruiter_id"],

            "company_name" =>
                $company["company_name"] ?? "",

            "logo" =>
                $company["logo"] ?? null,

            "description" =>
                $company["description"] ?? "",

            "email" =>
                $user["email"] ?? "",

            "phone" =>
                $user["phone"] ?? "",

            "location" =>
                $company["location"] ?? "",

            "website" =>
                $company["website"] ?? "",

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT COMPANY DETAILS
            |--------------------------------------------------------------------------
            */

            "industry" =>
                $company["industry"] ?? "",

            "company_size" =>
                $company["company_size"] ?? "",

            "founded_year" =>
                $company["founded_year"] ?? "",

            "status" =>
                $status,

            "company_status" =>
                $status,

            "can_post_job" =>
                $canPostJob,

            "company_message" =>
                getCompanyStatusMessage($status),

            "created_at" =>
                $company["created_at"] ?? null
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| POST - CREATE / UPDATE COMPANY
|--------------------------------------------------------------------------
*/

if ($method === "POST") {

    /*
    |--------------------------------------------------------------------------
    | CONTENT TYPE
    |--------------------------------------------------------------------------
    */

    $contentType =
        $_SERVER["CONTENT_TYPE"] ?? "";

    $isMultipart =
        stripos(
            $contentType,
            "multipart/form-data"
        ) !== false;

    /*
    |--------------------------------------------------------------------------
    | READ REQUEST
    |--------------------------------------------------------------------------
    */

    if ($isMultipart) {

        $data = $_POST;

    } else {

        $rawBody =
            file_get_contents("php://input");

        $data =
            json_decode(
                $rawBody,
                true
            );

        if (!is_array($data)) {

            sendResponse(400, [
                "success" => false,
                "message" =>
                    "Invalid JSON request body."
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RECRUITER ID
    |--------------------------------------------------------------------------
    */

    $recruiterId = (int) getRequestValue(
        $data,
        [
            "recruiter_id",
            "recruiterId",
            "userId",
            "user_id"
        ],
        0
    );

    /*
    |--------------------------------------------------------------------------
    | COMPANY DATA
    |--------------------------------------------------------------------------
    */

    $companyName = cleanString(
        getRequestValue(
            $data,
            [
                "company_name",
                "companyName"
            ]
        )
    );

    $description = cleanString(
        getRequestValue(
            $data,
            [
                "description",
                "company_description",
                "companyDescription"
            ]
        )
    );

    $email = cleanString(
        getRequestValue(
            $data,
            [
                "email"
            ]
        )
    );

    $phone = cleanString(
        getRequestValue(
            $data,
            [
                "phone"
            ]
        )
    );

    $location = cleanString(
        getRequestValue(
            $data,
            [
                "location"
            ]
        )
    );

    $website = cleanString(
        getRequestValue(
            $data,
            [
                "website",
                "company_website",
                "companyWebsite"
            ]
        )
    );

    /*
    |--------------------------------------------------------------------------
    | INDUSTRY
    |--------------------------------------------------------------------------
    */

    $industry = cleanString(
        getRequestValue(
            $data,
            [
                "industry",
                "company_industry",
                "companyIndustry"
            ]
        )
    );

    /*
    |--------------------------------------------------------------------------
    | COMPANY SIZE
    |--------------------------------------------------------------------------
    |
    | Supports both:
    | company_size
    | companySize
    |--------------------------------------------------------------------------
    */

    $companySize = cleanString(
        getRequestValue(
            $data,
            [
                "company_size",
                "companySize",
                "size"
            ]
        )
    );

    /*
    |--------------------------------------------------------------------------
    | FOUNDED YEAR
    |--------------------------------------------------------------------------
    |
    | Supports both:
    | founded_year
    | foundedYear
    |--------------------------------------------------------------------------
    */

    $foundedYear = cleanString(
        getRequestValue(
            $data,
            [
                "founded_year",
                "foundedYear",
                "yearFounded"
            ]
        )
    );

    /*
    |--------------------------------------------------------------------------
    | VALIDATE RECRUITER
    |--------------------------------------------------------------------------
    */

    if ($recruiterId <= 0) {

        sendResponse(400, [
            "success" => false,
            "message" =>
                "Valid recruiter_id is required."
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE COMPANY NAME
    |--------------------------------------------------------------------------
    */

    if ($companyName === "") {

        sendResponse(400, [
            "success" => false,
            "message" =>
                "Company name is required."
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DESCRIPTION LIMIT
    |--------------------------------------------------------------------------
    */

    if (strlen($description) > 5000) {

        sendResponse(400, [
            "success" => false,
            "message" =>
                "Company description cannot exceed 5000 characters."
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | EMAIL
    |--------------------------------------------------------------------------
    */

    if (
        $email !== "" &&
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        sendResponse(400, [
            "success" => false,
            "message" =>
                "Please enter a valid email address."
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | WEBSITE
    |--------------------------------------------------------------------------
    */

    if ($website !== "") {

        if (
            !preg_match(
                "/^https?:\/\/.+/i",
                $website
            )
        ) {

            sendResponse(400, [
                "success" => false,
                "message" =>
                    "Website must start with http:// or https://"
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FOUNDED YEAR
    |--------------------------------------------------------------------------
    */

    if ($foundedYear !== "") {

        if (
            !preg_match(
                "/^\d{4}$/",
                $foundedYear
            )
        ) {

            sendResponse(400, [
                "success" => false,
                "message" =>
                    "Founded year must be a valid 4-digit year."
            ]);
        }

        $currentYear =
            (int) date("Y");

        if (
            (int) $foundedYear < 1800 ||
            (int) $foundedYear > $currentYear
        ) {

            sendResponse(400, [
                "success" => false,
                "message" =>
                    "Please enter a valid founded year."
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | LOGO
    |--------------------------------------------------------------------------
    */

    $newLogoPath = null;

    try {

        /*
        |--------------------------------------------------------------------------
        | TRANSACTION
        |--------------------------------------------------------------------------
        */

        $conn->begin_transaction();

        /*
        |--------------------------------------------------------------------------
        | CHECK RECRUITER
        |--------------------------------------------------------------------------
        */

        $userStmt = $conn->prepare(
            "SELECT
                id,
                name,
                email,
                phone,
                role
             FROM users
             WHERE id = ?
             LIMIT 1"
        );

        if (!$userStmt) {

            throw new Exception(
                "Failed to prepare recruiter validation query: " .
                $conn->error
            );
        }

        $userStmt->bind_param(
            "i",
            $recruiterId
        );

        $userStmt->execute();

        $userResult =
            $userStmt->get_result();

        $user =
            $userResult->fetch_assoc();

        $userStmt->close();

        /*
        |--------------------------------------------------------------------------
        | USER NOT FOUND
        |--------------------------------------------------------------------------
        */

        if (!$user) {

            throw new Exception(
                "Recruiter ID " .
                $recruiterId .
                " does not exist in users table."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ROLE
        |--------------------------------------------------------------------------
        */

        if (
            !isset($user["role"]) ||
            strtolower(trim($user["role"])) !== "recruiter"
        ) {

            throw new Exception(
                "Only recruiters can create or update company profile."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | FIND EXISTING COMPANY
        |--------------------------------------------------------------------------
        */

        $companyCheckStmt =
            $conn->prepare(
                "SELECT
                    id,
                    logo,
                    status
                 FROM companies
                 WHERE recruiter_id = ?
                 ORDER BY id DESC
                 LIMIT 1"
            );

        if (!$companyCheckStmt) {

            throw new Exception(
                "Failed to prepare company lookup: " .
                $conn->error
            );
        }

        $companyCheckStmt->bind_param(
            "i",
            $recruiterId
        );

        $companyCheckStmt->execute();

        $companyResult =
            $companyCheckStmt->get_result();

        $existingCompany =
            $companyResult->fetch_assoc();

        $companyCheckStmt->close();

        /*
        |--------------------------------------------------------------------------
        | EXISTING COMPANY DATA
        |--------------------------------------------------------------------------
        */

        if ($existingCompany) {

            $companyId =
                (int) $existingCompany["id"];

            $status =
                strtolower(
                    trim(
                        (string) (
                            $existingCompany["status"]
                            ?? "pending"
                        )
                    )
                );

            if (
                !in_array(
                    $status,
                    $GLOBALS["allowedStatuses"],
                    true
                )
            ) {
                $status = "pending";
            }

            $oldLogo =
                $existingCompany["logo"] ?? null;

        } else {

            $companyId = 0;

            /*
            | New company always starts as pending.
            */

            $status = "pending";

            $oldLogo = null;
        }

        /*
        |--------------------------------------------------------------------------
        | LOGO UPLOAD
        |--------------------------------------------------------------------------
        */

        if (
            isset($_FILES["logo"]) &&
            is_array($_FILES["logo"])
        ) {

            $newLogoPath =
                uploadCompanyLogo(
                    $_FILES["logo"]
                );
        }

        /*
        |--------------------------------------------------------------------------
        | JSON LOGO
        |--------------------------------------------------------------------------
        */

        if (
            $newLogoPath === null &&
            !$isMultipart &&
            isset($data["logo"])
        ) {

            $logoValue =
                cleanString(
                    $data["logo"]
                );

            if ($logoValue !== "") {
                $newLogoPath =
                    $logoValue;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | FINAL LOGO
        |--------------------------------------------------------------------------
        */

        $finalLogo =
            $newLogoPath !== null
                ? $newLogoPath
                : $oldLogo;

        /*
        |--------------------------------------------------------------------------
        | UPDATE EXISTING COMPANY
        |--------------------------------------------------------------------------
        */

        if ($existingCompany) {

            $updateCompanyStmt =
                $conn->prepare(
                    "UPDATE companies
                     SET
                        company_name = ?,
                        logo = ?,
                        description = ?,
                        website = ?,
                        location = ?,
                        industry = ?,
                        company_size = ?,
                        founded_year = ?
                     WHERE
                        id = ?
                        AND recruiter_id = ?"
                );

            if (!$updateCompanyStmt) {

                throw new Exception(
                    "Failed to prepare company update: " .
                    $conn->error
                );
            }

            /*
            |--------------------------------------------------------------------------
            | 8 strings + 2 integers
            |--------------------------------------------------------------------------
            */

            $updateCompanyStmt->bind_param(
                "ssssssssii",
                $companyName,
                $finalLogo,
                $description,
                $website,
                $location,
                $industry,
                $companySize,
                $foundedYear,
                $companyId,
                $recruiterId
            );

            if (
                !$updateCompanyStmt->execute()
            ) {

                $error =
                    $updateCompanyStmt->error;

                $updateCompanyStmt->close();

                throw new Exception(
                    "Failed to update company: " .
                    $error
                );
            }

            $updateCompanyStmt->close();

            $companyAction = "updated";

        } else {

            /*
            |--------------------------------------------------------------------------
            | CREATE NEW COMPANY
            |--------------------------------------------------------------------------
            */

            $insertCompanyStmt =
                $conn->prepare(
                    "INSERT INTO companies
                    (
                        recruiter_id,
                        company_name,
                        logo,
                        description,
                        website,
                        location,
                        industry,
                        company_size,
                        founded_year,
                        status
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

            if (!$insertCompanyStmt) {

                throw new Exception(
                    "Failed to prepare company insert: " .
                    $conn->error
                );
            }

            /*
            |--------------------------------------------------------------------------
            | 1 integer + 9 strings
            |--------------------------------------------------------------------------
            */

            $insertCompanyStmt->bind_param(
                "isssssssss",
                $recruiterId,
                $companyName,
                $finalLogo,
                $description,
                $website,
                $location,
                $industry,
                $companySize,
                $foundedYear,
                $status
            );

            if (
                !$insertCompanyStmt->execute()
            ) {

                $error =
                    $insertCompanyStmt->error;

                $insertCompanyStmt->close();

                throw new Exception(
                    "Failed to create company: " .
                    $error
                );
            }

            $companyId =
                (int) $insertCompanyStmt->insert_id;

            $insertCompanyStmt->close();

            $companyAction = "created";
        }

        /*
        |--------------------------------------------------------------------------
        | COMPANY PROFILES
        |--------------------------------------------------------------------------
        */

        $profileCheckStmt =
            $conn->prepare(
                "SELECT id
                 FROM company_profiles
                 WHERE recruiter_id = ?
                 LIMIT 1"
            );

        if (!$profileCheckStmt) {

            throw new Exception(
                "Failed to prepare company profile lookup: " .
                $conn->error
            );
        }

        $profileCheckStmt->bind_param(
            "i",
            $recruiterId
        );

        $profileCheckStmt->execute();

        $profileResult =
            $profileCheckStmt->get_result();

        $existingProfile =
            $profileResult->fetch_assoc();

        $profileCheckStmt->close();

        /*
        |--------------------------------------------------------------------------
        | UPDATE PROFILE
        |--------------------------------------------------------------------------
        */

        if ($existingProfile) {

            $profileId =
                (int) $existingProfile["id"];

            $profileStmt =
                $conn->prepare(
                    "UPDATE company_profiles
                     SET
                        company_name = ?,
                        email = ?,
                        phone = ?,
                        location = ?,
                        website = ?
                     WHERE
                        id = ?
                        AND recruiter_id = ?"
                );

            if (!$profileStmt) {

                throw new Exception(
                    "Failed to prepare company profile update: " .
                    $conn->error
                );
            }

            $profileStmt->bind_param(
                "sssssii",
                $companyName,
                $email,
                $phone,
                $location,
                $website,
                $profileId,
                $recruiterId
            );

            if (
                !$profileStmt->execute()
            ) {

                $error =
                    $profileStmt->error;

                $profileStmt->close();

                throw new Exception(
                    "Failed to update company profile: " .
                    $error
                );
            }

            $profileStmt->close();

        } else {

            /*
            |--------------------------------------------------------------------------
            | CREATE PROFILE
            |--------------------------------------------------------------------------
            */

            $profileStmt =
                $conn->prepare(
                    "INSERT INTO company_profiles
                    (
                        recruiter_id,
                        company_name,
                        email,
                        phone,
                        location,
                        website
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?)"
                );

            if (!$profileStmt) {

                throw new Exception(
                    "Failed to prepare company profile insert: " .
                    $conn->error
                );
            }

            $profileStmt->bind_param(
                "isssss",
                $recruiterId,
                $companyName,
                $email,
                $phone,
                $location,
                $website
            );

            if (
                !$profileStmt->execute()
            ) {

                $error =
                    $profileStmt->error;

                $profileStmt->close();

                throw new Exception(
                    "Failed to create company profile: " .
                    $error
                );
            }

            $profileStmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE USER EMAIL / PHONE
        |--------------------------------------------------------------------------
        */

        $finalEmail =
            $email !== ""
                ? $email
                : ($user["email"] ?? "");

        $finalPhone =
            $phone !== ""
                ? $phone
                : ($user["phone"] ?? "");

        $updateUserStmt =
            $conn->prepare(
                "UPDATE users
                 SET
                    email = ?,
                    phone = ?
                 WHERE
                    id = ?
                    AND role = 'recruiter'"
            );

        if (!$updateUserStmt) {

            throw new Exception(
                "Failed to prepare user update: " .
                $conn->error
            );
        }

        $updateUserStmt->bind_param(
            "ssi",
            $finalEmail,
            $finalPhone,
            $recruiterId
        );

        if (
            !$updateUserStmt->execute()
        ) {

            $error =
                $updateUserStmt->error;

            $updateUserStmt->close();

            throw new Exception(
                "Failed to update recruiter contact information: " .
                $error
            );
        }

        $updateUserStmt->close();

        /*
        |--------------------------------------------------------------------------
        | COMMIT
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        /*
        |--------------------------------------------------------------------------
        | CAN POST JOB
        |--------------------------------------------------------------------------
        */

        $canPostJob =
            ($status === "approved");

        /*
        |--------------------------------------------------------------------------
        | SUCCESS MESSAGE
        |--------------------------------------------------------------------------
        */

        if ($companyAction === "created") {

            $successMessage =
                "Company profile created successfully. Your company is now waiting for admin approval.";

        } else {

            $successMessage =
                "Company profile updated successfully.";
        }

        /*
        |--------------------------------------------------------------------------
        | FINAL RESPONSE
        |--------------------------------------------------------------------------
        */

        sendResponse(200, [

            "success" => true,

            "message" =>
                $successMessage,

            "data" => [

                "id" =>
                    $companyId,

                "company_id" =>
                    $companyId,

                "recruiter_id" =>
                    $recruiterId,

                "company_name" =>
                    $companyName,

                "logo" =>
                    $finalLogo,

                "description" =>
                    $description,

                "email" =>
                    $finalEmail,

                "phone" =>
                    $finalPhone,

                "location" =>
                    $location,

                /*
                |--------------------------------------------------------------------------
                | IMPORTANT
                |--------------------------------------------------------------------------
                */

                "website" =>
                    $website,

                "industry" =>
                    $industry,

                "company_size" =>
                    $companySize,

                "founded_year" =>
                    $foundedYear,

                "status" =>
                    $status,

                "company_status" =>
                    $status,

                "can_post_job" =>
                    $canPostJob,

                "company_message" =>
                    getCompanyStatusMessage($status),

                "action" =>
                    $companyAction
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
            "Company profile save error: " .
            $e->getMessage()
        );

        sendResponse(500, [

            "success" => false,

            "message" =>
                $e->getMessage()
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| METHOD NOT ALLOWED
|--------------------------------------------------------------------------
*/

header(
    "Allow: GET, POST, OPTIONS"
);

sendResponse(405, [

    "success" => false,

    "message" =>
        "Method not allowed."
]);