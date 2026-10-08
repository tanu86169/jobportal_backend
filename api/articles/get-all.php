<?php

require_once "../../config/cors.php";
require_once "../../config/database.php";

header("Content-Type: application/json; charset=UTF-8");

try {

    $sql = "
        SELECT *
        FROM articles
        WHERE status = 'published'
        ORDER BY created_at DESC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception($conn->error);
    }

    $articles = [];

    while ($row = $result->fetch_assoc()) {

        // Make sure article ID is always available
        $row["article_id"] = isset($row["id"])
            ? (int)$row["id"]
            : (isset($row["article_id"]) ? (int)$row["article_id"] : null);

        $articles[] = $row;
    }

    echo json_encode([
        "success" => true,
        "total" => count($articles),
        "articles" => $articles
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch articles",
        "error" => $e->getMessage()
    ]);
}
?>