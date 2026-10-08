<?php

// ============================================================
// ADMIN FAQ CRUD API
// File:
// /job_portal/job-portal-api/api/admin/faqs.php
// ============================================================

declare(strict_types=1);

// ============================================================
// ERROR HANDLING
// ============================================================

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ============================================================
// CORS
// ============================================================

$allowedOrigins = [
    'http://localhost:5173',
    'http://localhost:5174',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
header('Content-Type: application/json; charset=UTF-8');

// ============================================================
// OPTIONS
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// DATABASE
// ============================================================

require_once '../../config/database.php';

// ============================================================
// DATABASE CONNECTION CHECK
// ============================================================

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database connection is not available.'
    ]);

    exit;
}

$conn->set_charset('utf8mb4');

// ============================================================
// RESPONSE HELPER
// ============================================================

function responseJson(
    bool $success,
    string $message,
    $data = null,
    int $statusCode = 200
): void {

    http_response_code($statusCode);

    $response = [
        'success' => $success,
        'message' => $message,
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

// ============================================================
// JSON INPUT
// ============================================================

function getJsonInput(): array
{
    $raw = file_get_contents('php://input');

    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}

// ============================================================
// FORMAT FAQ
// ============================================================

function formatFaq(array $row): array
{
    return [
        'id' => (int)($row['id'] ?? 0),

        'category' => (string)($row['category'] ?? ''),

        'question' => (string)($row['question'] ?? ''),

        'answer' => (string)($row['answer'] ?? ''),

        'is_featured' =>
            (int)($row['is_featured'] ?? 0),

        'featured' =>
            (int)($row['featured'] ?? 0),

        'status' =>
            (string)($row['status'] ?? 'active'),

        // Frontend compatibility
        'display_order' =>
            (int)($row['display_order'] ?? 0),

        'sort_order' =>
            (int)($row['display_order'] ?? 0),

        'created_at' =>
            $row['created_at'] ?? null,

        'updated_at' =>
            $row['updated_at'] ?? null,
    ];
}

// ============================================================
// CHECK TABLE
// ============================================================

$tableCheck = $conn->query("SHOW TABLES LIKE 'faqs'");

if (!$tableCheck) {
    responseJson(
        false,
        'Unable to check FAQ table.',
        null,
        500
    );
}

if ($tableCheck->num_rows === 0) {
    responseJson(
        false,
        'FAQ table "faqs" does not exist.',
        null,
        500
    );
}

// ============================================================
// METHOD
// ============================================================

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ============================================================
// GET
// ============================================================

if ($method === 'GET') {

    // --------------------------------------------------------
    // GET SINGLE FAQ
    // --------------------------------------------------------

    $id = isset($_GET['id'])
        ? (int)$_GET['id']
        : 0;

    if ($id > 0) {

        $stmt = $conn->prepare(
            "SELECT
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
             WHERE id = ?
             LIMIT 1"
        );

        if (!$stmt) {
            responseJson(
                false,
                'Failed to prepare FAQ query: ' . $conn->error,
                null,
                500
            );
        }

        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            responseJson(
                false,
                'Failed to fetch FAQ: ' . $error,
                null,
                500
            );
        }

        $result = $stmt->get_result();

        $row = $result->fetch_assoc();

        $stmt->close();

        if (!$row) {
            responseJson(
                false,
                'FAQ not found.',
                null,
                404
            );
        }

        responseJson(
            true,
            'FAQ fetched successfully.',
            [
                'faq' => formatFaq($row)
            ]
        );
    }

    // --------------------------------------------------------
    // FILTERS
    // --------------------------------------------------------

    $search = trim($_GET['search'] ?? '');

    $category = trim(
        $_GET['category'] ?? ''
    );

    $status = trim(
        $_GET['status'] ?? ''
    );

    $featured = trim(
        $_GET['featured'] ?? ''
    );

    // --------------------------------------------------------
    // BUILD WHERE
    // --------------------------------------------------------

    $where = [];
    $params = [];
    $types = '';

    if ($search !== '') {

        $where[] = "
            (
                question LIKE ?
                OR answer LIKE ?
                OR category LIKE ?
            )
        ";

        $searchValue = '%' . $search . '%';

        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;

        $types .= 'sss';
    }

    if ($category !== '' && strtolower($category) !== 'all') {

        $where[] = 'category = ?';

        $params[] = $category;

        $types .= 's';
    }

    if (
        $status !== '' &&
        strtolower($status) !== 'all'
    ) {

        $where[] = 'status = ?';

        $params[] = $status;

        $types .= 's';
    }

    if (
        $featured !== '' &&
        strtolower($featured) !== 'all'
    ) {

        $featuredValue =
            ($featured === '1' || strtolower($featured) === 'true')
                ? 1
                : 0;

        $where[] = 'is_featured = ?';

        $params[] = $featuredValue;

        $types .= 'i';
    }

    // --------------------------------------------------------
    // WHERE SQL
    // --------------------------------------------------------

    $whereSql = '';

    if (!empty($where)) {
        $whereSql =
            ' WHERE ' . implode(' AND ', $where);
    }

    // --------------------------------------------------------
    // MAIN QUERY
    // --------------------------------------------------------

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
        {$whereSql}
        ORDER BY display_order ASC, id ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        responseJson(
            false,
            'Failed to prepare FAQ list query: ' .
            $conn->error,
            null,
            500
        );
    }

    // --------------------------------------------------------
    // BIND FILTER PARAMETERS
    // --------------------------------------------------------

    if (!empty($params)) {

        $bindValues = [];

        $bindValues[] = $types;

        foreach ($params as $key => $value) {
            $bindValues[] = &$params[$key];
        }

        call_user_func_array(
            [$stmt, 'bind_param'],
            $bindValues
        );
    }

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        responseJson(
            false,
            'Failed to fetch FAQs: ' . $error,
            null,
            500
        );
    }

    $result = $stmt->get_result();

    $faqs = [];

    while ($row = $result->fetch_assoc()) {
        $faqs[] = formatFaq($row);
    }

    $stmt->close();

    // --------------------------------------------------------
    // CATEGORIES FROM FAQ TABLE
    // --------------------------------------------------------

    $categoryResult = $conn->query("
        SELECT DISTINCT category
        FROM faqs
        WHERE category IS NOT NULL
        AND TRIM(category) <> ''
        ORDER BY category ASC
    ");

    $categories = [];

    if ($categoryResult) {

        while ($row = $categoryResult->fetch_assoc()) {

            $categoryName =
                trim((string)($row['category'] ?? ''));

            if ($categoryName !== '') {
                $categories[] = $categoryName;
            }
        }
    }

    // --------------------------------------------------------
    // REMOVE DUPLICATES
    // --------------------------------------------------------

    $categories = array_values(
        array_unique($categories)
    );

    // --------------------------------------------------------
    // STATS
    // --------------------------------------------------------

    $total = count($faqs);

    $active = 0;
    $inactive = 0;
    $featuredTotal = 0;

    foreach ($faqs as $faq) {

        if ($faq['status'] === 'active') {
            $active++;
        }

        if ($faq['status'] === 'inactive') {
            $inactive++;
        }

        if ((int)$faq['is_featured'] === 1) {
            $featuredTotal++;
        }
    }

    // --------------------------------------------------------
    // RESPONSE
    // --------------------------------------------------------

    responseJson(
        true,
        'FAQs fetched successfully.',
        [
            'faqs' => $faqs,
            'categories' => $categories,
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'featured_total' => $featuredTotal,
        ]
    );
}

