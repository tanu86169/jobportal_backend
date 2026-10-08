<?php

// =====================================================
// ADMIN FAQ CATEGORY API
// GET    /api/admin/faq-categories.php
// POST   /api/admin/faq-categories.php
// OPTIONS
// =====================================================

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174",
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";

function responseJson(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    responseJson(false, "Database connection is not available.", [], 500);
}

$conn->set_charset("utf8mb4");

// =====================================================
// GET CATEGORIES
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $status = trim($_GET["status"] ?? "active");

    if (!in_array($status, ["active", "inactive", "all"], true)) {
        $status = "active";
    }

    if ($status === "all") {
        $sql = "
            SELECT id, name, status, display_order, created_at, updated_at
            FROM faq_categories
            ORDER BY display_order ASC, name ASC, id ASC
        ";
        $stmt = $conn->prepare($sql);
    } else {
        $sql = "
            SELECT id, name, status, display_order, created_at, updated_at
            FROM faq_categories
            WHERE status = ?
            ORDER BY display_order ASC, name ASC, id ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $status);
    }

    if (!$stmt) {
        responseJson(false, "Unable to prepare category query.", [], 500);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        responseJson(false, "Unable to fetch FAQ categories.", [], 500);
    }

    $result = $stmt->get_result();
    $categories = [];

    while ($row = $result->fetch_assoc()) {
        $row["id"] = (int) $row["id"];
        $row["display_order"] = (int) $row["display_order"];
        $categories[] = $row;
    }

    $stmt->close();

    responseJson(true, "FAQ categories fetched successfully.", [
        "categories" => $categories,
        "total" => count($categories),
    ]);
}

// =====================================================
// POST CATEGORY
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        responseJson(false, "Invalid JSON request.", [], 400);
    }

    $name = trim((string) ($data["name"] ?? $data["category"] ?? ""));
    $status = trim((string) ($data["status"] ?? "active"));
    $displayOrder = max(0, (int) ($data["display_order"] ?? 0));

    if ($name === "") {
        responseJson(false, "Category name is required.", [], 422);
    }

    if (mb_strlen($name) > 100) {
        responseJson(false, "Category name cannot exceed 100 characters.", [], 422);
    }

    if (!in_array($status, ["active", "inactive"], true)) {
        responseJson(false, "Invalid category status.", [], 422);
    }

    // Case-insensitive duplicate check.
    $check = $conn->prepare("SELECT id, name FROM faq_categories WHERE LOWER(name) = LOWER(?) LIMIT 1");

    if (!$check) {
        responseJson(false, "Unable to validate category.", [], 500);
    }

    $check->bind_param("s", $name);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if ($existing) {
        responseJson(false, "This category already exists.", [
            "category" => $existing,
        ], 409);
    }

    // If no order was supplied, put the new category at the end.
    if ($displayOrder === 0) {
        $orderResult = $conn->query("SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM faq_categories");
        if ($orderResult) {
            $orderRow = $orderResult->fetch_assoc();
            $displayOrder = (int) ($orderRow["next_order"] ?? 1);
        } else {
            $displayOrder = 1;
        }
    }

    $stmt = $conn->prepare("\n        INSERT INTO faq_categories (name, status, display_order)\n        VALUES (?, ?, ?)\n    ");

    if (!$stmt) {
        responseJson(false, "Unable to prepare category insert.", [], 500);
    }

    $stmt->bind_param("ssi", $name, $status, $displayOrder);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        responseJson(false, "Unable to add category: " . $error, [], 500);
    }

    $newId = (int) $stmt->insert_id;
    $stmt->close();

    $get = $conn->prepare("\n        SELECT id, name, status, display_order, created_at, updated_at\n        FROM faq_categories\n        WHERE id = ?\n        LIMIT 1\n    ");

    if (!$get) {
        responseJson(true, "Category added successfully.", [
            "category" => [
                "id" => $newId,
                "name" => $name,
                "status" => $status,
                "display_order" => $displayOrder,
            ],
        ], 201);
    }

    $get->bind_param("i", $newId);
    $get->execute();
    $category = $get->get_result()->fetch_assoc();
    $get->close();

    if ($category) {
        $category["id"] = (int) $category["id"];
        $category["display_order"] = (int) $category["display_order"];
    }

    responseJson(true, "Category added successfully.", [
        "category" => $category,
    ], 201);
}

responseJson(false, "Method not allowed.", [], 405);
