<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$ctx = stream_context_create(['http' => ['header' => 'Cookie: PHPSESSID=' . $argv[2], 'timeout' => 10]]);
echo file_get_contents($argv[1], false, $ctx);
