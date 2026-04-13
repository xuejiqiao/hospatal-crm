-- ============================================
-- CRM微信小程序后端数据库安装脚本
-- MySQL 5.4+ / PHP 7.x
-- 注意: 系统会自动创建表(通过Database.php)，此脚本仅用于手动初始化
-- ============================================

-- 创建数据库
CREATE DATABASE IF NOT EXISTS `crm_weapp` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `crm_weapp`;

-- ============================================
-- 用户表
-- ============================================
CREATE TABLE IF NOT EXISTS `weapp_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `openid` varchar(100) NOT NULL COMMENT '微信openid',
  `unionid` varchar(100) DEFAULT '' COMMENT '微信unionid',
  `nickname` varchar(100) DEFAULT '微信用户' COMMENT '昵称',
  `avatar` varchar(500) DEFAULT '' COMMENT '头像',
  `phone` varchar(20) DEFAULT '' COMMENT '手机号',
  `name` varchar(50) DEFAULT '' COMMENT '真实姓名',
  `role` varchar(20) DEFAULT 'user' COMMENT '角色:user用户,admin管理员,manager经理',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态:0禁用,1启用',
  `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
  `updatetime` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `openid` (`openid`),
  KEY `role` (`role`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- ============================================
-- 用户会话表
-- ============================================
CREATE TABLE IF NOT EXISTS `weapp_user_session` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `token` varchar(100) NOT NULL COMMENT '登录令牌',
  `session_key` varchar(100) DEFAULT '' COMMENT '微信session_key',
  `role` varchar(20) DEFAULT 'user' COMMENT '用户角色快照',
  `expire_time` int(11) NOT NULL COMMENT '过期时间',
  `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `token` (`token`),
  KEY `expire_time` (`expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户会话表';

-- ============================================
-- 医院表
-- ============================================
CREATE TABLE IF NOT EXISTS `weapp_hospital` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL COMMENT '医院名称',
  `address` varchar(500) DEFAULT '' COMMENT '医院地址',
  `phone` varchar(50) DEFAULT '' COMMENT '联系电话',
  `images` text COMMENT '医院图片(JSON数组)',
  `intro` text COMMENT '医院简介',
  `sort` int(11) DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态:0禁用,1启用',
  `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
  `updatetime` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='医院表';

-- ============================================
-- 预约表
-- ============================================
CREATE TABLE IF NOT EXISTS `weapp_reservation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `hospital_id` int(11) NOT NULL COMMENT '医院ID',
  `patient_name` varchar(50) NOT NULL COMMENT '就诊人姓名',
  `patient_phone` varchar(20) NOT NULL COMMENT '就诊人电话',
  `patient_idcard` varchar(50) DEFAULT '' COMMENT '就诊人身份证',
  `department` varchar(100) DEFAULT '' COMMENT '科室',
  `doctor` varchar(100) DEFAULT '' COMMENT '医生',
  `reservation_date` varchar(20) NOT NULL COMMENT '预约日期',
  `time_period` varchar(20) DEFAULT '上午' COMMENT '时间段',
  `remark` text COMMENT '备注',
  `status` tinyint(1) DEFAULT '0' COMMENT '状态:0待确认,1已确认,2已完成,3已取消',
  `admin_remark` text COMMENT '管理员备注',
  `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
  `updatetime` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `hospital_id` (`hospital_id`),
  KEY `status` (`status`),
  KEY `reservation_date` (`reservation_date`),
  KEY `addtime` (`addtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='预约表';

-- ============================================
-- 管理员表
-- ============================================
CREATE TABLE IF NOT EXISTS `weapp_admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `nickname` varchar(50) DEFAULT '' COMMENT '昵称',
  `role` varchar(20) DEFAULT 'admin' COMMENT '角色:super_admin超级管理员,admin管理员,operator操作员',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态:0禁用,1启用',
  `last_login_time` int(11) DEFAULT '0' COMMENT '最后登录时间',
  `last_login_ip` varchar(50) DEFAULT '' COMMENT '最后登录IP',
  `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
  `updatetime` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表';

-- ============================================
-- 管理员操作日志表
-- ============================================
CREATE TABLE IF NOT EXISTS `weapp_admin_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL COMMENT '管理员ID',
  `action` varchar(100) NOT NULL COMMENT '操作动作',
  `target_type` varchar(50) DEFAULT '' COMMENT '目标类型',
  `target_id` int(11) DEFAULT '0' COMMENT '目标ID',
  `content` text COMMENT '操作内容',
  `ip` varchar(50) DEFAULT '' COMMENT 'IP地址',
  `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  KEY `addtime` (`addtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员操作日志表';

-- ============================================
-- 科室表
-- ============================================
CREATE TABLE IF NOT EXISTS `weapp_department` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hospital_id` int(11) NOT NULL COMMENT '医院ID',
  `name` varchar(100) NOT NULL COMMENT '科室名称',
  `intro` text COMMENT '科室简介',
  `sort` int(11) DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态:0禁用,1启用',
  `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
  `updatetime` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `hospital_id` (`hospital_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='科室表';

-- ============================================
-- 医生表
-- ============================================
CREATE TABLE IF NOT EXISTS `weapp_doctor` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hospital_id` int(11) NOT NULL COMMENT '医院ID',
  `department_id` int(11) DEFAULT '0' COMMENT '科室ID',
  `name` varchar(50) NOT NULL COMMENT '医生姓名',
  `title` varchar(50) DEFAULT '' COMMENT '职称',
  `specialty` varchar(200) DEFAULT '' COMMENT '专长',
  `avatar` varchar(500) DEFAULT '' COMMENT '头像',
  `intro` text COMMENT '简介',
  `sort` int(11) DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态:0禁用,1启用',
  `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
  `updatetime` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `hospital_id` (`hospital_id`),
  KEY `department_id` (`department_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='医生表';

-- ============================================
-- 系统配置表
-- ============================================
CREATE TABLE IF NOT EXISTS `weapp_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_name` varchar(50) DEFAULT 'default' COMMENT '配置分组',
  `config_key` varchar(100) NOT NULL COMMENT '配置键',
  `config_value` text COMMENT '配置值',
  `remark` varchar(200) DEFAULT '' COMMENT '备注',
  `sort` int(11) DEFAULT '0' COMMENT '排序',
  `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
  `updatetime` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表';

-- ============================================
-- 初始数据
-- ============================================

-- 默认管理员账号 (密码: admin123)
INSERT INTO `weapp_admin` (`username`, `password`, `nickname`, `role`, `status`, `addtime`, `updatetime`)
VALUES ('admin', MD5('admin123'), '超级管理员', 'super_admin', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- 测试医院数据
INSERT INTO `weapp_hospital` (`name`, `address`, `phone`, `intro`, `sort`, `status`, `addtime`, `updatetime`) VALUES
('北京协和医院', '北京市东城区帅府园1号', '010-69156114', '中国医学科学院北京协和医院是集医疗、教学、科研于一体的大型三级甲等综合医院。', 100, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('北京大学第一医院', '北京市西城区西什库大街8号', '010-83572211', '北京大学第一医院创建于1915年,是我国最早创办的国立医院。', 90, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('北京同仁医院', '北京市东城区东交民巷1号', '010-58266699', '首都医科大学附属北京同仁医院是一所以眼科学、耳鼻咽喉科学为国家重点学科的大型综合三甲医院。', 80, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- 系统默认配置
INSERT INTO `weapp_config` (`group_name`, `config_key`, `config_value`, `remark`, `sort`, `addtime`, `updatetime`) VALUES
('basic', 'site_name', '医院CRM管理系统', '站点名称', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('basic', 'contact_phone', '400-000-0000', '联系电话', 3, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('wechat', 'appid', 'wx2e496a2cf5ea53d8', '微信小程序AppID', 10, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('reservation', 'advance_days', '7', '可提前预约天数', 20, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('reservation', 'cancel_hours', '24', '预约取消提前小时数', 21, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('reservation', 'auto_confirm', '0', '预约自动确认:0否1是', 22, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
