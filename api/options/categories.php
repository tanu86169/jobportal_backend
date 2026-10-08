<?php

require_once "../../config/database.php";
require_once "../../config/cors.php";

header("Content-Type: application/json");

try {
    $query = "
        SELECT id, name, icon, description
        FROM categories
        ORDER BY name ASC
    ";

    $result = $conn->query($query);

    if (!$result) {
        throw new Exception("Unable to fetch categories.");
    }

    $categories = [];

    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }

    echo json_encode([
        "success" => true,
        "categories" => $categories
    ]);

} catch (Exception $error) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $error->getMessage()
    ]);
}