<?php
/**
 * CRM后台管理系统 - 统一入口
 * 访问: http://127.0.0.3/admin.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1); // 开发阶段开启错误显示
date_default_timezone_set('Asia/Shanghai');

define('CRM_ADMIN', true);

// 全局异常处理 - 防止500白屏
set_exception_handler(function($e) {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    
    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'code' => 500,
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ), JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo '<div style="padding:30px;font-family:monospace;background:#fff5f5;border:2px solid #e53e3e;border-radius:8px;max-width:900px;margin:40px auto;">';
        echo '<h2 style="color:#e53e3e;margin:0 0 12px;">系统错误</h2>';
        echo '<p style="color:#742a2a;font-size:14px;margin:0 0 12px;">' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p style="color:#999;font-size:12px;">文件: ' . htmlspecialchars($e->getFile()) . ' 第 ' . $e->getLine() . ' 行</p>';
        echo '<pre style="background:#f7f7f7;padding:12px;border-radius:4px;font-size:12px;overflow-x:auto;max-height:300px;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        echo '</div>';
    }
    exit;
});

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Log.php';
require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/template.php';

/**
 * 记录管理员操作日志（全局函数）
 */
function addLog($action, $targetType = '', $targetId = 0, $content = '') {
    try {
        $db = Database::getInstance();
        $admin = AdminAuth::getAdmin();
        $db->insert('admin_log', array(
            'admin_id' => $admin ? intval($admin['id']) : 0,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => intval($targetId),
            'content' => $content,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            'addtime' => time()
        ));
    } catch(Exception $e) {
        // 日志写入失败不影响主流程
    }
}

// 获取模块和动作
$module = isset($_GET['module']) ? trim($_GET['module']) : 'index';
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// 登录页面不需要验证
if ($module === 'auth') {
    $auth = new AdminAuth();
    if ($action === 'login') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = isset($_POST['username']) ? trim($_POST['username']) : '';
            $password = isset($_POST['password']) ? trim($_POST['password']) : '';
            $result = $auth->login($username, $password);
            if ($result['success']) {
                header('Location: admin.php?module=index');
                exit;
            }
            $loginError = $result['message'];
        }
        renderLoginPage(isset($loginError) ? $loginError : '');
        exit;
    }
    if ($action === 'logout') {
        $auth->logout();
        header('Location: admin.php?module=auth&action=login');
        exit;
    }
}

// 其他页面需要登录验证
AdminAuth::requireLogin();

// 路由映射
$routeMap = array(
    'index' => 'admin/index.php',
    'user' => 'admin/users.php',
    'reservation' => 'admin/reservations.php',
    'patient' => 'admin/patients.php',
    'hospital' => 'admin/hospitals.php',
    'department' => 'admin/departments.php',
    'doctor' => 'admin/doctors.php',
    'follow_up' => 'admin/follow_up.php',
    'custom_field' => 'admin/custom_fields.php',
    'import' => 'admin/import.php',
    'export' => 'admin/export.php',
    'stats' => 'admin/stats.php',
    'log' => 'admin/logs.php',
    'settings' => 'admin/settings.php',
    'admin_user' => 'admin/admin_users.php'
);

// 通用API：按手机号搜索患者信息
if ($module === 'api_search_patient' && isset($_GET['phone'])) {
    header('Content-Type: application/json');
    $phone = trim($_GET['phone']);
    $prefix = Database::getConfig()['db']['prefix'];
    $db = Database::getInstance();
    try {
        $row = $db->query(
            "SELECT patient_phone, patient_name FROM {$prefix}reservation WHERE patient_phone=? LIMIT 1",
            array($phone)
        )->fetch();
        echo json_encode(array('code' => 200, 'data' => $row ?: null));
    } catch(PDOException $e) {
        echo json_encode(array('code' => 500, 'data' => null));
    }
    exit;
}

