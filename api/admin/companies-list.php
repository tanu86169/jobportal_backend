<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET method is allowed"
    ]);

    exit;
}

try {

    $sql = "
        SELECT
            id,
            recruiter_id,
            company_name,
            website,
            location,
            status
        FROM companies
        WHERE company_name IS NOT NULL
        AND company_name != ''
        ORDER BY company_name ASC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception($conn->error);
    }

    $companies = [];

    while ($row = $result->fetch_assoc()) {

        $companies[] = [
            "id" => (int) $row["id"],
            "recruiter_id" => $row["recruiter_id"],
            "company_name" => $row["company_name"],
            "website" => $row["website"],
            "location" => $row["location"],
            "status" => $row["status"]
        ];
    }

    echo json_encode([
        "success" => true,
        "count" => count($companies),
        "data" => $companies
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to fetch companies",
        "error" => $e->getMessage()
    ]);
}