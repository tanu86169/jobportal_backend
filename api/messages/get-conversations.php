<?php

// =========================================================
// CORS
// =========================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// =========================================================
// OPTIONS
// =========================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =========================================================
// DATABASE
// =========================================================

require_once "../../config/database.php";

// =========================================================
// ONLY GET
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
// GET USER ID
// =========================================================

$userId = isset($_GET["userId"])
    ? (int)$_GET["userId"]
    : 0;

if ($userId <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid userId."
    ]);

    exit;
}

try {

    // =====================================================
    // CHECK CURRENT USER
    // =====================================================

    $userSql = "
        SELECT
            id,
            name,
            email,
            role
        FROM users
        WHERE id = ?
        LIMIT 1
    ";

    $userStmt = $conn->prepare($userSql);

    if (!$userStmt) {

        throw new Exception(
            "User query prepare failed: " . $conn->error
        );
    }

    $userStmt->bind_param(
        "i",
        $userId
    );

    if (!$userStmt->execute()) {

        throw new Exception(
            "User query execute failed: " . $userStmt->error
        );
    }

    $userResult = $userStmt->get_result();

    $user = $userResult->fetch_assoc();

    $userStmt->close();

    // =====================================================
    // USER NOT FOUND
    // =====================================================

    if (!$user) {

        http_response_code(404);

        echo json_encode([
            "success" => false,
            "message" => "User not found."
        ]);

        exit;
    }

    // =====================================================
    // CURRENT USER ROLE
    // =====================================================

    $userRole = strtolower(
        trim(
            $user["role"] ?? ""
        )
    );

    // =====================================================
    // ONLY CANDIDATE / RECRUITER
    // =====================================================

    if ($userRole === "candidate") {

        $otherRole = "recruiter";

    } elseif ($userRole === "recruiter") {

        $otherRole = "candidate";

    } else {

        echo json_encode([
            "success" => true,
            "user_id" => $userId,
            "user_role" => $userRole,
            "conversations" => []
        ]);

        exit;
    }

    // =====================================================
    // GET ACTUAL CONVERSATIONS
    //
    // Candidate:
    //     Candidate <-> Recruiter
    //
    // Recruiter:
    //     Recruiter <-> Candidate
    //
    // Only users who have ACTUALLY exchanged
    // messages will appear.
    // =====================================================

    $sql = "

        SELECT

            u.id AS other_user_id,

            u.name AS other_user_name,

            u.email AS other_user_email,

            u.role AS other_user_role,

            lm.message AS last_message,

            lm.created_at AS last_message_time,

            lm.sender_id AS last_message_sender_id,

            lm.receiver_id AS last_message_receiver_id,

            (

                SELECT COUNT(*)

                FROM messages unread_msg

                WHERE

                    unread_msg.sender_id = u.id

                    AND

                    unread_msg.receiver_id = ?

                    AND

                    (
                        unread_msg.is_read = 0
                        OR unread_msg.is_read IS NULL
                    )

            ) AS unread_count

        FROM users u

        INNER JOIN (

            SELECT

                CASE

                    WHEN sender_id = ?
                    THEN receiver_id

                    ELSE sender_id

                END AS other_user_id,

                MAX(id) AS last_message_id

            FROM messages

            WHERE

                sender_id = ?

                OR

                receiver_id = ?

            GROUP BY

                CASE

                    WHEN sender_id = ?
                    THEN receiver_id

                    ELSE sender_id

                END

        ) conversation

            ON conversation.other_user_id = u.id

        INNER JOIN messages lm

            ON lm.id = conversation.last_message_id

        WHERE

            u.id != ?

            AND

            LOWER(TRIM(u.role)) = ?

        ORDER BY

            lm.created_at DESC,

            lm.id DESC

    ";

    // =====================================================
    // PREPARE
    // =====================================================

    $stmt = $conn->prepare($sql);

    if (!$stmt) {

        throw new Exception(
            "Conversation query prepare failed: "
            . $conn->error
        );
    }

    // =====================================================
    // IMPORTANT
    //
    // There are TOTAL 7 placeholders:
    //
    // 1. unread_msg.receiver_id = ?
    // 2. sender_id = ?
    // 3. sender_id = ?
    // 4. receiver_id = ?
    // 5. sender_id = ?
    // 6. u.id != ?
    // 7. role = ?
    //
    // Types:
    //
    // i i i i i i s
    //
    // Therefore:
    // "iiiiiis"
    // =====================================================

    $stmt->bind_param(
        "iiiiiis",
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $userId,
        $otherRole
    );

    // =====================================================
    // EXECUTE
    // =====================================================

    if (!$stmt->execute()) {

        throw new Exception(
            "Conversation query execute failed: "
            . $stmt->error
        );
    }

    // =====================================================
    // RESULT
    // =====================================================

    $result = $stmt->get_result();

    $conversations = [];

    // =====================================================
    // BUILD RESPONSE
    // =====================================================

    while ($row = $result->fetch_assoc()) {

        $conversations[] = [

            "other_user_id" =>
                (int)$row["other_user_id"],

            "other_user_name" =>
                $row["other_user_name"]
                ?? "User",

            "other_user_email" =>
                $row["other_user_email"]
                ?? "",

            "other_user_role" =>
                strtolower(
                    trim(
                        $row["other_user_role"]
                        ?? ""
                    )
                ),

            "last_message" =>
                $row["last_message"]
                ?? "",

            "last_message_time" =>
                $row["last_message_time"]
                ?? null,

            "last_message_sender_id" =>
                (int)(
                    $row["last_message_sender_id"]
                    ?? 0
                ),

            "last_message_receiver_id" =>
                (int)(
                    $row["last_message_receiver_id"]
                    ?? 0
                ),

            "unread_count" =>
                (int)(
                    $row["unread_count"]
                    ?? 0
                ),

            "isNew" => false
        ];
    }

    $stmt->close();

    // =====================================================
    // SUCCESS RESPONSE
    // =====================================================

    echo json_encode([
        "success" => true,

        "user_id" => $userId,

        "user_role" => $userRole,

        "conversations" => $conversations
    ]);

} catch (Throwable $e) {

    // =====================================================
    // ERROR RESPONSE
    // =====================================================

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server error while loading conversations.",
        "error" => $e->getMessage()
    ]);

}

?>