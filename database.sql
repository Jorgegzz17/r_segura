-- ============================================================
--  Rutas Seguras CBTis 258 - Esquema de Base de Datos
--  Motor: MySQL / MariaDB (compatible con XAMPP)
-- ============================================================

CREATE DATABASE IF NOT EXISTS rutas_seguras
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE rutas_seguras;

-- ------------------------------------------------------------
-- Cuentas de usuarios (separadas de admin_users)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(80) NOT NULL UNIQUE,
    emergency_phone VARCHAR(16) NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS sos_sessions (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    latitude    DECIMAL(10,7) NULL,
    longitude   DECIMAL(10,7) NULL,
    expires_at  DATETIME NOT NULL,
    stopped_at  DATETIME NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    INDEX idx_sos_user_active (user_id, stopped_at, expires_at),
    CONSTRAINT fk_sos_sessions_user FOREIGN KEY (user_id)
      REFERENCES users(id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- ------------------------------------------------------------
-- Tabla: reportes de la comunidad estudiantil
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reports (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    type        VARCHAR(80)  NOT NULL,
    location    VARCHAR(255) NOT NULL,
    description TEXT         NOT NULL,
    latitude    DECIMAL(10,7) NOT NULL,
    longitude   DECIMAL(10,7) NOT NULL,
    severity    ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
    status      ENUM('activo','resuelto')   NOT NULL DEFAULT 'activo',
    likes       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status   (status),
    INDEX idx_type     (type),
    INDEX idx_created  (created_at),
    INDEX idx_reports_user_created (user_id, created_at),
    CONSTRAINT fk_reports_user FOREIGN KEY (user_id)
      REFERENCES users(id) ON DELETE SET NULL
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS report_likes (
    report_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (report_id, user_id),
    INDEX idx_report_likes_user (user_id),
    CONSTRAINT fk_report_likes_report FOREIGN KEY (report_id)
      REFERENCES reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_report_likes_user FOREIGN KEY (user_id)
      REFERENCES users(id) ON DELETE CASCADE
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS report_media (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id   INT UNSIGNED NOT NULL,
    file_name   VARCHAR(255) NOT NULL,
    file_path   VARCHAR(255) NOT NULL,
    mime_type   VARCHAR(100) NOT NULL,
    file_size   INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_media_report (report_id),
    CONSTRAINT fk_report_media_report FOREIGN KEY (report_id)
      REFERENCES reports(id) ON DELETE CASCADE
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS report_updates (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id   INT UNSIGNED NOT NULL,
    author_type ENUM('admin','user') NOT NULL,
    author_id   INT UNSIGNED NULL,
    message     VARCHAR(1000) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_updates_report_created (report_id, created_at),
    CONSTRAINT fk_report_updates_report FOREIGN KEY (report_id)
      REFERENCES reports(id) ON DELETE CASCADE
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS report_notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    report_id   INT UNSIGNED NOT NULL,
    message     VARCHAR(255) NOT NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_notifications_user_read (user_id, is_read, created_at),
    CONSTRAINT fk_report_notifications_user FOREIGN KEY (user_id)
      REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_report_notifications_report FOREIGN KEY (report_id)
      REFERENCES reports(id) ON DELETE CASCADE
) ENGINE = InnoDB;

-- ------------------------------------------------------------
-- Cuentas administrativas (no se crean usuarios por registro web)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(80) NOT NULL UNIQUE COMMENT 'Correo electrónico del administrador',
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB;

INSERT IGNORE INTO admin_users (username, password_hash)
VALUES (
    'rutas.seguras@cbtis258.edu.mx',
    '$2y$10$RWdYbh6TO7KDdUhU9fTuaum3Q.SxjYqoxJf1lEM1fm0rEBXf5.Oli'
);

CREATE TABLE IF NOT EXISTS campus_location (
  id          TINYINT UNSIGNED PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  address     VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  latitude    DECIMAL(10,7) NOT NULL,
  longitude   DECIMAL(10,7) NOT NULL,
  image_path  VARCHAR(255) NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS safe_points (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  address     VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  latitude    DECIMAL(10,7) NOT NULL,
  longitude   DECIMAL(10,7) NOT NULL,
  image_path  VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_safe_point_name (name)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS safe_routes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  description TEXT NOT NULL,
  color       CHAR(7) NOT NULL DEFAULT '#059669',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS safe_route_points (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_id    INT UNSIGNED NOT NULL,
  sequence_no SMALLINT UNSIGNED NOT NULL,
  latitude    DECIMAL(10,7) NOT NULL,
  longitude   DECIMAL(10,7) NOT NULL,
  UNIQUE KEY uq_route_sequence (route_id, sequence_no),
  CONSTRAINT fk_safe_route_points_route FOREIGN KEY (route_id)
    REFERENCES safe_routes(id) ON DELETE CASCADE
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS safe_route_proposals (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  description TEXT NOT NULL,
  status      ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_route_proposals_status_created (status, created_at),
  INDEX idx_route_proposals_user_created (user_id, created_at),
  CONSTRAINT fk_route_proposals_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_route_proposals_admin FOREIGN KEY (reviewed_by)
    REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS safe_route_proposal_points (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proposal_id INT UNSIGNED NOT NULL,
  sequence_no SMALLINT UNSIGNED NOT NULL,
  latitude    DECIMAL(10,7) NOT NULL,
  longitude   DECIMAL(10,7) NOT NULL,
  UNIQUE KEY uq_proposal_sequence (proposal_id, sequence_no),
  CONSTRAINT fk_route_proposal_points_proposal FOREIGN KEY (proposal_id)
    REFERENCES safe_route_proposals(id) ON DELETE CASCADE
) ENGINE = InnoDB;



