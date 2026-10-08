<?php

header("Content-Type: application/json; charset=UTF-8");

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
}

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400");


/*
|--------------------------------------------------------------------------
| OPTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {

    http_response_code(200);

    echo json_encode([
        "success" => true,
        "message" => "CORS OK"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Only GET
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET request is allowed."
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Error Settings
|--------------------------------------------------------------------------
*/

ini_set("display_errors", "0");
ini_set("display_startup_errors", "0");

error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_OFF);


/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once "../../config/database.php";


/*
|--------------------------------------------------------------------------
| Database Check
|--------------------------------------------------------------------------
*/

if (!isset($conn)) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection variable \$conn was not found."
    ]);

    exit;
}

if (!($conn instanceof mysqli)) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection is not a valid mysqli connection."
    ]);

    exit;
}

$conn->set_charset("utf8mb4");


/*
|--------------------------------------------------------------------------
| Response Helper
|--------------------------------------------------------------------------
*/

function sendResponse(
    bool $success,
    string $message,
    $data = null,
    int $statusCode = 200
) {

    http_response_code($statusCode);

    $response = [
        "success" => $success,
        "message" => $message
    ];

    if ($data !== null) {
        $response["data"] = $data;
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Check FAQ Table
|--------------------------------------------------------------------------
*/

$tableCheck = $conn->query("SHOW TABLES LIKE 'faqs'");

if (!$tableCheck) {

    sendResponse(
        false,
        "Unable to check faqs table: " . $conn->error,
        null,
        500
    );
}

if ($tableCheck->num_rows === 0) {

    sendResponse(
        false,
        "faqs table does not exist.",
        null,
        500
    );
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$category = trim($_GET["category"] ?? "");
$featured = trim($_GET["featured"] ?? "");
$search = trim($_GET["search"] ?? "");


/*
|--------------------------------------------------------------------------
| WHERE Conditions
|--------------------------------------------------------------------------
*/

$where = [
    "status = 'active'"
];

$params = [];
$types = "";


/*
|--------------------------------------------------------------------------
| Category Filter
|--------------------------------------------------------------------------
*/

if (
    $category !== "" &&
    strtolower($category) !== "all"
) {

    $where[] = "category = ?";

    $params[] = $category;

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| Featured Filter
|--------------------------------------------------------------------------
*/

if ($featured !== "") {

    if (
        $featured === "1" ||
        strtolower($featured) === "true"
    ) {

        $where[] = "is_featured = 1";
    }

    if (
        $featured === "0" ||
        strtolower($featured) === "false"
    ) {

        $where[] = "is_featured = 0";
    }
}


/*
|--------------------------------------------------------------------------
| Search Filter
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $where[] = "
        (
            question LIKE ?
            OR answer LIKE ?
            OR category LIKE ?
        )
    ";

    $searchValue = "%" . $search . "%";

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= "sss";
}


/*
|--------------------------------------------------------------------------
| Build WHERE
|--------------------------------------------------------------------------
*/

$whereSQL = "";

if (!empty($where)) {

    $whereSQL = "WHERE " . implode(" AND ", $where);
}


/*
|--------------------------------------------------------------------------
| GET FAQs
|
| IMPORTANT:
| Your database uses display_order,
| NOT sort_order.
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        question,
        answer,
        is_featured,
        category,
        featured,
        status,
        display_order,
        created_at,
        updated_at
    FROM faqs
    $whereSQL
    ORDER BY display_order ASC, id ASC
";


$stmt = $conn->prepare($sql);

if (!$stmt) {

    sendResponse(
        false,
        "FAQ query prepare failed: " . $conn->error,
        null,
        500
    );
}


/*
|--------------------------------------------------------------------------
| Bind Parameters
|--------------------------------------------------------------------------
*/

if (!empty($params)) {

    $bindParams = [];

    $bindParams[] = $types;

    foreach ($params as $key => $value) {

        $bindParams[] = &$params[$key];
    }

    if (!call_user_func_array(
        [$stmt, "bind_param"],
        $bindParams
    )) {

        $stmt->close();

        sendResponse(
            false,
            "Failed to bind FAQ query parameters.",
            null,
            500
        );
    }
}


/*
|--------------------------------------------------------------------------
| Execute
|--------------------------------------------------------------------------
*/

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();

    sendResponse(
        false,
        "FAQ query execution failed: " . $error,
        null,
        500
    );
}


/*
|--------------------------------------------------------------------------
| Result
|--------------------------------------------------------------------------
*/

$result = $stmt->get_result();

if (!$result) {

    $error = $stmt->error;

    $stmt->close();

    sendResponse(
        false,
        "Unable to read FAQ result: " . $error,
        null,
        500
    );
}


/*
|--------------------------------------------------------------------------
| FAQ Array
|--------------------------------------------------------------------------
*/

$faqs = [];

while ($row = $result->fetch_assoc()) {

    $faqs[] = [
        "id" => (int) ($row["id"] ?? 0),

        "question" => $row["question"] ?? "",

        "answer" => $row["answer"] ?? "",

        "is_featured" =>
            (int) ($row["is_featured"] ?? 0),

        "category" =>
            $row["category"] ?? "General",

        "featured" =>
            (int) ($row["featured"] ?? 0),

        "status" =>
            $row["status"] ?? "active",

        "display_order" =>
            (int) ($row["display_order"] ?? 0),

        "created_at" =>
            $row["created_at"] ?? null,

        "updated_at" =>
            $row["updated_at"] ?? null
    ];
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

$categories = [];

$categorySQL = "
    SELECT DISTINCT category
    FROM faqs
    WHERE status = 'active'
      AND category IS NOT NULL
      AND category != ''
    ORDER BY category ASC
";

$categoryResult = $conn->query($categorySQL);

if (!$categoryResult) {

    sendResponse(
        false,
        "Category query failed: " . $conn->error,
        null,
        500
    );
}

while ($row = $categoryResult->fetch_assoc()) {

    $categories[] = $row["category"];
}


/*
|--------------------------------------------------------------------------
| Total Active FAQs
|--------------------------------------------------------------------------
*/

$total = 0;

$totalResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM faqs
    WHERE status = 'active'
");

if (!$totalResult) {

    sendResponse(
        false,
        "Total FAQ query failed: " . $conn->error,
        null,
        500
    );
}

$totalRow = $totalResult->fetch_assoc();

$total = (int) ($totalRow["total"] ?? 0);


/*
|--------------------------------------------------------------------------
| Featured FAQ Count
|--------------------------------------------------------------------------
*/

$featuredTotal = 0;

$featuredResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM faqs
    WHERE status = 'active'
      AND is_featured = 1
");

if (!$featuredResult) {

    sendResponse(
        false,
        "Featured FAQ query failed: " . $conn->error,
        null,
        500
    );
}

$featuredRow = $featuredResult->fetch_assoc();

$featuredTotal =
    (int) ($featuredRow["total"] ?? 0);


/*
|--------------------------------------------------------------------------
| Final Response
|--------------------------------------------------------------------------
*/

sendResponse(
    true,
    "FAQs fetched successfully.",
    [
        "faqs" => $faqs,
        "categories" => $categories,
        "total" => $total,
        "featured_total" => $featuredTotal
    ],
    200
);