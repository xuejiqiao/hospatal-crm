<?php
/**
 * 患者查询页面 - 前台公开访问
 * 安全设计：所有查询使用POST提交，URL不暴露任何患者信息
 * 文件访问使用一次性token验证
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/core/Database.php';

$secretKey = 'crm_patient_query_2026';
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];

// 获取预约状态配置
$statusConfig = array();
try {
    $cf = $db->find('config', array('config_key' => 'reservation_status_config'));
    if ($cf) { $statusConfig = json_decode($cf['config_value'], true); }
} catch(PDOException $e) {}
if (empty($statusConfig)) {
    $statusConfig = array(
        array('name' => '待确认', 'color' => '#92400e', 'bg' => '#fef3c7'),
        array('name' => '已预约', 'color' => '#92400e', 'bg' => '#fef3c7'),
        array('name' => '已寄送', 'color' => '#1f2937', 'bg' => '#f3f4f6'),
        array('name' => '已成单', 'color' => '#991b1b', 'bg' => '#fee2e2'),
        array('name' => '已取消', 'color' => '#6b7280', 'bg' => '#f3f4f6')
    );
}
$statusColorMap = array();
foreach ($statusConfig as $sc) { $statusColorMap[$sc['name']] = $sc; }

// 生成文件访问token
function makeFileToken($fileId, $phone, $secret) {
    return md5($fileId . '_' . $phone . '_' . $secret);
}

// 查询结果变量
$queryResult = null;
$queryError = '';
$patientToken = '';
$queryTime = 0;

// 处理查询请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 防止暴力破解：简单频率限制（同一IP 60秒内最多查10次）
    session_start();
    $now = time();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $rateKey = 'query_rate_' . md5($ip);
    $rateData = isset($_SESSION[$rateKey]) ? $_SESSION[$rateKey] : array('count' => 0, 'start' => $now);
    if ($now - $rateData['start'] > 60) { $rateData = array('count' => 0, 'start' => $now); }
    $rateData['count']++;
    $_SESSION[$rateKey] = $rateData;
    if ($rateData['count'] > 10) {
        $queryError = '查询过于频繁，请稍后再试';
    }
    
    if (!$queryError) {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $idcard = isset($_POST['idcard']) ? trim($_POST['idcard']) : '';
        
        // 验证：姓名+手机号 或 姓名+身份证号 二选一
        if (empty($name)) {
            $queryError = '请输入姓名';
        } elseif (empty($phone) && empty($idcard)) {
            $queryError = '请输入手机号或身份证号（至少一项）';
        } else {
            // 构建查询条件
            $conditions = array("patient_name = ?");
            $params = array($name);
            
            if (!empty($phone) && !empty($idcard)) {
                $conditions[] = "(patient_phone = ? OR patient_idcard = ?)";
                $params[] = $phone;
                $params[] = $idcard;
            } elseif (!empty($phone)) {
                $conditions[] = "patient_phone = ?";
                $params[] = $phone;
            } else {
                $conditions[] = "patient_idcard = ?";
                $params[] = $idcard;
            }
            
            $where = implode(' AND ', $conditions);
            
            try {
                $info = $db->query(
                    "SELECT patient_phone, patient_name, COUNT(*) as total_count,
                     SUM(CASE WHEN status='已成单' THEN 1 ELSE 0 END) as order_count,
                     MIN(addtime) as first_time, MAX(addtime) as last_time
                     FROM {$prefix}reservation WHERE {$where}
                     GROUP BY patient_phone, patient_name LIMIT 1",
                    $params
                )->fetch();
                
                if (!$info) {
                    $queryError = '未查询到有效客户，请联系客服';
                } elseif (intval($info['order_count']) <= 0) {
                    // 没有成单记录，不允许查询
                    $queryError = '未查询到有效客户，请联系客服';
                } else {
                    $realPhone = $info['patient_phone'];
                    $patientToken = md5($realPhone . '_' . $info['patient_name'] . '_' . $secretKey . '_' . date('Ymd'));
                    
                    // 查询预约历史
                    $reservations = $db->query(
                        "SELECT r.*, h.name as hospital_name FROM {$prefix}reservation r 
                         LEFT JOIN {$prefix}hospital h ON r.hospital_id=h.id 
                         WHERE r.patient_phone=? ORDER BY r.addtime DESC LIMIT 20",
                        array($realPhone)
                    )->fetchAll(PDO::FETCH_ASSOC);
                    
                    // 查询文件
                    $files = array();
                    try {
                        $files = $db->query(
                            "SELECT id, file_name, file_ext, file_size, description, addtime 
                             FROM {$prefix}patient_file WHERE patient_phone=? ORDER BY addtime DESC",
                            array($realPhone)
                        )->fetchAll(PDO::FETCH_ASSOC);
                        // 为每个文件生成访问token
                        foreach ($files as &$f) {
                            $f['token'] = makeFileToken($f['id'], $realPhone, $secretKey);
                        }
                        unset($f);
                    } catch(PDOException $e) {}
                    
                    $queryResult = array(
                        'info' => $info,
                        'reservations' => $reservations,
                        'files' => $files
                    );
                    
                    // 记录查询时间，用于5分钟过期校验
                    $_SESSION['query_expire_time'] = time() + 300; // 5分钟后过期
                    $_SESSION['query_phone_hash'] = md5($realPhone);
                    $queryTime = time();
                }
            } catch(PDOException $e) {
                $queryError = '查询失败，请稍后再试';
            }
        }
    }
}

// 服务端过期校验：如果session中的查询已过期，则清除结果
if ($queryResult && isset($_SESSION['query_expire_time'])) {
    if (time() > $_SESSION['query_expire_time']) {
        $queryResult = null;
        unset($_SESSION['query_expire_time']);
        unset($_SESSION['query_phone_hash']);
        $queryError = '查询结果已过期，请重新查询';
    } else {
        $queryTime = time() - ($_SESSION['query_expire_time'] - 300);
    }
}

// 手机号脱敏
function maskPhone($phone) {
    if (strlen($phone) >= 11) {
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }
    return $phone;
}

// 身份证脱敏
function maskIdcard($idcard) {
    if (strlen($idcard) >= 15) {
        return substr($idcard, 0, 4) . '**********' . substr($idcard, -4);
    }
    return $idcard;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>患者信息查询</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif; background:#f0f4f8; min-height:100vh; }
        .container { max-width:800px; margin:0 auto; padding:20px; }
        .header { text-align:center; padding:30px 0 20px; }
        .header h1 { font-size:24px; color:#1d4ed8; }
        .header p { font-size:14px; color:#6b7280; margin-top:6px; }
        .card { background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:24px; margin-bottom:16px; }
        .form-group { margin-bottom:16px; }
        .form-group label { display:block; font-size:13px; font-weight:500; color:#374151; margin-bottom:6px; }
        .form-group input { width:100%; padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; }
        .form-group input:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.12); }
        .or-divider { text-align:center; color:#9ca3af; font-size:12px; margin:12px 0; position:relative; }
        .or-divider::before,.or-divider::after { content:''; position:absolute; top:50%; width:calc(50% - 20px); height:1px; background:#e5e7eb; }
        .or-divider::before { left:0; }
        .or-divider::after { right:0; }
        .btn { display:block; width:100%; padding:12px; background:#1d4ed8; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer; }
        .btn:hover { background:#1e40af; }
        .error-msg { background:#fee2e2; color:#991b1b; padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:16px; }
        .info-row { display:grid; grid-template-columns:1fr 1fr; gap:8px 20px; font-size:13px; margin-bottom:12px; }
        .info-item { padding:8px 12px; background:#f9fafb; border-radius:6px; }
        .info-item .label { color:#9ca3af; font-size:11px; }
        .info-item .value { color:#111; margin-top:2px; font-weight:500; }
        .status-badge { display:inline-block; padding:2px 10px; border-radius:10px; font-size:12px; font-weight:500; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th { text-align:left; padding:10px 8px; border-bottom:2px solid #e5e7eb; color:#6b7280; font-weight:500; font-size:12px; }
        td { padding:10px 8px; border-bottom:1px solid #f3f4f6; }
        .file-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; }
        .file-card { border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
        .file-card img { width:100%; height:100px; object-fit:cover; cursor:pointer; }
        .file-card .file-icon { height:100px; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#f9fafb; cursor:pointer; }
        .file-card .file-icon .icon { font-size:32px; }
        .file-card .file-icon .text { font-size:11px; color:#6b7280; margin-top:4px; }
        .file-info { padding:6px 8px; }
        .file-info .name { font-size:12px; color:#111; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .file-info .meta { font-size:10px; color:#9ca3af; margin-top:2px; }
        .section-title { font-size:15px; font-weight:600; color:#1d4ed8; padding-bottom:8px; border-bottom:2px solid #dbeafe; margin-bottom:12px; }
        .privacy-note { font-size:11px; color:#9ca3af; text-align:center; margin-top:16px; line-height:1.6; }
        .back-link { display:inline-block; margin-bottom:16px; color:#3b82f6; text-decoration:none; font-size:14px; }
        .back-link:hover { text-decoration:underline; }
        .image-preview { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.8); display:none; align-items:center; justify-content:center; z-index:999; cursor:pointer; }
        .image-preview img { max-width:90vw; max-height:90vh; border-radius:8px; }
        .image-preview .close { position:absolute; top:20px; right:20px; color:#fff; font-size:30px; cursor:pointer; }
        .expire-bar { background:linear-gradient(135deg,#1d4ed8,#3b82f6); color:#fff; border-radius:12px; padding:14px 20px; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; }
        .expire-bar .expire-text { font-size:13px; opacity:.9; }
        .expire-bar .expire-timer { font-size:22px; font-weight:700; font-variant-numeric:tabular-nums; letter-spacing:1px; }
        .expire-bar .expire-timer.urgent { color:#fbbf24; animation:blink 1s infinite; }
        @keyframes blink { 50% { opacity:.5; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>患者信息查询</h1>
            <p>请输入您的信息进行查询</p>
        </div>
        
        <?php if (!$queryResult): ?>
        <!-- 查询表单 -->
        <div class="card">
            <?php if ($queryError): ?>
            <div class="error-msg"><?php echo htmlspecialchars($queryError); ?></div>
            <?php endif; ?>
            <form method="post" action="query.php" autocomplete="off">
                <div class="form-group">
                    <label>患者姓名 *</label>
                    <input type="text" name="name" placeholder="请输入姓名" required value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label>手机号</label>
                    <input type="tel" name="phone" placeholder="请输入手机号" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                </div>
                <div class="or-divider">或</div>
                <div class="form-group">
                    <label>身份证号</label>
                    <input type="text" name="idcard" placeholder="请输入身份证号" value="<?php echo isset($_POST['idcard']) ? htmlspecialchars($_POST['idcard']) : ''; ?>">
                </div>
                <button type="submit" class="btn">查 询</button>
            </form>
            <div class="privacy-note">
                您的个人信息将被严格保密，仅用于身份验证查询<br>
                手机号或身份证号只需填写一项即可
            </div>
        </div>
        <?php else: ?>
        <!-- 查询结果 -->
        <div class="expire-bar">
            <span class="expire-text">查询结果有效期剩余</span>
            <span class="expire-timer" id="expireTimer">05:00</span>
        </div>
        <a href="query.php" class="back-link">&larr; 重新查询</a>
        
        <div class="card">
            <div class="section-title">基本信息</div>
            <div class="info-row">
                <div class="info-item"><div class="label">姓名</div><div class="value"><?php echo htmlspecialchars($queryResult['info']['patient_name']); ?></div></div>
                <div class="info-item"><div class="label">手机号</div><div class="value"><?php echo maskPhone($queryResult['info']['patient_phone']); ?></div></div>
                <div class="info-item"><div class="label">总预约次数</div><div class="value"><?php echo $queryResult['info']['total_count']; ?> 次</div></div>
                <div class="info-item"><div class="label">首次预约</div><div class="value"><?php echo $queryResult['info']['first_time'] ? date('Y-m-d', $queryResult['info']['first_time']) : '-'; ?></div></div>
            </div>
        </div>
        
        <?php if (!empty($queryResult['reservations'])): ?>
        <div class="card">
            <div class="section-title">预约记录</div>
            <div style="overflow-x:auto;">
                <table>
                    <thead><tr><th>日期</th><th>医院</th><th>科室</th><th>医生</th><th>状态</th></tr></thead>
                    <tbody>
                    <?php foreach($queryResult['reservations'] as $r):
                        $sc = isset($statusColorMap[$r['status']]) ? $statusColorMap[$r['status']] : null;
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($r['reservation_date']); ?></strong><br><span style="font-size:11px;color:#9ca3af;"><?php echo htmlspecialchars($r['time_period']); ?></span></td>
                        <td><?php echo htmlspecialchars($r['hospital_name'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($r['department'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($r['doctor'] ?: '-'); ?></td>
                        <td>
                            <?php if($sc): ?>
                            <span class="status-badge" style="color:<?php echo htmlspecialchars($sc['color']); ?>;background:<?php echo htmlspecialchars($sc['bg']); ?>;"><?php echo htmlspecialchars($r['status']); ?></span>
                            <?php else: echo htmlspecialchars($r['status']); endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($queryResult['files'])): ?>
        <div class="card">
            <div class="section-title">报告文件</div>
            <div class="file-grid">
                <?php foreach($queryResult['files'] as $f):
                    $isImage = in_array($f['file_ext'], array('jpg','jpeg','png','gif','bmp','webp'));
                    $fileSize = $f['file_size'] > 1048576 ? round($f['file_size']/1048576,1).'MB' : round($f['file_size']/1024,1).'KB';
                    $fileUrl = 'admin.php?module=api_patient_file&action=view&id=' . $f['id'] . '&token=' . $f['token'];
                    $icon = $isImage ? '🖼' : ($f['file_ext']==='pdf' ? '📄' : '📝');
                ?>
                <div class="file-card">
                    <?php if($isImage): ?>
                    <img src="<?php echo htmlspecialchars($fileUrl); ?>" onclick="previewImg('<?php echo htmlspecialchars($fileUrl); ?>')" alt="<?php echo htmlspecialchars($f['file_name']); ?>">
                    <?php else: ?>
                    <a href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank" style="text-decoration:none;">
                        <div class="file-icon"><div class="icon"><?php echo $icon; ?></div><div class="text">点击查看/下载</div></div>
                    </a>
                    <?php endif; ?>
                    <div class="file-info">
                        <div class="name" title="<?php echo htmlspecialchars($f['file_name']); ?>"><?php echo htmlspecialchars($f['file_name']); ?></div>
                        <div class="meta"><?php echo $fileSize; ?> · <?php echo date('Y-m-d', $f['addtime']); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="privacy-note">
            为保护您的隐私，查询结果将在5分钟后自动失效，届时需重新验证查询<br>
            页面关闭后查询结果将不再显示，请妥善保管个人信息
        </div>
        <?php endif; ?>
    </div>
    
    <div class="image-preview" id="imagePreview" onclick="this.style.display='none'">
        <span class="close">&times;</span>
        <img id="previewImg" src="">
    </div>
    
    <script>
    // 5分钟倒计时（300秒）
    var remainSeconds = <?php echo max(0, (isset($_SESSION['query_expire_time']) ? $_SESSION['query_expire_time'] - time() : 300)); ?>;
    var timerEl = document.getElementById('expireTimer');
    
    function updateTimer() {
        if (remainSeconds <= 0) {
            // 已过期，跳转回查询页
            window.location.href = 'query.php';
            return;
        }
        var min = Math.floor(remainSeconds / 60);
        var sec = remainSeconds % 60;
        timerEl.textContent = String(min).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
        // 最后60秒变黄色闪烁警告
        if (remainSeconds <= 60) {
            timerEl.classList.add('urgent');
        }
        remainSeconds--;
        setTimeout(updateTimer, 1000);
    }
    updateTimer();
    
    function previewImg(url) {
        document.getElementById('previewImg').src = url;
        document.getElementById('imagePreview').style.display = 'flex';
    }
    // 禁止右键保存
    document.addEventListener('contextmenu', function(e) {
        if (e.target.tagName === 'IMG') e.preventDefault();
    });
    </script>
</body>
</html>
