<?php
// Database-schema en migraties. Idempotent: mag altijd opnieuw draaien.

function schema_migreren(): array
{
    $pdo = db();
    $log = [];
    $basis = [
"CREATE TABLE IF NOT EXISTS gebruikers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  naam VARCHAR(100) NOT NULL,
  gebruikersnaam VARCHAR(100) NOT NULL UNIQUE,
  wachtwoord_hash VARCHAR(255) NOT NULL,
  rol ENUM('admin','monteur') NOT NULL DEFAULT 'monteur',
  actief TINYINT(1) NOT NULL DEFAULT 1,
  laatst_ingelogd DATETIME NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS producten (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NULL UNIQUE,
  naam VARCHAR(255) NOT NULL,
  groep VARCHAR(100) NOT NULL DEFAULT '',
  ean VARCHAR(20) NULL,
  eenheid VARCHAR(20) NOT NULL DEFAULT 'stuks',
  prijs DECIMAL(10,2) NULL,
  voorraad DECIMAL(12,2) NOT NULL DEFAULT 0,
  minimum DECIMAL(12,2) NULL,
  locatie VARCHAR(100) NOT NULL DEFAULT '',
  actief TINYINT(1) NOT NULL DEFAULT 1,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (ean), INDEX (groep), INDEX (naam)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS projecten (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nummer VARCHAR(50) NOT NULL UNIQUE,
  omschrijving VARCHAR(255) NOT NULL DEFAULT '',
  afgerond TINYINT(1) NOT NULL DEFAULT 0,
  aangemaakt_door INT UNSIGNED NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS mutaties (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  gebruiker_id INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  type ENUM('uit','retour','ontvangst','telling','import','correctie') NOT NULL,
  aantal DECIMAL(12,2) NOT NULL,
  voorraad_na DECIMAL(12,2) NOT NULL,
  prijs DECIMAL(10,2) NULL,
  opmerking VARCHAR(255) NOT NULL DEFAULT '',
  ongedaan_van INT UNSIGNED NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (product_id), INDEX (project_id), INDEX (aangemaakt),
  CONSTRAINT fk_m_product FOREIGN KEY (product_id) REFERENCES producten(id),
  CONSTRAINT fk_m_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS login_pogingen (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  tijd DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, tijd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

// --- Inkoop en materiaallijsten (v2) ---
"CREATE TABLE IF NOT EXISTS materiaal (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  omschrijving VARCHAR(255) NOT NULL,
  ean VARCHAR(20) NULL,
  leverancier_code VARCHAR(50) NULL,
  eenheid VARCHAR(20) NOT NULL DEFAULT 'stuks',
  nodig DECIMAL(12,2) NOT NULL DEFAULT 0,
  gereserveerd DECIMAL(12,2) NOT NULL DEFAULT 0,
  op_project_geleverd DECIMAL(12,2) NOT NULL DEFAULT 0,
  bron VARCHAR(255) NOT NULL DEFAULT '',
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (project_id), INDEX (product_id),
  CONSTRAINT fk_mat_project FOREIGN KEY (project_id) REFERENCES projecten(id),
  CONSTRAINT fk_mat_product FOREIGN KEY (product_id) REFERENCES producten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS bestellingen (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  leverancier VARCHAR(100) NOT NULL,
  ordernummer VARCHAR(100) NOT NULL,
  project_id INT UNSIGNED NULL,
  besteldatum DATE NULL,
  leverdatum DATE NULL,
  levering ENUM('magazijn','project','onbekend') NOT NULL DEFAULT 'onbekend',
  afleveradres VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('besteld','bevestigd','verzonden','geleverd','gefactureerd') NOT NULL DEFAULT 'besteld',
  factuurnummer VARCHAR(100) NOT NULL DEFAULT '',
  factuurbedrag DECIMAL(12,2) NULL,
  besteld_door VARCHAR(100) NOT NULL DEFAULT '',
  bron VARCHAR(255) NOT NULL DEFAULT '',
  opmerking VARCHAR(255) NOT NULL DEFAULT '',
  geleverd_op DATETIME NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniek_order (leverancier, ordernummer),
  INDEX (project_id), INDEX (status),
  CONSTRAINT fk_b_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS bestelregels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bestelling_id INT UNSIGNED NOT NULL,
  materiaal_id INT UNSIGNED NULL,
  product_id INT UNSIGNED NULL,
  omschrijving VARCHAR(255) NOT NULL,
  ean VARCHAR(20) NULL,
  leverancier_code VARCHAR(50) NULL,
  aantal DECIMAL(12,2) NOT NULL,
  prijs DECIMAL(10,2) NULL,
  geleverd DECIMAL(12,2) NOT NULL DEFAULT 0,
  INDEX (bestelling_id), INDEX (materiaal_id), INDEX (product_id),
  CONSTRAINT fk_br_bestelling FOREIGN KEY (bestelling_id) REFERENCES bestellingen(id) ON DELETE CASCADE,
  CONSTRAINT fk_br_materiaal FOREIGN KEY (materiaal_id) REFERENCES materiaal(id) ON DELETE SET NULL,
  CONSTRAINT fk_br_product FOREIGN KEY (product_id) REFERENCES producten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

// --- Operatie: planning, dagopdrachten, taken, foto's (v3) ---
"CREATE TABLE IF NOT EXISTS planning (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  datum DATE NOT NULL,
  dagdeel ENUM('ochtend','middag') NOT NULL,
  gebruiker_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  tekst VARCHAR(255) NOT NULL DEFAULT '',
  voorbehoud TINYINT(1) NOT NULL DEFAULT 0,
  bijgewerkt_door INT UNSIGNED NULL,
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniek_slot (datum, dagdeel, gebruiker_id),
  INDEX (project_id), INDEX (gebruiker_id),
  CONSTRAINT fk_pl_gebruiker FOREIGN KEY (gebruiker_id) REFERENCES gebruikers(id),
  CONSTRAINT fk_pl_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS dagopdrachten (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  datum DATE NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  wat TEXT NOT NULL,
  meenemen TEXT NOT NULL,
  stroom VARCHAR(255) NOT NULL DEFAULT '',
  bron VARCHAR(255) NOT NULL DEFAULT '',
  akkoord1_door INT UNSIGNED NULL,
  akkoord1_op DATETIME NULL,
  akkoord2_door INT UNSIGNED NULL,
  akkoord2_op DATETIME NULL,
  bijgewerkt_door VARCHAR(100) NOT NULL DEFAULT '',
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniek_dag (datum, project_id),
  CONSTRAINT fk_do_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS taken (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titel VARCHAR(255) NOT NULL,
  toelichting TEXT NULL,
  project_id INT UNSIGNED NULL,
  eigenaar_id INT UNSIGNED NULL,
  datum DATE NULL,
  deadline DATE NULL,
  volgorde INT NOT NULL DEFAULT 100,
  prioriteit ENUM('kritiek','hoog','normaal','laag') NOT NULL DEFAULT 'normaal',
  status ENUM('open','bezig','wacht','klaar') NOT NULL DEFAULT 'open',
  wacht_op VARCHAR(255) NOT NULL DEFAULT '',
  bron VARCHAR(255) NOT NULL DEFAULT '',
  extern_id VARCHAR(150) NULL UNIQUE,
  aangemaakt_door VARCHAR(100) NOT NULL DEFAULT '',
  afgerond_op DATETIME NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (eigenaar_id, status), INDEX (project_id), INDEX (status),
  CONSTRAINT fk_t_project FOREIGN KEY (project_id) REFERENCES projecten(id),
  CONSTRAINT fk_t_eigenaar FOREIGN KEY (eigenaar_id) REFERENCES gebruikers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS taak_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  taak_id INT UNSIGNED NOT NULL,
  gebruiker_id INT UNSIGNED NULL,
  wie VARCHAR(100) NOT NULL DEFAULT '',
  tekst VARCHAR(600) NOT NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (taak_id), INDEX (aangemaakt),
  CONSTRAINT fk_tl_taak FOREIGN KEY (taak_id) REFERENCES taken(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS fotos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  gebruiker_id INT UNSIGNED NULL,
  bestand VARCHAR(100) NOT NULL,
  soort ENUM('schouw','uitvoering','oplevering','probleem') NOT NULL DEFAULT 'uitvoering',
  omschrijving VARCHAR(255) NOT NULL DEFAULT '',
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (project_id),
  CONSTRAINT fk_f_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS meldingen (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  datum DATE NOT NULL,
  project_id INT UNSIGNED NULL,
  gebruiker_id INT UNSIGNED NOT NULL,
  soort ENUM('klaar','probleem') NOT NULL,
  tekst VARCHAR(1000) NOT NULL DEFAULT '',
  taak_id INT UNSIGNED NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (datum), INDEX (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS notities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gebruiker_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  tekst TEXT NOT NULL,
  status ENUM('nieuw','verwerkt') NOT NULL DEFAULT 'nieuw',
  verwerking TEXT NULL,
  verwerkt_op DATETIME NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status), INDEX (aangemaakt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS seintjes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gebruiker_id INT UNSIGNED NOT NULL,
  tekst VARCHAR(500) NOT NULL,
  link VARCHAR(255) NOT NULL DEFAULT '',
  gezien_op DATETIME NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (gebruiker_id, gezien_op)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS voorstellen (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titel VARCHAR(255) NOT NULL,
  toelichting TEXT NULL,
  wijzigingen LONGTEXT NOT NULL,
  bron VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('open','akkoord','afgewezen') NOT NULL DEFAULT 'open',
  besloten_door INT UNSIGNED NULL,
  besloten_op DATETIME NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS project_berichten (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  datum DATETIME NOT NULL,
  bron VARCHAR(20) NOT NULL DEFAULT 'claude',
  van VARCHAR(150) NOT NULL DEFAULT '',
  onderwerp VARCHAR(255) NOT NULL DEFAULT '',
  tekst TEXT NULL,
  link VARCHAR(500) NOT NULL DEFAULT '',
  extern_id VARCHAR(150) NULL UNIQUE,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pb_project (project_id, datum),
  CONSTRAINT fk_pb_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS project_mijlpalen (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  datum DATE NULL,
  titel VARCHAR(200) NOT NULL,
  soort VARCHAR(20) NOT NULL DEFAULT 'overig',
  zeker TINYINT(1) NOT NULL DEFAULT 1,
  gedaan TINYINT(1) NOT NULL DEFAULT 0,
  gedaan_op DATETIME NULL,
  toelichting VARCHAR(500) NOT NULL DEFAULT '',
  bron VARCHAR(20) NOT NULL DEFAULT 'hand',
  door VARCHAR(100) NOT NULL DEFAULT '',
  extern_id VARCHAR(150) NULL UNIQUE,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pm_project (project_id, datum),
  CONSTRAINT fk_pm_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($basis as $sql) { $pdo->exec($sql); }

    // Kolommen toevoegen aan bestaande tabellen
    $kolommen = [
        ['producten', 'leverancier', "VARCHAR(100) NOT NULL DEFAULT '' AFTER ean"],
        ['producten', 'leverancier_code', "VARCHAR(50) NULL AFTER leverancier"],
        ['gebruikers', 'planbaar', "TINYINT(1) NOT NULL DEFAULT 0 AFTER actief"],
        ['gebruikers', 'volgorde', "INT NOT NULL DEFAULT 100 AFTER planbaar"],
        ['projecten', 'klant', "VARCHAR(150) NOT NULL DEFAULT '' AFTER omschrijving"],
        ['projecten', 'adres', "VARCHAR(255) NOT NULL DEFAULT '' AFTER klant"],
        ['projecten', 'contact', "VARCHAR(255) NOT NULL DEFAULT '' AFTER adres"],
        ['projecten', 'leider_id', "INT UNSIGNED NULL AFTER contact"],
        ['projecten', 'fase', "VARCHAR(20) NOT NULL DEFAULT 'nieuw' AFTER leider_id"],
        ['projecten', 'opleverdatum', "DATE NULL AFTER fase"],
        ['projecten', 'kwh', "INT UNSIGNED NULL AFTER opleverdatum"],
        ['projecten', 'systeem', "VARCHAR(150) NOT NULL DEFAULT '' AFTER kwh"],
        ['projecten', 'samenvatting', "TEXT NULL AFTER systeem"],
        ['projecten', 'samenvatting_op', "DATETIME NULL AFTER samenvatting"],
        ['projecten', 'pipedrive_id', "INT UNSIGNED NULL AFTER samenvatting_op"],
        ['gebruikers', 'beslisser', "TINYINT(1) NOT NULL DEFAULT 0 AFTER planbaar"],
        ['taken', 'van_id', "INT UNSIGNED NULL AFTER eigenaar_id"],
        // Overstap van Pipedrive Projects (26-11-2026): service-tickets en projectgegevens uit Pipedrive
        ['projecten', 'soort', "VARCHAR(10) NOT NULL DEFAULT 'project' AFTER nummer"],
        ['projecten', 'startdatum', "DATE NULL AFTER fase"],
        ['projecten', 'einddatum', "DATE NULL AFTER startdatum"],
        ['projecten', 'labels', "VARCHAR(255) NOT NULL DEFAULT '' AFTER systeem"],
        ['projecten', 'beschrijving', "TEXT NULL AFTER labels"],
        ['projecten', 'apparaat', "VARCHAR(20) NOT NULL DEFAULT '' AFTER beschrijving"],
        ['projecten', 'urgentie', "VARCHAR(20) NOT NULL DEFAULT '' AFTER apparaat"],
        ['projecten', 'afhandeling', "VARCHAR(20) NOT NULL DEFAULT '' AFTER urgentie"],
        ['projecten', 'foutcode', "VARCHAR(100) NOT NULL DEFAULT '' AFTER afhandeling"],
        ['projecten', 'telefoon', "VARCHAR(50) NOT NULL DEFAULT '' AFTER foutcode"],
        ['projecten', 'email', "VARCHAR(150) NOT NULL DEFAULT '' AFTER telefoon"],
        ['projecten', 'extern_ref', "VARCHAR(64) NULL AFTER email"],
        ['projecten', 'opgelost_op', "DATETIME NULL AFTER extern_ref"],
        ['projecten', 'pipedrive_deal_id', "INT UNSIGNED NULL AFTER pipedrive_id"],
    ];
    foreach ($kolommen as [$tabel, $kolom, $def]) {
        $bestaat = q("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$tabel, $kolom])->fetch()['c'];
        if (!$bestaat) {
            $pdo->exec("ALTER TABLE `$tabel` ADD COLUMN `$kolom` $def"); $log[] = "kolom $tabel.$kolom toegevoegd";
            // Ruben en Berend beslissen: wat zij invullen of goedkeuren is direct goed (Ruben, 16-11-2026)
            if ($kolom === 'beslisser') { $pdo->exec("UPDATE gebruikers SET beslisser = 1 WHERE gebruikersnaam IN ('ruben', 'berend')"); $log[] = 'ruben en berend zijn beslisser'; }
        }
    }
    $idx = q("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'producten' AND INDEX_NAME = 'idx_levcode'")->fetch()['c'];
    if (!$idx) { $pdo->exec("ALTER TABLE producten ADD INDEX idx_levcode (leverancier_code)"); }
    foreach ([['projecten', 'idx_soort', 'ADD INDEX idx_soort (soort, fase)'], ['projecten', 'idx_pipedrive', 'ADD INDEX idx_pipedrive (pipedrive_id)'], ['projecten', 'uq_extern_ref', 'ADD UNIQUE INDEX uq_extern_ref (extern_ref)']] as [$t, $naam, $sql]) {
        if (!q("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?", [$t, $naam])->fetch()['c']) {
            $pdo->exec("ALTER TABLE `$t` $sql"); $log[] = "index $t.$naam toegevoegd";
        }
    }
    return $log;
}
