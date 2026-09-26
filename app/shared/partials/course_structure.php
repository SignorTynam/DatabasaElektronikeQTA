<?php
declare(strict_types=1);

/**
 * course_structure.php — Struktura e një kursi: gatishmëria, modulet në radhë
 * dhe temat e secilit modul. E njëjta pamje përdoret nga faqja (course.php) dhe
 * nga përgjigjet e ndryshimeve (course_structure_update.php), që pas çdo ruajtjeje
 * pjesa të rivizatohet pa ringarkuar faqen.
 *
 *   echo qta_render_course_structure($course, $modules, $check, $edit, $usage);
 *
 * $usage = ['scheduled' => n, 'legacy' => n]  (grupet që e ndjekin kursin)
 */

require_once __DIR__ . '/../themeli.php';
require_once __DIR__ . '/../curriculum.php';

if (!function_exists('qta_render_course_structure')) {
  function qta_render_course_structure(array $course, array $modules, array $check, bool $edit, array $usage = []): string
  {
    $cid = (int)$course['id'];
    $scheduled = (int)($usage['scheduled'] ?? 0);
    $legacy = (int)($usage['legacy'] ?? 0);
    $mCount = count($modules);
    ob_start();
    ?>
    <section class="section cur-status" aria-labelledby="curStatusTitle">
      <div class="panel cur-summary<?= $check['ready'] ? ' is-ready' : '' ?>">
        <div class="cur-summary-head">
          <h2 class="section-title" id="curStatusTitle">
            <?= $check['ready'] ? 'Kursi është gati për grupe me orar' : ($mCount ? 'Kursi nuk është ende gati për grupe me orar' : 'Ndërto kursin: modulet dhe temat') ?>
          </h2>
          <?= $check['ready']
            ? qta_status('Gati', 'success', 'bi-check-circle-fill')
            : qta_status($mCount ? 'Jo gati' : 'Pa module', $mCount ? 'warning' : 'neutral', $mCount ? 'bi-exclamation-triangle' : 'bi-dash-circle') ?>
        </div>

        <div class="cur-meter">
          <div class="cur-meter-text">
            <span>Modulet kanë <b><?= h(qta_hours_label((int)$check['module_hours'])) ?></b> nga <b><?= h(qta_hours_label((int)$check['course_hours'])) ?></b> të kursit</span>
            <span class="text-muted"><?= h(qta_plural($mCount, 'modul', 'module')) ?> · <?= h(qta_plural((int)$check['topic_count'], 'temë', 'tema')) ?></span>
          </div>
          <progress class="hours-bar<?= (int)$check['module_hours'] > (int)$check['course_hours'] ? ' is-over' : ((int)$check['module_hours'] === (int)$check['course_hours'] ? ' is-full' : '') ?>"
                    max="<?= max(1, (int)$check['course_hours']) ?>" value="<?= min((int)$check['module_hours'], max(1, (int)$check['course_hours'])) ?>"
                    aria-label="Orët e moduleve: <?= (int)$check['module_hours'] ?> nga <?= (int)$check['course_hours'] ?>"></progress>
        </div>

        <?php if ($check['ready']): ?>
          <p class="mb-0">Modulet dhe temat mblidhen saktë në <?= h(qta_hours_label((int)$check['course_hours'])) ?>. Kur krijon një grup me këtë kurs, orari ndërtohet vetë, ditë pas dite, në këtë radhë.</p>
        <?php elseif ($check['issues']): ?>
          <p class="mb-2"><?= $mCount ? 'Rregullo këto që kursi të përdoret për grupe me orar:' : 'Ndaje kursin në module (p.sh. Word, Excel) dhe çdo modul në tema. Orët duhet të mblidhen saktë.' ?></p>
          <?php if ($mCount): ?>
            <ul class="cur-issues">
              <?php foreach ($check['issues'] as $issue): ?>
                <li>
                  <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                  <span><?= h((string)$issue['text']) ?></span>
                  <?php if ($edit && !empty($issue['fix'])): ?>
                    <button class="btn btn-secondary btn-sm" type="button" data-cur-fix="<?= h((string)$issue['fix']['action']) ?>"
                            data-value="<?= h((string)($issue['fix']['value'] ?? '')) ?>" data-module="<?= (int)($issue['module_id'] ?? 0) ?>"><?= h((string)$issue['fix']['label']) ?></button>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($scheduled > 0 || $legacy > 0): ?>
          <p class="cur-usage">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>
              <?php if ($scheduled > 0): ?>
                <a href="lesson_groups.php?course_id=<?= $cid ?>"><?= h(qta_plural($scheduled, 'grup me orar', 'grupe me orar')) ?></a> e ndjekin këtë kurs.
                Çdo grup ka kopjen e vet të temave, prandaj ndryshimet këtu vlejnë vetëm për grupet e reja.
              <?php endif; ?>
              <?php if ($legacy > 0): ?>
                <a href="groups.php?course_id=<?= $cid ?>"><?= h(qta_plural($legacy, 'grup i mëparshëm', 'grupe të mëparshme')) ?></a> pa orar nuk preken nga modulet dhe temat.
              <?php endif; ?>
            </span>
          </p>
        <?php endif; ?>
      </div>
    </section>

    <section class="section" aria-labelledby="curModulesTitle">
      <div class="section-head">
        <h2 class="section-title" id="curModulesTitle">Modulet dhe temat <span class="count"><?= $mCount ?></span></h2>
        <?php if ($mCount): ?>
          <span class="section-meta">Radha këtu është radha e mësimit në orar.</span>
        <?php endif; ?>
      </div>

      <?php if (!$modules): ?>
        <?= qta_empty('Kursi nuk ka ende module',
              'Shto modulin e parë, p.sh. "Word — 10 orë", pastaj temat e tij. Kur orët mblidhen saktë, kursi bëhet gati për grupe me orar.',
              'bi-diagram-3',
              $edit ? '<button class="btn btn-primary" type="button" data-cur-open="module-add"><i class="bi bi-plus-lg" aria-hidden="true"></i>Shto modul</button>' : '') ?>
      <?php else: ?>
        <ol class="cur-modules">
          <?php foreach ($modules as $mi => $m):
            $mid = (int)$m['id'];
            $info = $check['modules'][$mid] ?? ['topic_hours' => 0, 'topics' => 0, 'ok' => false];
            $pos = $mi + 1;
            $topics = $m['topics'] ?? [];
            $tCount = count($topics);
            $titleId = 'curModule' . $mid;
          ?>
            <li class="cur-module<?= $info['ok'] ? '' : ' has-issue' ?>" id="modul-<?= $mid ?>" data-module="<?= $mid ?>" aria-labelledby="<?= $titleId ?>">
              <div class="cur-module-head">
                <span class="cur-pos" aria-hidden="true"><?= $pos ?></span>
                <div class="cur-module-main">
                  <h3 class="cur-module-title" id="<?= $titleId ?>"><span class="visually-hidden">Moduli <?= $pos ?> nga <?= $mCount ?>: </span><?= h((string)$m['title']) ?></h3>
                  <p class="cur-module-meta">
                    <span><?= h(qta_hours_label((int)$m['hours'])) ?></span>
                    <span><?= h(qta_plural($tCount, 'temë', 'tema')) ?></span>
                    <?= $info['ok']
                      ? qta_status('Temat: ' . (int)$info['topic_hours'] . ' nga ' . (int)$m['hours'] . ' orë', 'success', 'bi-check2')
                      : qta_status($tCount ? 'Temat: ' . (int)$info['topic_hours'] . ' nga ' . (int)$m['hours'] . ' orë' : 'Pa tema', 'warning', 'bi-exclamation-triangle') ?>
                  </p>
                </div>
                <?php if ($edit): ?>
                  <div class="cur-actions" role="group" aria-label="Veprime për modulin <?= h((string)$m['title']) ?>">
                    <button class="btn btn-ghost btn-sm btn-icon" type="button" data-cur-move="module" data-id="<?= $mid ?>" data-dir="-1"
                            aria-label="Lëviz modulin <?= h((string)$m['title']) ?> një vend lart" title="Lëviz lart"<?= $pos === 1 ? ' disabled' : '' ?>><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                    <button class="btn btn-ghost btn-sm btn-icon" type="button" data-cur-move="module" data-id="<?= $mid ?>" data-dir="1"
                            aria-label="Lëviz modulin <?= h((string)$m['title']) ?> një vend poshtë" title="Lëviz poshtë"<?= $pos === $mCount ? ' disabled' : '' ?>><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
                    <button class="btn btn-ghost btn-sm" type="button" data-cur-open="module-edit" data-id="<?= $mid ?>"
                            data-title="<?= h((string)$m['title']) ?>" data-hours="<?= (int)$m['hours'] ?>" data-position="<?= $pos ?>" data-count="<?= $mCount ?>"
                            aria-label="Ndrysho modulin <?= h((string)$m['title']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i>Ndrysho</button>
                    <button class="btn btn-ghost btn-sm btn-icon btn-ghost-danger" type="button" data-cur-delete="module" data-id="<?= $mid ?>"
                            data-title="<?= h((string)$m['title']) ?>" data-topics="<?= $tCount ?>"
                            aria-label="Fshi modulin <?= h((string)$m['title']) ?>" title="Fshi modulin"><i class="bi bi-trash" aria-hidden="true"></i></button>
                  </div>
                <?php endif; ?>
              </div>

              <?php if ($topics): ?>
                <ol class="cur-topics" aria-label="Temat e modulit <?= h((string)$m['title']) ?>, me radhë">
                  <?php foreach ($topics as $ti => $t):
                    $tid = (int)$t['id'];
                    $tpos = $ti + 1; ?>
                    <li class="cur-topic" id="tema-<?= $tid ?>" data-topic="<?= $tid ?>">
                      <span class="cur-topic-pos" aria-hidden="true"><?= $tpos ?></span>
                      <span class="cur-topic-title"><span class="visually-hidden">Tema <?= $tpos ?>: </span><?= h((string)$t['title']) ?></span>
                      <span class="cur-topic-hours"><?= h(qta_hours_label((int)$t['hours'])) ?></span>
                      <?php if ($edit): ?>
                        <span class="cur-actions" role="group" aria-label="Veprime për temën <?= h((string)$t['title']) ?>">
                          <button class="btn btn-ghost btn-sm btn-icon" type="button" data-cur-move="topic" data-id="<?= $tid ?>" data-dir="-1"
                                  aria-label="Lëviz temën <?= h((string)$t['title']) ?> një vend lart" title="Lëviz lart"<?= $tpos === 1 ? ' disabled' : '' ?>><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                          <button class="btn btn-ghost btn-sm btn-icon" type="button" data-cur-move="topic" data-id="<?= $tid ?>" data-dir="1"
                                  aria-label="Lëviz temën <?= h((string)$t['title']) ?> një vend poshtë" title="Lëviz poshtë"<?= $tpos === $tCount ? ' disabled' : '' ?>><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
                          <button class="btn btn-ghost btn-sm btn-icon" type="button" data-cur-open="topic-edit" data-id="<?= $tid ?>" data-module="<?= $mid ?>"
                                  data-title="<?= h((string)$t['title']) ?>" data-hours="<?= (int)$t['hours'] ?>" data-position="<?= $tpos ?>" data-count="<?= $tCount ?>"
                                  data-module-title="<?= h((string)$m['title']) ?>"
                                  aria-label="Ndrysho temën <?= h((string)$t['title']) ?>" title="Ndrysho temën"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                          <button class="btn btn-ghost btn-sm btn-icon btn-ghost-danger" type="button" data-cur-delete="topic" data-id="<?= $tid ?>"
                                  data-title="<?= h((string)$t['title']) ?>"
                                  aria-label="Fshi temën <?= h((string)$t['title']) ?>" title="Fshi temën"><i class="bi bi-trash" aria-hidden="true"></i></button>
                        </span>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ol>
              <?php else: ?>
                <p class="cur-empty">Ky modul nuk ka ende tema.<?= $edit ? ' Shto temën e parë më poshtë.' : '' ?></p>
              <?php endif; ?>

              <?php if ($edit): ?>
                <form class="cur-quick" data-cur-add-topic="<?= $mid ?>" novalidate>
                  <div class="cur-quick-title">
                    <label class="visually-hidden" for="qaTitle<?= $mid ?>">Emri i temës së re te moduli <?= h((string)$m['title']) ?></label>
                    <input class="form-control form-control-sm" id="qaTitle<?= $mid ?>" name="title" type="text" maxlength="<?= QTA_TOPIC_TITLE_MAX ?>"
                           placeholder="Temë e re te <?= h((string)$m['title']) ?>" autocomplete="off">
                  </div>
                  <div class="cur-quick-hours">
                    <label class="visually-hidden" for="qaHours<?= $mid ?>">Orët e temës së re</label>
                    <input class="form-control form-control-sm" id="qaHours<?= $mid ?>" name="hours" type="number" min="1" max="<?= QTA_HOURS_MAX ?>" step="1" inputmode="numeric" placeholder="Orë">
                  </div>
                  <button class="btn btn-secondary btn-sm" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i>Shto temën</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
        <?php if ($edit): ?>
          <button class="btn btn-secondary mt-3" type="button" data-cur-open="module-add"><i class="bi bi-plus-lg" aria-hidden="true"></i>Shto modul</button>
        <?php endif; ?>
      <?php endif; ?>
    </section>
    <?php
    return (string)ob_get_clean();
  }

  /** Sa grupe (me orar / të mëparshme) e ndjekin kursin. */
  function qta_course_usage(PDO $pdo, int $courseId): array
  {
    $st = $pdo->prepare("SELECT SUM(model = 'scheduled') AS scheduled, SUM(model = 'legacy') AS legacy FROM course_groups WHERE course_id = ?");
    $st->execute([$courseId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['scheduled' => (int)($r['scheduled'] ?? 0), 'legacy' => (int)($r['legacy'] ?? 0)];
  }
}
