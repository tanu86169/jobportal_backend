<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Vary: Origin");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST request allowed"
    ]);

    exit;
}

try {

    $userId =
        isset($_POST["user_id"])
            ? (int)$_POST["user_id"]
            : 0;

    if ($userId <= 0) {
        throw new Exception(
            "Invalid user ID."
        );
    }

    if (
        !isset($_FILES["resume"]) ||
        $_FILES["resume"]["error"] !== UPLOAD_ERR_OK
    ) {
        throw new Exception(
            "Please select a resume."
        );
    }

    $file =
        $_FILES["resume"];

    if ($file["size"] > 5 * 1024 * 1024) {
        throw new Exception(
            "Resume must be less than 5 MB."
        );
    }

    $originalName =
        $file["name"];

    $extension =
        strtolower(
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
        throw new Exception(
            "Only PDF, DOC and DOCX files are allowed."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK USER
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT id
        FROM users
        WHERE id = ?
        AND role = 'candidate'
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $userId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if (!$result->fetch_assoc()) {

        $stmt->close();

        throw new Exception(
            "Candidate user not found."
        );
    }

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | GET OLD RESUME
    |--------------------------------------------------------------------------
    */

    $oldResume = "";

    $stmt = $conn->prepare("
        SELECT resume
        FROM candidates
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $userId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $oldRow =
        $result->fetch_assoc();

    if ($oldRow) {
        $oldResume =
            $oldRow["resume"] ?? "";
    }

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | DIRECTORY
    |--------------------------------------------------------------------------
    */

    $baseDirectory =
        dirname(
            __DIR__,
            2
        );

    $uploadDirectory =
        $baseDirectory .
        DIRECTORY_SEPARATOR .
        "uploads" .
        DIRECTORY_SEPARATOR .
        "resumes";

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
            throw new Exception(
                "Unable to create resume upload directory."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FILE NAME
    |--------------------------------------------------------------------------
    */

    $newFileName =
        "resume_" .
        $userId .
        "_" .
        time() .
        "_" .
        bin2hex(
            random_bytes(4)
        ) .
        "." .
        $extension;

    $targetPath =
        $uploadDirectory .
        DIRECTORY_SEPARATOR .
        $newFileName;

    /*
    |--------------------------------------------------------------------------
    | MOVE
    |--------------------------------------------------------------------------
    */

    if (
        !move_uploaded_file(
            $file["tmp_name"],
            $targetPath
        )
    ) {
        throw new Exception(
            "Failed to save resume file."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DB PATH
    |--------------------------------------------------------------------------
    */

    $relativePath =
        "uploads/resumes/" .
        $newFileName;

    /*
    |--------------------------------------------------------------------------
    | UPDATE PROFILE
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE candidates
        SET resume = ?
        WHERE user_id = ?
    ");

    if (!$stmt) {
        throw new Exception(
            $conn->error
        );
    }

    $stmt->bind_param(
        "si",
        $relativePath,
        $userId
    );

    if (!$stmt->execute()) {
        throw new Exception(
            $stmt->error
        );
    }

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | DELETE OLD FILE
    |--------------------------------------------------------------------------
    */

    if (
        $oldResume &&
        strpos(
            $oldResume,
            "uploads/resumes/"
        ) !== false
    ) {

        $oldFileName =
            basename(
                parse_url(
                    $oldResume,
                    PHP_URL_PATH
                )
            );

        $oldFilePath =
            $uploadDirectory .
            DIRECTORY_SEPARATOR .
            $oldFileName;

        if (
            is_file(
                $oldFilePath
            )
        ) {
            @unlink(
                $oldFilePath
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | URL
    |--------------------------------------------------------------------------
    */

    $fileUrl =
        "http://localhost/job_portal/job-portal-api/" .
        $relativePath;

    echo json_encode([
        "success" => true,

        "message" =>
            "Resume uploaded successfully.",

        "resume" =>
            $fileUrl,

        "url" =>
            $fileUrl,

        "file_url" =>
            $fileUrl,

        "path" =>
            $relativePath,

        "file_name" =>
            $newFileName
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" =>
            "Resume upload failed.",
        "error" =>
            $e->getMessage()
    ]);
}

if (isset($conn)) {
    $conn->close();
}

?>