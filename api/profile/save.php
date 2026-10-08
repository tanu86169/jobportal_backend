<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST request allowed"
    ]);

    exit;
}

function cleanString($value)
{
    if ($value === null) {
        return "";
    }

    return trim((string)$value);
}

function cleanArray($value)
{
    return is_array($value) ? $value : [];
}

try {

    $rawData = file_get_contents("php://input");

    $data = json_decode(
        $rawData,
        true
    );

    if (!is_array($data)) {
        throw new Exception(
            "Invalid JSON data."
        );
    }

    $userId = isset($data["user_id"])
        ? (int)$data["user_id"]
        : 0;

    if ($userId <= 0) {
        throw new Exception(
            "Valid user_id is required."
        );
    }

    /*
    =========================================================
    USER CHECK
    =========================================================
    */

    $userStmt = $conn->prepare("
        SELECT
            id,
            name,
            email,
            phone,
            role
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $userStmt->bind_param(
        "i",
        $userId
    );

    $userStmt->execute();

    $userResult =
        $userStmt->get_result();

    $user = $userResult->fetch_assoc();

    $userStmt->close();

    if (!$user) {
        throw new Exception(
            "User not found."
        );
    }

    if (
        $user["role"] !== "candidate"
    ) {
        throw new Exception(
            "Only candidate profile can be updated."
        );
    }

    /*
    =========================================================
    DATA
    =========================================================
    */

    $categoryId =
        !empty($data["category_id"])
            ? (int)$data["category_id"]
            : null;

    $profileImage =
        cleanString(
            $data["profile_image"] ?? ""
        );

    $headline =
        cleanString(
            $data["headline"] ?? ""
        );

    $bio =
        cleanString(
            $data["bio"] ?? ""
        );

    $location =
        cleanString(
            $data["location"] ?? ""
        );

    $dateOfBirth =
        cleanString(
            $data["date_of_birth"] ?? ""
        );

    if ($dateOfBirth === "") {
        $dateOfBirth = null;
    }

    $experienceLevel =
        cleanString(
            $data["experience_level"] ?? ""
        );

    $resume =
        cleanString(
            $data["resume"] ?? ""
        );

    $linkedin =
        cleanString(
            $data["linkedin"] ?? ""
        );

    $github =
        cleanString(
            $data["github"] ?? ""
        );

    $portfolio =
        cleanString(
            $data["portfolio"] ?? ""
        );

    /*
    =========================================================
    CATEGORY CHECK
    =========================================================
    */

    if ($categoryId !== null) {

        $categoryStmt =
            $conn->prepare("
                SELECT id, name
                FROM categories
                WHERE id = ?
                LIMIT 1
            ");

        $categoryStmt->bind_param(
            "i",
            $categoryId
        );

        $categoryStmt->execute();

        $categoryResult =
            $categoryStmt->get_result();

        $category =
            $categoryResult->fetch_assoc();

        $categoryStmt->close();

        if (!$category) {
            throw new Exception(
                "Selected category does not exist."
            );
        }
    }

    /*
    =========================================================
    JSON DATA
    =========================================================
    */

    $skills =
        cleanArray(
            $data["skills"] ?? []
        );

    $education =
        cleanArray(
            $data["education"] ?? []
        );

    $experience =
        cleanArray(
            $data["experience"] ?? []
        );

    $projects =
        cleanArray(
            $data["projects"] ?? []
        );

    $skillsJson =
        json_encode(
            $skills,
            JSON_UNESCAPED_UNICODE
        );

    $educationJson =
        json_encode(
            $education,
            JSON_UNESCAPED_UNICODE
        );

    $experienceJson =
        json_encode(
            $experience,
            JSON_UNESCAPED_UNICODE
        );

    $projectsJson =
        json_encode(
            $projects,
            JSON_UNESCAPED_UNICODE
        );

    if (
        $skillsJson === false ||
        $educationJson === false ||
        $experienceJson === false ||
        $projectsJson === false
    ) {
        throw new Exception(
            "Failed to encode profile data."
        );
    }

    /*
    =========================================================
    TRANSACTION
    =========================================================
    */

    $conn->begin_transaction();

    /*
    =========================================================
    CHECK CANDIDATE PROFILE
    =========================================================
    */

    $checkStmt =
        $conn->prepare("
            SELECT id
            FROM candidates
            WHERE user_id = ?
            LIMIT 1
        ");

    $checkStmt->bind_param(
        "i",
        $userId
    );

    $checkStmt->execute();

    $checkResult =
        $checkStmt->get_result();

    $candidate =
        $checkResult->fetch_assoc();

    $checkStmt->close();

    /*
    =========================================================
    UPDATE EXISTING PROFILE
    =========================================================
    */

    if ($candidate) {

        $candidateId =
            (int)$candidate["id"];

        $updateStmt =
            $conn->prepare("
                UPDATE candidates
                SET
                    category_id = ?,
                    profile_image = ?,
                    headline = ?,
                    bio = ?,
                    location = ?,
                    date_of_birth = ?,
                    education = ?,
                    projects = ?,
                    skills = ?,
                    experience = ?,
                    experience_level = ?,
                    resume = ?,
                    linkedin = ?,
                    github = ?,
                    portfolio = ?
                WHERE user_id = ?
            ");

        $types =
            "i" .
            str_repeat("s", 14) .
            "i";

        $updateStmt->bind_param(
            $types,
            $categoryId,
            $profileImage,
            $headline,
            $bio,
            $location,
            $dateOfBirth,
            $educationJson,
            $projectsJson,
            $skillsJson,
            $experienceJson,
            $experienceLevel,
            $resume,
            $linkedin,
            $github,
            $portfolio,
            $userId
        );

        if (
            !$updateStmt->execute()
        ) {
            throw new Exception(
                $updateStmt->error
            );
        }

        $updateStmt->close();

    } else {

        /*
        =====================================================
        CREATE PROFILE
        =====================================================
        */

        $insertStmt =
            $conn->prepare("
                INSERT INTO candidates (
                    user_id,
                    category_id,
                    profile_image,
                    headline,
                    bio,
                    location,
                    date_of_birth,
                    education,
                    projects,
                    skills,
                    experience,
                    experience_level,
                    resume,
                    linkedin,
                    github,
                    portfolio
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

        $types =
            "ii" .
            str_repeat("s", 14);

        $insertStmt->bind_param(
            $types,
            $userId,
            $categoryId,
            $profileImage,
            $headline,
            $bio,
            $location,
            $dateOfBirth,
            $educationJson,
            $projectsJson,
            $skillsJson,
            $experienceJson,
            $experienceLevel,
            $resume,
            $linkedin,
            $github,
            $portfolio
        );

        if (
            !$insertStmt->execute()
        ) {
            throw new Exception(
                $insertStmt->error
            );
        }

        $candidateId =
            $insertStmt->insert_id;

        $insertStmt->close();
    }

    /*
    =========================================================
    COMMIT
    =========================================================
    */

    $conn->commit();

    /*
    =========================================================
    RESPONSE
    =========================================================
    */

    echo json_encode([
        "success" => true,
        "message" => "Profile updated successfully.",
        "candidate_id" => (int)$candidateId,
        "category_id" => $categoryId,
        "experience_level" => $experienceLevel
    ]);

} catch (Throwable $e) {

    if ($conn) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
        }
    }

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to save profile.",
        "error" => $e->getMessage()
    ]);
}

if ($conn) {
    $conn->close();
}