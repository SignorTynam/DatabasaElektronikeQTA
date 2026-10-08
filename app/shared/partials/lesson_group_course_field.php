<?php
/** Course selector shared by the calculated and fixed-period settings dialogs. */
$courseFieldId = $fixedMode ? 'lgfsCourse' : 'lgsCourse';
?>
<div class="col-12">
  <label class="form-label" for="<?= h($courseFieldId) ?>">Kursi <span class="req" aria-hidden="true">*</span></label>
  <select class="form-select" id="<?= h($courseFieldId) ?>" name="course_id" required aria-describedby="<?= h($courseFieldId) ?>Help">
    <?php foreach ($settingsCourses as $c):
      $cid = (int)$c['id'];
      $current = $cid === (int)$g['course_id'];
      $ready = !empty($settingsSummaries[$cid]['ready']); ?>
      <option value="<?= $cid ?>" <?= $current ? 'selected' : '' ?> <?= $ready || $current ? '' : 'disabled' ?>><?= h((string)$c['name']) ?> · <?= h(qta_hours_label((int)$c['hours'])) ?><?= $ready || $current ? '' : ' — jo gati' ?></option>
    <?php endforeach; ?>
  </select>
  <div class="form-text" id="<?= h($courseFieldId) ?>Help">Kursi i ri zëvendëson modulet dhe temat e grupit dhe rindërton orarin. Zgjidhen vetëm kurset me module dhe tema të plota. Kur ka pikë të regjistruara, kursi nuk ndryshohet.</div>
</div>
