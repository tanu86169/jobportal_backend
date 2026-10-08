<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/
function sendResponse(
    $success,
    $message = "",
    $data = null,
    $statusCode = 200
) {
    http_response_code($statusCode);

    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| GET NOTIFICATIONS
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "GET") {

    $sql = "
        SELECT
            n.id,
            n.user_id,
            n.title,
            n.message,
            n.type,
            n.is_read,
            n.related_id,
            n.created_at,

            u.name AS user_name,
            u.email AS user_email,
            u.role AS user_role

        FROM notifications n

        LEFT JOIN users u
            ON n.user_id = u.id

        ORDER BY n.id DESC
    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        sendResponse(
            false,
            "Failed to fetch notifications: " .
            mysqli_error($conn),
            null,
            500
        );
    }

    $notifications = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $row["id"] = (int) $row["id"];

        if ($row["user_id"] !== null) {
            $row["user_id"] = (int) $row["user_id"];
        }

        $row["is_read"] = (int) $row["is_read"];

        if ($row["related_id"] !== null) {
            $row["related_id"] = (int) $row["related_id"];
        }

        $notifications[] = $row;
    }

    sendResponse(
        true,
        "Notifications fetched successfully.",
        $notifications
    );
}


/*
|--------------------------------------------------------------------------
| POST - CREATE / SEND NOTIFICATION
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($input)) {
        sendResponse(
            false,
            "Invalid JSON data.",
            null,
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BASIC DATA
    |--------------------------------------------------------------------------
    */

    $title = trim(
        $input["title"] ?? ""
    );

    $message = trim(
        $input["message"] ?? ""
    );

    $type = trim(
        $input["type"] ?? "General"
    );

    $target = $input["target"] ?? null;

    /*
    |--------------------------------------------------------------------------
    | BACKWARD COMPATIBILITY
    |
    | Agar frontend abhi bhi user_id bhej raha hai,
    | to specific user notification send hogi.
    |--------------------------------------------------------------------------
    */

    $userId = null;

    if (
        isset($input["user_id"]) &&
        $input["user_id"] !== "" &&
        $input["user_id"] !== null
    ) {
        $userId = (int) $input["user_id"];
    }

    $relatedId = null;

    if (
        isset($input["related_id"]) &&
        $input["related_id"] !== "" &&
        $input["related_id"] !== null
    ) {
        $relatedId = (int) $input["related_id"];
    }

    $isRead = isset($input["is_read"])
        ? (int) $input["is_read"]
        : 0;


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($title === "") {
        sendResponse(
            false,
            "Notification title is required.",
            null,
            422
        );
    }

    if (mb_strlen($title) < 3) {
        sendResponse(
            false,
            "Notification title must contain at least 3 characters.",
            null,
            422
        );
    }

    if ($message === "") {
        sendResponse(
            false,
            "Notification message is required.",
            null,
            422
        );
    }

    if (!in_array($isRead, [0, 1], true)) {
        $isRead = 0;
    }

    if ($type === "") {
        $type = "General";
    }


    /*
    |--------------------------------------------------------------------------
    | DETERMINE TARGET
    |--------------------------------------------------------------------------
    |
    | Supported:
    |
    | ALL_USERS
    | ALL_CANDIDATES
    | ALL_RECRUITERS
    | SPECIFIC USER
    |
    */

    if (
        $target === null ||
        $target === ""
    ) {

        if ($userId !== null) {
            $target = "SPECIFIC";
        } else {
            $target = "ALL_USERS";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | BUILD USER LIST
    |--------------------------------------------------------------------------
    */

    $userIds = [];


    /*
    |--------------------------------------------------------------------------
    | SPECIFIC USER
    |--------------------------------------------------------------------------
    */

    if (
        $target === "SPECIFIC" &&
        $userId !== null
    ) {

        $checkStmt = mysqli_prepare(
            $conn,
            "
            SELECT id
            FROM users
            WHERE id = ?
            LIMIT 1
            "
        );

        if (!$checkStmt) {
            sendResponse(
                false,
                "Prepare failed: " . mysqli_error($conn),
                null,
                500
            );
        }

        mysqli_stmt_bind_param(
            $checkStmt,
            "i",
            $userId
        );

        mysqli_stmt_execute(
            $checkStmt
        );

        $checkResult =
            mysqli_stmt_get_result(
                $checkStmt
            );

        if (
            mysqli_num_rows(
                $checkResult
            ) === 0
        ) {

            mysqli_stmt_close(
                $checkStmt
            );

            sendResponse(
                false,
                "Selected user not found.",
                null,
                404
            );
        }

        mysqli_stmt_close(
            $checkStmt
        );

        $userIds[] = $userId;
    }


    /*
    |--------------------------------------------------------------------------
    | ALL USERS
    |--------------------------------------------------------------------------
    */

    elseif ($target === "ALL_USERS") {

        $result = mysqli_query(
            $conn,
            "
            SELECT id
            FROM users
            WHERE role IN ('candidate', 'recruiter')
            ORDER BY id ASC
            "
        );

        if (!$result) {
            sendResponse(
                false,
                "Failed to fetch users: " .
                mysqli_error($conn),
                null,
                500
            );
        }

        while (
            $row =
            mysqli_fetch_assoc($result)
        ) {

            $userIds[] =
                (int) $row["id"];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ALL CANDIDATES
    |--------------------------------------------------------------------------
    */

    elseif (
        $target === "ALL_CANDIDATES"
    ) {

        $result = mysqli_query(
            $conn,
            "
            SELECT id
            FROM users
            WHERE role = 'candidate'
            ORDER BY id ASC
            "
        );

        if (!$result) {
            sendResponse(
                false,
                "Failed to fetch candidates: " .
                mysqli_error($conn),
                null,
                500
            );
        }

        while (
            $row =
            mysqli_fetch_assoc($result)
        ) {

            $userIds[] =
                (int) $row["id"];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ALL RECRUITERS
    |--------------------------------------------------------------------------
    */

    elseif (
        $target === "ALL_RECRUITERS"
    ) {

        $result = mysqli_query(
            $conn,
            "
            SELECT id
            FROM users
            WHERE role = 'recruiter'
            ORDER BY id ASC
            "
        );

        if (!$result) {
            sendResponse(
                false,
                "Failed to fetch recruiters: " .
                mysqli_error($conn),
                null,
                500
            );
        }

        while (
            $row =
            mysqli_fetch_assoc($result)
        ) {

            $userIds[] =
                (int) $row["id"];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | INVALID TARGET
    |--------------------------------------------------------------------------
    */

    else {

        sendResponse(
            false,
            "Invalid notification target.",
            null,
            422
        );
    }


    /*
    |--------------------------------------------------------------------------
    | NO USERS FOUND
    |--------------------------------------------------------------------------
    */

    if (count($userIds) === 0) {

        sendResponse(
            false,
            "No users found for the selected target.",
            null,
            404
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INSERT NOTIFICATIONS
    |--------------------------------------------------------------------------
    */

    mysqli_begin_transaction(
        $conn
    );

    try {

        $stmt = mysqli_prepare(
            $conn,
            "
            INSERT INTO notifications
            (
                user_id,
                title,
                message,
                type,
                is_read,
                related_id
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
            "
        );

        if (!$stmt) {
            throw new Exception(
                "Prepare failed: " .
                mysqli_error($conn)
            );
        }


        foreach ($userIds as $receiverId) {

            mysqli_stmt_bind_param(
                $stmt,
                "isssii",
                $receiverId,
                $title,
                $message,
                $type,
                $isRead,
                $relatedId
            );

            if (
                !mysqli_stmt_execute(
                    $stmt
                )
            ) {

                throw new Exception(
                    mysqli_stmt_error($stmt)
                );
            }
        }


        mysqli_stmt_close(
            $stmt
        );


        mysqli_commit(
            $conn
        );


        /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

        sendResponse(
            true,
            "Notification sent successfully.",
            [
                "target" => $target,
                "total_users" => count($userIds),
                "title" => $title,
                "type" => $type
            ],
            201
        );

    } catch (Exception $e) {

        mysqli_rollback(
            $conn
        );

        sendResponse(
            false,
            "Failed to send notification: " .
            $e->getMessage(),
            null,
            500
        );
    }
}


/*
|--------------------------------------------------------------------------
| PUT - UPDATE NOTIFICATION
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "PUT") {

    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($input)) {
        sendResponse(
            false,
            "Invalid JSON data.",
            null,
            400
        );
    }

    $id = isset($input["id"])
        ? (int) $input["id"]
        : 0;

    if ($id <= 0) {
        sendResponse(
            false,
            "Notification ID is required.",
            null,
            422
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK
    |--------------------------------------------------------------------------
    */

    $checkStmt = mysqli_prepare(
        $conn,
        "
        SELECT id
        FROM notifications
        WHERE id = ?
        LIMIT 1
        "
    );

    if (!$checkStmt) {
        sendResponse(
            false,
            "Prepare failed: " .
            mysqli_error($conn),
            null,
            500
        );
    }

    mysqli_stmt_bind_param(
        $checkStmt,
        "i",
        $id
    );

    mysqli_stmt_execute(
        $checkStmt
    );

    $checkResult =
        mysqli_stmt_get_result(
            $checkStmt
        );

    if (
        mysqli_num_rows(
            $checkResult
        ) === 0
    ) {

        mysqli_stmt_close(
            $checkStmt
        );

        sendResponse(
            false,
            "Notification not found.",
            null,
            404
        );
    }

    mysqli_stmt_close(
        $checkStmt
    );


    /*
    |--------------------------------------------------------------------------
    | DATA
    |--------------------------------------------------------------------------
    */

    $title = trim(
        $input["title"] ?? ""
    );

    $message = trim(
        $input["message"] ?? ""
    );

    $type = trim(
        $input["type"] ?? "General"
    );

    $userId =
        isset($input["user_id"]) &&
        $input["user_id"] !== "" &&
        $input["user_id"] !== null
            ? (int) $input["user_id"]
            : null;

    $relatedId =
        isset($input["related_id"]) &&
        $input["related_id"] !== "" &&
        $input["related_id"] !== null
            ? (int) $input["related_id"]
            : null;

    $isRead =
        isset($input["is_read"])
            ? (int) $input["is_read"]
            : 0;


    if ($title === "") {
        sendResponse(
            false,
            "Notification title is required.",
            null,
            422
        );
    }

    if ($message === "") {
        sendResponse(
            false,
            "Notification message is required.",
            null,
            422
        );
    }

    if (!in_array($isRead, [0, 1], true)) {
        $isRead = 0;
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        UPDATE notifications
        SET
            user_id = ?,
            title = ?,
            message = ?,
            type = ?,
            is_read = ?,
            related_id = ?
        WHERE id = ?
        "
    );

    if (!$stmt) {
        sendResponse(
            false,
            "Prepare failed: " .
            mysqli_error($conn),
            null,
            500
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        "isssiii",
        $userId,
        $title,
        $message,
        $type,
        $isRead,
        $relatedId,
        $id
    );

    if (
        !mysqli_stmt_execute(
            $stmt
        )
    ) {

        sendResponse(
            false,
            "Failed to update notification: " .
            mysqli_stmt_error($stmt),
            null,
            500
        );
    }

    mysqli_stmt_close(
        $stmt
    );


    /*
    |--------------------------------------------------------------------------
    | GET UPDATED
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            n.id,
            n.user_id,
            n.title,
            n.message,
            n.type,
            n.is_read,
            n.related_id,
            n.created_at,

            u.name AS user_name,
            u.email AS user_email,
            u.role AS user_role

        FROM notifications n

        LEFT JOIN users u
            ON n.user_id = u.id

        WHERE n.id = ?
        "
    );

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $id
    );

    mysqli_stmt_execute(
        $stmt
    );

    $result =
        mysqli_stmt_get_result(
            $stmt
        );

    $notification =
        mysqli_fetch_assoc(
            $result
        );

    mysqli_stmt_close(
        $stmt
    );


    sendResponse(
        true,
        "Notification updated successfully.",
        $notification
    );
}


/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    $id = isset($input["id"])
        ? (int) $input["id"]
        : 0;

    if ($id <= 0) {
        sendResponse(
            false,
            "Notification ID is required.",
            null,
            422
        );
    }


    $stmt = mysqli_prepare(
        $conn,
        "
        DELETE FROM notifications
        WHERE id = ?
        "
    );

    if (!$stmt) {
        sendResponse(
            false,
            "Prepare failed: " .
            mysqli_error($conn),
            null,
            500
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $id
    );

    if (
        !mysqli_stmt_execute(
            $stmt
        )
    ) {

        sendResponse(
            false,
            "Failed to delete notification: " .
            mysqli_stmt_error($stmt),
            null,
            500
        );
    }


    if (
        mysqli_stmt_affected_rows(
            $stmt
        ) === 0
    ) {

        mysqli_stmt_close(
            $stmt
        );

        sendResponse(
            false,
            "Notification not found.",
            null,
            404
        );
    }

    mysqli_stmt_close(
        $stmt
    );


    sendResponse(
        true,
        "Notification deleted successfully."
    );
}


/*
|--------------------------------------------------------------------------
| METHOD NOT ALLOWED
|--------------------------------------------------------------------------
*/

sendResponse(
    false,
    "Method not allowed.",
    null,
    405
);