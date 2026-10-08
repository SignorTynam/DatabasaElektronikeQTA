<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../app/shared/document_generation.php';
[$script, $id, $mode] = $argv;
qta_document_write($id, ['owner' => 'test', 'status' => 'working', 'percent' => 0, 'message' => 'test']);
qta_document_capture($id);
qta_download_status('ok', 'legacy early success');
if (qta_document_read($id)['status'] !== 'working') throw new RuntimeException('Premature completion');
ob_start(); echo 'discarded HTML'; qta_export_clean_output();
qta_export_header('Content-Type: application/pdf');
qta_export_header('Content-Disposition: attachment; filename="test.pdf"');
if ($mode === 'error') { qta_download_status('error', 'Test error'); echo 'sensitive partial bytes'; exit; }
if ($mode === 'fatal') { trigger_error('Test fatal after early success', E_USER_ERROR); }
if ($mode === 'memory') { ini_set('memory_limit', '8M'); $tooLarge = str_repeat('x', 16 * 1024 * 1024); }
if ($mode === 'truncated') qta_export_header('Content-Length: 999');
echo "%PDF-1.4\n" . str_repeat('binary', 20000);
