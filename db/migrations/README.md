# Migrimet e databazës

Skedarët këtu ndryshojnë një databazë **ekzistuese** pa fshirë të dhëna. Emri fillon me
datën; ekzekutohen sipas radhës së datës.

| Skedari | Çfarë sjell |
|---|---|
| `2026-09-26-kurset-modulet-temat-orari.sql` | Modulet dhe temat e kursit, grupet me orar mësimi, ditët e veçanta, `course_groups.model`, FK kurs → grupe pa fshirje zinxhir, historiku për tabelat e reja. Përshkrimi i plotë: `docs/domain/COURSES-AND-SCHEDULES.md`. |
| `2026-09-28-konvertimi-i-grupeve.sql` | Kufiri **8 orë në ditë** (ishte 12) te çdo CHECK i orarit; `group_schedules.schedule_mode` (`calculated` / `fixed_range`) dhe `daily_hours` NULL për oraret me data historike; tabelat `group_fixed_days` (plani i ditëve), `legacy_conversion_drafts` (drafti), `group_conversions` (shënimi i konvertimit); rregullat e bazës për konvertimin (`legacy` → `scheduled` vetëm brenda konvertimit) dhe historiku i tyre. Nuk konverton asnjë grup. Përshkrimi: `docs/domain/COURSES-AND-SCHEDULES.md` §14. |
| `2026-09-28-piket-sipas-moduleve.sql` | **Pikët sipas moduleve**: tabela `enrollment_module_scores` (një rresht për kursant në grup × modul, 0–100), kolona `course_group_students.legacy_final_score` (bosh; mbushet vetëm kur pikët e vjetra zëvendësohen nga modulet), rregullat e bazës (rezultati përfundimtar nuk shkruhet më me dorë; moduli duhet t'i përkasë grupit; data e provimit mbetet kur ka pikë; kursi i grupit dhe moduli me pikë të regjistrit të vjetër nuk ndryshojnë) dhe historiku i pikëve. Nuk ndryshon asnjë rresht ekzistues. Përshkrimi: `docs/domain/COURSES-AND-SCHEDULES.md` §15. |
| `2026-10-07-orari-me-periudhe-te-percaktuar.sql` | E bën `fixed_range` një mënyrë të përgjithshme për grupet e reja dhe të konvertuara; lejon korrigjimin e datave operative, por lë të pandryshueshme datat burimore te `group_conversions`. Përditëson auditimin e versionit/rindërtimit dhe të rreshtave të planit. Nuk ndryshon asnjë rresht ekzistues. |
| `2026-10-08-ndryshimi-i-kursit-te-grupit.sql` | Lejon ndërrimin e kursit vetëm përmes rindërtimit atomik të orarit dhe vetëm pa rezultate të regjistruara. Ruhet mbrojtja e modelit dhe prejardhjes së konvertimit. Nuk ndryshon rreshta ekzistues. |

## Radha

Migrimi më i ri: `2026-10-08-ndryshimi-i-kursit-te-grupit.sql`, pas `2026-10-07`.
Lejon ndërrimin e kursit të një grupi me orar vetëm nga shërbimi që rindërton
kopjen e moduleve/temave dhe planin në një transaksion. Refuzon ndryshimin kur
ka pikë moduli, rezultat përfundimtar ose pikë të vjetra. Nuk ndryshon të dhëna
ekzistuese, modelin e grupit apo prejardhjen e konvertimit; mund të ekzekutohet sërish.

```bash
mysql -u root -p qta_db < db/migrations/2026-10-08-ndryshimi-i-kursit-te-grupit.sql
```

Mos riekzekuto migrime më të vjetra pas këtij pa riekzekutuar edhe këtë: ato
rikrijojnë rregullin e mëparshëm që bllokonte çdo ndryshim kursi.

- **Instalim i ri:** `db/tables.sql` → `db/create_audit.sql` → çdo skedar këtu.
- **Databaza e punës:** vetëm skedarët që nuk janë ekzekutuar ende, sipas radhës (renditja e
  tabelës më sipër; dy skedarët e datës 2026-09-28: së pari `konvertimi-i-grupeve`, pastaj
  `piket-sipas-moduleve`), pastaj `2026-10-07-orari-me-periudhe-te-percaktuar.sql`.
  Migrimi 2026-10-07 kërkon tabelat e migrimit të konvertimit; përndryshe ndalet.

## Si ekzekutohet

1. Bëj kopje rezervë, bashkë me trigger-at dhe procedurat:

   ```bash
   mysqldump -u root -p --routines --triggers --single-transaction qta_db > qta_db_para_migrimit.sql
   ```

2. Ekzekuto migrimet që mungojnë, një nga një:

   ```bash
   mysql -u root -p qta_db < db/migrations/2026-09-26-kurset-modulet-temat-orari.sql
   mysql -u root -p qta_db < db/migrations/2026-09-28-konvertimi-i-grupeve.sql
   mysql -u root -p qta_db < db/migrations/2026-09-28-piket-sipas-moduleve.sql
   mysql -u root -p qta_db < db/migrations/2026-10-07-orari-me-periudhe-te-percaktuar.sql
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

4. Kontrollo pas `2026-09-28-konvertimi-i-grupeve`:

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

5. Kontrollo pas `2026-09-28-piket-sipas-moduleve`:

   ```sql
   -- Tabela e re (bosh) dhe kolona e pikëve të vjetra (bosh: asnjë rresht nuk ndryshoi).
   SELECT (SELECT COUNT(*) FROM enrollment_module_scores) AS piket_e_moduleve,
          (SELECT COUNT(*) FROM course_group_students WHERE legacy_final_score IS NOT NULL) AS kopje;   -- pritet: 0, 0

   -- Rregullat e bazës dhe historiku.
   SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
   WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('trg_ems_bi', 'trg_ems_bu', 'trg_cgs_results_bu',
     'trg_cgs_results_bd', 'trg_cg_results_course_bu', 'trg_cm_results_bd', 'trg_cm_results_bu',
     'trg_audit_ems_ai', 'trg_audit_ems_au', 'trg_audit_ems_ad');                           -- pritet: 10
   ```

   Pas këtij migrimi rezultati përfundimtar (`final_score`) nuk shkruhet më me dorë — as nga
   phpMyAdmin: baza e refuzon. Pikët vendosen sipas moduleve te "Vendos pikët" i grupit.

6. Kontrollo pas `2026-10-07-orari-me-periudhe-te-percaktuar`:

   ```sql
   -- fixed_range nuk kërkon më domosdoshmërisht një konvertim.
   SHOW CREATE TRIGGER trg_gs_requires_scheduled_bi;

   -- Datat burimore të konvertimit ruhen veçmas nga datat operative.
   SELECT cg.id, cg.start_date AS operative_start, cg.end_date AS operative_end,
          gc.source_start_date, gc.source_end_date
   FROM course_groups cg
   JOIN group_conversions gc ON gc.group_id = cg.id
   WHERE gc.status = 'completed';

   -- Auditimi i rindërtimit të plotë.
   SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
   WHERE TRIGGER_SCHEMA = DATABASE()
     AND TRIGGER_NAME IN ('trg_audit_gfd_ai','trg_audit_gfd_au','trg_audit_gfd_ad','trg_audit_gs_au');
   ```

## Siguria e migrimit

- Mund të ekzekutohen disa herë: çdo hap kontrollon nëse është bërë (`CREATE TABLE IF NOT
  EXISTS`, kolona/indeksi/FK/CHECK kontrollohen te `information_schema`, trigger-at
  rikrijohen). Një ekzekutim i dytë i `2026-09-28` lë të njëjtën skemë dhe të njëjtat të dhëna.
- Nuk fshijnë, nuk rishkruajnë dhe nuk zhvendosin asnjë rresht ekzistues; nuk krijojnë orar
  për grupet ekzistuese dhe nuk konvertojnë asnjë grup; nuk shtojnë asgjë në historik gjatë
  migrimit. `2026-09-28` i jep çdo orari ekzistues llojin `calculated`, pa i ndryshuar vlerat.
- `2026-10-07` rikrijon vetëm trigger-at përkatës. Është idempotent, nuk prek rreshtat
  ekzistues dhe nuk ndryshon `group_conversions.source_start_date/source_end_date`.
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
- Më sipër, `2026-09-28` është `2026-09-28-konvertimi-i-grupeve.sql`.
  `2026-09-28-piket-sipas-moduleve.sql` kontrollon **para çdo ndryshimi** që të dy migrimet
  e mëparshme janë ekzekutuar dhe ndalet pa ndryshuar asgjë kur mungon njëri. Nuk mbush asnjë
  kolonë dhe nuk prek asnjë trigger ekzistues: shton vetëm tabelën, kolonën bosh
  `legacy_final_score` dhe trigger-at e vet. Pikët ekzistuese (`final_score`) mbeten ashtu siç
  janë dhe shfaqen si "pikë të vjetra" derisa kursanti të marrë pikë sipas moduleve; atëherë
  vlera e vjetër ruhet te `legacy_final_score` dhe nuk ndryshon më.
- Të gjitha rastet e mësipërme testohen mbi databaza të përkohshme:
  `tests/integration/migration_conversion_test.php` (databazë e pastër, vetëm grupe të
  mëparshme, me grupe me orar, fixed_range i ri, datat operative/burimore, ekzekutim i dytë,
  rreshta mbi 8 orë, pa `2026-09-26`) dhe
  `tests/integration/migration_results_test.php` (databazë e pastër, me pikë të mëparshme,
  ekzekutim i dytë, pa migrimin e konvertimit).

## Nëse diçka nuk shkon

- **Ndalet te `fk_cg_course`** (`2026-09-26`): ka grupe që i referohen një kursi që nuk
  ekziston më. Gjeji me
  `SELECT g.id, g.course_id FROM course_groups g LEFT JOIN courses c ON c.id = g.course_id WHERE c.id IS NULL;`
  vendos për to, pastaj ekzekuto sërish migrimin (hapat e bërë kapërcehen).
- **"Migrimi 2026-09-28 u ndal: disa grupe me orar kanë mbi 8 orë…"**: korrigjo grupet e
  listës (shih më sipër) dhe ekzekutoje sërish. Asgjë nuk ka ndryshuar ndërkohë.
- **"Migrimi 2026-09-28 u ndal: ekzekuto së pari 2026-09-26…"**: ekzekuto `2026-09-26`,
  pastaj `2026-09-28`.
- **"Migrimi i pikëve sipas moduleve u ndal: ekzekuto së pari…"**: ekzekuto migrimet që
  mungojnë sipas radhës, pastaj `2026-09-28-piket-sipas-moduleve`. Asgjë nuk ka ndryshuar.
- **"Rezultati përfundimtar llogaritet nga pikët e moduleve dhe nuk shkruhet me dorë"** gjatë
  një ndryshimi në phpMyAdmin ose një skripti: pas migrimit të pikëve kjo është e pritshme.
  Pikët vendosen te "Vendos pikët" i grupit, dhe rezultati llogaritet vetë.
- **Kthimi mbrapsht**: rikthe kopjen rezervë të hapit 1. Pasi të jenë krijuar grupe me orar
  ose të jenë konvertuar grupe, kthimi mbrapsht i humb ato; mos e bëj pa e ruajtur më parë
  punën e re.
