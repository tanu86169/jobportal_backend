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

// =========================================================
// OPTIONAL CANDIDATE FILTER
// =========================================================

$candidateId =
    isset($_GET["candidateId"])
        ? (int)$_GET["candidateId"]
        : 0;

// =========================================================
// QUERY
// =========================================================

$sql = "

    SELECT

        c.id AS candidate_id,

        c.name AS candidate_name,

        c.email AS candidate_email,

        r.id AS recruiter_id,

        r.name AS recruiter_name,

        r.email AS recruiter_email,

        lm.id AS last_message_id,

        lm.message AS last_message,

        lm.sender_id AS last_message_sender_id,

        lm.receiver_id AS last_message_receiver_id,

        lm.created_at AS last_message_time

    FROM (

        SELECT

            CASE

                WHEN LOWER(TRIM(sender.role))
                     = 'candidate'

                THEN m.sender_id

                ELSE m.receiver_id

            END AS candidate_id,

            CASE

                WHEN LOWER(TRIM(sender.role))
                     = 'recruiter'

                THEN m.sender_id

                ELSE m.receiver_id

            END AS recruiter_id,

            MAX(m.id) AS last_message_id

        FROM messages m

        INNER JOIN users sender

            ON sender.id = m.sender_id

        INNER JOIN users receiver

            ON receiver.id = m.receiver_id

        WHERE

            (

                LOWER(TRIM(sender.role))
                = 'candidate'

                AND

                LOWER(TRIM(receiver.role))
                = 'recruiter'

            )

            OR

            (

                LOWER(TRIM(sender.role))
                = 'recruiter'

                AND

                LOWER(TRIM(receiver.role))
                = 'candidate'

            )

        GROUP BY

            CASE

                WHEN LOWER(TRIM(sender.role))
                     = 'candidate'

                THEN m.sender_id

                ELSE m.receiver_id

            END,

            CASE

                WHEN LOWER(TRIM(sender.role))
                     = 'recruiter'

                THEN m.sender_id

                ELSE m.receiver_id

            END

    ) conv

    INNER JOIN users c

        ON c.id = conv.candidate_id

    INNER JOIN users r

        ON r.id = conv.recruiter_id

    INNER JOIN messages lm

        ON lm.id = conv.last_message_id

";

// =========================================================
// CANDIDATE FILTER
// =========================================================

if ($candidateId > 0) {

    $sql .= "
        WHERE c.id = ?
    ";

}

$sql .= "

    ORDER BY
        lm.created_at DESC,
        lm.id DESC

";

// =========================================================
// PREPARE
// =========================================================

$stmt =
    $conn->prepare($sql);

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to prepare admin conversations query.",
        "error" => $conn->error
    ]);

    exit;
}

if ($candidateId > 0) {

    $stmt->bind_param(
        "i",
        $candidateId
    );
}

// =========================================================
// EXECUTE
// =========================================================

$stmt->execute();

$result =
    $stmt->get_result();

$conversations = [];

while ($row = $result->fetch_assoc()) {

    $conversations[] = [

        "candidate_id" =>
            (int)$row["candidate_id"],

        "candidate_name" =>
            $row["candidate_name"]
            ?? "Candidate",

        "candidate_email" =>
            $row["candidate_email"]
            ?? "",

        "recruiter_id" =>
            (int)$row["recruiter_id"],

        "recruiter_name" =>
            $row["recruiter_name"]
            ?? "Recruiter",

        "recruiter_email" =>
            $row["recruiter_email"]
            ?? "",

        "last_message_id" =>
            (int)$row["last_message_id"],

        "last_message" =>
            $row["last_message"]
            ?? "",

        "last_message_sender_id" =>
            (int)$row["last_message_sender_id"],

        "last_message_receiver_id" =>
            (int)$row["last_message_receiver_id"],

        "last_message_time" =>
            $row["last_message_time"]
            ?? null,

        "isNew" => false
    ];
}

$stmt->close();

echo json_encode([
    "success" => true,
    "count" => count($conversations),
    "conversations" => $conversations
]);

?>