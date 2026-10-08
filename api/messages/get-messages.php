<?php

// =========================================================
// CORS
// =========================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =========================================================
// DATABASE
// =========================================================

require_once "../../config/database.php";

// =========================================================
// METHOD
// =========================================================

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET method is allowed."
    ]);

    exit;
}

// =========================================================
// PARAMS
// =========================================================

$userId =
    isset($_GET["userId"])
        ? (int)$_GET["userId"]
        : 0;

$otherUserId =
    isset($_GET["otherUserId"])
        ? (int)$_GET["otherUserId"]
        : 0;

/*
 * Candidate/Recruiter frontend:
 * markRead=1 by default
 *
 * Admin monitoring:
 * markRead=0
 *
 * This prevents admin from accidentally
 * changing candidate unread messages.
 */

$markRead =
    isset($_GET["markRead"])
        ? (int)$_GET["markRead"]
        : 1;

// =========================================================
// VALIDATION
// =========================================================

if ($userId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid userId."
    ]);

    exit;
}

if ($otherUserId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid otherUserId."
    ]);

    exit;
}

if ($userId === $otherUserId) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid conversation."
    ]);

    exit;
}

// =========================================================
// GET MESSAGES
// =========================================================

$sql = "
    SELECT

        m.id,

        m.sender_id,

        m.receiver_id,

        m.message,

        m.is_read,

        m.created_at,

        sender.name AS sender_name,

        sender.email AS sender_email,

        sender.role AS sender_role,

        receiver.name AS receiver_name,

        receiver.email AS receiver_email,

        receiver.role AS receiver_role

    FROM messages m

    LEFT JOIN users sender
        ON sender.id = m.sender_id

    LEFT JOIN users receiver
        ON receiver.id = m.receiver_id

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
        "message" => "Failed to prepare messages query.",
        "error" => $conn->error
    ]);

    exit;
}

$stmt->bind_param(
    "iiii",
    $userId,
    $otherUserId,
    $otherUserId,
    $userId
);

$stmt->execute();

$result =
    $stmt->get_result();

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

// =========================================================
// MARK RECEIVED MESSAGES AS READ
// =========================================================

if ($markRead === 1) {

    $readSql = "
        UPDATE messages

        SET is_read = 1

        WHERE
            sender_id = ?

            AND

            receiver_id = ?

            AND

            (
                is_read = 0
                OR is_read IS NULL
            )
    ";

    $readStmt =
        $conn->prepare($readSql);

    if ($readStmt) {

        $readStmt->bind_param(
            "ii",
            $otherUserId,
            $userId
        );

        $readStmt->execute();

        $readStmt->close();
    }
}

// =========================================================
// RESPONSE
// =========================================================

echo json_encode([
    "success" => true,
    "user_id" => $userId,
    "other_user_id" => $otherUserId,
    "mark_read" => $markRead,
    "count" => count($messages),
    "messages" => $messages
]);

?>