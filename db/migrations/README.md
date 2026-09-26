# Migrimet e databazës

Skedarët këtu ndryshojnë një databazë **ekzistuese** pa fshirë të dhëna. Emri fillon me
datën; ekzekutohen sipas radhës së datës.

| Skedari | Çfarë sjell |
|---|---|
| `2026-09-26-kurset-modulet-temat-orari.sql` | Modulet dhe temat e kursit, grupet me orar mësimi, ditët e veçanta, `course_groups.model`, FK kurs → grupe pa fshirje zinxhir, historiku për tabelat e reja. Përshkrimi i plotë: `docs/domain/COURSES-AND-SCHEDULES.md`. |

## Radha

- **Instalim i ri:** `db/tables.sql` → `db/create_audit.sql` → çdo skedar këtu.
- **Databaza e punës:** vetëm skedarët që nuk janë ekzekutuar ende.

## Si ekzekutohet

1. Bëj kopje rezervë, bashkë me trigger-at dhe procedurat:

   ```bash
   mysqldump -u root -p --routines --triggers --single-transaction qta_db > qta_db_para_migrimit.sql
   ```

2. Ekzekuto migrimin:

   ```bash
   mysql -u root -p qta_db < db/migrations/2026-09-26-kurset-modulet-temat-orari.sql
   ```

   Në XAMPP: `C:\xampp\mysql\bin\mysql.exe`. Në phpMyAdmin: zgjidh databazën, skeda
   "SQL", ngjit përmbajtjen e skedarit dhe ekzekutoje (`DELIMITER` mbështetet).

3. Kontrollo:

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

## Siguria e migrimit

- Mund të ekzekutohet disa herë: çdo hap kontrollon nëse është bërë (`CREATE TABLE IF NOT
  EXISTS`, kolona/indeksi/FK kontrollohen te `information_schema`, trigger-at rikrijohen).
- Nuk fshin, nuk rishkruan dhe nuk zhvendos asnjë rresht ekzistues; nuk krijon orar për
  grupet ekzistuese; nuk shton asgjë në historik gjatë migrimit.
- Kërkon MariaDB 10.4+ ose MySQL 8.0.16+ dhe procedurën `audit_capture`
  (`db/create_audit.sql`), si trigger-at ekzistues.
- Përdoruesi i databazës duhet të ketë të drejtat `ALTER`, `CREATE`, `REFERENCES`,
  `TRIGGER` dhe `CREATE ROUTINE`. Me regjistrim binar (binlog) pa `SUPER`, serveri mund
  të kërkojë `log_bin_trust_function_creators = 1` për trigger-at.

## Nëse diçka nuk shkon

- **Ndalet te `fk_cg_course`**: ka grupe që i referohen një kursi që nuk ekziston më.
  Gjeji me
  `SELECT g.id, g.course_id FROM course_groups g LEFT JOIN courses c ON c.id = g.course_id WHERE c.id IS NULL;`
  vendos për to, pastaj ekzekuto sërish migrimin (hapat e bërë kapërcehen).
- **Kthimi mbrapsht**: rikthe kopjen rezervë të hapit 1. Pasi të jenë krijuar grupe me orar,
  kthimi mbrapsht i humb ato; mos e bëj pa e ruajtur më parë punën e re.
