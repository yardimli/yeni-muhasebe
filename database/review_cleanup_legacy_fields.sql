-- Cinius legacy schema cleanup review
-- Generated for manual review. NOTHING IN THIS FILE CHANGES DATA AS PROVIDED.
-- MariaDB/MyISAM warning: ALTER TABLE and DELETE cannot be rolled back.
-- Create and verify a full backup before uncommenting any destructive statement.
-- The administrator-only browser cleanup screen mirrors the recommended drop
-- candidates below. Its apply mode rechecks that each field has zero populated
-- rows immediately before issuing ALTER TABLE; this file remains review-only.

SET NAMES utf8mb4;

-- ============================================================================
-- 1. READ-ONLY AUDIT: confirm fields believed to be unused
-- ============================================================================

SELECT 'asp_urun.yazarlistetxt' field_name, COUNT(*) total_rows, SUM(yazarlistetxt IS NOT NULL AND TRIM(yazarlistetxt) <> '') populated_rows FROM asp_urun
UNION ALL
SELECT 'asp_urun.yayinevitxt', COUNT(*), SUM(yayinevitxt IS NOT NULL AND TRIM(yayinevitxt) <> '') FROM asp_urun
UNION ALL
SELECT 'banka_kasa.assign', COUNT(*), SUM(assign <> 0) FROM banka_kasa
UNION ALL
SELECT 'banka_kasa.kasaID', COUNT(*), SUM(kasaID <> 0) FROM banka_kasa
UNION ALL
SELECT 'banka_kasa.giderID', COUNT(*), SUM(giderID <> 0) FROM banka_kasa
UNION ALL
SELECT 'banka_kasa.gelirID', COUNT(*), SUM(gelirID <> 0) FROM banka_kasa
UNION ALL
SELECT 'banka_kasa.firmaID', COUNT(*), SUM(firmaID <> 0) FROM banka_kasa
UNION ALL
SELECT 'kasa.BankaIslemID', COUNT(*), SUM(BankaIslemID <> 0) FROM kasa
UNION ALL
SELECT 'kasa.giderID', COUNT(*), SUM(giderID <> 0) FROM kasa
UNION ALL
SELECT 'kasa.gelirID', COUNT(*), SUM(gelirID <> 0) FROM kasa;

-- Only consider dropping a column when populated_rows remains zero after the
-- old Delphi application has also been checked for reads/writes to that field.

-- ============================================================================
-- 2. RECOMMENDED DROP CANDIDATES
-- All statements remain commented out intentionally.
-- ============================================================================

-- Explicitly marked "abandoned" in the original schema.
-- ALTER TABLE banka_kasa DROP COLUMN assign;

-- Redundant display text. The web application uses the relational IDs and shows
-- an explicit "not found" value when the target record is missing.
-- ALTER TABLE asp_urun
--     DROP COLUMN yazarlistetxt,
--     DROP COLUMN yayinevitxt;
-- References to missing gider/gelir modules; live scan showed no populated values.
-- ALTER TABLE banka_kasa
--     DROP COLUMN giderID,
--     DROP COLUMN gelirID;

-- References to missing gider/gelir modules; live scan showed no populated values.
-- ALTER TABLE kasa
--     DROP COLUMN giderID,
--     DROP COLUMN gelirID;

-- Legacy cross-ledger fields that were empty in the live scan.
-- Uncomment only after confirming the Delphi application no longer writes them.
-- ALTER TABLE banka_kasa
--     DROP COLUMN kasaID,
--     DROP COLUMN firmaID;
-- ALTER TABLE kasa DROP COLUMN BankaIslemID;
-- ALTER TABLE kasa
--     DROP COLUMN CaridenGizle,
--     DROP COLUMN BelgeNo,
--     DROP COLUMN ceksenetID;

-- ============================================================================
-- 3. CANDIDATES THAT SHOULD BE RETAINED WITHOUT FURTHER BUSINESS CONFIRMATION
-- ============================================================================

-- ureticiler.TedarikciIDler
--   Contains populated descriptive supplier text. It is denormalized but not empty.

-- faturabaslik.netkitapSepetID
--   External Netkitap integration identifier. Retain unless that integration is retired.

-- faturabaski.kullaniciID
--   The legacy users table is missing, but the field is still useful for a future
--   mapping to web users and print audit history.

