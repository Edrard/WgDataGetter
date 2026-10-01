<?php

declare(strict_types=1);

use edrard\WgGetter\WgDataGetter;

require dirname(__DIR__).'/vendor/autoload.php';

$id = getenv('WG_APPLICATION_ID') ?: throw new LogicException('Configure WG_APPLICATION_ID.');
$getter = new WgDataGetter();
$getter->setUrls([
    'info' => 'https://api.worldoftanks.eu/wot/encyclopedia/info/?'.http_build_query([
        'application_id' => $id,
        'fields' => 'tanks_updated_at',
    ], '', '&', PHP_QUERY_RFC3986),
]);
$result = $getter->getData()['info'];
echo json_encode([
    'http_status' => $result->httpStatus,
    'attempts' => $result->attempts,
    'response_bytes' => strlen($result->body ?? ''),
], JSON_THROW_ON_ERROR).PHP_EOL;
