<?php
declare(strict_types=1);

/**
 * tests/run.php — Testet e QTA-së, pa varësi të jashtme.
 *
 *   php tests/run.php                 # testet e njësive (pa databazë)
 *   php tests/run.php --integration   # edhe testet me databazë (vetëm databazë testimi!)
 *
 * Testet me databazë lexojnë QTA_DB_HOST / QTA_DB_NAME / QTA_DB_USER / QTA_DB_PASSWORD
 * dhe refuzojnë të punojnë pa QTA_TEST_DB=1, që të mos prekin kurrë databazën e punës.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
  throw new ErrorException($msg, 0, $no, $file, $line);
});

$GLOBALS['QTA_T'] = ['pass' => 0, 'fail' => 0, 'failures' => [], 'current' => ''];

function t_ok(bool $cond, string $what): void
{
  if ($cond) {
    $GLOBALS['QTA_T']['pass']++;
    return;
  }
  $GLOBALS['QTA_T']['fail']++;
  $GLOBALS['QTA_T']['failures'][] = $GLOBALS['QTA_T']['current'] . ' — ' . $what;
}

function t_eq($expected, $actual, string $what): void
{
  $ok = $expected === $actual;
  t_ok($ok, $what . ($ok ? '' : ' | pritej ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', doli ' . json_encode($actual, JSON_UNESCAPED_UNICODE)));
}

/** @param class-string $class */
function t_throws(string $class, callable $fn, string $what, ?string $contains = null): ?Throwable
{
  try {
    $fn();
  } catch (Throwable $e) {
    $ok = $e instanceof $class && ($contains === null || mb_stripos($e->getMessage(), $contains) !== false);
    t_ok($ok, $what . ($ok ? '' : ' | doli ' . get_class($e) . ': ' . $e->getMessage()));
    return $e;
  }
  t_ok(false, $what . ' | nuk u hodh asnjë gabim');
  return null;
}

function t_case(string $name, callable $fn): void
{
  $GLOBALS['QTA_T']['current'] = $name;
  try {
    $fn();
  } catch (Throwable $e) {
    $GLOBALS['QTA_T']['fail']++;
    $GLOBALS['QTA_T']['failures'][] = $name . ' — gabim i papritur: ' . get_class($e) . ': ' . $e->getMessage()
      . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
  }
}

$integration = in_array('--integration', $argv, true);
$files = glob(__DIR__ . '/unit/*_test.php') ?: [];
if ($integration) {
  if (getenv('QTA_TEST_DB') !== '1') {
    fwrite(STDERR, "Testet me databazë kërkojnë QTA_TEST_DB=1 dhe një databazë testimi.\n");
    exit(2);
  }
  $files = array_merge($files, glob(__DIR__ . '/integration/*_test.php') ?: []);
}
sort($files);

foreach ($files as $file) {
  echo '• ', substr($file, strlen(__DIR__) + 1), PHP_EOL;
  require $file;
}

$r = $GLOBALS['QTA_T'];
echo PHP_EOL, 'Kaluan: ', $r['pass'], '  Dështuan: ', $r['fail'], PHP_EOL;
foreach ($r['failures'] as $f) {
  echo '  ✗ ', $f, PHP_EOL;
}
exit($r['fail'] > 0 ? 1 : 0);