-- depobaslik.depoFaturaID
--   Meaning remains ambiguous between an invoice and paired warehouse document.

-- cek_senet_kasa.tahsil_bankaID / ode_bankaID
--   Likely bank transaction references. Retain until the workflow is confirmed.

-- banka_kasa.transferBankaID
--   Currently sparse/empty but represents a valid bank-transfer workflow.

-- ============================================================================
-- 4. READ-ONLY ORPHAN AUDIT
-- Historical transactional orphans should normally be retained because detail
-- rows contain snapshot names, prices, barcodes, dates, and accounting evidence.
-- ============================================================================

SELECT 'depodetay.faturaID' relation_name, COUNT(*) orphan_rows
FROM depodetay x LEFT JOIN depobaslik p ON p.ID = x.faturaID
WHERE x.faturaID > 0 AND p.ID IS NULL
UNION ALL
SELECT 'depodetay.musteriID', COUNT(*)
FROM depodetay x LEFT JOIN adresdefteri p ON p.ID = x.musteriID
WHERE x.musteriID > 0 AND p.ID IS NULL
UNION ALL
SELECT 'depodetay.urunID', COUNT(*)
FROM depodetay x LEFT JOIN asp_urun p ON p.ID = x.urunID
WHERE x.urunID > 0 AND p.ID IS NULL
UNION ALL
SELECT 'faturadetay.faturaID', COUNT(*)
FROM faturadetay x LEFT JOIN faturabaslik p ON p.ID = x.faturaID
WHERE x.faturaID > 0 AND p.ID IS NULL
UNION ALL
SELECT 'faturadetay.musteriID', COUNT(*)
FROM faturadetay x LEFT JOIN adresdefteri p ON p.ID = x.musteriID
WHERE x.musteriID > 0 AND p.ID IS NULL
UNION ALL
SELECT 'faturadetay.urunID', COUNT(*)
FROM faturadetay x LEFT JOIN asp_urun p ON p.ID = x.urunID
WHERE x.urunID > 0 AND p.ID IS NULL
UNION ALL
SELECT 'banka_kasa.faturaID', COUNT(*)
FROM banka_kasa x LEFT JOIN faturabaslik p ON p.ID = x.faturaID
WHERE x.faturaID > 0 AND p.ID IS NULL
UNION ALL
SELECT 'kasa.FaturaID', COUNT(*)
FROM kasa x LEFT JOIN faturabaslik p ON p.ID = x.FaturaID
WHERE x.FaturaID > 0 AND p.ID IS NULL;

-- ============================================================================
-- 5. RETIRED CARI TREE TABLES
-- The web application no longer exposes or reads these tables. The administrator
-- cleanup screen provides cari_agac.tables to remove them in dependency order.
-- ============================================================================

-- DROP TABLE adres_defter_agac_iliskileri;
-- DROP TABLE adres_agac;

-- ============================================================================
-- 6. RETIRED CURRENCY TABLES AND REFERENCES
-- The application now uses the app_settings row keyed by default_currency.
-- Run these through cleanup.php so columns and tables are checked first.
-- ============================================================================

-- ALTER TABLE asp_urun DROP COLUMN kurID;
-- ALTER TABLE banka_kasa DROP COLUMN kurID;
-- ALTER TABLE hesaplar DROP COLUMN kurID;
-- ALTER TABLE kasa DROP COLUMN KurID;
-- DROP TABLE kurhareketleri;
-- DROP TABLE kurlar;

-- ============================================================================
-- 7. RETIRED PAYMENT TABLES
-- cleanup.php removes these in dependency order through payments.tables.
-- ============================================================================

-- DROP TABLE odemefatura;
-- DROP TABLE odemeler;

-- ============================================================================
-- 8. RETIRED AUXILIARY TABLES
-- cleanup.php removes these through obsolete.tables.
-- ============================================================================

-- DROP TABLE adresdefteri2;
-- DROP TABLE faturabaski;
-- DROP TABLE gelir;
-- DROP TABLE gider;
-- DROP TABLE nakitakis;
-- DROP TABLE stoktablo;

-- Do NOT delete orphaned faturadetay, depodetay, kasa, banka_kasa,
-- or cek_senet_kasa rows automatically. They are historical accounting evidence.