// 通用API：获取指定表的自定义字段
if ($module === 'api_custom_fields' && isset($_GET['table'])) {
    header('Content-Type: application/json');
    $targetTable = trim($_GET['table']);
    $prefix = Database::getConfig()['db']['prefix'];
    $db = Database::getInstance();
    try {
        $fields = $db->query(
            "SELECT * FROM {$prefix}custom_field WHERE target_table=? AND status=1 ORDER BY sort DESC, id ASC",
            array($targetTable)
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array('code' => 200, 'data' => $fields));
    } catch(PDOException $e) {
        echo json_encode(array('code' => 500, 'data' => array()));
    }
    exit;
}

// 通用API：获取自定义字段值
if ($module === 'api_custom_field_values' && isset($_GET['table']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $targetTable = trim($_GET['table']);
    $targetId = intval($_GET['id']);
    $prefix = Database::getConfig()['db']['prefix'];
    $db = Database::getInstance();
    try {
        $rows = $db->query(
            "SELECT fv.field_id, fv.field_value, cf.field_key, cf.field_name 
             FROM {$prefix}custom_field_value fv 
             INNER JOIN {$prefix}custom_field cf ON fv.field_id=cf.id 
             WHERE fv.target_table=? AND fv.target_id=?",
            array($targetTable, $targetId)
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array('code' => 200, 'data' => $rows));
    } catch(PDOException $e) {
        echo json_encode(array('code' => 500, 'data' => array()));
    }
    exit;
}

// 通用API：预约状态配置
if ($module === 'api_status_config') {
    header('Content-Type: application/json');
    $prefix = Database::getConfig()['db']['prefix'];
    $db = Database::getInstance();
    try {
        $row = $db->find('config', array('config_key' => 'reservation_status_config'));
        $statuses = $row ? json_decode($row['config_value'], true) : array();
        echo json_encode(array('code' => 200, 'data' => $statuses ?: array()));
    } catch(PDOException $e) {
        echo json_encode(array('code' => 500, 'data' => array()));
    }
    exit;
}

// 通用API：查重校验（按姓名/电话）
if ($module === 'api_check_duplicate') {
    header('Content-Type: application/json');
    $name = isset($_GET['name']) ? trim($_GET['name']) : '';
    $phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
    $excludeId = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : 0;
    $prefix = Database::getConfig()['db']['prefix'];
    $db = Database::getInstance();
    $results = array();
    try {
        $conditions = array();
        $params = array();
        if ($phone) {
            $conditions[] = "patient_phone = ?";
            $params[] = $phone;
        }
        if ($name && !$phone) {
            $conditions[] = "patient_name = ?";
            $params[] = $name;
        }
        if (empty($conditions)) {
            echo json_encode(array('code' => 200, 'data' => array()));
            exit;
        }
        $where = implode(' OR ', $conditions);
        $sql = "SELECT id, patient_name, patient_phone, status, reservation_date, hospital_id, addtime FROM {$prefix}reservation WHERE {$where}";
        if ($excludeId > 0) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $sql .= " ORDER BY addtime DESC LIMIT 5";
        $results = $db->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {}
    echo json_encode(array('code' => 200, 'data' => $results));
    exit;
}

// 通用API：患者文件上传
if ($module === 'api_patient_file' && $action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $prefix = Database::getConfig()['db']['prefix'];
    $db = Database::getInstance();
    $admin = AdminAuth::getAdmin();
    
    $patientPhone = isset($_POST['patient_phone']) ? trim($_POST['patient_phone']) : '';
    $reservationId = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    
    if (!$patientPhone) { echo json_encode(array('code'=>400,'message'=>'缺少患者手机号')); exit; }
    if (empty($_FILES['file'])) { echo json_encode(array('code'=>400,'message'=>'请选择文件')); exit; }
    
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(array('code'=>400,'message'=>'文件上传失败')); exit; }
    
    // 限制文件大小 20MB
    if ($file['size'] > 20 * 1024 * 1024) { echo json_encode(array('code'=>400,'message'=>'文件大小不能超过20MB')); exit; }
    
    // 允许的文件类型
    $allowedExts = array('jpg','jpeg','png','gif','bmp','webp','pdf','doc','docx','xls','xlsx');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts)) { echo json_encode(array('code'=>400,'message'=>'不支持的文件类型，仅支持图片/PDF/Word/Excel')); exit; }
    
    // 安全存储：使用随机文件名
    $uploadDir = __DIR__ . '/uploads/patient_files/';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
    $safeName = md5($file['name'] . time() . mt_rand(1000,9999)) . '.' . $ext;
    $filePath = 'patient_files/' . $safeName;
    $fullPath = $uploadDir . $safeName;
    
    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        echo json_encode(array('code'=>500,'message'=>'文件保存失败')); exit;
    }
    
    try {
        $id = $db->insert('patient_file', array(
            'patient_phone' => $patientPhone,
            'reservation_id' => $reservationId,
            'file_name' => $file['name'],
            'file_path' => $filePath,
            'file_type' => $file['type'],
            'file_size' => intval($file['size']),
            'file_ext' => $ext,
            'description' => $description,
            'admin_id' => $admin ? intval($admin['id']) : 0,
            'addtime' => time()
        ));
        addLog('upload', 'patient_file', $id, "上传文件:{$file['name']}, 患者:{$patientPhone}");
        echo json_encode(array('code'=>200,'message'=>'上传成功','data'=>array('id'=>$id,'file_name'=>$file['name'])));
    } catch(PDOException $e) {
        @unlink($fullPath);
        echo json_encode(array('code'=>500,'message'=>'保存记录失败'));
    }
    exit;
}

