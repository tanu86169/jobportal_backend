<?php

require_once "../../config/cors.php";
require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET request allowed"
    ]);

    exit;
}

$sql = "
    SELECT id, name
    FROM workplace_types
    WHERE status = 'active'
    ORDER BY name ASC
";

$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch workplace types"
    ]);

    exit;
}

$workplaceTypes = [];

while ($row = $result->fetch_assoc()) {
    $workplaceTypes[] = $row;
}

echo json_encode([
    "success" => true,
    "workplaceTypes" => $workplaceTypes
]);

$conn->close();

?>