CREATE TABLE IF NOT EXISTS `{PREFIX}debao_binding` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `debao_user_id` varchar(128) NOT NULL,
  `deshop_user_id` bigint unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `active_debao_user_id` varchar(128) GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN `debao_user_id` ELSE NULL END) STORED,
  `active_deshop_user_id` bigint unsigned GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN `deshop_user_id` ELSE NULL END) STORED,
  `revoked_at` int unsigned NOT NULL DEFAULT 0,
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `upd_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `active_debao_user_id` (`active_debao_user_id`),
  UNIQUE KEY `active_deshop_user_id` (`active_deshop_user_id`),
  KEY `debao_user_history` (`debao_user_id`,`status`),
  KEY `deshop_user_history` (`deshop_user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET={CHARSET} ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `{PREFIX}debao_binding_challenge` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `challenge_id` varchar(80) NOT NULL,
  `debao_user_id` varchar(128) NOT NULL,
  `deshop_user_id` bigint unsigned NOT NULL DEFAULT 0,
  `method` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `token_hash` char(64) NOT NULL,
  `confirmation_hash` char(64) NOT NULL DEFAULT '',
  `confirm_attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `expires_at` int unsigned NOT NULL,
  `approved_at` int unsigned NOT NULL DEFAULT 0,
  `confirmed_at` int unsigned NOT NULL DEFAULT 0,
  `consumed_at` int unsigned NOT NULL DEFAULT 0,
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `upd_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `challenge_id` (`challenge_id`),
  KEY `user_status` (`debao_user_id`,`status`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET={CHARSET} ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `{PREFIX}debao_nonce` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nonce_hash` char(64) NOT NULL,
  `client_id` varchar(128) NOT NULL,
  `key_id` varchar(128) NOT NULL,
  `expires_at` int unsigned NOT NULL,
  `add_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nonce_hash` (`nonce_hash`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET={CHARSET} ROW_FORMAT=DYNAMIC;
