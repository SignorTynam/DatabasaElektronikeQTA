# Enrollment audit — 9 October 2026

Baseline: clean `main`, `0ed3aa67fb53b3bd7bd17fc4f9af424b61839aa0`.

`student_course_plans` already has an identity, unique student/course key, lifecycle,
group FK and actor. Membership insertion creates/assigns it through a trigger, but
assignment, AMZË creation and member edits delete planned rows first. Membership
deletion cascades module scores. These paths lose historical enrollment identity.

Results are normalized but keyed by membership. `results.php` owns parsing, complete
averages and half-up rounding; `final_score` is a guarded read projection. Scheduled
groups read frozen source module IDs; legacy groups read current course modules.
The new model retains those rules and adds enrollment ownership to the score table.
Manual fallback is distinct from legacy results and is retained when explicitly
switching to modules. Enrollment module snapshots provide pre-group identity checks.

Exam ownership is already individual in active writes. Agency and QKL reads still
fall back to the historical group exam. The migration backfills missing individual
exams only after validating dates; new reads use the individual exam only.

Scheduled creation/rebuild uses `qta_lg_create`, `qta_lg_change`, the fixed proposal,
allocation and verification engines. Calculated settings, fixed boundaries, refresh
and course replacement all converge here. Legacy date edits and course replacement
have separate endpoints. All need enrollment reconciliation guards. Calendar and
group documents describe operational groups; trainee card, agency register, QKL and
trainee exports need individual boundaries with a group fallback.

UI uses Themeli modal, field, table, notice, plan-preview and confirmation components.
Needed states: loading course, draft/manual, ready/modules, incomplete/complete,
date conflict, candidate changed/full, schedule failure, validation, review, saving
and unsaved cancellation. Existing semantic surface/text/border, warning/danger,
spacing, control and focus tokens suffice; no compatibility CSS is needed.

No trustworthy certificate-issued flag exists. QR tokens do not establish issuance.
Use the general document warning; do not invent issuance state.

Test baseline: PHP/Node available. Local MySQL stopped. Use an isolated MariaDB
instance and disposable schemas for migration, service, HTTP and browser tests.
