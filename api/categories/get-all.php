<?php

require_once "../../config/cors.php";
require_once "../../config/database.php";

header("Content-Type: application/json; charset=UTF-8");

try {

    $sql = "
        SELECT
            c.id,
            c.name,
            c.status,
            COUNT(j.id) AS job_count
        FROM categories c

        LEFT JOIN jobs j
            ON LOWER(TRIM(j.category)) = LOWER(TRIM(c.name))

        WHERE LOWER(TRIM(c.status)) = 'active'

        GROUP BY
            c.id,
            c.name,
            c.status

        ORDER BY c.id DESC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception($conn->error);
    }

    $categories = [];

    while ($row = $result->fetch_assoc()) {

        $categories[] = [
            "id" => (int) $row["id"],
            "name" => $row["name"],
            "status" => $row["status"],
            "job_count" => (int) $row["job_count"]
        ];
    }

    echo json_encode([
        "success" => true,
        "total" => count($categories),
        "categories" => $categories
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch active categories",
        "error" => $e->getMessage()
    ]);
}

?>