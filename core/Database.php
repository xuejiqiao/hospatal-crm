<?php
/**
 * 数据库连接类
 */
class Database {
    private static $instance = null;
    private $connection;
    private $config;
    
    private function __construct() {
        $this->config = self::loadConfig();
        $this->connect();
    }
    
    /**
     * 加载配置（确保每次都拿到数组）
     */
    private static function loadConfig() {
        static $config = null;
        if ($config === null) {
            $config = require __DIR__ . '/../config/database.php';
        }
        return $config;
    }
    
    /**
     * 获取配置（供其他类使用，避免重复加载）
     */
    public static function getConfig() {
        return self::loadConfig();
    }
    
    /**
     * 单例模式获取数据库实例
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 连接数据库
     */
    private function connect() {
        $db = $this->config['db'];
        
        // 先尝试不带dbname连接，自动创建数据库
        try {
            $dsn = "mysql:host={$db['host']};port={$db['port']};charset={$db['charset']}";
            $pdo = new PDO($dsn, $db['username'], $db['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 创建数据库（如果不存在）
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['database']}` DEFAULT CHARACTER SET {$db['charset']} COLLATE {$db['charset']}_unicode_ci");
            $pdo->exec("USE `{$db['database']}`");
            
            $this->connection = $pdo;
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // 自动创建表（如果不存在）
            $this->initTables();
            
        } catch(PDOException $e) {
            $this->outputError('数据库连接失败', $e->getMessage());
        }
    }
    
    /**
     * 自动创建必要的数据表
     */
    private function initTables() {
        $prefix = $this->config['db']['prefix'];
        
        $sqls = array(
            // 用户表
            "CREATE TABLE IF NOT EXISTS `{$prefix}user` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表'",
            
            // 用户会话表
            "CREATE TABLE IF NOT EXISTS `{$prefix}user_session` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户会话表'",
            
            // 医院表
            "CREATE TABLE IF NOT EXISTS `{$prefix}hospital` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='医院表'",
            
            // 预约表
            "CREATE TABLE IF NOT EXISTS `{$prefix}reservation` (
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
                `status` varchar(20) DEFAULT '待确认' COMMENT '状态',
                `admin_remark` text COMMENT '管理员备注',
                `fee` decimal(10,2) DEFAULT '0.00' COMMENT '费用金额',
                `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
                `updatetime` int(11) DEFAULT '0' COMMENT '更新时间',
                PRIMARY KEY (`id`),
                KEY `user_id` (`user_id`),
                KEY `hospital_id` (`hospital_id`),
                KEY `status` (`status`),
                KEY `reservation_date` (`reservation_date`),
                KEY `addtime` (`addtime`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='预约表'",
            
            // 管理员操作日志表
            "CREATE TABLE IF NOT EXISTS `{$prefix}admin_log` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员操作日志表'",
            
            // 管理员表
            "CREATE TABLE IF NOT EXISTS `{$prefix}admin` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表'",
            
            // 科室表
            "CREATE TABLE IF NOT EXISTS `{$prefix}department` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='科室表'",
            
            // 医生表
            "CREATE TABLE IF NOT EXISTS `{$prefix}doctor` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='医生表'",
            
            // 系统配置表
            "CREATE TABLE IF NOT EXISTS `{$prefix}config` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表'",
            
            // 随访记录表
            "CREATE TABLE IF NOT EXISTS `{$prefix}follow_up` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `reservation_id` int(11) NOT NULL COMMENT '关联预约ID',
                `patient_phone` varchar(20) NOT NULL COMMENT '患者手机号',
                `patient_name` varchar(50) DEFAULT '' COMMENT '患者姓名',
                `follow_type` varchar(20) DEFAULT 'phone' COMMENT '随访方式:phone电话,wechat微信,visit到访',
                `follow_result` varchar(20) DEFAULT 'normal' COMMENT '随访结果:normal正常,abnormal异常,no_answer未接听,cancelled取消',
                `content` text COMMENT '随访内容',
                `next_date` varchar(20) DEFAULT '' COMMENT '下次随访日期',
                `admin_id` int(11) DEFAULT '0' COMMENT '操作管理员ID',
                `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
                PRIMARY KEY (`id`),
                KEY `reservation_id` (`reservation_id`),
                KEY `patient_phone` (`patient_phone`),
                KEY `admin_id` (`admin_id`),
                KEY `addtime` (`addtime`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='随访记录表'",
            
            // 科室-医院关联表（一个科室可属于多家医院）
            "CREATE TABLE IF NOT EXISTS `{$prefix}dept_hospital` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `department_id` int(11) NOT NULL COMMENT '科室ID',
                `hospital_id` int(11) NOT NULL COMMENT '医院ID',
                `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `dept_hosp` (`department_id`, `hospital_id`),
                KEY `hospital_id` (`hospital_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='科室医院关联表'",
            
            // 医生科室关联表（一个医生可关联多个科室）
            "CREATE TABLE IF NOT EXISTS `{$prefix}doctor_dept` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `doctor_id` int(11) NOT NULL COMMENT '医生ID',
                `department_id` int(11) NOT NULL COMMENT '科室ID',
                `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `doctor_dept` (`doctor_id`, `department_id`),
                KEY `doctor_id` (`doctor_id`),
                KEY `department_id` (`department_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='医生科室关联表'",
            
            // 自定义字段定义表
            "CREATE TABLE IF NOT EXISTS `{$prefix}custom_field` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `field_key` varchar(60) NOT NULL COMMENT '字段标识(英文)',
                `field_name` varchar(100) NOT NULL COMMENT '字段名称(中文)',
                `field_type` varchar(20) DEFAULT 'text' COMMENT '字段类型:text输入框,select下拉,textarea文本域,date日期,number数字',
                `target_table` varchar(30) NOT NULL COMMENT '所属表:reservation预约,patient患者',
                `options` text COMMENT '下拉选项JSON',
                `required` tinyint(1) DEFAULT '0' COMMENT '是否必填:0否1是',
                `sort` int(11) DEFAULT '0' COMMENT '排序',
                `status` tinyint(1) DEFAULT '1' COMMENT '状态:0禁用1启用',
                `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `field_key_table` (`field_key`, `target_table`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='自定义字段定义'",
            
            // 自定义字段值表（EAV模式）
            "CREATE TABLE IF NOT EXISTS `{$prefix}custom_field_value` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `field_id` int(11) NOT NULL COMMENT '字段ID',
                `target_table` varchar(30) NOT NULL COMMENT '所属表',
                `target_id` int(11) NOT NULL COMMENT '记录ID',
                `field_value` text COMMENT '字段值',
                `addtime` int(11) DEFAULT '0' COMMENT '创建时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `field_target` (`field_id`, `target_table`, `target_id`),
                KEY `target` (`target_table`, `target_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='自定义字段值'",
            
            // 患者文件表
            "CREATE TABLE IF NOT EXISTS `{$prefix}patient_file` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `patient_phone` varchar(20) NOT NULL COMMENT '患者手机号',
                `reservation_id` int(11) DEFAULT 0 COMMENT '关联预约ID',
                `file_name` varchar(255) NOT NULL COMMENT '原始文件名',
                `file_path` varchar(500) NOT NULL COMMENT '存储路径',
                `file_type` varchar(50) DEFAULT '' COMMENT '文件MIME类型',
                `file_size` int(11) DEFAULT 0 COMMENT '文件大小(字节)',
                `file_ext` varchar(20) DEFAULT '' COMMENT '文件扩展名',
                `description` varchar(255) DEFAULT '' COMMENT '文件描述',
                `admin_id` int(11) DEFAULT 0 COMMENT '上传管理员ID',
                `addtime` int(11) DEFAULT 0 COMMENT '上传时间',
                PRIMARY KEY (`id`),
                KEY `patient_phone` (`patient_phone`),
                KEY `reservation_id` (`reservation_id`),
                KEY `addtime` (`addtime`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='患者文件'"
        );
        
        foreach ($sqls as $sql) {
            try {
                $this->connection->exec($sql);
            } catch(PDOException $e) {
                // 表已存在则忽略
            }
        }
        
        // ===== 数据库迁移：自动升级旧表结构 =====
        $this->migrateTables($prefix);
        
        // 插入默认管理员（如果不存在）
        $stmt = $this->connection->query("SELECT COUNT(*) as count FROM `{$prefix}user` WHERE role='admin'");
        $row = $stmt->fetch();
        if ($row['count'] == 0) {
            $this->connection->exec("INSERT INTO `{$prefix}user` (openid, nickname, role, status, addtime, updatetime) VALUES ('admin_default', '系统管理员', 'admin', 1, " . time() . ", " . time() . ")");
        }
        
        // 插入示例医院数据（如果不存在）
        $stmt = $this->connection->query("SELECT COUNT(*) as count FROM `{$prefix}hospital`");
        $row = $stmt->fetch();
        if ($row['count'] == 0) {
            $now = time();
            $this->connection->exec("INSERT INTO `{$prefix}hospital` (name, address, phone, intro, sort, status, addtime, updatetime) VALUES 
                ('北京协和医院', '北京市东城区帅府园1号', '010-69156114', '中国医学科学院北京协和医院是集医疗、教学、科研于一体的大型三级甲等综合医院。', 100, 1, {$now}, {$now}),
                ('北京大学第一医院', '北京市西城区西什库大街8号', '010-83572211', '北京大学第一医院创建于1915年,是我国最早创办的国立医院。', 90, 1, {$now}, {$now}),
                ('北京同仁医院', '北京市东城区东交民巷1号', '010-58266699', '首都医科大学附属北京同仁医院是一所以眼科学、耳鼻咽喉科学为国家重点学科的大型综合三甲医院。', 80, 1, {$now}, {$now})");
        }
        
        // 插入默认管理员账号（如果不存在）
        $stmt = $this->connection->query("SELECT COUNT(*) as count FROM `{$prefix}admin`");
        $row = $stmt->fetch();
        if ($row['count'] == 0) {
            $now = time();
            $defaultPwd = md5('admin123');
            $this->connection->exec("INSERT INTO `{$prefix}admin` (username, password, nickname, role, status, addtime, updatetime) VALUES 
                ('admin', '{$defaultPwd}', '超级管理员', 'super_admin', 1, {$now}, {$now}),
                ('operator', '{$defaultPwd}', '操作员', 'operator', 1, {$now}, {$now})");
        }
        
        // 插入示例科室数据（如果不存在）
        $stmt = $this->connection->query("SELECT COUNT(*) as count FROM `{$prefix}department`");
        $row = $stmt->fetch();
        if ($row['count'] == 0) {
            $now = time();
            $this->connection->exec("INSERT INTO `{$prefix}department` (hospital_id, name, intro, sort, status, addtime, updatetime) VALUES 
                (1, '内科', '内科科室', 100, 1, {$now}, {$now}),
                (1, '外科', '外科科室', 90, 1, {$now}, {$now}),
                (1, '眼科', '眼科科室', 80, 1, {$now}, {$now}),
                (2, '内科', '内科科室', 100, 1, {$now}, {$now}),
                (2, '妇产科', '妇产科科室', 90, 1, {$now}, {$now}),
                (3, '眼科', '眼科科室', 100, 1, {$now}, {$now}),
                (3, '耳鼻喉科', '耳鼻喉科科室', 90, 1, {$now}, {$now})");
        }
        
        // 插入示例医生数据（如果不存在）
        $stmt = $this->connection->query("SELECT COUNT(*) as count FROM `{$prefix}doctor`");
        $row = $stmt->fetch();
        if ($row['count'] == 0) {
            $now = time();
            $this->connection->exec("INSERT INTO `{$prefix}doctor` (hospital_id, department_id, name, title, specialty, sort, status, addtime, updatetime) VALUES 
                (1, 1, '张主任', '主任医师', '心血管内科', 100, 1, {$now}, {$now}),
                (1, 2, '李医生', '副主任医师', '普外科', 90, 1, {$now}, {$now}),
                (1, 3, '王教授', '主任医师', '眼底病', 80, 1, {$now}, {$now}),
                (3, 6, '赵主任', '主任医师', '白内障', 100, 1, {$now}, {$now}),
                (3, 7, '陈医生', '副主任医师', '中耳炎', 90, 1, {$now}, {$now})");
        }
        
        // 插入系统默认配置（如果不存在）
        $stmt = $this->connection->query("SELECT COUNT(*) as count FROM `{$prefix}config`");
        $row = $stmt->fetch();
        if ($row['count'] == 0) {
            $now = time();
            $this->connection->exec("INSERT INTO `{$prefix}config` (`group_name`, `config_key`, `config_value`, `remark`, `sort`, `addtime`, `updatetime`) VALUES 
                ('basic', 'site_name', '医院CRM管理系统', '站点名称', 1, {$now}, {$now}),
                ('basic', 'site_logo', '', '站点LOGO', 2, {$now}, {$now}),
                ('basic', 'contact_phone', '400-000-0000', '联系电话', 3, {$now}, {$now}),
                ('wechat', 'appid', 'wx2e496a2cf5ea53d8', '微信小程序AppID', 10, {$now}, {$now}),
                ('reservation', 'advance_days', '7', '可提前预约天数', 20, {$now}, {$now}),
                ('reservation', 'cancel_hours', '24', '预约取消提前小时数', 21, {$now}, {$now}),
                ('reservation', 'auto_confirm', '0', '预约自动确认:0否1是', 22, {$now}, {$now})");
        }
    }
    
    /**
     * 数据库迁移：自动升级旧表结构
     */
    private function migrateTables($prefix) {
        // 检查config表是否有旧字段名，需要迁移
        try {
            $stmt = $this->connection->query("SHOW COLUMNS FROM `{$prefix}config` LIKE 'key'");
            if ($stmt && $stmt->rowCount() > 0) {
                // config表存在旧字段，需要重建
                // 先备份旧数据
                $oldData = $this->connection->query("SELECT * FROM `{$prefix}config`")->fetchAll(PDO::FETCH_ASSOC);
                // 删除旧表
                $this->connection->exec("DROP TABLE `{$prefix}config`");
                // 重新创建新表
                $this->connection->exec("CREATE TABLE `{$prefix}config` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表'");
                // 迁移旧数据
                if (!empty($oldData)) {
                    foreach ($oldData as $row) {
                        $groupVal = isset($row['group']) ? $row['group'] : (isset($row['group_name']) ? $row['group_name'] : 'basic');
                        $keyVal = isset($row['key']) ? $row['key'] : (isset($row['config_key']) ? $row['config_key'] : '');
                        $valVal = isset($row['value']) ? $row['value'] : (isset($row['config_value']) ? $row['config_value'] : '');
                        $remarkVal = isset($row['remark']) ? $row['remark'] : '';
                        $addtimeVal = isset($row['addtime']) ? $row['addtime'] : time();
                        $updatetimeVal = isset($row['updatetime']) ? $row['updatetime'] : time();
                        $this->connection->exec("INSERT INTO `{$prefix}config` (`group_name`, `config_key`, `config_value`, `remark`, `sort`, `addtime`, `updatetime`) VALUES ('" . addslashes($groupVal) . "', '" . addslashes($keyVal) . "', '" . addslashes($valVal) . "', '" . addslashes($remarkVal) . "', 0, {$addtimeVal}, {$updatetimeVal})");
                    }
                }
            }
        } catch(PDOException $e) {
            // 表不存在或其他错误，忽略
        }
        
        // 检查config表是否缺少sort字段
        try {
            $stmt = $this->connection->query("SHOW COLUMNS FROM `{$prefix}config` LIKE 'sort'");
            if (!$stmt || $stmt->rowCount() == 0) {
                $this->connection->exec("ALTER TABLE `{$prefix}config` ADD COLUMN `sort` int(11) DEFAULT '0' COMMENT '排序' AFTER `remark`");
            }
        } catch(PDOException $e) {
            // 忽略
        }
        
        // 检查reservation表是否缺少department_id字段
        try {
            $stmt = $this->connection->query("SHOW COLUMNS FROM `{$prefix}reservation` LIKE 'department_id'");
            if (!$stmt || $stmt->rowCount() == 0) {
                $this->connection->exec("ALTER TABLE `{$prefix}reservation` ADD COLUMN `department_id` int(11) DEFAULT '0' COMMENT '科室ID' AFTER `hospital_id`");
            }
        } catch(PDOException $e) {
            // 忽略
        }
        
        // 检查reservation表是否缺少新增字段
        $newFields = array(
            'sample_date' => "ALTER TABLE `{$prefix}reservation` ADD COLUMN `sample_date` varchar(20) DEFAULT '' COMMENT '采样日期' AFTER `time_period`",
            'test_date' => "ALTER TABLE `{$prefix}reservation` ADD COLUMN `test_date` varchar(20) DEFAULT '' COMMENT '送检日期' AFTER `sample_date`",
            'test_result' => "ALTER TABLE `{$prefix}reservation` ADD COLUMN `test_result` text COMMENT '检测结果' AFTER `test_date`",
            'report_address' => "ALTER TABLE `{$prefix}reservation` ADD COLUMN `report_address` varchar(500) DEFAULT '' COMMENT '报告邮寄地址' AFTER `test_result`",
            'wechat' => "ALTER TABLE `{$prefix}reservation` ADD COLUMN `wechat` varchar(100) DEFAULT '' COMMENT '微信号' AFTER `report_address`",
            'fee' => "ALTER TABLE `{$prefix}reservation` ADD COLUMN `fee` decimal(10,2) DEFAULT '0.00' COMMENT '费用金额' AFTER `admin_remark`"
        );
        foreach ($newFields as $field => $sql) {
            try {
                $stmt = $this->connection->query("SHOW COLUMNS FROM `{$prefix}reservation` LIKE '{$field}'");
                if (!$stmt || $stmt->rowCount() == 0) {
                    $this->connection->exec($sql);
                }
            } catch(PDOException $e) {
                // 忽略
            }
        }
        
        // 迁移旧department的hospital_id关系到dept_hospital关联表
        try {
            $count = $this->connection->query("SELECT COUNT(*) as cnt FROM `{$prefix}dept_hospital`")->fetch()['cnt'];
            if ($count == 0) {
                // 从旧表迁移：每个department的hospital_id写入关联表
                $this->connection->exec("INSERT IGNORE INTO `{$prefix}dept_hospital` (`department_id`, `hospital_id`, `addtime`) SELECT id, hospital_id, " . time() . " FROM `{$prefix}department` WHERE hospital_id > 0");
            }
        } catch(PDOException $e) {
            // 忽略
        }
        
        // 检查reservation表status字段是否为旧tinyint类型，需要迁移为varchar
        try {
            $stmt = $this->connection->query("SHOW COLUMNS FROM `{$prefix}reservation` LIKE 'status'");
            $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($col && strpos(strtolower($col['Type']), 'tinyint') !== false) {
                // 旧int状态值映射到新中文状态
                // 必须先ALTER改类型为varchar，否则tinyint无法存储中文
                $this->connection->exec("ALTER TABLE `{$prefix}reservation` MODIFY COLUMN `status` varchar(20) DEFAULT '待确认' COMMENT '状态'");
                // 然后UPDATE转换旧int值为中文
                $statusMap = array('0' => '待确认', '1' => '已预约', '2' => '已成单', '3' => '已取消');
                foreach ($statusMap as $old => $new) {
                    $this->connection->exec("UPDATE `{$prefix}reservation` SET `status`='{$new}' WHERE `status`='{$old}'");
                }
            }
        } catch(PDOException $e) {
            // 忽略
        }
        
        // 补救：如果status已经是varchar但值仍是数字字符串，也需要转换
        try {
            $stmt = $this->connection->query("SHOW COLUMNS FROM `{$prefix}reservation` LIKE 'status'");
            $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($col && strpos(strtolower($col['Type']), 'varchar') !== false) {
                $statusMap = array('0' => '待确认', '1' => '已预约', '2' => '已成单', '3' => '已取消');
                foreach ($statusMap as $old => $new) {
                    $this->connection->exec("UPDATE `{$prefix}reservation` SET `status`='{$new}' WHERE `status`='{$old}'");
                }
            }
        } catch(PDOException $e) {
            // 忽略
        }
        
        // 初始化预约状态配置（如果不存在）
        try {
            $cf = $this->connection->query("SELECT id FROM `{$prefix}config` WHERE `config_key`='reservation_status_config'")->fetch();
            if (!$cf) {
                $defaultStatus = json_encode(array(
                    array('name' => '待确认', 'color' => '#92400e', 'bg' => '#fef3c7'),
                    array('name' => '已预约', 'color' => '#92400e', 'bg' => '#fef3c7'),
                    array('name' => '已寄送', 'color' => '#1f2937', 'bg' => '#f3f4f6'),
                    array('name' => '已成单', 'color' => '#991b1b', 'bg' => '#fee2e2'),
                    array('name' => '已取消', 'color' => '#6b7280', 'bg' => '#f3f4f6')
                ), JSON_UNESCAPED_UNICODE);
                $this->connection->exec("INSERT INTO `{$prefix}config` (`group_name`,`config_key`,`config_value`,`remark`,`sort`,`addtime`,`updatetime`) VALUES ('reservation','reservation_status_config','{$defaultStatus}','预约状态配置(JSON)',0," . time() . "," . time() . ")");
            }
        } catch(PDOException $e) {
            // 忽略
        }
    }
    
    /**
     * 输出错误信息
     */
    private function outputError($title, $message) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'code' => 500,
            'message' => $title . ': ' . $message,
            'data' => null
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * 获取PDO连接
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * 执行查询
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch(PDOException $e) {
            Log::error('SQL错误: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw $e;
        }
    }
    
    /**
     * 查询所有记录
     */
    public function select($table, $where = [], $fields = '*', $order = '', $limit = '') {
        $prefix = $this->config['db']['prefix'];
        $sql = "SELECT {$fields} FROM {$prefix}{$table}";
        $params = [];
        
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $value) {
                if (is_array($value)) {
                    $operator = strtoupper($value[0]);
                    // 处理BETWEEN操作符
                    if ($operator === 'BETWEEN') {
                        $values = explode(',', $value[1]);
                        $conditions[] = "{$key} BETWEEN ? AND ?";
                        $params[] = $values[0];
                        $params[] = $values[1];
                    } else {
                        $conditions[] = "{$key} {$operator} ?";
                        $params[] = $value[1];
                    }
                } else {
                    $conditions[] = "{$key} = ?";
                    $params[] = $value;
                }
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        if ($order) {
            $sql .= " ORDER BY {$order}";
        }
        
        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }
        
        return $this->query($sql, $params)->fetchAll();
    }
    
    /**
     * 查询单条记录
     */
    public function find($table, $where, $fields = '*') {
        $result = $this->select($table, $where, $fields, '', '1');
        return $result ? $result[0] : null;
    }
    
    /**
     * 插入记录
     */
    public function insert($table, $data) {
        $fields = array_keys($data);
        $values = array_values($data);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        
        $sql = "INSERT INTO {$this->config['db']['prefix']}{$table} (" . implode(',', $fields) . ") VALUES ({$placeholders})";
        
        $this->query($sql, $values);
        return $this->connection->lastInsertId();
    }
    
    /**
     * 更新记录
     */
    public function update($table, $data, $where) {
        $fields = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $fields[] = "{$key} = ?";
            $params[] = $value;
        }
        
        $whereConditions = [];
        foreach ($where as $key => $value) {
            $whereConditions[] = "{$key} = ?";
            $params[] = $value;
        }
        
        $sql = "UPDATE {$this->config['db']['prefix']}{$table} SET " . implode(',', $fields) . " WHERE " . implode(' AND ', $whereConditions);
        
        return $this->query($sql, $params)->rowCount();
    }
    
    /**
     * 删除记录
     */
    public function delete($table, $where) {
        $conditions = [];
        $params = [];
        
        foreach ($where as $key => $value) {
            $conditions[] = "{$key} = ?";
            $params[] = $value;
        }
        
        $sql = "DELETE FROM {$this->config['db']['prefix']}{$table} WHERE " . implode(' AND ', $conditions);
        
        return $this->query($sql, $params)->rowCount();
    }
    
    /**
     * 统计记录数
     */
    public function count($table, $where = []) {
        $sql = "SELECT COUNT(*) as count FROM {$this->config['db']['prefix']}{$table}";
        $params = [];
        
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $value) {
                if (is_array($value)) {
                    $operator = strtoupper($value[0]);
                    // 处理BETWEEN操作符
                    if ($operator === 'BETWEEN') {
                        $values = explode(',', $value[1]);
                        $conditions[] = "{$key} BETWEEN ? AND ?";
                        $params[] = $values[0];
                        $params[] = $values[1];
                    } else {
                        $conditions[] = "{$key} {$operator} ?";
                        $params[] = $value[1];
                    }
                } else {
                    $conditions[] = "{$key} = ?";
                    $params[] = $value;
                }
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $result = $this->query($sql, $params)->fetch();
        return $result['count'];
    }
    
    /**
     * 开始事务
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    /**
     * 提交事务
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * 回滚事务
     */
    public function rollBack() {
        return $this->connection->rollBack();
    }
}
