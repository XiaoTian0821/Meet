CREATE DATABASE IF NOT EXISTS connectmeet
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE connectmeet;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  display_name  VARCHAR(64)  NOT NULL,
  email         VARCHAR(190) NULL,
  password_hash VARCHAR(255) NULL,
  role          ENUM('member','admin') NOT NULL DEFAULT 'member',
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meetings (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_code       VARCHAR(13)  NOT NULL,             -- human readable, e.g. abc-defgh-ijkl
  host_user_id       INT UNSIGNED NULL,                 -- optional; NULL for guest-host mode
  host_token_hash    CHAR(64)     NULL,                 -- hash of a random host token (guest-host)
  title              VARCHAR(128) NOT NULL DEFAULT '',
  status             ENUM('scheduled','active','locked','ended','expired') NOT NULL DEFAULT 'active',
  scheduled_at       DATETIME NULL,
  expires_at         DATETIME NULL,
  is_locked          TINYINT(1)   NOT NULL DEFAULT 0,
  allow_waiting_room TINYINT(1)   NOT NULL DEFAULT 0,
  waiting_room_open  TINYINT(1)   NOT NULL DEFAULT 0,   -- waiting room enabled & admissible
  participant_limit  SMALLINT UNSIGNED NOT NULL DEFAULT 8,
  created_by         INT UNSIGNED NULL,                  -- user id or 0
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_meetings_code (meeting_code),
  CONSTRAINT fk_meetings_host_user FOREIGN KEY (host_user_id)
    REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_meetings_created_by FOREIGN KEY (created_by)
    REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_meetings_status (status),
  KEY idx_meetings_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_participants (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id             INT UNSIGNED NOT NULL,
  user_id                INT UNSIGNED NULL,
  participant_token_hash CHAR(64)   NOT NULL,            -- hash of the credential presented by the client
  display_name           VARCHAR(64)  NOT NULL,
  role                   ENUM('host','participant') NOT NULL DEFAULT 'participant',
  audio_enabled          TINYINT(1)   NOT NULL DEFAULT 1,
  video_enabled          TINYINT(1)   NOT NULL DEFAULT 1,
  hand_raised          TINYINT(1)   NOT NULL DEFAULT 0,
  screen_sharing         TINYINT(1)   NOT NULL DEFAULT 0,
  joined_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at           DATETIME NULL,
  left_at                DATETIME NULL,
  UNIQUE KEY uq_participant_token (participant_token_hash),
  KEY idx_participants_meeting (meeting_id, left_at),
  KEY idx_participants_meeting_active (meeting_id, left_at, last_seen_at),
  CONSTRAINT fk_participants_meeting FOREIGN KEY (meeting_id)
    REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_participants_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_messages (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id       INT UNSIGNED NOT NULL,
  participant_id   INT UNSIGNED NULL,                     -- NULL for system messages
  message_type     ENUM('chat','reaction','system') NOT NULL DEFAULT 'chat',
  message_content  TEXT NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_messages_poll (meeting_id, id),
  CONSTRAINT fk_messages_meeting FOREIGN KEY (meeting_id)
    REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_participant FOREIGN KEY (participant_id)
    REFERENCES meeting_participants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_events (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id       INT UNSIGNED NOT NULL,
  from_participant INT UNSIGNED NULL,                       -- NULL = system
  to_participant   INT UNSIGNED NULL,                       -- NULL = broadcast
  event_type       VARCHAR(32) NOT NULL,                    -- offer, answer, ice, control, state, ...
  event_payload    MEDIUMTEXT NOT NULL,                     -- JSON
  delivered_at     DATETIME NULL,                            -- set when a receiver claims it
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_events_unread (meeting_id, to_participant, delivered_at, id),
  KEY idx_events_unread_self (meeting_id, from_participant, id),
  KEY idx_events_age (created_at),
  CONSTRAINT fk_events_meeting FOREIGN KEY (meeting_id)
    REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_from FOREIGN KEY (from_participant)
    REFERENCES meeting_participants(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_to FOREIGN KEY (to_participant)
    REFERENCES meeting_participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_invitations (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id   INT UNSIGNED NOT NULL,
  token_hash   CHAR(64) NOT NULL,
  label        VARCHAR(128) NOT NULL DEFAULT '',
  expires_at   DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invite_token (token_hash),
  KEY idx_invite_meeting (meeting_id),
  CONSTRAINT fk_invite_meeting FOREIGN KEY (meeting_id)
    REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket       VARCHAR(16) NOT NULL,                        -- ip hash or token
  operation    VARCHAR(32) NOT NULL,
  window_start DATETIME NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 1,
  KEY idx_rate_bucket (bucket, operation, window_start),
  CONSTRAINT chk_hits CHECK (hits >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  `key`        VARCHAR(64) NOT NULL PRIMARY KEY,
  `value`      TEXT NOT NULL,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (`key`,`value`) VALUES
  ('polling_interval_ms',    '2000'),
  ('stale_participant_sec',  '30'),
  ('rate_limit_chat',        '20'),      -- max chat messages per window
  ('rate_limit_window_sec',  '30'),
  ('max_display_name_len',   '40'),
  ('max_chat_len',           '1000'),
  ('meeting_expiry_hours',   '168');     -- guest/standalone meetings expire after 7 days
