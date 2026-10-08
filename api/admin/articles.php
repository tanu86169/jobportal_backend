<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/database.php";

/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function sendResponse($success, $message, $data = null, $statusCode = 200)
{
    http_response_code($statusCode);

    echo json_encode([
        "success" => $success,
        "message" => $message,
        "articles" => $data
    ]);

    exit;
}

function deleteOldImage($image)
{
    if (!$image) {
        return;
    }

    $image = str_replace("\\", "/", $image);

    /*
    | If DB stores:
    | uploads/articles/file.jpg
    */

    $basePath = realpath(__DIR__ . "/../../");

    if (!$basePath) {
        return;
    }

    $relativePath = ltrim($image, "/");

    $filePath = $basePath . DIRECTORY_SEPARATOR .
        str_replace("/", DIRECTORY_SEPARATOR, $relativePath);

    if (file_exists($filePath) && is_file($filePath)) {
        @unlink($filePath);
    }
}

/*
|--------------------------------------------------------------------------
| GET - Fetch All Articles
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    try {

        $sql = "
            SELECT
                id,
                title,
                description,
                category,
                image,
                author,
                status,
                created_at
            FROM articles
            ORDER BY id DESC
        ";

        $result = $conn->query($sql);

        if (!$result) {
            sendResponse(
                false,
                "Failed to fetch articles.",
                null,
                500
            );
        }

        $articles = [];

        while ($row = $result->fetch_assoc()) {

            $row["id"] = (int) $row["id"];

            $articles[] = $row;
        }

        sendResponse(
            true,
            "Articles fetched successfully.",
            $articles
        );

    } catch (Throwable $e) {

        sendResponse(
            false,
            "Database error while fetching articles.",
            $e->getMessage(),
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| POST - ADD / UPDATE
|--------------------------------------------------------------------------
|
| ADD:
| POST /articles.php
|
| UPDATE:
| POST /articles.php
| _method=PUT
|
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    try {

        /*
        |--------------------------------------------------------------------------
        | Detect Method
        |--------------------------------------------------------------------------
        */

        $method = strtoupper($_POST["_method"] ?? "POST");

        /*
        |--------------------------------------------------------------------------
        | Common Fields
        |--------------------------------------------------------------------------
        */

        $id = isset($_POST["id"])
            ? (int) $_POST["id"]
            : 0;

        $title = trim($_POST["title"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $category = trim($_POST["category"] ?? "Career Growth");
        $status = trim($_POST["status"] ?? "published");

        /*
        |--------------------------------------------------------------------------
        | Author
        |--------------------------------------------------------------------------
        */

        $author = trim($_POST["author"] ?? "Admin");

        if ($author === "") {
            $author = "Admin";
        }

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($title === "") {

            sendResponse(
                false,
                "Article title is required.",
                null,
                422
            );
        }

        if (strlen($title) < 3) {

            sendResponse(
                false,
                "Article title must contain at least 3 characters.",
                null,
                422
            );
        }

        if ($description === "") {

            sendResponse(
                false,
                "Article description is required.",
                null,
                422
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Category
        |--------------------------------------------------------------------------
        */

        if ($category === "") {
            $category = "Career Growth";
        }

        /*
        |--------------------------------------------------------------------------
        | Status Validation
        |--------------------------------------------------------------------------
        */

        $allowedStatuses = [
            "published",
            "draft"
        ];

        if (!in_array($status, $allowedStatuses, true)) {

            sendResponse(
                false,
                "Invalid article status.",
                null,
                422
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Upload Directory
        |--------------------------------------------------------------------------
        */

        $uploadDirectory = __DIR__ . "/../../uploads/articles/";

        if (!is_dir($uploadDirectory)) {

            if (!mkdir($uploadDirectory, 0777, true)) {

                sendResponse(
                    false,
                    "Unable to create article upload directory.",
                    null,
                    500
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | IMAGE UPLOAD
        |--------------------------------------------------------------------------
        */

        $newImagePath = "";

        if (
            isset($_FILES["image"]) &&
            $_FILES["image"]["error"] !== UPLOAD_ERR_NO_FILE
        ) {

            if ($_FILES["image"]["error"] !== UPLOAD_ERR_OK) {

                sendResponse(
                    false,
                    "Image upload failed.",
                    null,
                    422
                );
            }

            $file = $_FILES["image"];

            /*
            | Max 5MB
            */

            if ($file["size"] > 5 * 1024 * 1024) {

                sendResponse(
                    false,
                    "Image must be smaller than 5MB.",
                    null,
                    422
                );
            }

            /*
            | MIME validation
            */

            $allowedMimeTypes = [
                "image/jpeg" => "jpg",
                "image/png"  => "png",
                "image/webp" => "webp",
                "image/gif"  => "gif"
            ];

            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            $mimeType = finfo_file(
                $finfo,
                $file["tmp_name"]
            );

            finfo_close($finfo);

            if (!isset($allowedMimeTypes[$mimeType])) {

                sendResponse(
                    false,
                    "Only JPG, PNG, WEBP and GIF images are allowed.",
                    null,
                    422
                );
            }

            /*
            | Unique File Name
            */

            $extension = $allowedMimeTypes[$mimeType];

            $fileName =
                time() .
                "_" .
                bin2hex(random_bytes(8)) .
                "." .
                $extension;

            $destination =
                $uploadDirectory . $fileName;

            if (!move_uploaded_file(
                $file["tmp_name"],
                $destination
            )) {

                sendResponse(
                    false,
                    "Unable to save uploaded image.",
                    null,
                    500
                );
            }

            /*
            | Store relative path in DB
            */

            $newImagePath =
                "uploads/articles/" . $fileName;
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE
        |--------------------------------------------------------------------------
        */

        if ($method === "PUT") {

            if ($id <= 0) {

                sendResponse(
                    false,
                    "Valid article ID is required.",
                    null,
                    422
                );
            }

            /*
            | Check existing article
            */

            $checkStmt = $conn->prepare("
                SELECT
                    id,
                    image
                FROM articles
                WHERE id = ?
                LIMIT 1
            ");

            $checkStmt->bind_param(
                "i",
                $id
            );

            $checkStmt->execute();

            $existingResult =
                $checkStmt->get_result();

            if ($existingResult->num_rows === 0) {

                sendResponse(
                    false,
                    "Article not found.",
                    null,
                    404
                );
            }

            $existingArticle =
                $existingResult->fetch_assoc();

            $oldImage =
                $existingArticle["image"] ?? "";

            /*
            |--------------------------------------------------------------------------
            | If new image uploaded
            |--------------------------------------------------------------------------
            */

            if ($newImagePath !== "") {

                $updateStmt = $conn->prepare("
                    UPDATE articles
                    SET
                        title = ?,
                        description = ?,
                        category = ?,
                        image = ?,
                        author = ?,
                        status = ?
                    WHERE id = ?
                ");

                $updateStmt->bind_param(
                    "ssssssi",
                    $title,
                    $description,
                    $category,
                    $newImagePath,
                    $author,
                    $status,
                    $id
                );

            } else {

                /*
                | Keep old image
                */

                $updateStmt = $conn->prepare("
                    UPDATE articles
                    SET
                        title = ?,
                        description = ?,
                        category = ?,
                        author = ?,
                        status = ?
                    WHERE id = ?
                ");

                $updateStmt->bind_param(
                    "sssssi",
                    $title,
                    $description,
                    $category,
                    $author,
                    $status,
                    $id
                );
            }

            if (!$updateStmt->execute()) {

                /*
                | Remove newly uploaded image if DB update failed
                */

                if ($newImagePath !== "") {
                    deleteOldImage($newImagePath);
                }

                sendResponse(
                    false,
                    "Unable to update article.",
                    $updateStmt->error,
                    500
                );
            }

            /*
            | Delete old image only after successful update
            */

            if (
                $newImagePath !== "" &&
                $oldImage !== ""
            ) {
                deleteOldImage($oldImage);
            }

            /*
            | Fetch updated article
            */

            $fetchStmt = $conn->prepare("
                SELECT
                    id,
                    title,
                    description,
                    category,
                    image,
                    author,
                    status,
                    created_at
                FROM articles
                WHERE id = ?
                LIMIT 1
            ");

            $fetchStmt->bind_param(
                "i",
                $id
            );

            $fetchStmt->execute();

            $updatedArticle =
                $fetchStmt
                    ->get_result()
                    ->fetch_assoc();

            sendResponse(
                true,
                "Article updated successfully.",
                $updatedArticle
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ADD
        |--------------------------------------------------------------------------
        */

        if ($method === "POST") {

            $insertStmt = $conn->prepare("
                INSERT INTO articles
                (
                    title,
                    description,
                    category,
                    image,
                    author,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            $insertStmt->bind_param(
                "ssssss",
                $title,
                $description,
                $category,
                $newImagePath,
                $author,
                $status
            );

            if (!$insertStmt->execute()) {

                /*
                | Remove uploaded image if insert failed
                */

                if ($newImagePath !== "") {
                    deleteOldImage($newImagePath);
                }

                sendResponse(
                    false,
                    "Unable to create article.",
                    $insertStmt->error,
                    500
                );
            }

            $newId = $conn->insert_id;

            /*
            | Fetch newly created article
            */

            $fetchStmt = $conn->prepare("
                SELECT
                    id,
                    title,
                    description,
                    category,
                    image,
                    author,
                    status,
                    created_at
                FROM articles
                WHERE id = ?
                LIMIT 1
            ");

            $fetchStmt->bind_param(
                "i",
                $newId
            );

            $fetchStmt->execute();

            $newArticle =
                $fetchStmt
                    ->get_result()
                    ->fetch_assoc();

            sendResponse(
                true,
                "Article created successfully.",
                $newArticle
            );
        }

        sendResponse(
            false,
            "Invalid request method.",
            null,
            405
        );

    } catch (Throwable $e) {

        sendResponse(
            false,
            "Server error while saving article.",
            $e->getMessage(),
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    try {

        $input = json_decode(
            file_get_contents("php://input"),
            true
        );

        $id = isset($input["id"])
            ? (int) $input["id"]
            : 0;

        if ($id <= 0) {

            sendResponse(
                false,
                "Valid article ID is required.",
                null,
                422
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Get Article
        |--------------------------------------------------------------------------
        */

        $checkStmt = $conn->prepare("
            SELECT
                id,
                image
            FROM articles
            WHERE id = ?
            LIMIT 1
        ");

        $checkStmt->bind_param(
            "i",
            $id
        );

        $checkStmt->execute();

        $result =
            $checkStmt
                ->get_result();

        if ($result->num_rows === 0) {

            sendResponse(
                false,
                "Article not found.",
                null,
                404
            );
        }

        $article =
            $result->fetch_assoc();

        $image =
            $article["image"] ?? "";

        /*
        |--------------------------------------------------------------------------
        | Delete Database Record
        |--------------------------------------------------------------------------
        */

        $deleteStmt = $conn->prepare("
            DELETE FROM articles
            WHERE id = ?
        ");

        $deleteStmt->bind_param(
            "i",
            $id
        );

        if (!$deleteStmt->execute()) {

            sendResponse(
                false,
                "Unable to delete article.",
                $deleteStmt->error,
                500
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Delete Image
        |--------------------------------------------------------------------------
        */

        if ($image !== "") {
            deleteOldImage($image);
        }

        sendResponse(
            true,
            "Article deleted successfully.",
            null
        );

    } catch (Throwable $e) {

        sendResponse(
            false,
            "Server error while deleting article.",
            $e->getMessage(),
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| Unsupported Method
|--------------------------------------------------------------------------
*/

sendResponse(
    false,
    "Method not allowed.",
    null,
    405
);