// 通用API：患者文件列表
if ($module === 'api_patient_file' && $action === 'list') {
    header('Content-Type: application/json');
    $phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
    if (!$phone) { echo json_encode(array('code'=>400,'data'=>array())); exit; }
    $prefix = Database::getConfig()['db']['prefix'];
    $db = Database::getInstance();
    try {
        $files = $db->query(
            "SELECT * FROM {$prefix}patient_file WHERE patient_phone=? ORDER BY addtime DESC",
            array($phone)
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array('code'=>200,'data'=>$files));
    } catch(PDOException $e) {
        echo json_encode(array('code'=>500,'data'=>array()));
    }
    exit;
}

// 通用API：删除患者文件
if ($module === 'api_patient_file' && $action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? intval($input['id']) : 0;
    if (!$id) { echo json_encode(array('code'=>400,'message'=>'参数错误')); exit; }
    $prefix = Database::getConfig()['db']['prefix'];
    $db = Database::getInstance();
    try {
        $file = $db->find('patient_file', array('id' => $id));
        if ($file) {
            $fullPath = __DIR__ . '/uploads/' . $file['file_path'];
            if (file_exists($fullPath)) { @unlink($fullPath); }
            $db->delete('patient_file', array('id' => $id));
            addLog('delete', 'patient_file', $id, "删除文件:{$file['file_name']}");
        }
        echo json_encode(array('code'=>200,'message'=>'已删除'));
    } catch(PDOException $e) {
        echo json_encode(array('code'=>500,'message'=>'删除失败'));
    }
    exit;
}

// 通用API：安全下载患者文件（需要管理员登录）
if ($module === 'api_patient_file' && $action === 'download') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id) { http_response_code(404); exit; }
    $prefix = Database::getConfig()['db']['prefix'];
    $db = Database::getInstance();
    try {
        $file = $db->find('patient_file', array('id' => $id));
        if (!$file) { http_response_code(404); exit; }
        $fullPath = __DIR__ . '/uploads/' . $file['file_path'];
        if (!file_exists($fullPath)) { http_response_code(404); exit; }
        
        $imageExts = array('jpg','jpeg','png','gif','bmp','webp');
        if (in_array($file['file_ext'], $imageExts)) {
            header('Content-Type: ' . $file['file_type']);
            header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file['file_name']) . '"');
        }
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
    } catch(PDOException $e) { http_response_code(500); }
    exit;
}

