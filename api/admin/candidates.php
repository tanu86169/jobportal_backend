<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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
| ONLY GET
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET request allowed"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| EXPERIENCE FORMATTER
|--------------------------------------------------------------------------
*/

function formatExperience($experience)
{
    if ($experience === null) {
        return "Fresher";
    }

    $experience = trim((string)$experience);

    if ($experience === "") {
        return "Fresher";
    }

    $decoded = json_decode($experience, true);

    if (json_last_error() === JSON_ERROR_NONE) {

        /*
        |--------------------------------------------------------------------------
        | JSON ARRAY
        |--------------------------------------------------------------------------
        */

        if (is_array($decoded) && array_is_list($decoded)) {

            $values = [];

            foreach ($decoded as $item) {

                if (!is_array($item)) {
                    continue;
                }

                if (
                    isset($item["experience"]) &&
                    trim((string)$item["experience"]) !== ""
                ) {
                    $values[] = trim(
                        (string)$item["experience"]
                    );

                    continue;
                }

                if (
                    isset($item["years"]) &&
                    trim((string)$item["years"]) !== ""
                ) {
                    $years = trim(
                        (string)$item["years"]
                    );

                    $values[] =
                        $years .
                        " Year" .
                        ((float)$years == 1 ? "" : "s");

                    continue;
                }

                if (
                    isset($item["experience_years"]) &&
                    trim((string)$item["experience_years"]) !== ""
                ) {
                    $years = trim(
                        (string)$item["experience_years"]
                    );

                    $values[] =
                        $years .
                        " Year" .
                        ((float)$years == 1 ? "" : "s");

                    continue;
                }

                if (
                    isset($item["designation"]) &&
                    trim((string)$item["designation"]) !== ""
                ) {
                    $values[] = trim(
                        (string)$item["designation"]
                    );
                }
            }

            if (!empty($values)) {
                return implode(", ", array_unique($values));
            }

            return "Fresher";
        }

        /*
        |--------------------------------------------------------------------------
        | JSON OBJECT
        |--------------------------------------------------------------------------
        */

        if (is_array($decoded)) {

            if (
                isset($decoded["experience"]) &&
                trim((string)$decoded["experience"]) !== ""
            ) {
                return trim(
                    (string)$decoded["experience"]
                );
            }

            if (
                isset($decoded["years"]) &&
                trim((string)$decoded["years"]) !== ""
            ) {
                $years = trim(
                    (string)$decoded["years"]
                );

                return $years .
                    " Year" .
                    ((float)$years == 1 ? "" : "s");
            }

            if (
                isset($decoded["experience_years"]) &&
                trim((string)$decoded["experience_years"]) !== ""
            ) {
                $years = trim(
                    (string)$decoded["experience_years"]
                );

                return $years .
                    " Year" .
                    ((float)$years == 1 ? "" : "s");
            }

            if (
                isset($decoded["designation"]) &&
                trim((string)$decoded["designation"]) !== ""
            ) {
                return trim(
                    (string)$decoded["designation"]
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | NORMAL TEXT
    |--------------------------------------------------------------------------
    */

    return $experience;
}

/*
|--------------------------------------------------------------------------
| FETCH CANDIDATES
|--------------------------------------------------------------------------
*/

try {

    $sql = "
        SELECT

            /* =========================================================
               USER
            ========================================================= */

            u.id AS user_id,
            u.id AS candidate_id,
            u.name AS candidate_name,
            u.email AS candidate_email,
            u.phone AS candidate_phone,
            u.status AS candidate_status,
            u.created_at AS created_at,

            /* =========================================================
               CATEGORY
            ========================================================= */

            c.id AS category_id,
            c.name AS category_name,
            c.icon AS category_icon,
            c.description AS category_description,

            /* =========================================================
               CANDIDATE PROFILE
            ========================================================= */

            cp.id AS candidate_profile_id,
            cp.profile_image,
            cp.headline,
            cp.bio,
            cp.location,
            cp.education,
            cp.projects,
            cp.skills,
            cp.experience,
            cp.resume,
            cp.linkedin,
            cp.github,
            cp.portfolio,

            /* =========================================================
               APPLICATION
            ========================================================= */

            a.id AS application_id,
            a.job_id,
            a.cover_letter,
            a.resume AS application_resume,
            a.status AS application_status,
            a.applied_at,
            a.interview_note,
            a.interview_date,

            /* =========================================================
               JOB
            ========================================================= */

            j.job_title,
            j.skills AS job_skills,
            j.experience AS job_experience,
            j.company_name,
            j.recruiter_id,

            /* =========================================================
               RECRUITER
            ========================================================= */

            r.name AS recruiter_name

        FROM users u

        LEFT JOIN candidates cp
            ON cp.user_id = u.id

        LEFT JOIN categories c
            ON c.id = cp.category_id

        LEFT JOIN applications a
            ON a.candidate_id = u.id

        LEFT JOIN jobs j
            ON a.job_id = j.id

        LEFT JOIN users r
            ON j.recruiter_id = r.id

        WHERE u.role = 'candidate'

        ORDER BY
            u.id DESC,
            a.id DESC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception($conn->error);
    }

    $candidatesById = [];

    while ($row = $result->fetch_assoc()) {

        /*
        |--------------------------------------------------------------------------
        | USER ID
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | user_id = users.id
        | Messaging ke liye isi ID ko use karna hai.
        |
        */

        $row["user_id"] = $row["user_id"] !== null
            ? (int)$row["user_id"]
            : null;

        /*
        |--------------------------------------------------------------------------
        | CANDIDATE ID
        |--------------------------------------------------------------------------
        */

        $row["candidate_id"] = $row["candidate_id"] !== null
            ? (int)$row["candidate_id"]
            : null;

        /*
        |--------------------------------------------------------------------------
        | CATEGORY ID
        |--------------------------------------------------------------------------
        */

        if ($row["category_id"] !== null) {
            $row["category_id"] =
                (int)$row["category_id"];
        }

        /*
        |--------------------------------------------------------------------------
        | CANDIDATE PROFILE ID
        |--------------------------------------------------------------------------
        */

        if ($row["candidate_profile_id"] !== null) {
            $row["candidate_profile_id"] =
                (int)$row["candidate_profile_id"];
        }

        /*
        |--------------------------------------------------------------------------
        | APPLICATION ID
        |--------------------------------------------------------------------------
        */

        if ($row["application_id"] !== null) {
            $row["application_id"] =
                (int)$row["application_id"];
        }

        /*
        |--------------------------------------------------------------------------
        | JOB ID
        |--------------------------------------------------------------------------
        */

        if ($row["job_id"] !== null) {
            $row["job_id"] =
                (int)$row["job_id"];
        }

        /*
        |--------------------------------------------------------------------------
        | RECRUITER ID
        |--------------------------------------------------------------------------
        */

        if ($row["recruiter_id"] !== null) {
            $row["recruiter_id"] =
                (int)$row["recruiter_id"];
        }

        /*
        |--------------------------------------------------------------------------
        | CATEGORY
        |--------------------------------------------------------------------------
        */

        $row["category_name"] =
            $row["category_name"] ?? "";

        /*
        |--------------------------------------------------------------------------
        | EXPERIENCE
        |--------------------------------------------------------------------------
        */

        $row["experience_display"] =
            formatExperience(
                $row["experience"] ?? ""
            );

        /*
        |--------------------------------------------------------------------------
        | SKILLS
        |--------------------------------------------------------------------------
        */

        if ($row["skills"] === null) {
            $row["skills"] = "";
        }

        /*
        |--------------------------------------------------------------------------
        | JOB SKILLS
        |--------------------------------------------------------------------------
        */

        if ($row["job_skills"] === null) {
            $row["job_skills"] = "";
        }

        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        if (
            $row["candidate_status"] === null ||
            trim((string)$row["candidate_status"]) === ""
        ) {
            $row["candidate_status"] = "active";
        }

        /*
        |--------------------------------------------------------------------------
        | APPLICATION STATUS
        |--------------------------------------------------------------------------
        */

        if ($row["application_status"] === null) {
            $row["application_status"] = "";
        }

        $candidateKey = (string)$row["candidate_id"];
        if (!isset($candidatesById[$candidateKey])) {
            $candidate = $row;
            $candidate["applications"] = [];
            $candidate["application_count"] = 0;
            $candidatesById[$candidateKey] = $candidate;
        }

        if ($row["application_id"] !== null) {
            $candidatesById[$candidateKey]["applications"][] = [
                "application_id" => $row["application_id"],
                "job_id" => $row["job_id"],
                "job_title" => $row["job_title"] ?? "",
                "company_name" => $row["company_name"] ?? "",
                "recruiter_id" => $row["recruiter_id"],
                "recruiter_name" => $row["recruiter_name"] ?? "",
                "job_skills" => $row["job_skills"] ?? "",
                "job_experience" => $row["job_experience"] ?? "",
                "application_status" => $row["application_status"] ?? "Applied",
                "applied_at" => $row["applied_at"],
                "cover_letter" => $row["cover_letter"] ?? "",
                "application_resume" => $row["application_resume"] ?? "",
                "interview_note" => $row["interview_note"] ?? "",
                "interview_date" => $row["interview_date"]
            ];
        }
    }

    $candidates = array_values($candidatesById);
    foreach ($candidates as &$candidate) {
        $candidate["application_count"] = count($candidate["applications"]);

        // Keep the most recent application on the legacy top-level fields
        // used by candidate-level actions and the list summary.
        if (!empty($candidate["applications"])) {
            $candidate = array_merge(
                $candidate,
                $candidate["applications"][0]
            );
        }
    }
    unset($candidate);

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        "success" => true,
        "total" => count($candidates),
        "candidates" => $candidates
    ], JSON_UNESCAPED_UNICODE);

    $result->free();

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch candidates",
        "error" => $e->getMessage()
    ]);

}

$conn->close();

?>