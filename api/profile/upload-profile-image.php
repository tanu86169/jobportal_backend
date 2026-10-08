<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST request allowed."
    ]);

    exit;
}

require_once "../../config/database.php";

try {

    /*
    |--------------------------------------------------------------------------
    | USER ID
    |--------------------------------------------------------------------------
    */

    $userId = isset($_POST["user_id"])
        ? (int) $_POST["user_id"]
        : 0;

    if ($userId <= 0) {
        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Valid user_id is required."
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | IMAGE CHECK
    |--------------------------------------------------------------------------
    */

    if (
        !isset($_FILES["profile_image"]) ||
        $_FILES["profile_image"]["error"] !== UPLOAD_ERR_OK
    ) {
        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Profile image is required."
        ]);

        exit;
    }

    $file = $_FILES["profile_image"];

    /*
    |--------------------------------------------------------------------------
    | FILE SIZE - MAX 2 MB
    |--------------------------------------------------------------------------
    */

    $maxSize = 2 * 1024 * 1024;

    if ($file["size"] > $maxSize) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Profile image must be under 2 MB."
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | MIME TYPE
    |--------------------------------------------------------------------------
    */

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mimeType = $finfo->file(
        $file["tmp_name"]
    );

    $allowedMimeTypes = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp",
    ];

    if (
        !isset(
            $allowedMimeTypes[$mimeType]
        )
    ) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Only JPG, PNG and WEBP images are allowed."
        ]);

        exit;
    }

    $extension =
        $allowedMimeTypes[$mimeType];

    /*
    |--------------------------------------------------------------------------
    | UPLOAD DIRECTORY
    |--------------------------------------------------------------------------
    */

    $uploadDirectory =
        dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR
        . "uploads"
        . DIRECTORY_SEPARATOR
        . "profile-images"
        . DIRECTORY_SEPARATOR;

    if (
        !is_dir($uploadDirectory)
    ) {

        if (
            !mkdir(
                $uploadDirectory,
                0777,
                true
            )
        ) {

            throw new Exception(
                "Unable to create upload directory."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | OLD IMAGE
    |--------------------------------------------------------------------------
    */

    $oldImage = null;

    $candidateQuery = "
        SELECT profile_image
        FROM candidates
        WHERE user_id = ?
        LIMIT 1
    ";

    $candidateStmt =
        $conn->prepare(
            $candidateQuery
        );

    if (!$candidateStmt) {
        throw new Exception(
            "Failed to prepare candidate query."
        );
    }

    $candidateStmt->bind_param(
        "i",
        $userId
    );

    $candidateStmt->execute();

    $candidateResult =
        $candidateStmt->get_result();

    if (
        $candidateRow =
            $candidateResult->fetch_assoc()
    ) {
        $oldImage =
            $candidateRow["profile_image"]
            ?? null;
    }

    $candidateStmt->close();

    /*
    |--------------------------------------------------------------------------
    | UNIQUE FILE NAME
    |--------------------------------------------------------------------------
    */

    $fileName =
        "user_" .
        $userId .
        "_" .
        bin2hex(
            random_bytes(8)
        ) .
        "." .
        $extension;

    $destination =
        $uploadDirectory .
        $fileName;

    /*
    |--------------------------------------------------------------------------
    | MOVE FILE
    |--------------------------------------------------------------------------
    */

    if (
        !move_uploaded_file(
            $file["tmp_name"],
            $destination
        )
    ) {

        throw new Exception(
            "Failed to upload profile image."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DATABASE PATH
    |--------------------------------------------------------------------------
    */

    $imageUrl =
        "http://localhost/job_portal/job-portal-api/uploads/profile-images/"
        . $fileName;

    /*
    |--------------------------------------------------------------------------
    | CHECK CANDIDATE
    |--------------------------------------------------------------------------
    */

    $checkQuery = "
        SELECT id
        FROM candidates
        WHERE user_id = ?
        LIMIT 1
    ";

    $checkStmt =
        $conn->prepare(
            $checkQuery
        );

    if (!$checkStmt) {
        throw new Exception(
            "Failed to prepare candidate check."
        );
    }

    $checkStmt->bind_param(
        "i",
        $userId
    );

    $checkStmt->execute();

    $checkResult =
        $checkStmt->get_result();

    $candidateExists =
        $checkResult->num_rows > 0;

    $checkStmt->close();

    /*
    |--------------------------------------------------------------------------
    | INSERT / UPDATE
    |--------------------------------------------------------------------------
    */

    if ($candidateExists) {

        $updateQuery = "
            UPDATE candidates
            SET profile_image = ?
            WHERE user_id = ?
        ";

        $updateStmt =
            $conn->prepare(
                $updateQuery
            );

        if (!$updateStmt) {
            throw new Exception(
                "Failed to prepare image update."
            );
        }

        $updateStmt->bind_param(
            "si",
            $imageUrl,
            $userId
        );

        $updateStmt->execute();

        $updateStmt->close();

    } else {

        $insertQuery = "
            INSERT INTO candidates (
                user_id,
                profile_image
            )
            VALUES (?, ?)
        ";

        $insertStmt =
            $conn->prepare(
                $insertQuery
            );

        if (!$insertStmt) {
            throw new Exception(
                "Failed to prepare candidate insert."
            );
        }

        $insertStmt->bind_param(
            "is",
            $userId,
            $imageUrl
        );

        $insertStmt->execute();

        $insertStmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE OLD IMAGE
    |--------------------------------------------------------------------------
    */

    if (
        $oldImage &&
        filter_var(
            $oldImage,
            FILTER_VALIDATE_URL
        )
    ) {

        $oldFileName =
            basename(
                parse_url(
                    $oldImage,
                    PHP_URL_PATH
                )
            );

        $oldFilePath =
            $uploadDirectory .
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
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        "success" => true,
        "message" => "Profile image uploaded successfully.",
        "profile_image" => $imageUrl,
        "url" => $imageUrl,
        "file_url" => $imageUrl,
        "file_name" => $fileName
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}