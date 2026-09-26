# QTA UI/UX QA Checklist

Use this after every substantial UI phase and before declaring the revamp complete.

## 1. Build/runtime

- PHP syntax passes for every modified PHP file.
- No fatal/warning output appears before HTML.
- No browser console errors.
- No failed asset requests.
- No mixed-content errors.
- No missing font/icon assets.

## 2. Representative pages

Verify:

- public home;
- verify;
- login/selectProfile;
- admin dashboard;
- editor dashboard;
- agency dashboard;
- student dashboard;
- students;
- groups;
- register;
- logs/audit;
- profile.

## 3. Roles and authorization

For each role:

- only allowed navigation items appear;
- direct unauthorized URLs remain denied by server logic;
- redesign has not made hidden UI the security boundary;
- logout/account actions still work.

## 4. Responsive widths

Test at least:

- 320px;
- 375px;
- 768px;
- 1024px;
- 1440px;
- wide desktop for large tables.

Check:

- no accidental page-wide horizontal overflow;
- sidebar/drawer behavior;
- local page actions;
- dialogs;
- tables;
- forms;
- search;
- long Albanian labels.

## 5. Theme

Verify:

- Light;
- System;
- Dark.

Check:

- canvas/surfaces;
- sidebar;
- inputs;
- tables;
- dialogs;
- selected state;
- focus;
- disabled state;
- error/success/warning;
- links.

## 6. Keyboard

Complete core tasks with keyboard only:

- open/close sidebar drawer;
- navigate global nav;
- open command search;
- move through results;
- open/close account menu;
- switch theme;
- fill and submit login;
- filter students;
- enter edit mode;
- open/close dialog;
- confirm/cancel;
- navigate form errors.

## 7. Focus

- focus indicator always visible;
- no focus trapped outside modal;
- modal/drawer returns focus;
- sticky UI does not obscure focused control;
- no positive tabindex.

## 8. Forms

- labels visible;
- required state clear;
- errors readable and associated;
- entered values preserved after validation where appropriate;
- disabled/read-only distinguishable;
- submit loading prevents accidental duplicate action when relevant;
- cancel path clear.

## 9. Tables

- headers readable;
- sort state clear;
- row action discoverable;
- selection visible;
- bulk-action selected count correct;
- edit-mode state clear;
- keyboard focus visible inside scrollable table;
- narrow viewport behavior remains usable.

## 10. Dialogs/drawers

- accessible title;
- initial focus correct;
- Escape closes when permitted;
- background not keyboard-focusable when modal;
- destructive action visually distinct;
- cancel action predictable;
- focus restored.

## 11. Search/command palette

- Ctrl/Cmd+K;
- "/" only where allowed;
- Escape closes;
- arrow navigation;
- Enter activates;
- no results state;
- loading state;
- authorization preserved.

## 12. Motion

With normal motion:

- transitions are short and state-driven.

With prefers-reduced-motion:

- no decorative entrance/scroll animations;
- no content becomes inaccessible;
- state remains understandable.

## 13. 200% text zoom

Check critical pages at 200%:

- navigation remains operable;
- text does not overlap;
- buttons remain reachable;
- dialogs do not clip essential actions;
- no horizontal overflow except intentional data-table region.

## 14. Public verification

Verify:

- manual code search;
- QR/camera if supported;
- file/image scan if supported;
- valid certificate;
- invalid/not found;
- scanning permission denied;
- API/server failure;
- result remains understandable without color.

## 15. Business regression

Confirm unchanged:

- login;
- role resolution;
- CSRF;
- students CRUD/edit mode;
- groups and splitting behavior;
- course/module management;
- exports;
- audit logging;
- public verification;
- profile/account;
- search.

Courses and scheduled groups (see `docs/domain/COURSES-AND-SCHEDULES.md`):

- `php tests/run.php` and `--integration` against a test database pass;
- a course is "Gati për grup" only when module hours = course hours and topic hours =
  module hours; a draft cannot be chosen for a new group;
- modules and topics reorder with the keyboard (arrow buttons "Lëviz lart" / "Lëviz poshtë"), focus stays on the item;
- 100-hour course from 01.10.2026 at 5 hours/day with Sunday 11.10.2026 at 4 hours ends on
  23.10.2026 with a 1-hour last day;
- every earlier group opens in "Grupet e mëparshme" with unchanged dates, exams and points,
  and cannot be opened, dated or moved through the scheduled-group pages or endpoints;
- a change that touches past days or a closed group asks first; an exam date before the new
  end blocks the change;
- agencies and trainees cannot open `course.php`, `lesson_groups.php`, `lesson_group.php`.

## 16. Visual review

Take screenshots at minimum:

- 375px;
- 1440px;
- Light;
- Dark.

Review:

- hierarchy;
- alignment;
- spacing;
- typography;
- border/shadow discipline;
- overuse of cards;
- overuse of accent;
- old PROTOKOLL artifacts;
- generic Bootstrap defaults leaking through.

## 17. Dead-code cleanup verification

For every deletion:

- search before;
- delete;
- load representative routes;
- check console/network;
- search again;
- document any retained compatibility file and why.

## 18. Completion gate

Do not mark the full revamp complete while any of these remain globally required for ordinary web UI:

- PROTOKOLL design tokens;
- PROTOKOLL top-navbar shell;
- paper grain;
- legacy-map compatibility layer.

Print/export-specific styles are exempt when truly required.
