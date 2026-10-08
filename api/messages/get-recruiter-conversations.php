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

$userId = isset($_GET["userId"])
    ? (int)$_GET["userId"]
    : 0;

if ($userId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid user ID."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Validate recruiter
|--------------------------------------------------------------------------
*/

$userSql = "
    SELECT id, name, email, role
    FROM users
    WHERE id = ?
    LIMIT 1
";

$userStmt = $conn->prepare($userSql);

if (!$userStmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare user query."
    ]);

    exit;
}

$userStmt->bind_param("i", $userId);
$userStmt->execute();

$result = $userStmt->get_result();
$user = $result->fetch_assoc();

$userStmt->close();

if (!$user) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "User not found."
    ]);

    exit;
}

$role = strtolower(
    trim($user["role"] ?? "")
);

if ($role !== "recruiter") {
    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "This endpoint is only for recruiters."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Actual candidate conversations
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT

        u.id AS other_user_id,
        u.name AS other_user_name,
        u.email AS other_user_email,
        u.role AS other_user_role,

        lm.message AS last_message,
        lm.created_at AS last_message_time,

        (
            SELECT COUNT(*)
            FROM messages unread_msg
            WHERE
                unread_msg.sender_id = u.id
                AND unread_msg.receiver_id = ?
                AND (
                    unread_msg.is_read = 0
                    OR unread_msg.is_read IS NULL
                )
        ) AS unread_count

    FROM users u

    INNER JOIN
    (
        SELECT

            CASE
                WHEN m.sender_id = ?
                    THEN m.receiver_id
                ELSE
                    m.sender_id
            END AS other_user_id,

            MAX(m.id) AS last_message_id

        FROM messages m

        WHERE
            m.sender_id = ?
            OR m.receiver_id = ?

        GROUP BY

            CASE
                WHEN m.sender_id = ?
                    THEN m.receiver_id
                ELSE
                    m.sender_id
            END

    ) conversation

        ON conversation.other_user_id = u.id

    INNER JOIN messages lm
        ON lm.id = conversation.last_message_id

    WHERE
        u.id != ?
        AND LOWER(TRIM(u.role)) = 'candidate'

    ORDER BY
        lm.created_at DESC,
        lm.id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare conversations query.",
        "error" => $conn->error
    ]);

    exit;
}

$stmt->bind_param(
    "iiiiii",
    $userId,
    $userId,
    $userId,
    $userId,
    $userId,
    $userId
);

$stmt->execute();

$result = $stmt->get_result();

$conversations = [];

while ($row = $result->fetch_assoc()) {

    $conversations[] = [

        "other_user_id" =>
            (int)$row["other_user_id"],

        "other_user_name" =>
            $row["other_user_name"] ??
            "Candidate",

        "other_user_email" =>
            $row["other_user_email"] ??
            "",

        "other_user_role" =>
            strtolower(
                trim(
                    $row["other_user_role"] ??
                    ""
                )
            ),

        "last_message" =>
            $row["last_message"] ??
            "",

        "last_message_time" =>
            $row["last_message_time"] ??
            null,

        "unread_count" =>
            (int)(
                $row["unread_count"] ??
                0
            )
    ];
}

$stmt->close();

echo json_encode([
    "success" => true,
    "user_id" => $userId,
    "conversations" => $conversations
]);

?>