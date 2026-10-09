
<?php

/*
|--------------------------------------------------------------------------
| ADMIN SETTINGS API
|--------------------------------------------------------------------------
| GET  -> Fetch all settings
| PUT  -> Insert or update settings
|--------------------------------------------------------------------------
*/

ini_set("display_errors", "0");
ini_set("log_errors", "1");
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174",
    "http://192.168.1.50:5173",
    "http://192.168.1.50:5174",

    // Replace with your actual deployed frontend origin
    "https://YOUR-FRONTEND-DOMAIN"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Access-Control-Allow-Credentials: true");
    header("Vary: Origin");
}

header("Access-Control-Allow-Methods: GET, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

/*
|--------------------------------------------------------------------------
| PREFLIGHT
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| ALLOWED METHODS
|--------------------------------------------------------------------------
*/

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

if (!in_array($method, ["GET", "PUT"], true)) {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Method not allowed"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

require_once "../../config/database.php";

/*
|--------------------------------------------------------------------------
| DEFAULT SETTINGS
|--------------------------------------------------------------------------
*/

$defaultSettings = [
    [
        "setting_key" => "site_name",
        "setting_value" => "JobPortal",
        "setting_type" => "text",
        "description" => "Website name"
    ],
    [
        "setting_key" => "site_email",
        "setting_value" => "",
        "setting_type" => "email",
        "description" => "Website contact email"
    ],
    [
        "setting_key" => "site_phone",
        "setting_value" => "",
        "setting_type" => "text",
        "description" => "Website contact phone"
    ],
    [
        "setting_key" => "maintenance_mode",
        "setting_value" => "0",
        "setting_type" => "boolean",
        "description" => "Enable or disable website maintenance mode"
    ],
    [
        "setting_key" => "allow_registration",
        "setting_value" => "1",
        "setting_type" => "boolean",
        "description" => "Allow new users to register"
    ],
    [
        "setting_key" => "allow_job_posting",
        "setting_value" => "1",
        "setting_type" => "boolean",
        "description" => "Allow recruiters to post jobs"
    ]
];

/*
|--------------------------------------------------------------------------
| HELPER: JSON RESPONSE
|--------------------------------------------------------------------------
*/

function sendJson($statusCode, $data)
{
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| MAIN API
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | GET SETTINGS
    |--------------------------------------------------------------------------
    */

    if ($method === "GET") {

        $result = $conn->query("
            SELECT
                id,
                setting_key,
                setting_value,
                setting_type,
                description,
                updated_at
            FROM admin_settings
            ORDER BY id ASC
        ");

        if (!$result) {
            throw new Exception("Failed to fetch settings");
        }

        $databaseSettings = [];

        while ($row = $result->fetch_assoc()) {
            $databaseSettings[$row["setting_key"]] = $row;
        }

        $settings = [];

        foreach ($defaultSettings as $default) {

            $key = $default["setting_key"];

            if (isset($databaseSettings[$key])) {
                $settings[] = $databaseSettings[$key];
                continue;
            }

            // Insert a missing default setting
            $stmt = $conn->prepare("
                INSERT INTO admin_settings
                    (setting_key, setting_value, setting_type, description)
                VALUES (?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new Exception("Failed to prepare default setting");
            }

            $stmt->bind_param(
                "ssss",
                $default["setting_key"],
                $default["setting_value"],
                $default["setting_type"],
                $default["description"]
            );

            if (!$stmt->execute()) {
                $stmt->close();

                // Another request may have inserted this key.
                $check = $conn->prepare("
                    SELECT id, setting_key, setting_value,
                           setting_type, description, updated_at
                    FROM admin_settings
                    WHERE setting_key = ?
                    LIMIT 1
                ");

                if (!$check) {
                    throw new Exception("Failed to check setting");
                }

                $check->bind_param("s", $key);
                $check->execute();

                $existing = $check->get_result()->fetch_assoc();
                $check->close();

                if (!$existing) {
                    throw new Exception("Failed to insert default setting");
                }

                $settings[] = $existing;
                continue;
            }

            $newId = $conn->insert_id;
            $stmt->close();

            $settings[] = [
                "id" => $newId,
                "setting_key" => $default["setting_key"],
                "setting_value" => $default["setting_value"],
                "setting_type" => $default["setting_type"],
                "description" => $default["description"],
                "updated_at" => date("Y-m-d H:i:s")
            ];
        }

        // Include any additional settings already in the database.
        foreach ($databaseSettings as $key => $dbSetting) {

            $exists = false;

            foreach ($settings as $setting) {
                if ($setting["setting_key"] === $key) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $settings[] = $dbSetting;
            }
        }

        sendJson(200, [
            "success" => true,
            "total" => count($settings),
            "settings" => $settings
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PUT SETTINGS
    |--------------------------------------------------------------------------
    */

    if ($method === "PUT") {

        $rawData = file_get_contents("php://input");
        $data = json_decode($rawData, true);

        if (
            json_last_error() !== JSON_ERROR_NONE ||
            !is_array($data)
        ) {
            sendJson(400, [
                "success" => false,
                "message" => "Invalid JSON data"
            ]);
        }

        if (
            !isset($data["settings"]) ||
            !is_array($data["settings"])
        ) {
            sendJson(400, [
                "success" => false,
                "message" => "Settings data is required"
            ]);
        }

        $conn->begin_transaction();

        try {

            /*
            |------------------------------------------------------------------
            | INSERT OR UPDATE
            |------------------------------------------------------------------
            | setting_key must have a UNIQUE index in the database.
            */

            $stmt = $conn->prepare("
                INSERT INTO admin_settings
                    (setting_key, setting_value, setting_type, description)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    setting_type = VALUES(setting_type),
                    description = VALUES(description)
            ");

            if (!$stmt) {
                throw new Exception("Failed to prepare settings query");
            }

            $updatedCount = 0;

            foreach ($data["settings"] as $setting) {

                if (
                    !is_array($setting) ||
                    !array_key_exists("setting_key", $setting) ||
                    !array_key_exists("setting_value", $setting)
                ) {
                    continue;
                }

                $settingKey = trim((string) $setting["setting_key"]);

                if ($settingKey === "") {
                    continue;
                }

                $value = $setting["setting_value"];

                if (is_array($value) || is_object($value)) {
                    $settingValue = json_encode(
                        $value,
                        JSON_UNESCAPED_UNICODE
                    );

                    if ($settingValue === false) {
                        throw new Exception("Invalid setting value");
                    }
                } elseif (is_bool($value)) {
                    $settingValue = $value ? "1" : "0";
                } elseif ($value === null) {
                    $settingValue = "";
                } else {
                    $settingValue = (string) $value;
                }

                $settingType = isset($setting["setting_type"])
                    ? trim((string) $setting["setting_type"])
                    : "text";

                $description = isset($setting["description"])
                    ? trim((string) $setting["description"])
                    : "";

                // Normalize known boolean settings.
                if (in_array($settingKey, [
                    "maintenance_mode",
                    "allow_registration",
                    "allow_job_posting"
                ], true)) {

                    $settingValue = in_array(
                        strtolower($settingValue),
                        ["1", "true", "on"],
                        true
                    ) ? "1" : "0";

                    $settingType = "boolean";
                }

                $stmt->bind_param(
                    "ssss",
                    $settingKey,
                    $settingValue,
                    $settingType,
                    $description
                );

                if (!$stmt->execute()) {
                    throw new Exception("Failed to save a setting");
                }

                $updatedCount++;
            }

            $stmt->close();

            $conn->commit();

        } catch (Throwable $e) {

            $conn->rollback();
            throw $e;
        }

        /*
        |--------------------------------------------------------------------------
        | RETURN UPDATED SETTINGS
        |--------------------------------------------------------------------------
        */

        $result = $conn->query("
            SELECT
                id,
                setting_key,
                setting_value,
                setting_type,
                description,
                updated_at
            FROM admin_settings
            ORDER BY id ASC
        ");

        if (!$result) {
            throw new Exception("Settings saved, but reload failed");
        }

        $allSettings = [];

        while ($row = $result->fetch_assoc()) {
            $allSettings[] = $row;
        }

        sendJson(200, [
            "success" => true,
            "message" => "Settings updated successfully",
            "updated" => $updatedCount,
            "settings" => $allSettings
        ]);
    }

} catch (Throwable $e) {

    error_log("Admin settings API error: " . $e->getMessage());

    sendJson(500, [
        "success" => false,
        "message" => "An internal server error occurred"
    ]);
}
?>
