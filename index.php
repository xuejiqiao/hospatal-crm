<?php
/**
 * API统一入口
 * 路由分发器
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 设置字符集
header('Content-Type: text/html; charset=utf-8');

// 自动加载核心类
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Log.php';

// 获取请求路径
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptName = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));

// 移除basePath
$path = str_replace($scriptName, '', $requestUri);
$path = trim($path, '/');

// 移除可能的index.php前缀
if (strpos($path, 'index.php/') === 0) {
    $path = substr($path, 10);
}

// 解析路径
$pathParts = array_filter(explode('/', $path));
$pathParts = array_values($pathParts); // 重新索引

// 获取模块和动作
$module = isset($pathParts[0]) ? $pathParts[0] : '';
$action = isset($pathParts[1]) ? $pathParts[1] : '';

// 调试信息(开发环境可启用)
// var_dump(['path' => $path, 'pathParts' => $pathParts, 'module' => $module, 'action' => $action]);

// 路由映射
$routes = array(
    'wechat' => 'api/wechat.php',
    'hospital' => 'api/hospital.php',
    'reservation' => 'api/reservation.php',
    'stats' => 'api/stats.php'
);

// 检查模块是否存在
if (!isset($routes[$module])) {
    Response::error('接口模块不存在', 404);
}

// 检查API文件是否存在
$apiFile = __DIR__ . '/' . $routes[$module];
if (!file_exists($apiFile)) {
    Response::error('接口文件不存在', 404);
}

// 记录日志
Log::info("API请求: {$module}/{$action} | IP: " . $_SERVER['REMOTE_ADDR']);

// 包含API文件并执行
$_GET['action'] = $action;
require_once $apiFile;
