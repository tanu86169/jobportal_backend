<?php

/*
|--------------------------------------------------------------------------
| ADMIN SETTINGS API
|--------------------------------------------------------------------------
| GET  -> All settings fetch
| PUT  -> Settings update / insert
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174",
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
}

header("Access-Control-Allow-Methods: GET, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");


/*
|--------------------------------------------------------------------------
| OPTIONS / PREFLIGHT
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
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
| Agar database me setting missing hai to ye default values use hongi.
|--------------------------------------------------------------------------
*/

$defaultSettings = [

    [
        "setting_key"   => "site_name",
        "setting_value" => "JobPortal",
        "setting_type"  => "text",
        "description"   => "Website name"
    ],

    [
        "setting_key"   => "site_email",
        "setting_value" => "",
        "setting_type"  => "email",
        "description"   => "Website contact email"
    ],

    [
        "setting_key"   => "site_phone",
        "setting_value" => "",
        "setting_type"  => "text",
        "description"   => "Website contact phone"
    ],

    [
        "setting_key"   => "maintenance_mode",
        "setting_value" => "0",
        "setting_type"  => "boolean",
        "description"   => "Enable or disable website maintenance mode"
    ],

    [
        "setting_key"   => "allow_registration",
        "setting_value" => "1",
        "setting_type"  => "boolean",
        "description"   => "Allow new users to register"
    ],

    [
        "setting_key"   => "allow_job_posting",
        "setting_value" => "1",
        "setting_type"  => "boolean",
        "description"   => "Allow recruiters to post jobs"
    ]

];


