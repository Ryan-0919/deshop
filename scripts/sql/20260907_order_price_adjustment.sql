-- 待付款订单改价审计记录。
-- 执行前请确认当前数据库表前缀为 sxo；重复执行安全。
CREATE TABLE IF NOT EXISTS `sxo_order_price_adjustment` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '改价记录id',
  `order_id` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '订单id',
  `order_no` char(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '订单号',
  `old_total_price` decimal(10, 2) NOT NULL DEFAULT 0.00 COMMENT '改价前应付金额',
  `new_total_price` decimal(10, 2) NOT NULL DEFAULT 0.00 COMMENT '改价后应付金额',
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '改价原因',
  `creator` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作管理员id',
  `creator_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '操作管理员名称',
  `add_time` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `order_id` (`order_id` ASC) USING BTREE,
  INDEX `order_no` (`order_no` ASC) USING BTREE,
  INDEX `add_time` (`add_time` ASC) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='订单改价审计记录';

-- 为非超级管理员角色提供独立的改价权限节点；超级管理员（admin id=1 / role id=1）自动拥有。
INSERT INTO `sxo_power` (`pid`, `name`, `control`, `action`, `url`, `sort`, `is_show`, `icon`, `add_time`, `upd_time`)
SELECT 178, '订单修改应付金额', 'Order', 'ModifyPrice', '', 4, 0, '', UNIX_TIMESTAMP(), 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `sxo_power` WHERE `control`='Order' AND `action`='ModifyPrice');
