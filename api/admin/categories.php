<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/database.php";

/*
|--------------------------------------------------------------------------
| OPTIONS / CORS
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| Response Helper
|--------------------------------------------------------------------------
*/
function sendResponse($success, $message, $data = null, $statusCode = 200)
{
    http_response_code($statusCode);

    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Read JSON
|--------------------------------------------------------------------------
*/
function getJsonInput()
{
    $input = file_get_contents("php://input");

    if (!$input) {
        return [];
    }

    $data = json_decode($input, true);

    if (!is_array($data)) {
        sendResponse(false, "Invalid JSON data.", null, 400);
    }

    return $data;
}

/*
|--------------------------------------------------------------------------
| GET - Get All Categories
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "GET") {

    $sql = "
        SELECT 
            id,
            name,
            description,
            status,
            created_at
        FROM categories
        ORDER BY id DESC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        sendResponse(
            false,
            "Failed to fetch categories: " . $conn->error,
            null,
            500
        );
    }

    $categories = [];

    while ($row = $result->fetch_assoc()) {

        $categories[] = [
            "id" => (int)$row["id"],
            "name" => $row["name"],
            "description" => $row["description"] ?? "",
            "status" => $row["status"],
            "created_at" => $row["created_at"]
        ];
    }

    sendResponse(
        true,
        "Categories fetched successfully.",
        $categories
    );
}


/*
|--------------------------------------------------------------------------
| POST - Add Category
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $data = getJsonInput();

    $name = trim($data["name"] ?? "");
    $description = trim($data["description"] ?? "");
    $status = strtolower(trim($data["status"] ?? "active"));

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($name === "") {
        sendResponse(
            false,
            "Category name is required.",
            null,
            422
        );
    }

    if (strlen($name) < 2) {
        sendResponse(
            false,
            "Category name must contain at least 2 characters.",
            null,
            422
        );
    }

    if (!in_array($status, ["active", "inactive"])) {
        sendResponse(
            false,
            "Invalid status.",
            null,
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Check Duplicate
    |--------------------------------------------------------------------------
    */

    $checkStmt = $conn->prepare("
        SELECT id 
        FROM categories
        WHERE LOWER(name) = LOWER(?)
        LIMIT 1
    ");

    if (!$checkStmt) {
        sendResponse(
            false,
            "Database error: " . $conn->error,
            null,
            500
        );
    }

    $checkStmt->bind_param("s", $name);
    $checkStmt->execute();

    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {

        $checkStmt->close();

        sendResponse(
            false,
            "Category already exists.",
            null,
            409
        );
    }

    $checkStmt->close();

    /*
    |--------------------------------------------------------------------------
    | Insert
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        INSERT INTO categories
        (
            name,
            description,
            status
        )
        VALUES
        (
            ?,
            ?,
            ?
        )
    ");

    if (!$stmt) {
        sendResponse(
            false,
            "Database error: " . $conn->error,
            null,
            500
        );
    }

    $stmt->bind_param(
        "sss",
        $name,
        $description,
        $status
    );

    if (!$stmt->execute()) {

        $stmt->close();

        sendResponse(
            false,
            "Failed to add category: " . $conn->error,
            null,
            500
        );
    }

    $newId = $stmt->insert_id;

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Get Inserted Category
    |--------------------------------------------------------------------------
    */

    $getStmt = $conn->prepare("
        SELECT
            id,
            name,
            description,
            status,
            created_at
        FROM categories
        WHERE id = ?
        LIMIT 1
    ");

    $getStmt->bind_param("i", $newId);
    $getStmt->execute();

    $result = $getStmt->get_result();

    $category = $result->fetch_assoc();

    $getStmt->close();

    sendResponse(
        true,
        "Category added successfully.",
        $category,
        201
    );
}


/*
|--------------------------------------------------------------------------
| PUT - Update Category
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "PUT") {

    $data = getJsonInput();

    $id = (int)($data["id"] ?? 0);
    $name = trim($data["name"] ?? "");
    $description = trim($data["description"] ?? "");
    $status = strtolower(trim($data["status"] ?? "active"));

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($id <= 0) {
        sendResponse(
            false,
            "Valid category ID is required.",
            null,
            422
        );
    }

    if ($name === "") {
        sendResponse(
            false,
            "Category name is required.",
            null,
            422
        );
    }

    if (strlen($name) < 2) {
        sendResponse(
            false,
            "Category name must contain at least 2 characters.",
            null,
            422
        );
    }

    if (!in_array($status, ["active", "inactive"])) {
        sendResponse(
            false,
            "Invalid status.",
            null,
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Check Category Exists
    |--------------------------------------------------------------------------
    */

    $existStmt = $conn->prepare("
        SELECT id
        FROM categories
        WHERE id = ?
        LIMIT 1
    ");

    $existStmt->bind_param("i", $id);
    $existStmt->execute();

    $existResult = $existStmt->get_result();

    if ($existResult->num_rows === 0) {

        $existStmt->close();

        sendResponse(
            false,
            "Category not found.",
            null,
            404
        );
    }

    $existStmt->close();

    /*
    |--------------------------------------------------------------------------
    | Duplicate Name Check
    |--------------------------------------------------------------------------
    */

    $duplicateStmt = $conn->prepare("
        SELECT id
        FROM categories
        WHERE LOWER(name) = LOWER(?)
        AND id != ?
        LIMIT 1
    ");

    $duplicateStmt->bind_param(
        "si",
        $name,
        $id
    );

    $duplicateStmt->execute();

    $duplicateResult = $duplicateStmt->get_result();

    if ($duplicateResult->num_rows > 0) {

        $duplicateStmt->close();

        sendResponse(
            false,
            "Another category with this name already exists.",
            null,
            409
        );
    }

    $duplicateStmt->close();

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE categories
        SET
            name = ?,
            description = ?,
            status = ?
        WHERE id = ?
    ");

    if (!$stmt) {
        sendResponse(
            false,
            "Database error: " . $conn->error,
            null,
            500
        );
    }

    $stmt->bind_param(
        "sssi",
        $name,
        $description,
        $status,
        $id
    );

    if (!$stmt->execute()) {

        $stmt->close();

        sendResponse(
            false,
            "Failed to update category: " . $conn->error,
            null,
            500
        );
    }

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Get Updated Category
    |--------------------------------------------------------------------------
    */

    $getStmt = $conn->prepare("
        SELECT
            id,
            name,
            description,
            status,
            created_at
        FROM categories
        WHERE id = ?
        LIMIT 1
    ");

    $getStmt->bind_param("i", $id);
    $getStmt->execute();

    $result = $getStmt->get_result();

    $category = $result->fetch_assoc();

    $getStmt->close();

    sendResponse(
        true,
        "Category updated successfully.",
        $category
    );
}


/*
|--------------------------------------------------------------------------
| DELETE - Delete Category
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $data = getJsonInput();

    $id = (int)($data["id"] ?? 0);

    if ($id <= 0) {
        sendResponse(
            false,
            "Valid category ID is required.",
            null,
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Check Category Exists
    |--------------------------------------------------------------------------
    */

    $checkStmt = $conn->prepare("
        SELECT id
        FROM categories
        WHERE id = ?
        LIMIT 1
    ");

    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();

    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {

        $checkStmt->close();

        sendResponse(
            false,
            "Category not found.",
            null,
            404
        );
    }

    $checkStmt->close();

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        DELETE FROM categories
        WHERE id = ?
    ");

    $stmt->bind_param("i", $id);

    if (!$stmt->execute()) {

        $stmt->close();

        sendResponse(
            false,
            "Failed to delete category: " . $conn->error,
            null,
            500
        );
    }

    $stmt->close();

    sendResponse(
        true,
        "Category deleted successfully.",
        [
            "id" => $id
        ]
    );
}


/*
|--------------------------------------------------------------------------
| Invalid Method
|--------------------------------------------------------------------------
*/

sendResponse(
    false,
    "Method not allowed.",
    null,
    405
);