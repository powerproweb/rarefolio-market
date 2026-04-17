<?php
/**
 * JSON endpoint for validating a CIP-25 asset object.
 *
 * POST body: { "asset": { ... } }
 * Response:  { "valid": bool, "errors": [...], "warnings": [...] }
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Cip25\Validator;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['asset']) || !is_array($body['asset'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Expected JSON body with `asset` object.']);
    exit;
}

echo json_encode(Validator::validate($body['asset']), JSON_UNESCAPED_SLASHES);
