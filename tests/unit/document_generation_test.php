<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/shared/document_generation.php';

t_case('document capture: ready only after complete bytes, failure is never a file', static function (): void {
    foreach (['ok', 'error', 'fatal', 'memory', 'truncated'] as $mode) {
        $id = bin2hex(random_bytes(24));
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/document_capture.php', $id, $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        proc_close($proc);
        $state = qta_document_read($id);
        t_eq('', $stdout, $mode . ': no binary appended to the start response');
        t_eq($mode === 'ok' ? 'ready' : 'error', $state['status'], $mode . ': correct terminal state');
        $path = qta_document_directory() . '/' . $id . '.bin';
        if ($mode === 'ok') {
            t_eq(100, $state['percent'], 'completion is 100%');
            t_eq('test.pdf', $state['filename'], 'attachment name retained');
            t_eq("%PDF-1.4\n" . str_repeat('binary', 20000), file_get_contents($path), 'nested cleanup and chunking preserve exact bytes');
            unlink($path);
        } else t_ok(!is_file($path), $mode . ': partial bytes removed');
        unlink(qta_document_directory() . '/' . $id . '.json');
    }
    t_eq(null, qta_document_read('../invalid'), 'invalid job IDs rejected');
});