// ============================================================
// POST - CREATE FAQ
// ============================================================

if ($method === 'POST') {

    $data = getJsonInput();

    // --------------------------------------------------------
    // VALUES
    // --------------------------------------------------------

    $category = trim(
        (string)($data['category'] ?? '')
    );

    $question = trim(
        (string)($data['question'] ?? '')
    );

    $answer = trim(
        (string)($data['answer'] ?? '')
    );

    $isFeatured = !empty($data['is_featured'])
        ? 1
        : 0;

    $featured = !empty($data['featured'])
        ? 1
        : 0;

    $status = trim(
        (string)($data['status'] ?? 'active')
    );

    // Support both names
    $displayOrder = isset($data['display_order'])
        ? (int)$data['display_order']
        : (int)($data['sort_order'] ?? 0);

    // --------------------------------------------------------
    // VALIDATION
    // --------------------------------------------------------

    if ($category === '') {
        responseJson(
            false,
            'Category is required.',
            null,
            422
        );
    }

    if ($question === '') {
        responseJson(
            false,
            'Question is required.',
            null,
            422
        );
    }

    if ($answer === '') {
        responseJson(
            false,
            'Answer is required.',
            null,
            422
        );
    }

    if (
        $status !== 'active' &&
        $status !== 'inactive'
    ) {
        $status = 'active';
    }

    if ($displayOrder < 0) {
        $displayOrder = 0;
    }

    // --------------------------------------------------------
    // INSERT
    // --------------------------------------------------------

    $sql = "
        INSERT INTO faqs
        (
            category,
            question,
            answer,
            is_featured,
            featured,
            status,
            display_order
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        responseJson(
            false,
            'Failed to prepare FAQ insert: ' .
            $conn->error,
            null,
            500
        );
    }

    $stmt->bind_param(
        'sssiisi',
        $category,
        $question,
        $answer,
        $isFeatured,
        $featured,
        $status,
        $displayOrder
    );

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        responseJson(
            false,
            'Failed to create FAQ: ' . $error,
            null,
            500
        );
    }

    $newId = (int)$stmt->insert_id;

    $stmt->close();

    // --------------------------------------------------------
    // FETCH CREATED FAQ
    // --------------------------------------------------------

    $stmt = $conn->prepare("
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
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        responseJson(
            true,
            'FAQ created successfully.',
            [
                'id' => $newId
            ]
        );
    }

    $stmt->bind_param('i', $newId);

    $stmt->execute();

    $result = $stmt->get_result();

    $createdFaq = $result->fetch_assoc();

    $stmt->close();

    responseJson(
        true,
        'FAQ created successfully.',
        [
            'faq' => $createdFaq
                ? formatFaq($createdFaq)
                : ['id' => $newId]
        ],
        201
    );
}

