CREATE TABLE IF NOT EXISTS `{PREFIX}debao_session` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(80) NOT NULL,
  `debao_user_id` varchar(128) NOT NULL,
  `type` varchar(20) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `payload` longtext NOT NULL,
  `expires_at` int unsigned NOT NULL,
  `consumed_at` int unsigned NOT NULL DEFAULT 0,
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `upd_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `session_id` (`session_id`), KEY `owner_status` (`debao_user_id`,`status`), KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET={CHARSET} ROW_FORMAT=DYNAMIC;
