<?php
/**
 * API统一入口
 * 访问: http://127.0.0.3/api.php?module=hospital&action=getList
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// OPTIONS预检直接返回
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 自动加载核心类
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Log.php';

// 获取请求参数
$module = isset($_GET['module']) ? trim($_GET['module']) : '';
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// 路由映射
$routes = array(
    'wechat' => 'api/wechat.php',
    'hospital' => 'api/hospital.php',
    'department' => 'api/department.php',
    'doctor' => 'api/doctor.php',
    'reservation' => 'api/reservation.php',
    'stats' => 'api/stats.php'
);

// 检查模块是否存在
if (!isset($routes[$module])) {
    Response::error('接口模块不存在，可用模块: ' . implode(', ', array_keys($routes)), 404);
}

// 检查API文件是否存在
$apiFile = __DIR__ . '/' . $routes[$module];
if (!file_exists($apiFile)) {
    Response::error('接口文件不存在: ' . $routes[$module], 404);
}

// 执行API（包裹try-catch捕获所有异常）
try {
    require_once $apiFile;
} catch (PDOException $e) {
    Response::error('数据库错误: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    Response::error('系统错误: ' . $e->getMessage(), 500);
}
