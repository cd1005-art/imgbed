-- ============================================================
-- XiaoYao ImageBed v1.0 数据库初始化脚本
-- 适用：MySQL 5.7+ / utf8mb4 / InnoDB
-- 表前缀：img_
-- 默认后台账号：admin / admin123（安装后请立即修改）
-- ============================================================

CREATE DATABASE IF NOT EXISTS `imgbed` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `imgbed`;

-- ------------------------------------------------------------
-- 1. 后台管理员
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_admins`;
CREATE TABLE `img_admins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL DEFAULT '' COMMENT '登录名',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT 'bcrypt',
  `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1正常 2禁用',
  `last_login_time` int unsigned DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台管理员';

-- ------------------------------------------------------------
-- 2. 前台用户
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_users`;
CREATE TABLE `img_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL DEFAULT '' COMMENT '登录邮箱',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT 'bcrypt',
  `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称',
  `group_id` int unsigned NOT NULL DEFAULT '2' COMMENT '策略组ID',
  `quota_total` bigint NOT NULL DEFAULT '0' COMMENT '总容量字节(0=跟策略组)',
  `quota_used` bigint NOT NULL DEFAULT '0' COMMENT '已用容量字节',
  `vip_expire_time` int unsigned NOT NULL DEFAULT '0' COMMENT 'VIP到期时间戳(0=非VIP)',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1正常 2禁用',
  `last_login_time` int unsigned NOT NULL DEFAULT '0',
  `last_login_ip` varchar(45) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `email_verified` tinyint NOT NULL DEFAULT '1' COMMENT '1已验证',
  `verify_token` varchar(64) NOT NULL DEFAULT '',
  `verify_expire` int unsigned NOT NULL DEFAULT '0',
  `reset_token` varchar(64) NOT NULL DEFAULT '',
  `reset_expire` int unsigned NOT NULL DEFAULT '0',
  `verify_tries` int NOT NULL DEFAULT '0',
  `reset_tries` int NOT NULL DEFAULT '0',
  `auto_public` tinyint NOT NULL DEFAULT '0' COMMENT '自动公开新图',
  `credits` int NOT NULL DEFAULT '0' COMMENT '站币余额',
  `credits_total` int NOT NULL DEFAULT '0' COMMENT '累计收益',
  `violation_count` int NOT NULL DEFAULT '0',
  `alipay_name` varchar(50) NOT NULL DEFAULT '',
  `alipay_account` varchar(80) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台用户';

-- ------------------------------------------------------------
-- 3. 策略组（角色 x 配额 x 存储 x 审核）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_strategy_groups`;
CREATE TABLE `img_strategy_groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '组名',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `max_size` bigint NOT NULL DEFAULT '5242880' COMMENT '单图最大字节',
  `daily_count` int NOT NULL DEFAULT '100' COMMENT '每日上传次数(0=不限)',
  `daily_size` bigint NOT NULL DEFAULT '0' COMMENT '每日上传总量字节(0=不限)',
  `quota_total` bigint NOT NULL DEFAULT '1073741824' COMMENT '总容量字节(0=不限)',
  `allow_ext` varchar(255) NOT NULL DEFAULT 'jpg,jpeg,png,gif,webp,avif,bmp' COMMENT '允许扩展名',
  `keep_days` int NOT NULL DEFAULT '0' COMMENT '保留天数(0=永久)',
  `storage_id` int unsigned NOT NULL DEFAULT '1' COMMENT '默认存储实例ID',
  `token_daily_limit` int NOT NULL DEFAULT '0' COMMENT 'Token日调用上限(0不限)',
  `token_quota_mb` int NOT NULL DEFAULT '0' COMMENT 'Token容量上限MB(0不限)',
  `max_tokens` int NOT NULL DEFAULT '0' COMMENT '可生成Token数量上限(0不限)',
  `audit_driver` varchar(30) NOT NULL DEFAULT '' COMMENT '审核驱动(空=跟全局)',
  `ad_exempt` tinyint NOT NULL DEFAULT '0' COMMENT '1=该组免广告',
  `is_guest` tinyint NOT NULL DEFAULT '0' COMMENT '1=游客默认组',
  `is_register` tinyint NOT NULL DEFAULT '0' COMMENT '1=注册默认组',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1启用 2停用',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `allow_public` tinyint NOT NULL DEFAULT '1',
  `allow_auto_public` tinyint NOT NULL DEFAULT '0',
  `can_report` tinyint NOT NULL DEFAULT '1',
  `allow_paid` tinyint NOT NULL DEFAULT '1',
  `max_tokens` int NOT NULL DEFAULT '0' COMMENT '可生成Token数量上限(0不限)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='策略组';

