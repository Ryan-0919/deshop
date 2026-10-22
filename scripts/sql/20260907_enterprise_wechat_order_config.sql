-- 企业微信群机器人订单通知后台配置（幂等，可重复执行）
INSERT INTO `{PREFIX}config` (`value`, `name`, `describe`, `error_tips`, `type`, `only_tag`, `upd_time`)
SELECT '0', '企业微信订单通知', '支付成功后发送到企业微信群机器人', '请选择是否启用企业微信订单通知', 'common', 'enterprise_wechat_order_enabled', UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `{PREFIX}config` WHERE `only_tag` = 'enterprise_wechat_order_enabled');

INSERT INTO `{PREFIX}config` (`value`, `name`, `describe`, `error_tips`, `type`, `only_tag`, `upd_time`)
SELECT '', '企业微信机器人 Webhook', '仅支持 qyapi.weixin.qq.com HTTPS 地址；留空保持已保存地址', '请填写有效的企业微信机器人 Webhook', 'common', 'enterprise_wechat_order_webhook_url', UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `{PREFIX}config` WHERE `only_tag` = 'enterprise_wechat_order_webhook_url');

INSERT INTO `{PREFIX}config` (`value`, `name`, `describe`, `error_tips`, `type`, `only_tag`, `upd_time`)
SELECT '3', '企业微信通知超时', '网络请求超时时间，单位秒（1-5）', '请输入1到5之间的秒数', 'common', 'enterprise_wechat_order_timeout', UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `{PREFIX}config` WHERE `only_tag` = 'enterprise_wechat_order_timeout');

INSERT INTO `{PREFIX}config` (`value`, `name`, `describe`, `error_tips`, `type`, `only_tag`, `upd_time`)
SELECT '', '企业微信 @ 手机号', '多个手机号用英文逗号或空格分隔，留空不 @', '请输入手机号列表', 'common', 'enterprise_wechat_order_mention_mobiles', UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `{PREFIX}config` WHERE `only_tag` = 'enterprise_wechat_order_mention_mobiles');
