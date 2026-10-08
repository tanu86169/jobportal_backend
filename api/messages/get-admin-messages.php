<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET method is allowed."
    ]);

    exit;
}

$candidateId = isset($_GET["candidateId"])
    ? (int)$_GET["candidateId"]
    : 0;

$recruiterId = isset($_GET["recruiterId"])
    ? (int)$_GET["recruiterId"]
    : 0;

if ($candidateId <= 0 || $recruiterId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Valid candidateId and recruiterId are required."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Verify candidate
|--------------------------------------------------------------------------
*/

$candidateStmt = $conn->prepare("
    SELECT
        id,
        name,
        email,
        role
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$candidateStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Candidate query prepare failed.",
        "error" => $conn->error
    ]);

    exit;
}

$candidateStmt->bind_param(
    "i",
    $candidateId
);

$candidateStmt->execute();

$candidate = $candidateStmt
    ->get_result()
    ->fetch_assoc();

$candidateStmt->close();

if (!$candidate) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Candidate not found."
    ]);

    exit;
}

$candidateRole = strtolower(
    trim($candidate["role"] ?? "")
);

if ($candidateRole !== "candidate") {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Selected user is not a candidate."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Verify recruiter
|--------------------------------------------------------------------------
*/

$recruiterStmt = $conn->prepare("
    SELECT
        id,
        name,
        email,
        role
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$recruiterStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter query prepare failed.",
        "error" => $conn->error
    ]);

    exit;
}

$recruiterStmt->bind_param(
    "i",
    $recruiterId
);

$recruiterStmt->execute();

$recruiter = $recruiterStmt
    ->get_result()
    ->fetch_assoc();

$recruiterStmt->close();

if (!$recruiter) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Recruiter not found."
    ]);

    exit;
}

$recruiterRole = strtolower(
    trim($recruiter["role"] ?? "")
);

if ($recruiterRole !== "recruiter") {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Selected user is not a recruiter."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get only Candidate ↔ Recruiter messages
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT

        m.id,

        m.sender_id,

        m.receiver_id,

        m.message,

        m.is_read,

        m.created_at,

        s.name AS sender_name,

        s.email AS sender_email,

        s.role AS sender_role,

        r.name AS receiver_name,

        r.email AS receiver_email,

        r.role AS receiver_role

    FROM messages m

    LEFT JOIN users s
        ON s.id = m.sender_id

    LEFT JOIN users r
        ON r.id = m.receiver_id

    WHERE

        (
            m.sender_id = ?
            AND
            m.receiver_id = ?
        )

        OR

        (
            m.sender_id = ?
            AND
            m.receiver_id = ?
        )

    ORDER BY
        m.created_at ASC,
        m.id ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Messages query prepare failed.",
        "error" => $conn->error
    ]);

    exit;
}

$stmt->bind_param(
    "iiii",
    $candidateId,
    $recruiterId,
    $recruiterId,
    $candidateId
);

$stmt->execute();

$result = $stmt->get_result();

$messages = [];

while ($row = $result->fetch_assoc()) {

    $messages[] = [

        "id" =>
            (int)$row["id"],

        "sender_id" =>
            (int)$row["sender_id"],

        "receiver_id" =>
            (int)$row["receiver_id"],

        "message" =>
            $row["message"] ?? "",

        "is_read" =>
            (int)($row["is_read"] ?? 0),

        "created_at" =>
            $row["created_at"] ?? null,

        "sender_name" =>
            $row["sender_name"] ?? "",

        "sender_email" =>
            $row["sender_email"] ?? "",

        "sender_role" =>
            strtolower(
                trim(
                    $row["sender_role"] ?? ""
                )
            ),

        "receiver_name" =>
            $row["receiver_name"] ?? "",

        "receiver_email" =>
            $row["receiver_email"] ?? "",

        "receiver_role" =>
            strtolower(
                trim(
                    $row["receiver_role"] ?? ""
                )
            )
    ];
}

$stmt->close();

echo json_encode([

    "success" => true,

    "candidate" => [
        "id" => (int)$candidate["id"],
        "name" => $candidate["name"] ?? "",
        "email" => $candidate["email"] ?? ""
    ],

    "recruiter" => [
        "id" => (int)$recruiter["id"],
        "name" => $recruiter["name"] ?? "",
        "email" => $recruiter["email"] ?? ""
    ],

    "count" => count($messages),

    "messages" => $messages

]);

$conn->close();

?>