// 通用API：前台患者文件下载（token验证，无需管理员登录）
if ($module === 'api_patient_file' && $action === 'view') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $token = isset($_GET['token']) ? trim($_GET['token']) : '';
    if (!$id || !$token) { http_response_code(403); echo 'Access Denied'; exit; }
    
    // 验证token（token = md5(file_id + patient_phone + 密钥)）
    $prefix = Database::getConfig()['db']['prefix'];
    $db = Database::getInstance();
    $secretKey = 'crm_patient_query_2026';
    try {
        $file = $db->find('patient_file', array('id' => $id));
        if (!$file) { http_response_code(404); exit; }
        $expectedToken = md5($id . '_' . $file['patient_phone'] . '_' . $secretKey);
        if ($token !== $expectedToken) { http_response_code(403); echo 'Token Invalid'; exit; }
        
        $fullPath = __DIR__ . '/uploads/' . $file['file_path'];
        if (!file_exists($fullPath)) { http_response_code(404); exit; }
        
        $imageExts = array('jpg','jpeg','png','gif','bmp','webp');
        if (in_array($file['file_ext'], $imageExts)) {
            header('Content-Type: ' . $file['file_type']);
            header('Content-Disposition: inline; filename="' . basename($file['file_name']) . '"');
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file['file_name']) . '"');
        }
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
    } catch(PDOException $e) { http_response_code(500); }
    exit;
}

// 默认跳转仪表盘
if ($module === '' || !isset($routeMap[$module])) {
    $module = 'index';
}

$file = __DIR__ . '/' . $routeMap[$module];
if (file_exists($file)) {
    require_once $file;
} else {
    echo '<div style="padding:40px;text-align:center"><h2>页面不存在</h2><p>模块文件缺失: ' . htmlspecialchars($routeMap[$module]) . '</p></div>';
}

/**
 * 渲染登录页面
 */
function renderLoginPage($error = '') {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - CRM后台管理</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; }
        .login-page { display:flex; align-items:center; justify-content:center; min-height:100vh; background:linear-gradient(135deg,#1d4ed8 0%,#3b82f6 50%,#60a5fa 100%); }
        .login-box { background:#fff; border-radius:12px; padding:40px; width:400px; max-width:90vw; box-shadow:0 20px 60px rgba(0,0,0,.3); }
        .login-box h1 { text-align:center; font-size:24px; color:#1d4ed8; margin-bottom:8px; }
        .login-box p { text-align:center; color:#6b7280; margin-bottom:30px; font-size:14px; }
        .form-group { margin-bottom:20px; }
        .form-group label { display:block; margin-bottom:6px; font-weight:500; color:#374151; font-size:14px; }
        .form-group input { width:100%; padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; transition:border .2s; }
        .form-group input:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
        .btn-login { width:100%; padding:12px; background:#1d4ed8; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer; }
        .btn-login:hover { background:#1e40af; }
        .login-error { background:#fee2e2; color:#991b1b; padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:13px; <?php echo $error ? '' : 'display:none;'; ?> }
        .login-tip { text-align:center; margin-top:20px; font-size:12px; color:#9ca3af; }
    </style>
</head>
<body>
    <div class="login-page">
        <div class="login-box">
            <h1>CRM管理系统</h1>
            <p>医院预约后台管理平台</p>
            <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
            <form method="post" action="admin.php?module=auth&action=login">
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" name="username" placeholder="请输入用户名" value="admin" required>
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" name="password" placeholder="请输入密码" value="admin123" required>
                </div>
                <button type="submit" class="btn-login">登 录</button>
            </form>
            <div class="login-tip">默认账号: admin / admin123</div>
        </div>
    </div>
</body>
</html>
<?php
}
