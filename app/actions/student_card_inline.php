<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=UTF-8');

try {
  $pdo = getPDO();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // (opsionale) audit
  if (file_exists(__DIR__ . '/inc/audit_bootstrap.php')) {
    require_once __DIR__ . '/inc/audit_bootstrap.php';
    if (function_exists('qta_audit_attach')) qta_audit_attach($pdo);
  }

  /* =========================
     Auth & Role & Edit Mode
  ========================== */
  if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Nuk jeni i autentikuar.']); exit;
  }

  $st = $pdo->prepare("
    SELECT u.id, u.role_id, r.name AS role_name
    FROM users u JOIN roles r ON r.id=u.role_id
    WHERE u.id=:id LIMIT 1
  ");
  $st->execute([':id'=>$_SESSION['user_id']]);
  $ME = $st->fetch(PDO::FETCH_ASSOC);
  if (!$ME) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Akses i ndaluar.']); exit; }

  $ROLE      = strtolower((string)$ME['role_name']);
  $CAN_EDIT  = in_array($ROLE, ['administrator','editor'], true);
  $EDIT_MODE = (bool)($_SESSION['edit_mode'] ?? false);

  // Agency scope (nëse është agjenci)
  $MY_AGENCY_ID = null;
  if ($ROLE === 'agjencia') {
    $a = $pdo->prepare("SELECT id FROM agencies WHERE user_id=:u LIMIT 1");
    $a->execute([':u'=>(int)$ME['id']]);
    $MY_AGENCY_ID = (int)($a->fetchColumn() ?: 0);
    if ($MY_AGENCY_ID<=0) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Agjencia nuk u gjet.']); exit; }
  }

  /* ===============
     Lexo input-in
  ================ */
  $raw  = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = $_POST;

  $csrf = (string)($data['csrf'] ?? '');
  if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'CSRF token i pavlefshëm.']); exit;
  }

  $action = (string)($data['action'] ?? '');
  if ($action==='') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Mungon action.']); exit; }

  /* ===============
     Helpers
  ================ */
  $clean = fn($s) => trim(preg_replace('/\s+/u',' ', (string)($s ?? '')));

  $to_iso_date = function($v): ?string {
    if ($v===null) return null;
    $v = trim((string)$v);
    if ($v==='') return null;
    // YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/',$v,$m)) {
      $yy=$m[1]; $mm=str_pad($m[2],2,'0',STR_PAD_LEFT); $dd=str_pad($m[3],2,'0',STR_PAD_LEFT);
      return "{$yy}-{$mm}-{$dd}";
    }
    // DD-MM-YYYY
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/',$v,$m)) {
      $dd=str_pad($m[1],2,'0',STR_PAD_LEFT); $mm=str_pad($m[2],2,'0',STR_PAD_LEFT); $yy=$m[3];
      return "{$yy}-{$mm}-{$dd}";
    }
    throw new RuntimeException('Formati i datës duhet të jetë DD-MM-YYYY.');
  };

  $fmt_display = fn(?string $iso): string =>
    ($iso && preg_match('/^\d{4}-\d{2}-\d{2}$/',$iso)) ? date('d-m-Y', strtotime($iso)) : ($iso ?: '—');

  $require_edit = function() use ($CAN_EDIT, $EDIT_MODE) {
    if (!$CAN_EDIT || !$EDIT_MODE) {
      http_response_code(403);
      echo json_encode(['ok'=>false,'error'=>'Kërkohet Edit Mode (administrator/editor).']); exit;
    }
  };

  $person_qr_payload = fn(int $pid, string $token): string => "QTA|PID:{$pid}|TOKEN:{$token}";

  $ensure_person_exists = function(PDO $pdo, int $pid): void {
    $s=$pdo->prepare("SELECT id FROM persons WHERE id=:id LIMIT 1");
    $s->execute([':id'=>$pid]);
    if (!$s->fetchColumn()) throw new RuntimeException('Personi nuk u gjet.');
  };

  $ensure_agency_can_see_person = function(PDO $pdo, int $agencyId, int $pid): void {
    // lejohet vetëm nëse ndonjë student i këtij personi i përket asaj agjencie
    $sql = "SELECT 1
            FROM students s
            JOIN agency_students ajs ON ajs.student_id=s.id
            WHERE s.person_id=:pid AND ajs.agency_id=:aid
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':pid'=>$pid, ':aid'=>$agencyId]);
    if (!$st->fetchColumn()) {
      http_response_code(403);
      echo json_encode(['ok'=>false,'error'=>'S’keni akses për këtë person.']); exit;
    }
  };

  /* =========================================================
     READ-ONLY: qr_payload  (lejohet edhe për 'agjencia')
  ========================================================== */
  if ($action === 'qr_payload') {
    $sid = (int)($data['student_id'] ?? 0);
    $pid = (int)($data['person_id']  ?? 0);
    $amze = null;

    if ($sid>0 && $pid<=0) {
      $x=$pdo->prepare("SELECT person_id, nr_amze FROM students WHERE id=:sid");
      $x->execute([':sid'=>$sid]);
      $row=$x->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException('Studenti nuk u gjet.');
      $pid = (int)$row['person_id'];
      $amze = (string)($row['nr_amze'] ?? '');
    }
    if ($pid<=0) throw new RuntimeException('Person i pavlefshëm.');

    // Nëse është agjenci, kontrollo qasjen
    if ($ROLE === 'agjencia') {
      $ensure_agency_can_see_person($pdo, (int)$MY_AGENCY_ID, $pid);
    }

    $q=$pdo->prepare("SELECT token, created_at FROM person_qr_tokens WHERE person_id=:p LIMIT 1");
    $q->execute([':p'=>$pid]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new RuntimeException('Ky person s’ka ende QR.');
    $token = (string)$r['token'];

    $payload = $person_qr_payload($pid, $token) . ($amze ? ('|AMZE:'.$amze) : '');
    echo json_encode(['ok'=>true,'token'=>$token,'payload'=>$payload]); exit;
  }

  /* =========================================================
     MUTATING: kërko Edit Mode + admin/editor
  ========================================================== */
  // nga këtu e poshtë lejo vetëm nëse është Edit Mode dhe ka të drejta
  $require_edit();

  /* =========================================================
     generate_qr_person  (alias i vjetër: generate_qr)
  ========================================================== */
  if ($action === 'generate_qr_person' || $action === 'generate_qr') {
    // pranon person_id (ose student_id -> nxjerr person_id)
    $pid = (int)($data['person_id'] ?? 0);
    $sid = (int)($data['student_id'] ?? 0);

    if ($pid<=0 && $sid>0) {
      $s=$pdo->prepare("SELECT person_id FROM students WHERE id=:sid");
      $s->execute([':sid'=>$sid]);
      $pid = (int)($s->fetchColumn() ?: 0);
    }
    if ($pid<=0) throw new RuntimeException('ID personi e pavlefshme.');

    $ensure_person_exists($pdo, $pid);

    // idempotent: nëse ekziston, kthe ekzistuesin
    $ex=$pdo->prepare("SELECT token FROM person_qr_tokens WHERE person_id=:p LIMIT 1");
    $ex->execute([':p'=>$pid]);
    $tok=$ex->fetchColumn();
    if (!$tok) {
      $tok = bin2hex(random_bytes(16)); // 32-hex
      $ins=$pdo->prepare("INSERT INTO person_qr_tokens (person_id, token, created_at) VALUES (:p,:t,NOW())
                          ON DUPLICATE KEY UPDATE token=token");
      $ins->execute([':p'=>$pid, ':t'=>$tok]);
      if ($ins->rowCount()===0) { // race-condition: lexo sërish
        $ex->execute([':p'=>$pid]); $tok = (string)$ex->fetchColumn();
      }
    }

    echo json_encode(['ok'=>true,'token'=>$tok,'payload'=>$person_qr_payload($pid,$tok)]); exit;
  }

  /* =========================================================
     set_person_field
  ========================================================== */
  if ($action === 'set_person_field') {
    $pid   = (int)($data['person_id'] ?? 0);
    $field = (string)($data['field'] ?? '');
    $value = $clean($data['value'] ?? '');

    if ($pid<=0) throw new RuntimeException('Person i pavlefshëm.');
    $ensure_person_exists($pdo, $pid);

    switch ($field) {
      case 'first_name':
      case 'father_name':
      case 'last_name':
      case 'birth_place':
      case 'phone': {
        $st=$pdo->prepare("UPDATE persons SET {$field}=:v WHERE id=:id");
        $st->execute([':v'=>($value!==''?$value:null), ':id'=>$pid]);
        echo json_encode(['ok'=>true,'display'=>($value!==''?$value:'—')]); exit;
      }

      case 'personal_number': {
        if ($value!=='') {
          $du=$pdo->prepare("SELECT id FROM persons WHERE personal_number=:pn AND id<>:id LIMIT 1");
          $du->execute([':pn'=>$value, ':id'=>$pid]);
          if ($du->fetchColumn()) throw new RuntimeException('Ky ID personal përdoret nga një person tjetër.');
        }
        $st=$pdo->prepare("UPDATE persons SET personal_number=:v WHERE id=:id");
        $st->execute([':v'=>($value!==''?$value:null), ':id'=>$pid]);
        echo json_encode(['ok'=>true,'display'=>($value!==''?$value:'—')]); exit;
      }

      case 'birth_date': {
        $iso = $to_iso_date($value!=='' ? $value : null);
        $st=$pdo->prepare("UPDATE persons SET birth_date=:v WHERE id=:id");
        $st->execute([':v'=>$iso, ':id'=>$pid]);
        echo json_encode(['ok'=>true,'display'=>$fmt_display($iso)]); exit;
      }

      case 'gender_id': {
        // mos lejo bosh, DB shpesh e ka NOT NULL
        $gid = (int)$value;
        if ($gid<=0) throw new RuntimeException('Zgjidhni një gjini.');
        $g=$pdo->prepare("SELECT id,label FROM genders WHERE id=:id");
        $g->execute([':id'=>$gid]);
        $gr=$g->fetch(PDO::FETCH_ASSOC);
        if (!$gr) throw new RuntimeException('Gjini e panjohur.');
        $st=$pdo->prepare("UPDATE persons SET gender_id=:g WHERE id=:id");
        $st->execute([':g'=>$gid, ':id'=>$pid]);
        echo json_encode(['ok'=>true,'display'=>(string)$gr['label']]); exit;
      }

      default:
        throw new RuntimeException('Fushë e palejuar për personin.');
    }
  }

  /* =========================================================
     set_student_field
  ========================================================== */
  if ($action === 'set_student_field') {
    $sid   = (int)($data['student_id'] ?? 0);
    $field = (string)($data['field'] ?? '');
    $value = $clean($data['value'] ?? '');

    if ($sid<=0) throw new RuntimeException('ID studenti e pavlefshme.');

    $chk=$pdo->prepare("SELECT id FROM students WHERE id=:id LIMIT 1");
    $chk->execute([':id'=>$sid]);
    if (!$chk->fetchColumn()) throw new RuntimeException('Studenti nuk u gjet.');

    switch ($field) {
      case 'nr_amze': {
        if ($value==='') throw new RuntimeException('AMZË nuk mund të jetë bosh.');
        $du=$pdo->prepare("SELECT id FROM students WHERE nr_amze=:v AND id<>:id LIMIT 1");
        $du->execute([':v'=>$value, ':id'=>$sid]);
        if ($du->fetchColumn()) throw new RuntimeException('Kjo AMZË është në përdorim.');
        $st=$pdo->prepare("UPDATE students SET nr_amze=:v WHERE id=:id");
        $st->execute([':v'=>$value, ':id'=>$sid]);
        echo json_encode(['ok'=>true,'display'=>$value]); exit;
      }

      case 'education_level_id': {
        if ($value==='' || (int)$value===0) {
          $st=$pdo->prepare("UPDATE students SET education_level_id=NULL WHERE id=:id");
          $st->execute([':id'=>$sid]);
          echo json_encode(['ok'=>true,'display'=>'—']); exit;
        }
        $eid=(int)$value;
        $lev=$pdo->prepare("SELECT id,code,label FROM education_levels WHERE id=:id");
        $lev->execute([':id'=>$eid]);
        $L=$lev->fetch(PDO::FETCH_ASSOC);
        if (!$L) throw new RuntimeException('Niveli i edukimit nuk u gjet.');
        $st=$pdo->prepare("UPDATE students SET education_level_id=:e WHERE id=:id");
        $st->execute([':e'=>$eid, ':id'=>$sid]);
        $disp = ($L['code']?($L['code'].' — '):'').($L['label'] ?? '');
        echo json_encode(['ok'=>true,'display'=>$disp]); exit;
      }

      default:
        throw new RuntimeException('Fushë e palejuar për studentin.');
    }
  }

  /* =========================================================
     add_amze_for_person  (krijon user student nëse mungon)
  ========================================================== */
  if ($action === 'add_amze_for_person') {
    $pid = (int)($data['person_id'] ?? 0);
    $nr  = $clean($data['nr_amze'] ?? '');
    if ($pid<=0) throw new RuntimeException('Person i pavlefshëm.');
    if ($nr==='') throw new RuntimeException('AMZË nuk mund të jetë bosh.');

    $ensure_person_exists($pdo, $pid);

    // Unik AMZË
    $du=$pdo->prepare("SELECT id FROM students WHERE nr_amze=:nr LIMIT 1");
    $du->execute([':nr'=>$nr]);
    if ($du->fetchColumn()) throw new RuntimeException('Kjo AMZË ekziston tashmë.');

    // Gjej ose krijo user për këtë person me rolin "student"
    $getStudentRole = (int)$pdo->query("SELECT id FROM roles WHERE name='student' LIMIT 1")->fetchColumn();
    if ($getStudentRole<=0) throw new RuntimeException('Roli "student" mungon.');

    $u=$pdo->prepare("SELECT id FROM users WHERE person_id=:p LIMIT 1");
    $u->execute([':p'=>$pid]);
    $user_id = (int)($u->fetchColumn() ?: 0);

    if ($user_id<=0) {
      // formo full_name nga persons
      $p=$pdo->prepare("SELECT first_name,last_name FROM persons WHERE id=:p");
      $p->execute([':p'=>$pid]);
      $pr=$p->fetch(PDO::FETCH_ASSOC) ?: ['first_name'=>null,'last_name'=>null];
      $full = trim(($pr['first_name']??'').' '.($pr['last_name']??''));
      $insU=$pdo->prepare("INSERT INTO users (role_id, person_id, full_name, email) VALUES (:r,:p,:n,NULL)");
      $insU->execute([':r'=>$getStudentRole, ':p'=>$pid, ':n'=>($full ?: null)]);
      $user_id = (int)$pdo->lastInsertId();
    }

    $insS=$pdo->prepare("INSERT INTO students (person_id, user_id, nr_amze, education_level_id)
                         VALUES (:p,:u,:n,NULL)");
    $insS->execute([':p'=>$pid, ':u'=>$user_id, ':n'=>$nr]);
    $sid=(int)$pdo->lastInsertId();

    echo json_encode(['ok'=>true,'student_id'=>$sid,'nr_amze'=>$nr]); exit;
  }

  /* =========================================================
     delete_amze (vetëm nëse s’ka grupe/plane)
  ========================================================== */
  if ($action === 'delete_amze') {
    $sid = (int)($data['student_id'] ?? 0);
    if ($sid<=0) throw new RuntimeException('ID studenti e pavlefshme.');

    $ex=$pdo->prepare("SELECT id FROM students WHERE id=:id");
    $ex->execute([':id'=>$sid]);
    if (!$ex->fetchColumn()) throw new RuntimeException('Studenti nuk u gjet.');

    $c1=$pdo->prepare("SELECT COUNT(*) FROM course_group_students WHERE student_id=:sid");
    $c1->execute([':sid'=>$sid]);
    if ((int)$c1->fetchColumn()>0) throw new RuntimeException('S’mund të fshihet: studenti ka pjesëmarrje në grupe.');

    $c2=$pdo->prepare("SELECT COUNT(*) FROM student_course_plans WHERE student_id=:sid");
    $c2->execute([':sid'=>$sid]);
    if ((int)$c2->fetchColumn()>0) throw new RuntimeException('S’mund të fshihet: studenti ka plane.');

    $del=$pdo->prepare("DELETE FROM students WHERE id=:id");
    $del->execute([':id'=>$sid]);

    echo json_encode(['ok'=>true]); exit;
  }

    /* =========================================================
     delete_participation (group | planned)
  ========================================================== */
  if ($action === 'delete_participation') {
    // vetëm admin/editor + Edit Mode (e garanton $require_edit() më sipër)

    $kind = (string)($data['kind'] ?? '');

    if ($kind === 'group') {
      $group_id   = (int)($data['group_id']   ?? 0);
      $student_id = (int)($data['student_id'] ?? 0);
      if ($group_id <= 0 || $student_id <= 0) {
        throw new RuntimeException('Mungon group_id / student_id.');
      }

      // ekziston kjo pjesëmarrje?
      $ex = $pdo->prepare("SELECT 1 FROM course_group_students WHERE group_id=:g AND student_id=:s LIMIT 1");
      $ex->execute([':g'=>$group_id, ':s'=>$student_id]);
      if (!$ex->fetchColumn()) {
        throw new RuntimeException('Pjesëmarrja nuk u gjet.');
      }

      // fshi rreshtin
      $del = $pdo->prepare("DELETE FROM course_group_students WHERE group_id=:g AND student_id=:s LIMIT 1");
      $del->execute([':g'=>$group_id, ':s'=>$student_id]);

      // audit (nëse ekziston funksioni)
      if (function_exists('qta_audit')) {
        qta_audit('group_participation.delete', [
          'group_id'   => $group_id,
          'student_id' => $student_id,
          'by_user_id' => (int)$_SESSION['user_id'],
        ]);
      }

      echo json_encode([
        'ok'      => true,
        'kind'    => 'group',
        'removed' => ($del->rowCount() > 0)
      ]); exit;
    }

    if ($kind === 'planned') {
      $scp_id = (int)($data['scp_id'] ?? 0);
      if ($scp_id <= 0) {
        throw new RuntimeException('Mungon scp_id.');
      }

      // sigurohu që është "planned", pa grup
      $ex = $pdo->prepare("
        SELECT 1
        FROM student_course_plans
        WHERE id=:id AND group_id IS NULL AND status='planned'
        LIMIT 1
      ");
      $ex->execute([':id'=>$scp_id]);
      if (!$ex->fetchColumn()) {
        throw new RuntimeException('Plani nuk u gjet ose s’është më “planned”.');
      }

      $del = $pdo->prepare("DELETE FROM student_course_plans WHERE id=:id LIMIT 1");
      $del->execute([':id'=>$scp_id]);

      if (function_exists('qta_audit')) {
        qta_audit('planned.delete', [
          'scp_id'     => $scp_id,
          'by_user_id' => (int)$_SESSION['user_id'],
        ]);
      }

      echo json_encode([
        'ok'      => true,
        'kind'    => 'planned',
        'removed' => ($del->rowCount() > 0)
      ]); exit;
    }

    throw new RuntimeException('Lloj i panjohur për fshirje.');
  }


  /* =========================================================
     Nëse s’u kap asnjë action
  ========================================================== */
  echo json_encode(['ok'=>false,'error'=>'Veprim i panjohur.']); exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage() ?: 'Gabim i panjohur.']);
}
