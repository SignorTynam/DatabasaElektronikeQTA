<?php
declare(strict_types=1);

/**
 * domain.php — Bazat e shërbimeve të domenit (kurset, orari i grupeve).
 *
 * Gabimet që i lexon përdoruesi, konfirmimet që kërkon një veprim dhe leximi
 * i vlerave të futura (data, numra, tekst). Asnjë HTML dhe asnjë query këtu.
 */

if (!class_exists('QtaUserError')) {
  /**
   * Gabim që i tregohet përdoruesit ashtu siç është: çfarë nuk shkoi dhe çfarë
   * të bëjë. Mesazhi është gjithmonë shqip, pa terma teknikë.
   */
  class QtaUserError extends RuntimeException
  {
    /** @var array<string,mixed> */
    public array $data;

    /** @param array<string,mixed> $data */
    public function __construct(string $message, array $data = [])
    {
      parent::__construct($message);
      $this->data = $data;
    }
  }
}

if (!class_exists('QtaConfirmNeeded')) {
  /**
   * Veprimi është i lejuar, por ka një pasojë që përdoruesi duhet ta pranojë
   * (p.sh. ndryshon ditë mësimi që kanë kaluar, ose grupi është i mbyllur).
   * Ndërfaqja pyet dhe e dërgon sërish me force = 1.
   */
  class QtaConfirmNeeded extends RuntimeException
  {
    public string $title;
    public string $confirmLabel;
    /** @var array<string,mixed> */
    public array $data;

    /** @param array<string,mixed> $data */
    public function __construct(string $title, string $message, string $confirmLabel = 'Po, vazhdo', array $data = [])
    {
      parent::__construct($message);
      $this->title = $title;
      $this->confirmLabel = $confirmLabel;
      $this->data = $data;
    }
  }
}

if (!function_exists('qta_parse_date_input')) {
  /**
   * Data e shkruar nga përdoruesi → 'Y-m-d', ose null kur nuk është datë e vërtetë.
   * Pranon dd.mm.vvvv, dd-mm-vvvv, dd/mm/vvvv dhe vvvv-mm-dd.
   */
  function qta_parse_date_input($value): ?string
  {
    $s = trim((string)$value);
    if ($s === '') {
      return null;
    }
    if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/', $s, $m)) {
      [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
      [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } else {
      return null;
    }
    if ($y < 1900 || $y > 2200 || !checkdate($mo, $d, $y)) {
      return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
  }
}

if (!function_exists('qta_parse_int_input')) {
  /** Numër i plotë brenda kufijve, ose null. "5", 5 dhe " 5 " pranohen; "5.5", "5a" jo. */
  function qta_parse_int_input($value, int $min, int $max): ?int
  {
    if (is_int($value)) {
      $n = $value;
    } else {
      $s = trim((string)$value);
      if ($s === '' || !preg_match('/^\d{1,6}$/', $s)) {
        return null;
      }
      $n = (int)$s;
    }
    return ($n >= $min && $n <= $max) ? $n : null;
  }
}

if (!function_exists('qta_clean_text')) {
  /** Tekst i shkurtër: pa hapësira të tepërta, pa shenja kontrolli, i prerë në $max shenja. */
  function qta_clean_text($value, int $max): string
  {
    $s = (string)$value;
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    if (mb_strlen($s, 'UTF-8') > $max) {
      $s = rtrim(mb_substr($s, 0, $max, 'UTF-8'));
    }
    return $s;
  }
}

if (!function_exists('qta_tx')) {
  /**
   * Ekzekuton $fn brenda një transaksioni. Nëse transaksioni është hapur nga
   * thirrësi, e përdor atë (pa commit/rollback këtu); përndryshe e hap, e mbyll
   * me commit, dhe e kthen mbrapsht me çdo gabim — asgjë gjysmake nuk mbetet.
   *
   * @template T
   * @param callable():T $fn
   * @return T
   */
  function qta_tx(PDO $pdo, callable $fn)
  {
    if ($pdo->inTransaction()) {
      return $fn();
    }
    $pdo->beginTransaction();
    try {
      $result = $fn();
      $pdo->commit();
      return $result;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
  }
}

if (!function_exists('qta_hours_label')) {
  /** "1 orë", "5 orë" — në shqip "orë" nuk ndryshon në shumës. */
  function qta_hours_label(int $n): string
  {
    return number_format($n, 0, ',', '.') . ' orë';
  }
}
