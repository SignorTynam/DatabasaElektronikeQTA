# Migrimet e databazës

Skedarët këtu ndryshojnë një databazë **ekzistuese** pa fshirë të dhëna. Emri fillon me
datën; ekzekutohen sipas radhës së datës.

| Skedari | Çfarë sjell |
|---|---|
| `2026-09-26-kurset-modulet-temat-orari.sql` | Modulet dhe temat e kursit, grupet me orar mësimi, ditët e veçanta, `course_groups.model`, FK kurs → grupe pa fshirje zinxhir, historiku për tabelat e reja. Përshkrimi i plotë: `docs/domain/COURSES-AND-SCHEDULES.md`. |
| `2026-09-28-konvertimi-i-grupeve.sql` | Kufiri **8 orë në ditë** (ishte 12) te çdo CHECK i orarit; `group_schedules.schedule_mode` (`calculated` / `fixed_range`) dhe `daily_hours` NULL për oraret me data historike; tabelat `group_fixed_days` (plani i ditëve), `legacy_conversion_drafts` (drafti), `group_conversions` (shënimi i konvertimit); rregullat e bazës për konvertimin (`legacy` → `scheduled` vetëm brenda konvertimit) dhe historiku i tyre. Nuk konverton asnjë grup. Përshkrimi: `docs/domain/COURSES-AND-SCHEDULES.md` §14. |

## Radha

- **Instalim i ri:** `db/tables.sql` → `db/create_audit.sql` → çdo skedar këtu.
- **Databaza e punës:** vetëm skedarët që nuk janë ekzekutuar ende, sipas radhës.
  `2026-09-28` kërkon që `2026-09-26` të jetë ekzekutuar; përndryshe ndalet pa ndryshuar asgjë.

## Si ekzekutohet

1. Bëj kopje rezervë, bashkë me trigger-at dhe procedurat:

   ```bash
   mysqldump -u root -p --routines --triggers --single-transaction qta_db > qta_db_para_migrimit.sql
   ```

2. Ekzekuto migrimet që mungojnë, një nga një:

   ```bash
   mysql -u root -p qta_db < db/migrations/2026-09-26-kurset-modulet-temat-orari.sql
   mysql -u root -p qta_db < db/migrations/2026-09-28-konvertimi-i-grupeve.sql
   ```

   Në XAMPP: `C:\xampp\mysql\bin\mysql.exe`. Në phpMyAdmin: zgjidh databazën, skeda
   "SQL", ngjit përmbajtjen e skedarit dhe ekzekutoje (`DELIMITER` mbështetet).

3. Kontrollo pas `2026-09-26`:

   ```sql
   -- Çdo grup ekzistues është 'legacy'; asnjë 'scheduled' para se të krijohet i pari.
   SELECT model, COUNT(*) FROM course_groups GROUP BY model;

   -- Fshirja e një kursi me grupe ndalohet nga vetë databaza.
   SELECT CONSTRAINT_NAME, DELETE_RULE
   FROM information_schema.REFERENTIAL_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'course_groups'
     AND REFERENCED_TABLE_NAME = 'courses';          -- pritet: fk_cg_course, RESTRICT

   -- Tabelat e reja.
   SHOW TABLES LIKE 'course_modules';
   SHOW TABLES LIKE 'group_schedule%';
   ```

4. Kontrollo pas `2026-09-28`:

   ```sql
   -- Çdo orar ekzistues është 'calculated', me të njëjtat orë në ditë (1–8).
   SELECT schedule_mode, MIN(daily_hours), MAX(daily_hours), COUNT(*) FROM group_schedules GROUP BY schedule_mode;

   -- Kufiri 8 orë dhe lidhja lloj ↔ orë në ditë.
   SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND CONSTRAINT_NAME IN ('chk_gs_daily', 'chk_gsd_hours', 'chk_gss_hours', 'chk_gdr_hours', 'chk_gfd_hours');

   -- Tabelat e reja (bosh derisa të konvertohet grupi i parë).
   SELECT (SELECT COUNT(*) FROM group_fixed_days) AS plani, (SELECT COUNT(*) FROM legacy_conversion_drafts) AS drafte,
          (SELECT COUNT(*) FROM group_conversions) AS konvertime;

   -- Rregullat e bazës për konvertimin.
   SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
   WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('trg_cg_model_guard_bu', 'trg_gs_requires_scheduled_bi',
     'trg_gfd_requires_fixed_bi', 'trg_lcd_requires_legacy_bi', 'trg_gc_start_bi', 'trg_gc_complete_bu');   -- pritet: 6

   -- Asnjë procedurë e përkohshme e migrimit.
   SELECT ROUTINE_NAME FROM information_schema.ROUTINES
   WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'qta\_migrate\_%';               -- pritet: bosh
   ```

