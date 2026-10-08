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
        unlink(qta_document_directory() . '/' . $id . '.lock');
    }
    t_eq(null, qta_document_read('../invalid'), 'invalid job IDs rejected');
});

t_case('document worker: a live process never expires; a killed process is detected', static function (): void {
    $id = bin2hex(random_bytes(24));
    $proc = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/document_capture.php', $id, 'worker'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    fclose($pipes[0]);
    try {
        for ($i = 0; $i < 100; ++$i) {
            $state = is_file(qta_document_directory() . '/' . $id . '.json') ? qta_document_read($id) : null;
            if (($state['percent'] ?? 0) === 55) break;
            usleep(20000);
        }
        t_eq(55, $state['percent'], 'worker reached the processing stage');
        // Old metadata is not a deadline: the actual process still owns the lock.
        touch(qta_document_directory() . '/' . $id . '.json', time() - 7 * 86400);
        t_eq('working', qta_document_status($id, $state)['status'], 'live worker has no time limit');
        t_ok(proc_terminate($proc, 9), 'test worker was terminated');
        for ($i = 0; $i < 100 && proc_get_status($proc)['running']; ++$i) usleep(20000);
        for ($i = 0; $i < 100; ++$i) {
            $stopped = qta_document_status($id, qta_document_read($id));
            if ($stopped['status'] !== 'working') break;
            usleep(20000); // Windows may finish releasing handles after reporting process exit.
        }
        t_eq('error', $stopped['status'], 'hard kill without shutdown does not leave progress stuck');
        t_ok(isset($stopped['finished_at']), 'interrupted job has a terminal state');
        t_ok(!is_file(qta_document_directory() . '/' . $id . '.bin'), 'interrupted partial file removed');
        t_eq($stopped, qta_document_status($id, $stopped), 'terminal state is stable');
    } finally {
        if (proc_get_status($proc)['running']) proc_terminate($proc, 9);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
        foreach (['json', 'lock', 'bin'] as $extension) {
            $path = qta_document_directory() . '/' . $id . '.' . $extension;
            if (is_file($path)) unlink($path);
        }
    }
});
