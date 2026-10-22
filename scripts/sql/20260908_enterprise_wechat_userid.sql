-- 企业微信成员 ID，用于按分销员动态艾特订单通知。
-- 部署脚本应先检查 information_schema.columns，仅在字段不存在时执行：
ALTER TABLE `sxo_user`
  ADD COLUMN `enterprise_wechat_userid` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '企业微信成员userid' AFTER `referrer`;
