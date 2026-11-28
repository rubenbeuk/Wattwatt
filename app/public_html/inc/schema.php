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
,
// --- Agenda (26-11-2026): afspraken met een vast tijdstip, vervangt Pipedrive-activiteiten; per persoon een agenda-abonnement (ICS) voor Outlook en iPhone ---
"CREATE TABLE IF NOT EXISTS afspraken (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gebruiker_id INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  titel VARCHAR(255) NOT NULL,
  start DATETIME NOT NULL,
  eind DATETIME NULL,
  hele_dag TINYINT(1) NOT NULL DEFAULT 0,
  locatie VARCHAR(255) NOT NULL DEFAULT '',
  notitie TEXT NULL,
  bron VARCHAR(255) NOT NULL DEFAULT '',
  extern_id VARCHAR(150) NULL UNIQUE,
  aangemaakt_door VARCHAR(100) NOT NULL DEFAULT '',
  geannuleerd TINYINT(1) NOT NULL DEFAULT 0,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (gebruiker_id, start), INDEX (project_id), INDEX (start),
  CONSTRAINT fk_af_project FOREIGN KEY (project_id) REFERENCES projecten(id),
  CONSTRAINT fk_af_gebruiker FOREIGN KEY (gebruiker_id) REFERENCES gebruikers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
// --- Klanten (26-11-2026): één kaart per klant met alle projecten, tickets en installaties ---
"CREATE TABLE IF NOT EXISTS klanten (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  naam VARCHAR(150) NOT NULL,
  bedrijf VARCHAR(150) NOT NULL DEFAULT '',
  telefoon VARCHAR(50) NOT NULL DEFAULT '',
  telefoon_kaal VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(150) NOT NULL DEFAULT '',
  adres VARCHAR(255) NOT NULL DEFAULT '',
  installatie TEXT NULL,
  notitie TEXT NULL,
  pipedrive_person_id INT UNSIGNED NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (naam), INDEX (telefoon_kaal), INDEX (email), INDEX (pipedrive_person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
// --- Opleverdossier (26-11-2026): wat de monteur op locatie vastlegt bij oplevering (was de Pipedrive-taak "Oplever documentatie") ---
"CREATE TABLE IF NOT EXISTS opleveringen (
  project_id INT UNSIGNED NOT NULL PRIMARY KEY,
  serienummers TEXT NULL,
  netwerk TEXT NULL,
  ems VARCHAR(255) NOT NULL DEFAULT '',
  kabel TEXT NULL,
  materiaal TEXT NULL,
  opmerkingen TEXT NULL,
  check_inbedrijf TINYINT(1) NOT NULL DEFAULT 0,
  check_monitoring TINYINT(1) NOT NULL DEFAULT 0,
  check_uitleg TINYINT(1) NOT NULL DEFAULT 0,
  check_keuring TINYINT(1) NOT NULL DEFAULT 0,
  afgerond_op DATETIME NULL,
  bijgewerkt_door VARCHAR(100) NOT NULL DEFAULT '',
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ol_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
// --- Overleg (26-11-2026): bespreekpunten voor het overleg Ruben en Berend (was een Word-document "Update" in OneDrive) ---
"CREATE TABLE IF NOT EXISTS bespreekpunten (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titel VARCHAR(255) NOT NULL,
  toelichting TEXT NULL,
  project_id INT UNSIGNED NULL,
  prioriteit ENUM('hoog','normaal','laag') NOT NULL DEFAULT 'normaal',
  status ENUM('open','besproken','vervallen') NOT NULL DEFAULT 'open',
  besluit TEXT NULL,
  bron VARCHAR(255) NOT NULL DEFAULT '',
  extern_id VARCHAR(150) NULL UNIQUE,
  ingebracht_door VARCHAR(100) NOT NULL DEFAULT '',
  besproken_op DATETIME NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status), INDEX (project_id),
  CONSTRAINT fk_bp_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
// --- Uren (26-11-2026): wie werkte hoe lang aan welk project of ticket (nacalculatie en facturen) ---
"CREATE TABLE IF NOT EXISTS uren (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gebruiker_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  datum DATE NOT NULL,
  uren DECIMAL(4,2) NOT NULL,
  soort ENUM('werk','reis','overig') NOT NULL DEFAULT 'werk',
  omschrijving VARCHAR(255) NOT NULL DEFAULT '',
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (gebruiker_id, datum), INDEX (project_id), INDEX (datum),
  CONSTRAINT fk_u_gebruiker FOREIGN KEY (gebruiker_id) REFERENCES gebruikers(id),
  CONSTRAINT fk_u_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
