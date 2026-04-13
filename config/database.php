<?php
/**
 * 数据库配置文件
 */
return array(
    // 数据库配置
    'db' => array(
        'host' => 'localhost',
        'port' => '3306',
        'database' => 'crm_weapp',
        'username' => 'root',
        'password' => 'admin',
        'charset' => 'utf8mb4',
        'prefix' => 'weapp_'
    ),
    
    // 微信小程序配置
    'wechat' => array(
        'appid' => 'you_appid',
        'secret' => 'you_AppSecret', // 请替换为您的AppSecret
    ),
    
    // API配置
    'api' => array(
        'token_expire' => 7200, // token过期时间(秒)
        'page_size' => 10, // 默认每页数量
        'max_page_size' => 100, // 最大每页数量
    ),
    
    // 上传配置
    'upload' => array(
        'path' => './uploads/',
        'max_size' => 5 * 1024 * 1024, // 5MB
        'allowed_types' => array('jpg', 'jpeg', 'png', 'gif'),
    ),
    
    // 错误日志
    'log' => array(
        'enable' => true,
        'path' => './logs/',
        'level' => 'error', // debug, info, error
    ),
);
