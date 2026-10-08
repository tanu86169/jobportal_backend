<?php

require_once "../../config/database.php";
require_once "../../config/cors.php";

header("Content-Type: application/json; charset=UTF-8");

$method = $_SERVER["REQUEST_METHOD"];

/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

if ($method === "OPTIONS") {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| GET
| Get recruiter profile + settings
|--------------------------------------------------------------------------
*/

if ($method === "GET") {

    $recruiterId = isset($_GET["recruiterId"])
        ? intval($_GET["recruiterId"])
        : 0;

    if ($recruiterId <= 0) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Invalid recruiter ID"
        ]);

        exit;
    }

    try {

        /*
        |--------------------------------------------------------------------------
        | Check recruiter
        |--------------------------------------------------------------------------
        */

        $userStmt = $conn->prepare("
            SELECT
                id,
                name,
                email,
                phone,
                role,
                status,
                created_at
            FROM users
            WHERE id = ?
              AND role = 'recruiter'
            LIMIT 1
        ");

        if (!$userStmt) {
            throw new Exception($conn->error);
        }

        $userStmt->bind_param("i", $recruiterId);
        $userStmt->execute();

        $userResult = $userStmt->get_result();
        $user = $userResult->fetch_assoc();

        $userStmt->close();

        if (!$user) {

            http_response_code(404);

            echo json_encode([
                "success" => false,
                "message" => "Recruiter not found"
            ]);

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Get recruiter settings
        |--------------------------------------------------------------------------
        */

        $settingsStmt = $conn->prepare("
            SELECT
                email_notifications,
                new_application_alerts,
                message_notifications,
                interview_updates,
                profile_visibility
            FROM recruiter_settings
            WHERE recruiter_id = ?
            LIMIT 1
        ");

        if (!$settingsStmt) {
            throw new Exception($conn->error);
        }

        $settingsStmt->bind_param("i", $recruiterId);
        $settingsStmt->execute();

        $settingsResult = $settingsStmt->get_result();
        $settings = $settingsResult->fetch_assoc();

        $settingsStmt->close();

        /*
        |--------------------------------------------------------------------------
        | Create default settings if not available
        |--------------------------------------------------------------------------
        */

        if (!$settings) {

            $insertStmt = $conn->prepare("
                INSERT INTO recruiter_settings
                (
                    recruiter_id,
                    email_notifications,
                    new_application_alerts,
                    message_notifications,
                    interview_updates,
                    profile_visibility
                )
                VALUES (?, 1, 1, 1, 1, 1)
            ");

            if (!$insertStmt) {
                throw new Exception($conn->error);
            }

            $insertStmt->bind_param("i", $recruiterId);

            if (!$insertStmt->execute()) {
                throw new Exception(
                    "Failed to create default settings: " .
                    $insertStmt->error
                );
            }

            $insertStmt->close();

            $settings = [
                "email_notifications" => 1,
                "new_application_alerts" => 1,
                "message_notifications" => 1,
                "interview_updates" => 1,
                "profile_visibility" => 1
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        echo json_encode([
            "success" => true,
            "message" => "Recruiter settings fetched successfully",

            "profile" => [
                "id" => intval($user["id"]),
                "name" => $user["name"],
                "email" => $user["email"],
                "phone" => $user["phone"],
                "role" => $user["role"],
                "status" => $user["status"],
                "created_at" => $user["created_at"]
            ],

            "settings" => [
                "email_notifications" =>
                    (bool) $settings["email_notifications"],

                "new_application_alerts" =>
                    (bool) $settings["new_application_alerts"],

                "message_notifications" =>
                    (bool) $settings["message_notifications"],

                "interview_updates" =>
                    (bool) $settings["interview_updates"],

                "profile_visibility" =>
                    (bool) $settings["profile_visibility"]
            ]
        ]);

        exit;

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| PUT
| Update recruiter profile + settings
|--------------------------------------------------------------------------
*/

if ($method === "PUT") {

    $data = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($data)) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Invalid JSON data"
        ]);

        exit;
    }

    $recruiterId = intval(
        $data["recruiter_id"] ?? 0
    );

    if ($recruiterId <= 0) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Valid recruiter ID is required"
        ]);

        exit;
    }

    try {

        /*
        |--------------------------------------------------------------------------
        | Check recruiter
        |--------------------------------------------------------------------------
        */

        $checkStmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND role = 'recruiter'
            LIMIT 1
        ");

        if (!$checkStmt) {
            throw new Exception($conn->error);
        }

        $checkStmt->bind_param("i", $recruiterId);
        $checkStmt->execute();

        $checkResult = $checkStmt->get_result();
        $recruiter = $checkResult->fetch_assoc();

        $checkStmt->close();

        if (!$recruiter) {

            http_response_code(404);

            echo json_encode([
                "success" => false,
                "message" => "Recruiter not found"
            ]);

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Profile data
        |--------------------------------------------------------------------------
        */

        $name = trim(
            $data["name"] ?? ""
        );

        $email = trim(
            $data["email"] ?? ""
        );

        $phone = trim(
            $data["phone"] ?? ""
        );

        if ($name === "") {

            http_response_code(400);

            echo json_encode([
                "success" => false,
                "message" => "Name is required"
            ]);

            exit;
        }

        if ($email === "") {

            http_response_code(400);

            echo json_encode([
                "success" => false,
                "message" => "Email is required"
            ]);

            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            http_response_code(400);

            echo json_encode([
                "success" => false,
                "message" => "Invalid email address"
            ]);

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Check duplicate email
        |--------------------------------------------------------------------------
        */

        $emailStmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
              AND id != ?
            LIMIT 1
        ");

        if (!$emailStmt) {
            throw new Exception($conn->error);
        }

        $emailStmt->bind_param(
            "si",
            $email,
            $recruiterId
        );

        $emailStmt->execute();

        $emailResult = $emailStmt->get_result();

        if ($emailResult->fetch_assoc()) {

            $emailStmt->close();

            http_response_code(409);

            echo json_encode([
                "success" => false,
                "message" => "Email already exists"
            ]);

            exit;
        }

        $emailStmt->close();

        /*
        |--------------------------------------------------------------------------
        | Recruiter Notification Settings
        |--------------------------------------------------------------------------
        */

        $emailNotifications =
            !empty($data["email_notifications"]) ? 1 : 0;

        $newApplicationAlerts =
            !empty($data["new_application_alerts"]) ? 1 : 0;

        $messageNotifications =
            !empty($data["message_notifications"]) ? 1 : 0;

        $interviewUpdates =
            !empty($data["interview_updates"]) ? 1 : 0;

        $profileVisibility =
            !empty($data["profile_visibility"]) ? 1 : 0;

        /*
        |--------------------------------------------------------------------------
        | Transaction
        |--------------------------------------------------------------------------
        */

        $conn->begin_transaction();

        /*
        |--------------------------------------------------------------------------
        | Update users table
        |--------------------------------------------------------------------------
        */

        $userUpdate = $conn->prepare("
            UPDATE users
            SET
                name = ?,
                email = ?,
                phone = ?
            WHERE id = ?
              AND role = 'recruiter'
        ");

        if (!$userUpdate) {
            throw new Exception($conn->error);
        }

        $userUpdate->bind_param(
            "sssi",
            $name,
            $email,
            $phone,
            $recruiterId
        );

        if (!$userUpdate->execute()) {

            throw new Exception(
                "Failed to update profile: " .
                $userUpdate->error
            );
        }

        $userUpdate->close();

        /*
        |--------------------------------------------------------------------------
        | Insert / Update recruiter settings
        |--------------------------------------------------------------------------
        */

        $settingsStmt = $conn->prepare("
            INSERT INTO recruiter_settings
            (
                recruiter_id,
                email_notifications,
                new_application_alerts,
                message_notifications,
                interview_updates,
                profile_visibility
            )
            VALUES (?, ?, ?, ?, ?, ?)

            ON DUPLICATE KEY UPDATE
                email_notifications =
                    VALUES(email_notifications),

                new_application_alerts =
                    VALUES(new_application_alerts),

                message_notifications =
                    VALUES(message_notifications),

                interview_updates =
                    VALUES(interview_updates),

                profile_visibility =
                    VALUES(profile_visibility)
        ");

        if (!$settingsStmt) {
            throw new Exception($conn->error);
        }

        $settingsStmt->bind_param(
            "iiiiii",
            $recruiterId,
            $emailNotifications,
            $newApplicationAlerts,
            $messageNotifications,
            $interviewUpdates,
            $profileVisibility
        );

        if (!$settingsStmt->execute()) {

            throw new Exception(
                "Failed to update recruiter settings: " .
                $settingsStmt->error
            );
        }

        $settingsStmt->close();

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        /*
        |--------------------------------------------------------------------------
        | Success Response
        |--------------------------------------------------------------------------
        */

        echo json_encode([
            "success" => true,
            "message" => "Recruiter settings updated successfully",

            "profile" => [
                "id" => $recruiterId,
                "name" => $name,
                "email" => $email,
                "phone" => $phone
            ],

            "settings" => [
                "email_notifications" =>
                    (bool) $emailNotifications,

                "new_application_alerts" =>
                    (bool) $newApplicationAlerts,

                "message_notifications" =>
                    (bool) $messageNotifications,

                "interview_updates" =>
                    (bool) $interviewUpdates,

                "profile_visibility" =>
                    (bool) $profileVisibility
            ]
        ]);

        exit;

    } catch (Exception $e) {

        /*
        |--------------------------------------------------------------------------
        | Rollback
        |--------------------------------------------------------------------------
        */

        if ($conn->errno === 0 || $conn->errno !== 0) {
            try {
                $conn->rollback();
            } catch (Exception $rollbackError) {
                // Ignore rollback error
            }
        }

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Method Not Allowed
|--------------------------------------------------------------------------
*/

http_response_code(405);

echo json_encode([
    "success" => false,
    "message" => "Method not allowed"
]);

?>