// ============================================================
// PUT - UPDATE FAQ
// ============================================================

if ($method === 'PUT') {

    $data = getJsonInput();

    $id = (int)($data['id'] ?? 0);

    if ($id <= 0) {
        responseJson(
            false,
            'Valid FAQ ID is required.',
            null,
            422
        );
    }

    $category = trim(
        (string)($data['category'] ?? '')
    );

    $question = trim(
        (string)($data['question'] ?? '')
    );

    $answer = trim(
        (string)($data['answer'] ?? '')
    );

    $isFeatured = !empty($data['is_featured'])
        ? 1
        : 0;

    $featured = !empty($data['featured'])
        ? 1
        : 0;

    $status = trim(
        (string)($data['status'] ?? 'active')
    );

    $displayOrder = isset($data['display_order'])
        ? (int)$data['display_order']
        : (int)($data['sort_order'] ?? 0);

    // --------------------------------------------------------
    // VALIDATION
    // --------------------------------------------------------

    if ($category === '') {
        responseJson(
            false,
            'Category is required.',
            null,
            422
        );
    }

    if ($question === '') {
        responseJson(
            false,
            'Question is required.',
            null,
            422
        );
    }

    if ($answer === '') {
        responseJson(
            false,
            'Answer is required.',
            null,
            422
        );
    }

    if (
        $status !== 'active' &&
        $status !== 'inactive'
    ) {
        $status = 'active';
    }

    if ($displayOrder < 0) {
        $displayOrder = 0;
    }

    // --------------------------------------------------------
    // UPDATE
    // --------------------------------------------------------

    $sql = "
        UPDATE faqs
        SET
            category = ?,
            question = ?,
            answer = ?,
            is_featured = ?,
            featured = ?,
            status = ?,
            display_order = ?
        WHERE id = ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        responseJson(
            false,
            'Failed to prepare FAQ update: ' .
            $conn->error,
            null,
            500
        );
    }

    $stmt->bind_param(
        'sssiisii',
        $category,
        $question,
        $answer,
        $isFeatured,
        $featured,
        $status,
        $displayOrder,
        $id
    );

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        responseJson(
            false,
            'Failed to update FAQ: ' . $error,
            null,
            500
        );
    }

    $affectedRows = $stmt->affected_rows;

    $stmt->close();

    // --------------------------------------------------------
    // FETCH UPDATED
    // --------------------------------------------------------

    $stmt = $conn->prepare("
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
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        responseJson(
            true,
            'FAQ updated successfully.',
            [
                'id' => $id,
                'affected_rows' => $affectedRows
            ]
        );
    }

    $stmt->bind_param('i', $id);

    $stmt->execute();

    $result = $stmt->get_result();

    $updatedFaq = $result->fetch_assoc();

    $stmt->close();

    responseJson(
        true,
        'FAQ updated successfully.',
        [
            'faq' => $updatedFaq
                ? formatFaq($updatedFaq)
                : ['id' => $id],
            'affected_rows' => $affectedRows
        ]
    );
}

