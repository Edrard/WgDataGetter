<?php

declare(strict_types=1);

use edrard\WgGetter\Exceptions\RequestException;
use edrard\WgGetter\WgDataGetter;

require dirname(__DIR__).'/vendor/autoload.php';

$id = getenv('WG_APPLICATION_ID') ?: trim((string) fgets(STDIN));
if ($id === '') {
    fwrite(STDERR, "Provide WG_APPLICATION_ID or the application ID on stdin.\n");
    exit(1);
}
$getter = new WgDataGetter(multi: 1);
$getter->setUrls(['info' => 'https://api.worldoftanks.eu/wot/encyclopedia/info/?'.http_build_query([
    'application_id' => $id, 'fields' => 'tanks_updated_at',
], '', '&', PHP_QUERY_RFC3986)]);
try {
    $envelope = $getter->getEnvelopes()['info'];
    echo json_encode(['status' => $envelope['status'], 'meta' => $envelope['meta'] ?? []], JSON_THROW_ON_ERROR)."\n";
} catch (RequestException $exception) {
    fwrite(STDERR, 'WG request failed, code '.$exception->getCode().".\n");
    exit(1);
}
