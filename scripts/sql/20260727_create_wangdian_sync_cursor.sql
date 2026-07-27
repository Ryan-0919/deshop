CREATE TABLE IF NOT EXISTS `{PREFIX}wangdian_sync_cursor` (
  `name` varchar(60) NOT NULL DEFAULT '' COMMENT 'Cursor name',
  `cursor_value` bigint UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Numeric cursor value',
  `cursor_time` int UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Timestamp cursor value',
  `add_time` int UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Created Unix timestamp',
  `upd_time` int UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Updated Unix timestamp',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Wangdiantong synchronization cursors';

SET @wangdian_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='{PREFIX}order_aftersale' AND index_name='wangdian_upd_id'),
  'SELECT 1',
  'ALTER TABLE `{PREFIX}order_aftersale` ADD INDEX `wangdian_upd_id` (`upd_time`, `id`)'
);
PREPARE wangdian_stmt FROM @wangdian_sql;
EXECUTE wangdian_stmt;
DEALLOCATE PREPARE wangdian_stmt;

SET @wangdian_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='{PREFIX}order_aftersale' AND index_name='wangdian_add_id'),
  'SELECT 1',
  'ALTER TABLE `{PREFIX}order_aftersale` ADD INDEX `wangdian_add_id` (`add_time`, `id`)'
);
PREPARE wangdian_stmt FROM @wangdian_sql;
EXECUTE wangdian_stmt;
DEALLOCATE PREPARE wangdian_stmt;

SET @wangdian_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='{PREFIX}order_aftersale' AND index_name='wangdian_detail_id'),
  'SELECT 1',
  'ALTER TABLE `{PREFIX}order_aftersale` ADD INDEX `wangdian_detail_id` (`order_detail_id`)'
);
PREPARE wangdian_stmt FROM @wangdian_sql;
EXECUTE wangdian_stmt;
DEALLOCATE PREPARE wangdian_stmt;

SET @wangdian_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='{PREFIX}order' AND index_name='wangdian_upd_id'),
  'SELECT 1',
  'ALTER TABLE `{PREFIX}order` ADD INDEX `wangdian_upd_id` (`upd_time`, `id`)'
);
PREPARE wangdian_stmt FROM @wangdian_sql;
EXECUTE wangdian_stmt;
DEALLOCATE PREPARE wangdian_stmt;

SET @wangdian_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='{PREFIX}order_detail' AND index_name='wangdian_upd_id'),
  'SELECT 1',
  'ALTER TABLE `{PREFIX}order_detail` ADD INDEX `wangdian_upd_id` (`upd_time`, `id`)'
);
PREPARE wangdian_stmt FROM @wangdian_sql;
EXECUTE wangdian_stmt;
DEALLOCATE PREPARE wangdian_stmt;

SET @wangdian_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='{PREFIX}wangdian_sync_log' AND index_name='business_status_upd'),
  'SELECT 1',
  'ALTER TABLE `{PREFIX}wangdian_sync_log` ADD INDEX `business_status_upd` (`business_type`, `status`, `upd_time`, `id`)'
);
PREPARE wangdian_stmt FROM @wangdian_sql;
EXECUTE wangdian_stmt;
DEALLOCATE PREPARE wangdian_stmt;
