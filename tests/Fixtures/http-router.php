<?php

declare(strict_types=1);
header('Content-Type: application/json');
if (str_starts_with($_SERVER['REQUEST_URI'], '/limited')) {
    http_response_code(429);
    header('Retry-After: 2');
    echo '{"status":"error","error":{"code":407,"message":"REQUEST_LIMIT_EXCEEDED"}}';
} else {
    echo '{"status":"ok","data":{"fixture":true}}';
}
