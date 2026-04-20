<?php
declare(strict_types=1);

use RareFolio\Api\Response;

Response::ok([
    'name'    => 'Rarefolio Public API',
    'version' => 'v1',
    'endpoints' => [
        'GET /api/v1/health'              => 'service liveness',
        'GET /api/v1/tokens/{cnft_id}'    => 'look up a single CNFT by rarefolio_token_id',
        'GET /api/v1/bars/{bar_serial}'   => 'summary for a physical silver bar',
        'GET /api/v1/listings'            => 'current active listings (query: bar, format, limit, offset)',
    ],
]);