// --- WhatsApp (26-11-2026): Rubens Mac stuurt alleen zakelijke chats; het brein in de cloud leest ze hier. Privechats komen nooit op de server. ---
"CREATE TABLE IF NOT EXISTS wa_chats (
  jid VARCHAR(100) NOT NULL PRIMARY KEY,
  naam VARCHAR(150) NOT NULL DEFAULT '',
  soort ENUM('nieuw','zakelijk','prive') NOT NULL DEFAULT 'nieuw',
  proef TEXT NULL,
  ingedeeld_door VARCHAR(100) NOT NULL DEFAULT '',
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (soort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
"CREATE TABLE IF NOT EXISTS wa_berichten (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bron_id BIGINT UNSIGNED NOT NULL UNIQUE,
  jid VARCHAR(100) NOT NULL,
  tijd DATETIME NOT NULL,
  van VARCHAR(150) NOT NULL DEFAULT '',
  ik TINYINT(1) NOT NULL DEFAULT 0,
  tekst TEXT NOT NULL,
  ontvangen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (tijd), INDEX (jid, tijd), INDEX (ontvangen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
// --- Stand van het brein (26-11-2026): slot tegen twee runs tegelijk (Mac en cloud) en kleine standen zoals watermerken ---
"CREATE TABLE IF NOT EXISTS claude_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run VARCHAR(50) NOT NULL DEFAULT '',
  opdracht VARCHAR(50) NOT NULL,
  tabel VARCHAR(40) NOT NULL,
  rij_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  wat VARCHAR(500) NOT NULL DEFAULT '',
  voor LONGTEXT NULL,
  na LONGTEXT NULL,
  ongedaan_op DATETIME NULL,
  ongedaan_door INT UNSIGNED NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (aangemaakt), INDEX (tabel, rij_id), INDEX (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
// --- Opschonen van de takenlijst (28-11-2026): voorstellen van Claude per taak, kantoor keurt goed of af ---
"CREATE TABLE IF NOT EXISTS taak_voorstellen (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  taak_id INT UNSIGNED NOT NULL,
  actie ENUM('klaar','vervalt','samenvoegen','eigenaar','deadline','project') NOT NULL,
  eigenaar_id INT UNSIGNED NULL,
  in_taak_id INT UNSIGNED NULL,
  deadline DATE NULL,
  project_id INT UNSIGNED NULL,
  reden VARCHAR(500) NOT NULL DEFAULT '',
  status ENUM('open','akkoord','afgewezen') NOT NULL DEFAULT 'open',
  besloten_door INT UNSIGNED NULL,
  besloten_op DATETIME NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status), INDEX (taak_id),
  CONSTRAINT fk_tv_taak FOREIGN KEY (taak_id) REFERENCES taken(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
// --- Ronde 2 (28-11-2026): locaties en installatieregister ---
"CREATE TABLE IF NOT EXISTS locaties (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  klant_id INT UNSIGNED NULL,
  naam VARCHAR(150) NOT NULL DEFAULT '',
  adres VARCHAR(255) NOT NULL DEFAULT '',
  contact VARCHAR(255) NOT NULL DEFAULT '',
  telefoon VARCHAR(50) NOT NULL DEFAULT '',
  notitie TEXT NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (klant_id), INDEX (adres),
  CONSTRAINT fk_loc_klant FOREIGN KEY (klant_id) REFERENCES klanten(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
"CREATE TABLE IF NOT EXISTS installaties (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  locatie_id INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  soort VARCHAR(20) NOT NULL DEFAULT 'overig',
  merk VARCHAR(100) NOT NULL DEFAULT '',
  type VARCHAR(150) NOT NULL DEFAULT '',
  serienummer VARCHAR(100) NOT NULL DEFAULT '',
  firmware VARCHAR(100) NOT NULL DEFAULT '',
  vermogen VARCHAR(50) NOT NULL DEFAULT '',
  geplaatst_op DATE NULL,
  garantie_tot DATE NULL,
  monitoring VARCHAR(50) NOT NULL DEFAULT '',
  monitoring_id VARCHAR(100) NOT NULL DEFAULT '',
  status ENUM('actief','vervangen','verwijderd') NOT NULL DEFAULT 'actief',
  notitie TEXT NULL,
  bijgewerkt_door VARCHAR(100) NOT NULL DEFAULT '',
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (locatie_id), INDEX (project_id), INDEX (serienummer), INDEX (monitoring_id),
  CONSTRAINT fk_ins_locatie FOREIGN KEY (locatie_id) REFERENCES locaties(id) ON DELETE SET NULL,
  CONSTRAINT fk_ins_project FOREIGN KEY (project_id) REFERENCES projecten(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
// --- Digitale werkbon per bezoek, met handtekening van de klant ---
"CREATE TABLE IF NOT EXISTS werkbonnen (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  datum DATE NOT NULL,
  gebruiker_id INT UNSIGNED NULL,
  monteurs VARCHAR(255) NOT NULL DEFAULT '',
  werk TEXT NULL,
  materiaal TEXT NULL,
  uren DECIMAL(5,2) NULL,
  opmerkingen TEXT NULL,
  klant_naam VARCHAR(150) NOT NULL DEFAULT '',
  handtekening MEDIUMTEXT NULL,
  getekend_op DATETIME NULL,
  status ENUM('concept','getekend') NOT NULL DEFAULT 'concept',
  concept_door VARCHAR(100) NOT NULL DEFAULT '',
  bijgewerkt_door VARCHAR(100) NOT NULL DEFAULT '',
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (project_id, datum), INDEX (status),
  CONSTRAINT fk_wb_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
// --- Begroting (uit de offerte), betaaltermijnen en factuurvoorstellen ---
"CREATE TABLE IF NOT EXISTS begroting (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  soort ENUM('materiaal','arbeid','overig') NOT NULL DEFAULT 'materiaal',
  omschrijving VARCHAR(255) NOT NULL,
  aantal DECIMAL(12,2) NOT NULL DEFAULT 1,
  eenheid VARCHAR(20) NOT NULL DEFAULT 'stuks',
  prijs DECIMAL(12,2) NOT NULL DEFAULT 0,
  bron VARCHAR(255) NOT NULL DEFAULT '',
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (project_id),
  CONSTRAINT fk_beg_project FOREIGN KEY (project_id) REFERENCES projecten(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
"CREATE TABLE IF NOT EXISTS termijnen (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  volgorde TINYINT UNSIGNED NOT NULL DEFAULT 1,
  omschrijving VARCHAR(150) NOT NULL,
  percentage DECIMAL(5,2) NULL,
  bedrag DECIMAL(12,2) NULL,
  moment VARCHAR(20) NOT NULL DEFAULT 'handmatig',
  factuur_id INT UNSIGNED NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (project_id),
  CONSTRAINT fk_ter_project FOREIGN KEY (project_id) REFERENCES projecten(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
"CREATE TABLE IF NOT EXISTS facturen (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  soort ENUM('termijn','regie') NOT NULL DEFAULT 'regie',
  termijn_id INT UNSIGNED NULL,
  omschrijving VARCHAR(255) NOT NULL DEFAULT '',
  btw DECIMAL(4,1) NOT NULL DEFAULT 21.0,
  status ENUM('concept','akkoord','geboekt','betaald','vervallen') NOT NULL DEFAULT 'concept',
  boekhouding_nummer VARCHAR(50) NOT NULL DEFAULT '',
  concept_door VARCHAR(100) NOT NULL DEFAULT '',
  akkoord_door VARCHAR(100) NOT NULL DEFAULT '',
  akkoord_op DATETIME NULL,
  geboekt_op DATETIME NULL,
  betaald_op DATE NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (project_id), INDEX (status),
  CONSTRAINT fk_fac_project FOREIGN KEY (project_id) REFERENCES projecten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
"CREATE TABLE IF NOT EXISTS factuurregels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  factuur_id INT UNSIGNED NOT NULL,
  omschrijving VARCHAR(255) NOT NULL,
  aantal DECIMAL(12,2) NOT NULL DEFAULT 1,
  eenheid VARCHAR(20) NOT NULL DEFAULT 'stuks',
  prijs DECIMAL(12,2) NOT NULL DEFAULT 0,
  bron VARCHAR(100) NOT NULL DEFAULT '',
  INDEX (factuur_id),
  CONSTRAINT fk_fr_factuur FOREIGN KEY (factuur_id) REFERENCES facturen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
// --- Ronde 3 (28-11-2026): onderhoudscontracten met reactietijd, klantberichten en pushmeldingen ---
"CREATE TABLE IF NOT EXISTS contracten (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  klant_id INT UNSIGNED NULL,
  locatie_id INT UNSIGNED NULL,
  installatie_id INT UNSIGNED NULL,
  omschrijving VARCHAR(255) NOT NULL DEFAULT '',
  soort VARCHAR(20) NOT NULL DEFAULT 'onderhoud',
  startdatum DATE NULL,
  einddatum DATE NULL,
  interval_maanden TINYINT UNSIGNED NOT NULL DEFAULT 12,
  volgende_onderhoud DATE NULL,
  reactietijd_uren SMALLINT UNSIGNED NULL,
  prijs_jaar DECIMAL(10,2) NULL,
  status ENUM('actief','opgezegd','verlopen') NOT NULL DEFAULT 'actief',
  notitie TEXT NULL,
  bijgewerkt_door VARCHAR(100) NOT NULL DEFAULT '',
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (klant_id), INDEX (locatie_id), INDEX (status, volgende_onderhoud),
  CONSTRAINT fk_con_klant FOREIGN KEY (klant_id) REFERENCES klanten(id) ON DELETE SET NULL,
  CONSTRAINT fk_con_locatie FOREIGN KEY (locatie_id) REFERENCES locaties(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
"CREATE TABLE IF NOT EXISTS klantberichten (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  soort VARCHAR(20) NOT NULL DEFAULT 'vrij',
  sleutel VARCHAR(60) NULL,
  aan VARCHAR(150) NOT NULL DEFAULT '',
  onderwerp VARCHAR(255) NOT NULL DEFAULT '',
  tekst TEXT NULL,
  status ENUM('concept','verstuurd','vervallen') NOT NULL DEFAULT 'concept',
  concept_door VARCHAR(100) NOT NULL DEFAULT '',
  verstuurd_door VARCHAR(100) NOT NULL DEFAULT '',
  verstuurd_op DATETIME NULL,
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (project_id), INDEX (status), UNIQUE KEY uq_kb_sleutel (sleutel),
  CONSTRAINT fk_kb_project FOREIGN KEY (project_id) REFERENCES projecten(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
"CREATE TABLE IF NOT EXISTS push_abonnementen (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gebruiker_id INT UNSIGNED NOT NULL,
  endpoint TEXT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  apparaat VARCHAR(150) NOT NULL DEFAULT '',
  aangemaakt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  laatst_ok DATETIME NULL,
  fouten TINYINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_push (endpoint_hash), INDEX (gebruiker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
,
"CREATE TABLE IF NOT EXISTS brein_stand (
  sleutel VARCHAR(100) NOT NULL PRIMARY KEY,
  waarde TEXT NOT NULL,
  bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
        ['gebruikers', 'agenda_token', "VARCHAR(48) NULL AFTER beslisser"],
        ['projecten', 'klant_id', "INT UNSIGNED NULL AFTER klant"],
        // Tweede inlogstap voor kantoor (28-11-2026): geheim voor de code-app op de telefoon
        ['gebruikers', 'totp_geheim', "VARCHAR(64) NULL AFTER agenda_token"],
        ['gebruikers', 'totp_sinds', "DATETIME NULL AFTER totp_geheim"],
        // "Vraag Claude" op project en ticket (28-11-2026)
        ['notities', 'soort', "VARCHAR(10) NOT NULL DEFAULT 'notitie' AFTER project_id"],
        // Opleverdossier als concept van Claude na "Werk klaar" (28-11-2026)
        ['opleveringen', 'concept_door', "VARCHAR(100) NOT NULL DEFAULT '' AFTER opmerkingen"],
        // Ronde 2 (28-11-2026): project hoort bij een locatie, een ticket bij een installatie; uren met weekakkoord
        ['projecten', 'locatie_id', "INT UNSIGNED NULL AFTER klant_id"],
        ['projecten', 'installatie_id', "INT UNSIGNED NULL AFTER locatie_id"],
        ['uren', 'bron', "VARCHAR(10) NOT NULL DEFAULT 'hand' AFTER omschrijving"],
        ['uren', 'akkoord_door', "VARCHAR(100) NOT NULL DEFAULT '' AFTER bron"],
        ['uren', 'akkoord_op', "DATETIME NULL AFTER akkoord_door"],
        // Termijnen van lopende projecten: eerst controleren of ze al buiten de app zijn gefactureerd (28-11-2026)
        ['termijnen', 'controleren', "TINYINT(1) NOT NULL DEFAULT 0 AFTER moment"],
        // Ronde 3: ticket hoort bij een contract en heeft een reactietijd
        ['projecten', 'contract_id', "INT UNSIGNED NULL AFTER installatie_id"],
        ['projecten', 'reactie_voor', "DATETIME NULL AFTER contract_id"],
        ['projecten', 'gereageerd_op', "DATETIME NULL AFTER reactie_voor"],
        // Aanmelding bij de netbeheerder hoort bij de oplevering (energieleveren.nl)
        ['opleveringen', 'check_netbeheer', "TINYINT(1) NOT NULL DEFAULT 0 AFTER check_keuring"],
    ];
    foreach ($kolommen as [$tabel, $kolom, $def]) {
        $bestaat = q("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$tabel, $kolom])->fetch()['c'];
        if (!$bestaat) {
            $pdo->exec("ALTER TABLE `$tabel` ADD COLUMN `$kolom` $def"); $log[] = "kolom $tabel.$kolom toegevoegd";
            // Ruben en Berend beslissen: wat zij invullen of goedkeuren is direct goed (Ruben, 16-11-2026)
            if ($kolom === 'beslisser') { $pdo->exec("UPDATE gebruikers SET beslisser = 1 WHERE gebruikersnaam IN ('ruben', 'berend')"); $log[] = 'ruben en berend zijn beslisser'; }
        }
    }
    // Stand van het brein mag groter worden (28-11-2026): de grote run bewaart hier ook zijn items en lijsten in plaats van in het dashboard
    if (q("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'brein_stand' AND COLUMN_NAME = 'waarde'")->fetchColumn() === 'text') {
        $pdo->exec('ALTER TABLE brein_stand MODIFY waarde MEDIUMTEXT NOT NULL'); $log[] = 'brein_stand.waarde vergroot';
    }
    // Rollen verkoop en onderaannemer (28-11-2026)
    $rolType = q("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gebruikers' AND COLUMN_NAME = 'rol'")->fetchColumn();
    if ($rolType && !str_contains((string)$rolType, 'onderaannemer')) {
        $pdo->exec("ALTER TABLE gebruikers MODIFY rol ENUM('admin','monteur','verkoop','onderaannemer') NOT NULL DEFAULT 'monteur'"); $log[] = 'rollen verkoop en onderaannemer toegevoegd';
    }
    $idx = q("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'producten' AND INDEX_NAME = 'idx_levcode'")->fetch()['c'];
    if (!$idx) { $pdo->exec("ALTER TABLE producten ADD INDEX idx_levcode (leverancier_code)"); }
    if (!q("SELECT 1 FROM klanten LIMIT 1")->fetch()) { $n = klanten_vullen(); if ($n) $log[] = "$n klanten aangemaakt uit projecten"; }
    // Eén locatie per klant en adres uit de bestaande projecten (eenmalig, daarna alleen voor nieuwe projecten zonder locatie)
    $n = locaties_vullen(); if ($n) $log[] = "$n projecten aan een locatie gekoppeld";
    foreach ([['projecten', 'idx_soort', 'ADD INDEX idx_soort (soort, fase)'], ['projecten', 'idx_pipedrive', 'ADD INDEX idx_pipedrive (pipedrive_id)'], ['projecten', 'uq_extern_ref', 'ADD UNIQUE INDEX uq_extern_ref (extern_ref)']] as [$t, $naam, $sql]) {
        if (!q("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?", [$t, $naam])->fetch()['c']) {
            $pdo->exec("ALTER TABLE `$t` $sql"); $log[] = "index $t.$naam toegevoegd";
        }
    }
    return $log;
}