// ============================================================
// DELETE
// ============================================================

if ($method === 'DELETE') {

    $id = isset($_GET['id'])
        ? (int)$_GET['id']
        : 0;

    // Also support JSON body
    if ($id <= 0) {

        $data = getJsonInput();

        $id = (int)($data['id'] ?? 0);
    }

    if ($id <= 0) {
        responseJson(
            false,
            'Valid FAQ ID is required.',
            null,
            422
        );
    }

    // --------------------------------------------------------
    // CHECK EXISTS
    // --------------------------------------------------------

    $stmt = $conn->prepare(
        "SELECT id FROM faqs WHERE id = ? LIMIT 1"
    );

    if (!$stmt) {
        responseJson(
            false,
            'Failed to check FAQ: ' .
            $conn->error,
            null,
            500
        );
    }

    $stmt->bind_param('i', $id);

    $stmt->execute();

    $result = $stmt->get_result();

    $exists = $result->fetch_assoc();

    $stmt->close();

    if (!$exists) {
        responseJson(
            false,
            'FAQ not found.',
            null,
            404
        );
    }

    // --------------------------------------------------------
    // DELETE
    // --------------------------------------------------------

    $stmt = $conn->prepare(
        "DELETE FROM faqs WHERE id = ?"
    );

    if (!$stmt) {
        responseJson(
            false,
            'Failed to prepare FAQ delete: ' .
            $conn->error,
            null,
            500
        );
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        responseJson(
            false,
            'Failed to delete FAQ: ' . $error,
            null,
            500
        );
    }

    $stmt->close();

    responseJson(
        true,
        'FAQ deleted successfully.',
        [
            'id' => $id
        ]
    );
}

// ============================================================
// INVALID METHOD
// ============================================================

responseJson(
    false,
    'Method not allowed.',
    null,
    405
);

// ============================================================
// GLOBAL ERROR HANDLER
// ============================================================

register_shutdown_function(function () {

    $error = error_get_last();

    if ($error === null) {
        return;
    }

    $fatalTypes = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
    ];

    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    // Response may already have started.
    // This is mainly useful during debugging.
    if (!headers_sent()) {
        http_response_code(500);

        header(
            'Content-Type: application/json; charset=UTF-8'
        );
    }

    echo json_encode([
        'success' => false,
        'message' => 'PHP fatal error in FAQ API.',
        'error' => $error['message'],
        'file' => $error['file'],
        'line' => $error['line'],
    ]);
});