-- ------------------------------------------------------------
-- 4. 存储实例（local / webdav / s3 / imagehost）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_storage_configs`;
CREATE TABLE `img_storage_configs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '实例名',
  `driver` varchar(20) NOT NULL DEFAULT 'local' COMMENT '驱动类型',
  `config` text COMMENT '驱动配置JSON',
  `url_mode` tinyint NOT NULL DEFAULT '1' COMMENT '外链模式 1代理中转 2直链302',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1启用 2停用',
  `sort` int NOT NULL DEFAULT '0',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='存储实例';

-- ------------------------------------------------------------
-- 5. 图片主表
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_images`;
CREATE TABLE `img_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0' COMMENT '上传用户(0=游客)',
  `group_id` int unsigned NOT NULL DEFAULT '0' COMMENT '上传时策略组',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `path` varchar(255) NOT NULL DEFAULT '' COMMENT '存储相对路径',
  `sha1` char(40) NOT NULL DEFAULT '' COMMENT 'SHA1(秒传去重)',
  `md5` char(32) NOT NULL DEFAULT '' COMMENT 'MD5',
  `size` bigint NOT NULL DEFAULT '0' COMMENT '字节数',
  `width` int NOT NULL DEFAULT '0',
  `height` int NOT NULL DEFAULT '0',
  `mime` varchar(80) NOT NULL DEFAULT '',
  `ext` varchar(10) NOT NULL DEFAULT '',
  `storage_id` int unsigned NOT NULL DEFAULT '1' COMMENT '存储实例ID',
  `urls` text COMMENT '多链JSON(main+backups, 二期)',
  `audit_status` tinyint NOT NULL DEFAULT '0' COMMENT '0待审 1通过 2拒绝 3人工复审',
  `audit_score` int NOT NULL DEFAULT '0' COMMENT '审核得分',
  `audit_time` int unsigned NOT NULL DEFAULT '0',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1正常 2冻结 0已删',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `ua` varchar(255) NOT NULL DEFAULT '',
  `expire_time` int unsigned NOT NULL DEFAULT '0' COMMENT '过期时间戳(0=永久)',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned NOT NULL DEFAULT '0' COMMENT '软删时间',
  `is_public` tinyint NOT NULL DEFAULT '0',
  `ban_public` tinyint NOT NULL DEFAULT '0',
  `is_anonymous` tinyint NOT NULL DEFAULT '0',
  `view_count` int NOT NULL DEFAULT '0',
  `origin` varchar(20) NOT NULL DEFAULT 'upload',
  `price_credits` int NOT NULL DEFAULT '0',
  `license_price` int NOT NULL DEFAULT '0',
  `tags` text NULL COMMENT '标签JSON数组',
  PRIMARY KEY (`id`),
  KEY `idx_sha1` (`sha1`),
  KEY `idx_user` (`user_id`,`create_time`),
  KEY `idx_audit` (`audit_status`,`status`),
  KEY `idx_expire` (`expire_time`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片主表';

-- ------------------------------------------------------------
-- 6. API Token
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_api_tokens`;
CREATE TABLE `img_api_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0' COMMENT '所属用户',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT 'Token名',
  `token` varchar(64) NOT NULL DEFAULT '' COMMENT 'Token值',
  `daily_limit` int NOT NULL DEFAULT '0' COMMENT '日调用上限(0=跟策略组)',
  `quota_limit` bigint NOT NULL DEFAULT '0' COMMENT '容量上限字节(0=跟策略组)',
  `request_count` int unsigned NOT NULL DEFAULT '0' COMMENT '累计调用（鉴权通过）',
  `upload_count` int NOT NULL DEFAULT '0' COMMENT '上传成功次数',
  `upload_fail_count` int NOT NULL DEFAULT '0' COMMENT '上传失败次数(鉴权通过)',
  `list_count` int NOT NULL DEFAULT '0' COMMENT '列表调用次数',
  `info_count` int NOT NULL DEFAULT '0' COMMENT '信息调用次数',
  `delete_count` int NOT NULL DEFAULT '0' COMMENT '删除调用次数',
  `last_used_time` int unsigned NOT NULL DEFAULT '0',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1启用 2停用',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API Token';

-- ------------------------------------------------------------
-- 7. VIP 套餐
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_vip_plans`;
CREATE TABLE `img_vip_plans` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '套餐名',
  `days` int NOT NULL DEFAULT '30' COMMENT '时长(天)',
  `price` int NOT NULL DEFAULT '0' COMMENT '价格(分)',
  `group_id` int unsigned NOT NULL DEFAULT '2' COMMENT '生效策略组',
  `quota_total` bigint NOT NULL DEFAULT '0' COMMENT '赠送容量字节(0=跟组)',
  `max_size` bigint NOT NULL DEFAULT '0' COMMENT '单图上限字节(0=跟组)',
  `daily_count` int NOT NULL DEFAULT '0' COMMENT '日传次数(0=跟组)',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '说明',
  `sort` int NOT NULL DEFAULT '0',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1上架 2下架',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='VIP套餐';

-- ------------------------------------------------------------
-- 8. 订单（前缀 V=VIP / C=容量包）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_orders`;
CREATE TABLE `img_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(40) NOT NULL DEFAULT '' COMMENT '订单号(前缀V/C+日期+随机)',
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `plan_id` int unsigned NOT NULL DEFAULT '0' COMMENT '套餐ID',
  `type` varchar(20) NOT NULL DEFAULT 'vip' COMMENT 'vip/capacity',
  `amount` int NOT NULL DEFAULT '0' COMMENT '实付金额(分)',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '0待支付 1已支付 2已取消 3退款',
  `gateway` varchar(20) NOT NULL DEFAULT '' COMMENT 'xpay/yipay',
  `pay_channel` varchar(20) NOT NULL DEFAULT '' COMMENT '支付渠道 alipay/wxpay/qqpay',
  `trade_no` varchar(64) NOT NULL DEFAULT '' COMMENT '网关流水号',
  `pay_time` int unsigned NOT NULL DEFAULT '0',
  `notify_raw` text COMMENT '回调原始参数',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单';

-- ------------------------------------------------------------
-- 9. 支付配置（平移 cx：易支付协议双网关）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_payment_config`;
CREATE TABLE `img_payment_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `payment_code` varchar(30) NOT NULL DEFAULT 'yipay' COMMENT '支付标识',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '名称',
  `config` text COMMENT 'JSON: api_url/pid/key/gateway_type',
  `is_open` tinyint NOT NULL DEFAULT '0' COMMENT '1开启',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_code` (`payment_code`,`is_open`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付配置';

-- ------------------------------------------------------------
-- 10. 广告位
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_ad_slots`;
CREATE TABLE `img_ad_slots` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slot_key` varchar(30) NOT NULL DEFAULT '' COMMENT '位置标识 home_header/home_footer/upload_done/view_page',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '广告位名',
  `type` varchar(10) NOT NULL DEFAULT 'html' COMMENT 'html/image/js',
  `content` text COMMENT '素材内容',
  `is_open` tinyint NOT NULL DEFAULT '0',
  `sort` int NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slot` (`slot_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='广告位';

-- ------------------------------------------------------------
-- 11. 审核日志
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_audit_logs`;
CREATE TABLE `img_audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `image_id` bigint unsigned NOT NULL DEFAULT '0',
  `driver` varchar(30) NOT NULL DEFAULT '' COMMENT '审核驱动',
  `result` varchar(10) NOT NULL DEFAULT '' COMMENT 'pass/reject/review',
  `score` int NOT NULL DEFAULT '0',
  `detail` text COMMENT '原始返回JSON',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_image` (`image_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='审核日志';

-- ------------------------------------------------------------
-- 12. 上传日志（含失败原因，运营防滥用）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_upload_logs`;
CREATE TABLE `img_upload_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `ua` varchar(255) NOT NULL DEFAULT '',
  `image_id` bigint unsigned NOT NULL DEFAULT '0',
  `result` tinyint NOT NULL DEFAULT '0' COMMENT '1成功 0失败',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '失败原因',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip`,`create_time`),
  KEY `idx_user` (`user_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='上传日志';

-- ------------------------------------------------------------
-- 13. 站点设置
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_settings`;
CREATE TABLE `img_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `skey` varchar(50) NOT NULL DEFAULT '' COMMENT '键',
  `svalue` text COMMENT '值',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '说明',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点设置';

-- ------------------------------------------------------------
-- 14. API 调用日志
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_api_call_logs`;
CREATE TABLE `img_api_call_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_id` int unsigned NOT NULL DEFAULT '0',
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `action` varchar(20) NOT NULL DEFAULT '' COMMENT 'upload/list/info/delete',
  `success` tinyint NOT NULL DEFAULT '0',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_token_time` (`token_id`,`create_time`),
  KEY `idx_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API调用日志';

-- ------------------------------------------------------------
-- 15. 审核模型（多模型链）
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `img_audit_models`;
CREATE TABLE `img_audit_models` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '',
  `endpoint` varchar(255) NOT NULL DEFAULT '',
  `api_key` varchar(255) NOT NULL DEFAULT '',
  `model_name` varchar(80) NOT NULL DEFAULT '',
  `type` varchar(20) NOT NULL DEFAULT 'llm' COMMENT 'llm/local_nsfw',
  `status` tinyint NOT NULL DEFAULT '1',
  `sort` int NOT NULL DEFAULT '0',
  `fail_count` int NOT NULL DEFAULT '0',
  `last_ok_time` int unsigned NOT NULL DEFAULT '0',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `type` varchar(20) NOT NULL DEFAULT 'llm' COMMENT 'llm/local_nsfw',
  `purpose` varchar(20) NOT NULL DEFAULT 'audit' COMMENT 'audit/image_gen',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='审核模型（多模型链）';

-- ============================================================
-- 初始数据
-- ============================================================

-- 管理员（admin / admin123，与 cx 同源 bcrypt，装完立即改密）
INSERT INTO `img_admins` (`username`, `password`, `nickname`, `status`, `create_time`, `update_time`) VALUES
('admin', '$2y$10$E9Nnx75WM/Z14g174mwB8.dNrGnLH7EGIqFmM8lxsmbHqvFh63T6W', '超级管理员', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- 策略组：1 游客组 / 2 注册默认组 / 3 VIP组
INSERT INTO `img_strategy_groups` (`id`, `name`, `remark`, `max_size`, `daily_count`, `daily_size`, `quota_total`, `allow_ext`, `keep_days`, `storage_id`, `audit_driver`, `ad_exempt`, `is_guest`, `is_register`, `status`, `create_time`) VALUES
(1, '游客', '未登录游客上传', 5242880, 20, 52428800, 52428800, 'jpg,jpeg,png,gif,webp', 30, 1, '', 0, 1, 0, 1, UNIX_TIMESTAMP()),
(2, '注册用户', '注册免费组', 10485760, 100, 0, 1073741824, 'jpg,jpeg,png,gif,webp,avif,bmp', 0, 1, '', 0, 0, 1, 1, UNIX_TIMESTAMP()),
(3, 'VIP', '付费VIP组', 52428800, 0, 0, 5368709120, 'jpg,jpeg,png,gif,webp,avif,bmp', 0, 1, '', 1, 0, 0, 1, UNIX_TIMESTAMP());

-- 存储实例：1 本地磁盘
INSERT INTO `img_storage_configs` (`id`, `name`, `driver`, `config`, `url_mode`, `status`, `sort`, `create_time`) VALUES
(1, '本地磁盘', 'local', '{"root":"storage/images"}', 1, 1, 0, UNIX_TIMESTAMP());

-- VIP 套餐示例
INSERT INTO `img_vip_plans` (`name`, `days`, `price`, `group_id`, `quota_total`, `max_size`, `daily_count`, `description`, `sort`, `status`, `create_time`) VALUES
('VIP 月卡', 30, 990, 3, 0, 0, 0, '5GB容量/单图50MB/不限日传/免广告', 0, 1, UNIX_TIMESTAMP()),
('VIP 年卡', 365, 8990, 3, 0, 0, 0, '5GB容量/单图50MB/不限日传/免广告', 1, 1, UNIX_TIMESTAMP());

-- 站点设置
INSERT INTO `img_settings` (`skey`, `svalue`, `remark`, `update_time`) VALUES
('site_name', '逍遥图床', '站点名称', UNIX_TIMESTAMP()),
('operation_mode', 'free_pay', '运营模式 free_ad纯免费+广告 / free_pay免费+付费 / invite邀请制', UNIX_TIMESTAMP()),
('guest_group_id', '1', '游客策略组', UNIX_TIMESTAMP()),
('register_group_id', '2', '注册默认策略组', UNIX_TIMESTAMP()),
('default_audit_driver', 'none', '全局审核驱动 none/local_nsfw/aliyun/tencent/llm_vlm', UNIX_TIMESTAMP()),
('llm_audit_endpoint', '', 'LLM审核端点(OpenAI兼容)', UNIX_TIMESTAMP()),
('llm_audit_key', '', 'LLM审核密钥', UNIX_TIMESTAMP()),
('llm_audit_model', '', 'LLM审核模型', UNIX_TIMESTAMP()),
('ad_vip_exempt', '1', 'VIP是否免广告', UNIX_TIMESTAMP()),
('allow_register', '1', '是否开放注册', UNIX_TIMESTAMP()),
('upload_watermark', '0', '上传水印开关', UNIX_TIMESTAMP()),
('audit_mode', 'failover', '审核模式 failover顺序切换/parallel并行投票', UNIX_TIMESTAMP());

-- 广告位（默认关闭）
INSERT INTO `img_ad_slots` (`slot_key`, `name`, `type`, `content`, `is_open`, `sort`, `update_time`) VALUES
('home_header', '首页顶部', 'html', '', 0, 0, UNIX_TIMESTAMP()),
('home_footer', '首页底部', 'html', '', 0, 1, UNIX_TIMESTAMP()),
('upload_done', '上传成功页', 'html', '', 0, 2, UNIX_TIMESTAMP()),
('view_page', '图片查看页', 'html', '', 0, 3, UNIX_TIMESTAMP());

-- ============================================================
-- v1.1 增量迁移（M1 邮箱体系 / M2 标签与公开 / M4 举报与付费 / M5 造图）
-- 旧版本升级：可直接执行本段（幂等）
-- ============================================================

CREATE TABLE IF NOT EXISTS `img_mail_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(120) NOT NULL DEFAULT '',
  `subject` varchar(200) NOT NULL DEFAULT '',
  `template` varchar(50) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT '0',
  `error` varchar(255) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`), KEY `idx_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件发送日志';

-- 注：新列已并入上方基础表结构定义（MySQL 5.7 不支持 ADD COLUMN IF NOT EXISTS）

CREATE TABLE IF NOT EXISTS `img_cron_heartbeats` (
  `task_key` varchar(40) NOT NULL DEFAULT '',
  `last_run` int unsigned NOT NULL DEFAULT '0',
  `last_result` varchar(200) NOT NULL DEFAULT '',
  `run_count` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`task_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='计划任务心跳';

INSERT IGNORE INTO `img_settings` (`skey`,`svalue`,`remark`,`update_time`) VALUES
('cron_key','','计划任务访问密钥',UNIX_TIMESTAMP()),
('gen_daily_limit','100','造图机器人单日上限',UNIX_TIMESTAMP()),
('credits_per_yuan','10','1元兑换站币数',UNIX_TIMESTAMP());

CREATE TABLE IF NOT EXISTS `img_cron_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_key` varchar(40) NOT NULL DEFAULT '',
  `ok` tinyint NOT NULL DEFAULT '1',
  `duration_ms` int NOT NULL DEFAULT '0',
  `result` varchar(200) NOT NULL DEFAULT '',
  `source` varchar(10) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`), KEY `idx_task` (`task_key`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='计划任务执行历史';

ALTER TABLE `img_cron_heartbeats`
  ADD COLUMN `fail_streak` int NOT NULL DEFAULT '0',
  ADD COLUMN `last_duration` int NOT NULL DEFAULT '0',
  ADD COLUMN `last_ok_time` int unsigned NOT NULL DEFAULT '0',
  ADD COLUMN `manual_result` varchar(200) NOT NULL DEFAULT '';

INSERT IGNORE INTO `img_settings` (`skey`,`svalue`,`remark`,`update_time`) VALUES
('mail_events','','通知开关JSON（空=全开）',UNIX_TIMESTAMP()),
('site_domain','','站点域名（巡检用，空=自动）',UNIX_TIMESTAMP()),
('gen_daily_limit','100','造图机器人单日上限',UNIX_TIMESTAMP()),
('credits_per_yuan','10','1元兑换站币数',UNIX_TIMESTAMP());

INSERT IGNORE INTO `img_settings` (`skey`,`svalue`,`remark`,`update_time`) VALUES
('alert_disk_left_pct','10','磁盘剩余告警%（与配额/网盘统一口径）',UNIX_TIMESTAMP());

INSERT IGNORE INTO `img_settings` (`skey`,`svalue`,`remark`,`update_time`) VALUES
('alert_unit','pct','告警判断口径 pct=剩余百分比 gb=剩余容量',UNIX_TIMESTAMP());
