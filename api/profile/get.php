<?php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Vary: Origin");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/database.php";

/*
|--------------------------------------------------------------------------
| CORS OPTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| ONLY GET REQUEST
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET request allowed."
    ]);

    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | USER ID
    |--------------------------------------------------------------------------
    */

    $userId = isset($_GET["user_id"])
        ? (int) $_GET["user_id"]
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
    | FETCH PROFILE
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            u.id AS user_id,
            u.name,
            u.email,
            u.phone,
            u.role,
            u.status AS account_status,
            u.created_at,

            c.id AS candidate_profile_id,
            c.profile_image,
            c.headline,
            c.bio,
            c.location,
            c.date_of_birth,
            c.category_id,

            cat.name AS category_name,

            c.education,
            c.projects,
            c.skills,
            c.experience,
            c.experience_level,

            c.resume,
            c.linkedin,
            c.github,
            c.portfolio,

            c.created_at AS candidate_created_at,
            c.updated_at

        FROM users u

        LEFT JOIN candidates c
            ON c.user_id = u.id

        LEFT JOIN categories cat
            ON cat.id = c.category_id

        WHERE
            u.id = ?
            AND u.role = 'candidate'

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception(
            "Failed to prepare profile query: " . $conn->error
        );
    }

    $stmt->bind_param("i", $userId);

    if (!$stmt->execute()) {
        throw new Exception(
            "Failed to execute profile query: " . $stmt->error
        );
    }

    $result = $stmt->get_result();

    $row = $result->fetch_assoc();

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | PROFILE NOT FOUND
    |--------------------------------------------------------------------------
    */

    if (!$row) {
        http_response_code(404);

        echo json_encode([
            "success" => false,
            "message" => "Candidate profile not found."
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | JSON HELPER
    |--------------------------------------------------------------------------
    */

    $decodeJson = function ($value) {

        if (
            $value === null ||
            $value === ""
        ) {
            return [];
        }

        /*
        |--------------------------------------------------------------
        | Already array
        |--------------------------------------------------------------
        */

        if (is_array($value)) {
            return $value;
        }

        /*
        |--------------------------------------------------------------
        | Decode JSON
        |--------------------------------------------------------------
        */

        $decoded = json_decode(
            $value,
            true
        );

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($decoded)
        ) {
            return $decoded;
        }

        return [];
    };

    /*
    |--------------------------------------------------------------------------
    | DECODE PROFILE DATA
    |--------------------------------------------------------------------------
    */

    $skills = $decodeJson(
        $row["skills"] ?? null
    );

    $education = $decodeJson(
        $row["education"] ?? null
    );

    $experience = $decodeJson(
        $row["experience"] ?? null
    );

    $projects = $decodeJson(
        $row["projects"] ?? null
    );

    /*
    |--------------------------------------------------------------------------
    | SKILLS FALLBACK
    |--------------------------------------------------------------------------
    |
    | Agar database me skills JSON ke bajay
    | comma-separated format me hain:
    |
    | React, Node.js, MongoDB
    |
    */

    if (
        empty($skills) &&
        !empty($row["skills"])
    ) {

        $skills = array_values(
            array_filter(
                array_map(
                    "trim",
                    explode(",", $row["skills"])
                )
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESUME URL
    |--------------------------------------------------------------------------
    */

    $resume = trim(
        (string) ($row["resume"] ?? "")
    );

    if (
        $resume !== "" &&
        !preg_match(
            '/^https?:\/\//i',
            $resume
        )
    ) {

        if (
            strpos($resume, "/") === 0
        ) {

            $resume =
                "http://localhost" .
                $resume;

        } else {

            $resume =
                "http://localhost/job_portal/job-portal-api/" .
                ltrim($resume, "/");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PROFILE IMAGE URL
    |--------------------------------------------------------------------------
    */

    $profileImage = trim(
        (string) ($row["profile_image"] ?? "")
    );

    if (
        $profileImage !== "" &&
        !preg_match(
            '/^https?:\/\//i',
            $profileImage
        )
    ) {

        if (
            strpos($profileImage, "/") === 0
        ) {

            $profileImage =
                "http://localhost" .
                $profileImage;

        } else {

            $profileImage =
                "http://localhost/job_portal/job-portal-api/" .
                ltrim($profileImage, "/");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CATEGORY
    |--------------------------------------------------------------------------
    */

    $categoryId = !empty($row["category_id"])
        ? (int) $row["category_id"]
        : null;

    $categoryName =
        $row["category_name"] ?? "";

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    echo json_encode(
        [
            "success" => true,

            "profile" => [

                /*
                |--------------------------------------------------------------------------
                | USER INFORMATION
                |--------------------------------------------------------------------------
                */

                "user_id" =>
                    (int) $row["user_id"],

                "candidate_profile_id" =>
                    !empty($row["candidate_profile_id"])
                        ? (int) $row["candidate_profile_id"]
                        : null,

                "name" =>
                    $row["name"] ?? "",

                "email" =>
                    $row["email"] ?? "",

                "phone" =>
                    $row["phone"] ?? "",

                "role" =>
                    $row["role"] ?? "",

                "account_status" =>
                    $row["account_status"] ?? "",

                "created_at" =>
                    $row["created_at"] ?? "",

                /*
                |--------------------------------------------------------------------------
                | PROFILE IMAGE
                |--------------------------------------------------------------------------
                */

                "profile_image" =>
                    $profileImage,

                /*
                |--------------------------------------------------------------------------
                | BASIC PROFILE
                |--------------------------------------------------------------------------
                */

                "headline" =>
                    $row["headline"] ?? "",

                "designation" =>
                    $row["headline"] ?? "",

                "bio" =>
                    $row["bio"] ?? "",

                "about" =>
                    $row["bio"] ?? "",

                "location" =>
                    $row["location"] ?? "",

                "date_of_birth" =>
                    $row["date_of_birth"] ?? "",

                /*
                |--------------------------------------------------------------------------
                | CATEGORY / CAREER FIELD
                |--------------------------------------------------------------------------
                */

                "category_id" =>
                    $categoryId,

                "career_field_id" =>
                    $categoryId,

                "category_name" =>
                    $categoryName,

                "category" =>
                    $categoryName,

                "career_field" =>
                    $categoryName,

                /*
                |--------------------------------------------------------------------------
                | EXPERIENCE
                |--------------------------------------------------------------------------
                */

                "experience_level" =>
                    $row["experience_level"] ?? "",

                /*
                |--------------------------------------------------------------------------
                | ARRAYS
                |--------------------------------------------------------------------------
                */

                "skills" =>
                    $skills,

                "education" =>
                    $education,

                "experience" =>
                    $experience,

                "projects" =>
                    $projects,

                /*
                |--------------------------------------------------------------------------
                | RESUME
                |--------------------------------------------------------------------------
                */

                "resume" =>
                    $resume,

                "resume_url" =>
                    $resume,

                /*
                |--------------------------------------------------------------------------
                | SOCIAL LINKS
                |--------------------------------------------------------------------------
                */

                "linkedin" =>
                    $row["linkedin"] ?? "",

                "github" =>
                    $row["github"] ?? "",

                "portfolio" =>
                    $row["portfolio"] ?? "",

                /*
                |--------------------------------------------------------------------------
                | UPDATED
                |--------------------------------------------------------------------------
                */

                "updated_at" =>
                    $row["updated_at"] ?? ""
            ]
        ],
        JSON_UNESCAPED_UNICODE
    );

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch profile.",
        "error" => $e->getMessage()
    ]);
}

/*
|--------------------------------------------------------------------------
| CLOSE DATABASE
|--------------------------------------------------------------------------
*/

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

?>