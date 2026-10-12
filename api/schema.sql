-- Run once in Hostinger hPanel > Databases > phpMyAdmin > SQL tab.
CREATE TABLE IF NOT EXISTS leads (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name         VARCHAR(80)  NOT NULL,
  phone        VARCHAR(15)  NOT NULL,
  service      VARCHAR(80)  NOT NULL DEFAULT '',
  -- which page the form was submitted from
  page_url     VARCHAR(500) NOT NULL DEFAULT '',
  page_path    VARCHAR(255) NOT NULL DEFAULT '',
  page_title   VARCHAR(255) NOT NULL DEFAULT '',
  -- where the visitor came from: instagram, facebook, google, direct, or another site's domain
  source       VARCHAR(100) NOT NULL DEFAULT 'direct',
  medium       VARCHAR(100) NOT NULL DEFAULT '',
  campaign     VARCHAR(150) NOT NULL DEFAULT '',
  referrer     VARCHAR(500) NOT NULL DEFAULT '',
  landing_page VARCHAR(500) NOT NULL DEFAULT '',
  click_id     VARCHAR(255) NOT NULL DEFAULT '',
  ip_address   VARCHAR(45)  NOT NULL DEFAULT '',
  user_agent   VARCHAR(500) NOT NULL DEFAULT '',
  -- result of the WhatsApp alert to the owner: sent / failed (empty if not attempted)
  wa_status    VARCHAR(10)  NOT NULL DEFAULT '',
  wa_error     VARCHAR(500) NOT NULL DEFAULT '',
  KEY idx_created (created_at),
  KEY idx_source (source),
  KEY idx_page (page_path),
  KEY idx_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If the leads table already exists, add the two WhatsApp columns instead of re-creating it:
-- ALTER TABLE leads
--   ADD COLUMN wa_status VARCHAR(10)  NOT NULL DEFAULT '' AFTER user_agent,
--   ADD COLUMN wa_error  VARCHAR(500) NOT NULL DEFAULT '' AFTER wa_status;
