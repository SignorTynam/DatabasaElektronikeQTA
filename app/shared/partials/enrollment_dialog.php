<?php
declare(strict_types=1);
/** Shared fields, existing-enrollment editor and the three reconciliation paths. */
function qta_enrollment_fields(string $prefix): void { ?>
  <section data-enrollment-fields aria-label="Të dhënat e kursit">
    <h3 class="modal-section-title" tabindex="-1" data-enrollment-heading>Të dhënat e kursit</h3>
    <p data-enrollment-course role="status">Po lexoj kursin…</p>
    <div class="row g-3">
      <div class="col-sm-6"><label class="form-label" for="<?= $prefix ?>Start">Data e fillimit</label><input class="form-control" id="<?= $prefix ?>Start" name="start_date" data-dmy required placeholder="dd.mm.vvvv" autocomplete="off"></div>
      <div class="col-sm-6"><label class="form-label" for="<?= $prefix ?>End">Data e mbarimit</label><input class="form-control" id="<?= $prefix ?>End" name="end_date" data-dmy required placeholder="dd.mm.vvvv" autocomplete="off"></div>
      <div class="col-sm-6"><label class="form-label" for="<?= $prefix ?>Exam">Data e provimit <span class="optional">(kërkohet kur ka pikë)</span></label><input class="form-control" id="<?= $prefix ?>Exam" name="exam_date" data-dmy placeholder="dd.mm.vvvv" autocomplete="off"></div>
    </div>
    <div class="mt-3" data-enrollment-results></div>
    <p class="plan-preview mt-3" data-enrollment-average role="status" aria-live="polite"></p>
  </section>
<?php }
function qta_enrollment_dialogs(string $csrf='', bool $edit=true): void {
  static $rendered=false; if($rendered) return; $rendered=true;
  $config=json_encode(['csrf'=>$csrf,'edit'=>$edit,'assign'=>'student_assignment.php'],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP);
?>
<script type="application/json" id="enrollmentConfig"><?= $config ?></script>
<div class="modal fade" id="enrollmentModal" tabindex="-1" aria-labelledby="enrollmentTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" data-enrollment-editor>
    <div class="modal-header"><h2 class="modal-title" id="enrollmentTitle">Të dhënat e kursit</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button></div>
    <div class="modal-body"><div class="alert alert-danger" data-enrollment-error role="alert" hidden></div><?php qta_enrollment_fields('enEdit'); ?></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Anulo</button><button class="btn btn-primary" type="submit">Ruaj regjistrimin</button></div>
  </form></div>
</div>
<div class="modal fade" id="enrollmentConflict" tabindex="-1" aria-labelledby="enConflictTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title" id="enConflictTitle">Datat nuk përputhen</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button></div>
    <div class="modal-body">
      <div class="alert alert-danger" data-conflict-error role="alert" hidden></div>
      <dl class="kv kv-2" data-conflict-dates></dl>
      <div data-conflict-choices>
      <p>Zgjidh si dëshiron të vazhdosh.</p>
      <div class="enrollment-choices">
        <button class="btn btn-secondary" type="button" data-en-resolution="group"><strong>1. Përdor datat e grupit</strong><span>Ndryshon periudhën individuale të kursantit.</span></button>
        <button class="btn btn-secondary" type="button" data-en-resolution="student"><strong>2. Përdor datat e kursantit</strong><span>Përdor ose krijon një grup me të njëjtat data.</span></button>
        <button class="btn btn-secondary" type="button" data-en-resolution="candidates"><strong>3. Shiko grupet e sugjeruara</strong><span>Shfaq vendet e lira dhe diferencën e datave.</span></button>
      </div>
      </div>
      <section class="modal-section" data-conflict-work hidden tabindex="-1"></section>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Anulo</button></div>
  </div></div>
</div>
<?php }