try {

    /*
    |--------------------------------------------------------------------------
    | GET SETTINGS
    |--------------------------------------------------------------------------
    */

    if ($_SERVER["REQUEST_METHOD"] === "GET") {

        $sql = "
            SELECT
                id,
                setting_key,
                setting_value,
                setting_type,
                description,
                updated_at
            FROM admin_settings
            ORDER BY id ASC
        ";

        $result = $conn->query($sql);

        if (!$result) {
            throw new Exception(
                "Failed to fetch settings: " . $conn->error
            );
        }

        $databaseSettings = [];

        while ($row = $result->fetch_assoc()) {

            $databaseSettings[$row["setting_key"]] = $row;

        }


        /*
        |--------------------------------------------------------------------------
        | MERGE DATABASE SETTINGS WITH DEFAULT SETTINGS
        |--------------------------------------------------------------------------
        */

        $settings = [];

        foreach ($defaultSettings as $default) {

            $key = $default["setting_key"];

            if (isset($databaseSettings[$key])) {

                $settings[] = $databaseSettings[$key];

            } else {

                /*
                |--------------------------------------------------------------
                | Missing setting database me create kar do
                |--------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    INSERT INTO admin_settings
                    (
                        setting_key,
                        setting_value,
                        setting_type,
                        description
                    )
                    VALUES (?, ?, ?, ?)
                ");

                if (!$stmt) {
                    throw new Exception($conn->error);
                }

                $stmt->bind_param(
                    "ssss",
                    $default["setting_key"],
                    $default["setting_value"],
                    $default["setting_type"],
                    $default["description"]
                );

                $stmt->execute();

                $newId = $stmt->insert_id;

                $stmt->close();


                /*
                |--------------------------------------------------------------
                | Newly created setting response me add karo
                |--------------------------------------------------------------
                */

                $settings[] = [
                    "id"            => $newId,
                    "setting_key"   => $default["setting_key"],
                    "setting_value" => $default["setting_value"],
                    "setting_type"  => $default["setting_type"],
                    "description"   => $default["description"],
                    "updated_at"    => date("Y-m-d H:i:s")
                ];

            }

        }


        /*
        |--------------------------------------------------------------------------
        | EXTRA SETTINGS
        |--------------------------------------------------------------------------
        | Agar database me future me koi extra setting add ho,
        | to usko bhi response me include karenge.
        |--------------------------------------------------------------------------
        */

        foreach ($databaseSettings as $key => $databaseSetting) {

            $alreadyExists = false;

            foreach ($settings as $setting) {

                if ($setting["setting_key"] === $key) {
                    $alreadyExists = true;
                    break;
                }

            }

            if (!$alreadyExists) {
                $settings[] = $databaseSetting;
            }

        }


        echo json_encode([
            "success"  => true,
            "total"    => count($settings),
            "settings" => $settings
        ]);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | PUT SETTINGS
    |--------------------------------------------------------------------------
    */

    if ($_SERVER["REQUEST_METHOD"] === "PUT") {

        $rawData = file_get_contents("php://input");

        $data = json_decode($rawData, true);


        /*
        |--------------------------------------------------------------------------
        | JSON VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {

            http_response_code(400);

            echo json_encode([
                "success" => false,
                "message" => "Invalid JSON data"
            ]);

            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | SETTINGS VALIDATION
        |--------------------------------------------------------------------------
        */

        if (
            !isset($data["settings"]) ||
            !is_array($data["settings"])
        ) {

            http_response_code(400);

            echo json_encode([
                "success" => false,
                "message" => "Settings data is required"
            ]);

            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | TRANSACTION START
        |--------------------------------------------------------------------------
        */

        $conn->begin_transaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | UPSERT QUERY
            |--------------------------------------------------------------------------
            |
            | Agar setting_key already exist:
            |     UPDATE
            |
            | Agar setting_key exist nahi:
            |     INSERT
            |
            */

            $stmt = $conn->prepare("
                INSERT INTO admin_settings
                (
                    setting_key,
                    setting_value,
                    setting_type,
                    description
                )
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    setting_type = VALUES(setting_type),
                    description = VALUES(description)
            ");

            if (!$stmt) {
                throw new Exception(
                    "Failed to prepare settings query: " . $conn->error
                );
            }


            $updatedSettings = [];


            /*
            |--------------------------------------------------------------------------
            | PROCESS EACH SETTING
            |--------------------------------------------------------------------------
            */

            foreach ($data["settings"] as $setting) {

                /*
                |------------------------------------------------------------------
                | Required fields
                |------------------------------------------------------------------
                */

                if (
                    !isset($setting["setting_key"]) ||
                    !isset($setting["setting_value"])
                ) {
                    continue;
                }


                $settingKey = trim(
                    (string) $setting["setting_key"]
                );


                /*
                |------------------------------------------------------------------
                | Empty key skip
                |------------------------------------------------------------------
                */

                if ($settingKey === "") {
                    continue;
                }


                /*
                |------------------------------------------------------------------
                | Setting value
                |------------------------------------------------------------------
                */

                $settingValue = $setting["setting_value"];


                /*
                |------------------------------------------------------------------
                | Convert array/object to JSON
                |------------------------------------------------------------------
                */

                if (
                    is_array($settingValue) ||
                    is_object($settingValue)
                ) {

                    $settingValue = json_encode(
                        $settingValue,
                        JSON_UNESCAPED_UNICODE
                    );

                } else {

                    $settingValue = (string) $settingValue;

                }


                /*
                |------------------------------------------------------------------
                | Setting type
                |------------------------------------------------------------------
                */

                $settingType = isset($setting["setting_type"])
                    ? trim((string) $setting["setting_type"])
                    : "text";


                /*
                |------------------------------------------------------------------
                | Description
                |------------------------------------------------------------------
                */

                $description = isset($setting["description"])
                    ? trim((string) $setting["description"])
                    : "";


                /*
                |------------------------------------------------------------------
                | Boolean settings validation
                |------------------------------------------------------------------
                */

                if (
                    $settingKey === "maintenance_mode" ||
                    $settingKey === "allow_registration" ||
                    $settingKey === "allow_job_posting"
                ) {

                    $settingValue = (
                        $settingValue === "1" ||
                        $settingValue === "true" ||
                        $settingValue === "on"
                    )
                        ? "1"
                        : "0";

                    $settingType = "boolean";

                }


                /*
                |------------------------------------------------------------------
                | Bind
                |------------------------------------------------------------------
                */

                $stmt->bind_param(
                    "ssss",
                    $settingKey,
                    $settingValue,
                    $settingType,
                    $description
                );


                /*
                |------------------------------------------------------------------
                | Execute
                |------------------------------------------------------------------
                */

                if (!$stmt->execute()) {

                    throw new Exception(
                        "Failed to save setting: " . $settingKey
                    );

                }


                $updatedSettings[] = [
                    "setting_key"   => $settingKey,
                    "setting_value" => $settingValue,
                    "setting_type"  => $settingType,
                    "description"   => $description
                ];

            }


            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | COMMIT
            |--------------------------------------------------------------------------
            */

            $conn->commit();


            /*
            |--------------------------------------------------------------------------
            | GET UPDATED SETTINGS
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
                throw new Exception(
                    "Settings saved but failed to reload data"
                );
            }


            $allSettings = [];

            while ($row = $result->fetch_assoc()) {
                $allSettings[] = $row;
            }


            /*
            |--------------------------------------------------------------------------
            | SUCCESS RESPONSE
            |--------------------------------------------------------------------------
            */

            echo json_encode([
                "success" => true,
                "message" => "Settings updated successfully",
                "updated" => count($updatedSettings),
                "settings" => $allSettings
            ]);

            exit;

        } catch (Exception $e) {

            /*
            |--------------------------------------------------------------------------
            | ROLLBACK
            |--------------------------------------------------------------------------
            */

            $conn->rollback();

            throw $e;

        }

    }


    /*
    |--------------------------------------------------------------------------
    | METHOD NOT ALLOWED
    |--------------------------------------------------------------------------
    */

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Method not allowed"
    ]);

    exit;


} catch (Exception $e) {

    /*
    |--------------------------------------------------------------------------
    | SERVER ERROR
    |--------------------------------------------------------------------------
    */

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);

    exit;

}

?>