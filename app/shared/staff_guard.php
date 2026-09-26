<?php
declare(strict_types=1);

/**
 * staff_guard.php — Kontrollet e përbashkëta për endpoint-et JSON të stafit
 * (administrator ose editor): seanca, roli nga databaza, kyçi i ndryshimeve
 * dhe tokeni i faqes. Asnjë vendim nuk mbështetet te butonat e fshehur.
 */

if (!function_exists('qta_json_out')) {
  function qta_json_out(array $payload, int $status = 200): void
  {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  /** Lexon trupin JSON (ose formularin) të kërkesës. */
  function qta_json_input(): array
  {
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : $_POST;
  }

  /**
   * Stafi i loguar, me rolin e lexuar nga databaza. Kthen përdoruesin ose
   * përgjigjet 401/403 dhe ndalon.
   */
  function qta_json_require_staff(PDO $pdo): array
  {
    if (empty($_SESSION['user_id'])) {
      qta_json_out(['ok' => false, 'error' => 'Seanca ka mbaruar. Hyr sërish në llogari.'], 401);
    }
    $st = $pdo->prepare('SELECT u.id, u.full_name, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1');
    $st->execute([(int)$_SESSION['user_id']]);
    $me = $st->fetch(PDO::FETCH_ASSOC);
    $role = strtolower((string)($me['role_name'] ?? ''));
    if (!$me || !in_array($role, ['administrator', 'editor'], true)) {
      qta_json_out(['ok' => false, 'error' => 'Vetëm stafi i QTA-së (administrator ose editor) mund ta bëjë këtë veprim.'], 403);
    }
    return $me;
  }

  function qta_json_require_csrf(array $data): void
  {
    $token = (string)($data['csrf'] ?? '');
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
      qta_json_out(['ok' => false, 'error' => 'Faqja ka qëndruar e hapur shumë gjatë. Rifreskoje dhe provo sërish.'], 400);
    }
  }

  function qta_json_require_edit_mode(): void
  {
    if (empty($_SESSION['edit_mode'])) {
      qta_json_out(['ok' => false, 'error' => 'Ndryshimet janë të mbyllura. Shtyp "Lejo ndryshimet" dhe provo sërish.'], 403);
    }
  }

  /**
   * Gabimet e domenit → përgjigje JSON e qartë. Gabimet e papritura regjistrohen
   * dhe përdoruesi merr një mesazh të thjeshtë (pa detaje teknike).
   */
  function qta_json_fail(Throwable $e): void
  {
    if ($e instanceof QtaConfirmNeeded) {
      qta_json_out(['ok' => false, 'confirm' => ['title' => $e->title, 'message' => $e->getMessage(), 'confirm' => $e->confirmLabel]], 409);
    }
    if ($e instanceof QtaUserError) {
      qta_json_out(['ok' => false, 'error' => $e->getMessage(), 'code' => $e->data['code'] ?? null], 400);
    }
    if ($e instanceof PDOException && ($e->errorInfo[0] ?? '') === '45000' && !empty($e->errorInfo[2])) {
      /* Rregull i bazës (trigger) me mesazh shqip. */
      qta_json_out(['ok' => false, 'error' => (string)$e->errorInfo[2]], 400);
    }
    $ref = bin2hex(random_bytes(4));
    error_log('[QTA ' . $ref . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    qta_json_out(['ok' => false, 'error' => 'Ndryshimi nuk u ruajt për shkak të një gabimi të papritur. Asgjë nuk ndryshoi. Provo sërish; nëse përsëritet, njofto administratorin (referenca ' . $ref . ').'], 500);
  }
}
