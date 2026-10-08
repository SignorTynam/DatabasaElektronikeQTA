<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/shared/group_members.php';

function rr_worker(string $mode, string $dir, string $id): array
{
    $p = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/session_worker.php', $mode, $dir, $id],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$p, $pipes];
}
function rr_finish(array $worker): array
{
    [$p, $pipes] = $worker;
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    if ($code !== 0 || $err !== '') throw new RuntimeException($err ?: $out);
    return json_decode($out, true) ?: [];
}
t_case('Sesioni: provohet bllokimi legacy dhe lirimi paralel para DB work', function () {
    $dir = sys_get_temp_dir() . '/qta_session_' . bin2hex(random_bytes(5));
    mkdir($dir);
    try {
        foreach (['hold', 'release', 'flash'] as $mode) {
            $id = 'qta' . bin2hex(random_bytes(8));
            if (is_file($dir . '/ready')) unlink($dir . '/ready');
            $first = rr_worker($mode, $dir, $id);
            $limit = microtime(true) + 5;
            while (!is_file($dir . '/ready') && microtime(true) < $limit) { clearstatcache(); usleep(10000); }
            t_ok(is_file($dir . '/ready'), 'worker A arriti te puna e ngadaltë');
            $read = rr_finish(rr_worker('read', $dir, $id));
            echo '  session ', $mode, ': reader ', $read['elapsed_ms'], " ms\n";
            if ($mode === 'hold') t_ok($read['elapsed_ms'] >= 800, 'baseline riprodhon session lock');
            else t_ok($read['elapsed_ms'] < 500, 'request B nuk pret punën e request A');
            if ($mode === 'flash') rr_finish(rr_worker('toggle', $dir, $id));
            rr_finish($first);
            if ($mode === 'flash') {
                $state = rr_finish(rr_worker('read', $dir, $id));
                t_eq(true, $state['session']['edit_mode'], 'flash nuk mbishkruan edit mode nga kërkesa tjetër');
                t_eq('Ruajtur', $state['session']['flash']['ok'], 'flash ruhet pas punës');
            }
            $audit = rr_finish(rr_worker('audit', $dir, $id));
            t_eq(false, $audit['active'], 'audit include nuk rihap sesionin');
        }
    } finally {
        foreach (glob($dir . '/*') ?: [] as $f) unlink($f);
        rmdir($dir);
    }
});
t_case('AMZË: kufi i përbashkët për grupet dhe agjencitë', function () {
    t_eq([7977, 7978, 7979, 7980, 7981], qta_amze_parse('7977-7981'), 'lista e kërkuar');
    t_eq([7, 8], qta_amze_parse('0007,8,7'), 'semantika numerike dhe deduplikimi');
    t_throws(QtaUserError::class, fn() => qta_amze_parse('1-999999999'), 'interval masiv refuzohet para loop');
    t_throws(QtaUserError::class, fn() => qta_amze_parse('1-200,201'), 'kufi total');
    t_throws(QtaUserError::class, fn() => qta_amze_parse(str_repeat('1,', 5000)), 'kufi i gjatësisë së inputit');
});
