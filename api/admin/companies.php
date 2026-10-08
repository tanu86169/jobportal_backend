<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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
| JSON RESPONSE
|--------------------------------------------------------------------------
*/

function responseJson(
    $success,
    $message = "",
    $data = [],
    $statusCode = 200
) {
    http_response_code($statusCode);

    echo json_encode(
        array_merge(
            [
                "success" => $success,
                "message" => $message
            ],
            $data
        )
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| ALLOWED STATUS
|--------------------------------------------------------------------------
*/

$allowedStatuses = [
    "pending",
    "approved",
    "rejected",
    "blocked",
    "suspended"
];

/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
|
| GET
|     -> companies
|
| GET ?action=recruiters
|     -> recruiter dropdown
|
*/

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    /*
    |--------------------------------------------------------------------------
    | GET RECRUITERS
    |--------------------------------------------------------------------------
    */

    if (
        isset($_GET["action"]) &&
        $_GET["action"] === "recruiters"
    ) {

        $sql = "
            SELECT
                id,
                name,
                email,
                phone
            FROM users
            WHERE LOWER(role) IN (
                'recruiter',
                'employer'
            )
            ORDER BY name ASC
        ";

        $result = mysqli_query($conn, $sql);

        if (!$result) {

            responseJson(
                false,
                "Failed to fetch recruiters: " .
                mysqli_error($conn),
                [],
                500
            );
        }

        $recruiters = [];

        while ($row = mysqli_fetch_assoc($result)) {

            $recruiters[] = [
                "id" => (int)$row["id"],
                "name" => $row["name"],
                "email" => $row["email"],
                "phone" => $row["phone"]
            ];
        }

        responseJson(
            true,
            "Recruiters fetched successfully.",
            [
                "recruiters" => $recruiters
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | GET SINGLE COMPANY
    |--------------------------------------------------------------------------
    */

    if (
        isset($_GET["id"]) &&
        intval($_GET["id"]) > 0
    ) {

        $companyId = intval($_GET["id"]);

        $stmt = $conn->prepare("
            SELECT
                c.id,
                c.recruiter_id,
                c.company_name,
                c.website,
                c.location,
                c.status,
                c.created_at,

                u.name AS recruiter_name,
                u.email AS recruiter_email,
                u.phone AS recruiter_phone

            FROM companies c

            LEFT JOIN users u
                ON u.id = c.recruiter_id

            WHERE c.id = ?

            LIMIT 1
        ");

        if (!$stmt) {

            responseJson(
                false,
                "Failed to prepare company query: " .
                $conn->error,
                [],
                500
            );
        }

        $stmt->bind_param(
            "i",
            $companyId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows === 0) {

            responseJson(
                false,
                "Company not found.",
                [],
                404
            );
        }

        $company = $result->fetch_assoc();

        responseJson(
            true,
            "Company fetched successfully.",
            [
                "company" => $company
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | GET ALL COMPANIES
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            c.id,
            c.recruiter_id,
            c.company_name,
            c.website,
            c.location,
            c.status,
            c.created_at,

            u.name AS recruiter_name,
            u.email AS recruiter_email,
            u.phone AS recruiter_phone

        FROM companies c

        LEFT JOIN users u
            ON u.id = c.recruiter_id

        ORDER BY c.id DESC
    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {

        responseJson(
            false,
            "Failed to fetch companies: " .
            mysqli_error($conn),
            [],
            500
        );
    }

    $companies = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $companies[] = [
            "id" => (int)$row["id"],
            "recruiter_id" => (int)$row["recruiter_id"],
            "company_name" => $row["company_name"],
            "website" => $row["website"],
            "location" => $row["location"],
            "status" => $row["status"],
            "created_at" => $row["created_at"],

            "recruiter_name" =>
                $row["recruiter_name"],

            "recruiter_email" =>
                $row["recruiter_email"],

            "recruiter_phone" =>
                $row["recruiter_phone"]
        ];
    }

    responseJson(
        true,
        "Companies fetched successfully.",
        [
            "companies" => $companies
        ]
    );
}


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
| ADD COMPANY
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($input)) {
        $input = [];
    }

    $recruiterId = isset($input["recruiter_id"])
        ? intval($input["recruiter_id"])
        : 0;

    $companyName = isset($input["company_name"])
        ? trim($input["company_name"])
        : "";

    $location = isset($input["location"])
        ? trim($input["location"])
        : "";

    $website = isset($input["website"])
        ? trim($input["website"])
        : "";

    $status = isset($input["status"])
        ? strtolower(trim($input["status"]))
        : "pending";


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($companyName === "") {

        responseJson(
            false,
            "Company name is required.",
            [],
            400
        );
    }

    if ($recruiterId <= 0) {

        responseJson(
            false,
            "Recruiter is required.",
            [],
            400
        );
    }

    if (!in_array($status, $allowedStatuses, true)) {

        responseJson(
            false,
            "Invalid company status.",
            [],
            400
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK RECRUITER
    |--------------------------------------------------------------------------
    */

    $recruiterStmt = $conn->prepare("
        SELECT
            id,
            name,
            email
        FROM users
        WHERE id = ?
        AND LOWER(role) IN (
            'recruiter',
            'employer'
        )
        LIMIT 1
    ");

    if (!$recruiterStmt) {

        responseJson(
            false,
            "Recruiter query failed: " .
            $conn->error,
            [],
            500
        );
    }

    $recruiterStmt->bind_param(
        "i",
        $recruiterId
    );

    $recruiterStmt->execute();

    $recruiterResult =
        $recruiterStmt->get_result();

    if ($recruiterResult->num_rows === 0) {

        responseJson(
            false,
            "Selected recruiter does not exist.",
            [],
            404
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INSERT
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        INSERT INTO companies
        (
            recruiter_id,
            company_name,
            website,
            location,
            status
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {

        responseJson(
            false,
            "Failed to prepare insert query: " .
            $conn->error,
            [],
            500
        );
    }

    $stmt->bind_param(
        "issss",
        $recruiterId,
        $companyName,
        $website,
        $location,
        $status
    );

    if (!$stmt->execute()) {

        responseJson(
            false,
            "Failed to add company: " .
            $stmt->error,
            [],
            500
        );
    }

    $companyId = $stmt->insert_id;


    /*
    |--------------------------------------------------------------------------
    | GET CREATED COMPANY
    |--------------------------------------------------------------------------
    */

    $getStmt = $conn->prepare("
        SELECT
            c.id,
            c.recruiter_id,
            c.company_name,
            c.website,
            c.location,
            c.status,
            c.created_at,

            u.name AS recruiter_name,
            u.email AS recruiter_email,
            u.phone AS recruiter_phone

        FROM companies c

        LEFT JOIN users u
            ON u.id = c.recruiter_id

        WHERE c.id = ?

        LIMIT 1
    ");

    $getStmt->bind_param(
        "i",
        $companyId
    );

    $getStmt->execute();

    $result =
        $getStmt->get_result();

    $company =
        $result->fetch_assoc();

    responseJson(
        true,
        "Company added successfully.",
        [
            "company" => $company
        ],
        201
    );
}


/*
|--------------------------------------------------------------------------
| PUT
|--------------------------------------------------------------------------
| UPDATE COMPANY
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "PUT") {

    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($input)) {
        $input = [];
    }

    $companyId = isset($input["id"])
        ? intval($input["id"])
        : 0;

    $recruiterId = isset($input["recruiter_id"])
        ? intval($input["recruiter_id"])
        : 0;

    $companyName = isset($input["company_name"])
        ? trim($input["company_name"])
        : "";

    $location = isset($input["location"])
        ? trim($input["location"])
        : "";

    $website = isset($input["website"])
        ? trim($input["website"])
        : "";

    $status = isset($input["status"])
        ? strtolower(trim($input["status"]))
        : "pending";


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($companyId <= 0) {

        responseJson(
            false,
            "Company ID is required.",
            [],
            400
        );
    }

    if ($companyName === "") {

        responseJson(
            false,
            "Company name is required.",
            [],
            400
        );
    }

    if ($recruiterId <= 0) {

        responseJson(
            false,
            "Recruiter is required.",
            [],
            400
        );
    }

    if (!in_array($status, $allowedStatuses, true)) {

        responseJson(
            false,
            "Invalid company status.",
            [],
            400
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK RECRUITER
    |--------------------------------------------------------------------------
    */

    $recruiterStmt = $conn->prepare("
        SELECT id
        FROM users
        WHERE id = ?
        AND LOWER(role) IN (
            'recruiter',
            'employer'
        )
        LIMIT 1
    ");

    $recruiterStmt->bind_param(
        "i",
        $recruiterId
    );

    $recruiterStmt->execute();

    $recruiterResult =
        $recruiterStmt->get_result();

    if ($recruiterResult->num_rows === 0) {

        responseJson(
            false,
            "Selected recruiter does not exist.",
            [],
            404
        );
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE companies
        SET
            recruiter_id = ?,
            company_name = ?,
            website = ?,
            location = ?,
            status = ?
        WHERE id = ?
    ");

    if (!$stmt) {

        responseJson(
            false,
            "Failed to prepare update query: " .
            $conn->error,
            [],
            500
        );
    }

    $stmt->bind_param(
        "issssi",
        $recruiterId,
        $companyName,
        $website,
        $location,
        $status,
        $companyId
    );

    if (!$stmt->execute()) {

        responseJson(
            false,
            "Failed to update company: " .
            $stmt->error,
            [],
            500
        );
    }


    /*
    |--------------------------------------------------------------------------
    | GET UPDATED COMPANY
    |--------------------------------------------------------------------------
    */

    $getStmt = $conn->prepare("
        SELECT
            c.id,
            c.recruiter_id,
            c.company_name,
            c.website,
            c.location,
            c.status,
            c.created_at,

            u.name AS recruiter_name,
            u.email AS recruiter_email,
            u.phone AS recruiter_phone

        FROM companies c

        LEFT JOIN users u
            ON u.id = c.recruiter_id

        WHERE c.id = ?

        LIMIT 1
    ");

    $getStmt->bind_param(
        "i",
        $companyId
    );

    $getStmt->execute();

    $result =
        $getStmt->get_result();

    $company =
        $result->fetch_assoc();

    responseJson(
        true,
        "Company updated successfully.",
        [
            "company" => $company
        ]
    );
}


/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $companyId = isset($_GET["id"])
        ? intval($_GET["id"])
        : 0;

    if ($companyId <= 0) {

        $input = json_decode(
            file_get_contents("php://input"),
            true
        );

        if (is_array($input)) {
            $companyId = isset($input["id"])
                ? intval($input["id"])
                : 0;
        }
    }

    if ($companyId <= 0) {

        responseJson(
            false,
            "Company ID is required.",
            [],
            400
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK COMPANY
    |--------------------------------------------------------------------------
    */

    $checkStmt = $conn->prepare("
        SELECT id
        FROM companies
        WHERE id = ?
        LIMIT 1
    ");

    $checkStmt->bind_param(
        "i",
        $companyId
    );

    $checkStmt->execute();

    $checkResult =
        $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {

        responseJson(
            false,
            "Company not found.",
            [],
            404
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    $deleteStmt = $conn->prepare("
        DELETE FROM companies
        WHERE id = ?
    ");

    if (!$deleteStmt) {

        responseJson(
            false,
            "Failed to prepare delete query: " .
            $conn->error,
            [],
            500
        );
    }

    $deleteStmt->bind_param(
        "i",
        $companyId
    );

    if (!$deleteStmt->execute()) {

        responseJson(
            false,
            "Failed to delete company: " .
            $deleteStmt->error,
            [],
            500
        );
    }

    responseJson(
        true,
        "Company deleted successfully.",
        [
            "company_id" => $companyId
        ]
    );
}


/*
|--------------------------------------------------------------------------
| METHOD NOT ALLOWED
|--------------------------------------------------------------------------
*/

responseJson(
    false,
    "Method not allowed.",
    [],
    405
);