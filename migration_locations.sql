-- EPSS Work Location / GIS migration
-- Safe to run on an existing CAS database. Existing user data is preserved.
-- Seed hub coordinates are intentionally marked Estimated until EPSS verifies exact facilities.

CREATE TABLE IF NOT EXISTS epss_location (
  id INT AUTO_INCREMENT PRIMARY KEY,
  location_code VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL UNIQUE,
  location_type VARCHAR(30) NOT NULL DEFAULT 'hub',
  administrative_region VARCHAR(200) NOT NULL,
  physical_address VARCHAR(500) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  verification_status VARCHAR(40) NOT NULL DEFAULT 'unverified',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  effective_from DATE NULL,
  effective_to DATE NULL,
  notes TEXT NULL,
  created_by INT NULL,
  updated_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_epss_location_active (is_active),
  KEY idx_epss_location_region (administrative_region)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epss_location_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  location_id INT NULL,
  action VARCHAR(40) NOT NULL,
  before_json LONGTEXT NULL,
  after_json LONGTEXT NULL,
  changed_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_location_audit_location (location_id),
  KEY idx_location_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @location_id_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'location_id'
);
SET @location_id_add_sql = IF(
  @location_id_exists = 0,
  'ALTER TABLE users ADD COLUMN location_id INT NULL AFTER work_function',
  'DO 1'
);
PREPARE stmt FROM @location_id_add_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @location_id_index_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'idx_users_location_id'
);
SET @location_id_index_sql = IF(
  @location_id_index_exists = 0,
  'CREATE INDEX idx_users_location_id ON users(location_id)',
  'DO 1'
);
PREPARE stmt FROM @location_id_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO epss_location
(location_code,name,location_type,administrative_region,physical_address,latitude,longitude,verification_status,is_active,notes)
VALUES
('HQ','EPSS HQ','hq','Addis Ababa City Administration','Addis Ababa, Ethiopia',NULL,NULL,'unverified',1,'Separate EPSS headquarters location. Exact facility coordinates must be verified before mapping.'),
('HUB-AA1','Addis Ababa Hub 1','hub','Addis Ababa City Administration','Swaziland Street (Central Depot)',9.0435000,38.7423000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-AA2','Addis Ababa Hub 2','hub','Addis Ababa City Administration','Gulelle Area / St. Paul''s Campus',9.0440000,38.7415000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-ADAMA','Adama Hub','hub','Oromia Region','Industrial & Logistics Zone, Adama',8.5414000,39.2689000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-ARBA-MINCH','Arba Minch Hub','hub','South Ethiopia Region','Hospital Road Area, Arba Minch',6.0206000,37.5511000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-ASSOSA','Assosa Hub','hub','Benishangul-Gumuz Region','General Logistics Sector, Assosa',10.0667000,34.5333000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-BAHIR-DAR','Bahir Dar Hub','hub','Amhara Region','Kebele 11 Logistics Strip, Bahir Dar',11.5742000,37.3614000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-BALE-GOBA','Bale Goba Hub','hub','Oromia Region','Hospital Corridor Zone, Goba',7.0101000,39.9793000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-DESSIE','Dessie Hub','hub','Amhara Region','Combolcha Road Corridor, Dessie',11.1149000,39.6324000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-DIRE-DAWA','Dire Dawa Hub','hub','Dire Dawa City Administration','Melka Jebdu Logistics Axis, Dire Dawa',9.5931000,41.8661000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-GAMBELLA','Gambella Hub','hub','Gambella Region','Regional Hospital Zone, Gambella',8.2472000,34.5919000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-GONDAR','Gondar Hub','hub','Amhara Region','Maraki Campus Area, Gondar',12.6074000,37.4582000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-HAWASSA','Hawassa Hub','hub','Sidama Region / Central Ethiopia','Industrial Park Road, Hawassa',7.0470000,38.4752000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-JIJIGA','Jijiga Hub','hub','Somali Region','Eastern Logistics Ring, Jijiga',9.3512000,42.7951000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-JIMMA','Jimma Hub','hub','Oromia Region','JUMC Road District, Jimma',7.6734000,36.8344000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-MEKELLE','Mekelle Hub','hub','Tigray Region','Ayder Health Sector, Mekelle',13.4967000,39.4683000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-NEKEMTE','Nekemte Hub','hub','Oromia Region','Wollega Zone Logistics Strip, Nekemte',9.0833000,36.5500000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-SEMERA','Semera Hub','hub','Afar Region','Logia-Semera Transit Road, Semera',11.7922000,41.0051000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-SHIRE','Shire Hub','hub','Tigray Region','Northern Transport Sector, Indaselassie',14.1022000,38.2831000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'),
('HUB-WOLAITA-SODO','Wolaita Sodo Hub','hub','South Ethiopia Region','Sodo Core Logistics District',6.8575000,37.7608000,'estimated',1,'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.');