## Siguria e migrimit

- Mund të ekzekutohen disa herë: çdo hap kontrollon nëse është bërë (`CREATE TABLE IF NOT
  EXISTS`, kolona/indeksi/FK/CHECK kontrollohen te `information_schema`, trigger-at
  rikrijohen). Një ekzekutim i dytë i `2026-09-28` lë të njëjtën skemë dhe të njëjtat të dhëna.
- Nuk fshijnë, nuk rishkruajnë dhe nuk zhvendosin asnjë rresht ekzistues; nuk krijojnë orar
  për grupet ekzistuese dhe nuk konvertojnë asnjë grup; nuk shtojnë asgjë në historik gjatë
  migrimit. `2026-09-28` i jep çdo orari ekzistues llojin `calculated`, pa i ndryshuar vlerat.
- `2026-09-28` kontrollon **para çdo ndryshimi** dhe ndalet pa ndryshuar asgjë kur:
  - `2026-09-26` nuk është ekzekutuar;
  - një grup me orar ka mbi 8 orë në një ditë (orët në ditë, një ditë e orarit, një pjesë e
    saj ose një ditë e veçantë). Para mesazhit shfaq listën e rreshtave (lloji, grupi, data,
    orët). Uli orët te faqja e secilit grup ("Ndrysho fillimin ose orët në ditë" ose dita e
    veçantë), pastaj ekzekuto sërish migrimin.
  Pas një ndalimi të tillë mbetet vetëm procedura e kontrollit `qta_migrate_20260928_check`;
  ekzekutimi i radhës e rikrijon dhe e fshin (ose:
  `DROP PROCEDURE IF EXISTS qta_migrate_20260928_check;`).
- **Mos e ekzekuto `2026-09-26` pas `2026-09-28`.** Ai rikrijon trigger-at e tij të vjetër
  (p.sh. lloji i grupit nuk ndryshon kurrë, pra as konvertimi nuk kalon). Nëse ndodh,
  ekzekuto sërish `2026-09-28`: skema kthehet saktësisht si më parë.
- Kërkon MariaDB 10.4+ ose MySQL 8.0.19+ (`ALTER TABLE … DROP CONSTRAINT`) dhe procedurën
  `audit_capture` (`db/create_audit.sql`), si trigger-at ekzistues.
- Përdoruesi i databazës duhet të ketë të drejtat `ALTER`, `CREATE`, `REFERENCES`,
  `TRIGGER` dhe `CREATE ROUTINE`. Me regjistrim binar (binlog) pa `SUPER`, serveri mund
  të kërkojë `log_bin_trust_function_creators = 1` për trigger-at.
- Të gjitha rastet e mësipërme testohen mbi databaza të përkohshme:
  `tests/integration/migration_conversion_test.php` (databazë e pastër, vetëm grupe të
  mëparshme, me grupe me orar, ekzekutim i dytë, rreshta mbi 8 orë, pa `2026-09-26`).

## Nëse diçka nuk shkon

- **Ndalet te `fk_cg_course`** (`2026-09-26`): ka grupe që i referohen një kursi që nuk
  ekziston më. Gjeji me
  `SELECT g.id, g.course_id FROM course_groups g LEFT JOIN courses c ON c.id = g.course_id WHERE c.id IS NULL;`
  vendos për to, pastaj ekzekuto sërish migrimin (hapat e bërë kapërcehen).
- **"Migrimi 2026-09-28 u ndal: disa grupe me orar kanë mbi 8 orë…"**: korrigjo grupet e
  listës (shih më sipër) dhe ekzekutoje sërish. Asgjë nuk ka ndryshuar ndërkohë.
- **"Migrimi 2026-09-28 u ndal: ekzekuto së pari 2026-09-26…"**: ekzekuto `2026-09-26`,
  pastaj `2026-09-28`.
- **Kthimi mbrapsht**: rikthe kopjen rezervë të hapit 1. Pasi të jenë krijuar grupe me orar
  ose të jenë konvertuar grupe, kthimi mbrapsht i humb ato; mos e bëj pa e ruajtur më parë
  punën e re.
