<?php
/**
 * 后台首页 - 仪表盘（增强版）
 */
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

// 统计数据
$todayStart = strtotime(date('Y-m-d 00:00:00'));
$todayEnd = strtotime(date('Y-m-d 23:59:59'));
$monthStart = strtotime(date('Y-m-01 00:00:00'));

$todayReservations = $db->count('reservation', array('addtime' => array('BETWEEN', "{$todayStart},{$todayEnd}")));
$monthReservations = $db->count('reservation', array('addtime' => array('BETWEEN', "{$monthStart}," . time())));
$totalReservations = $db->count('reservation');
$totalUsers = $db->count('user');
$totalHospitals = $db->count('hospital', array('status' => 1));

// 动态获取各状态预约数量
$statusCounts = array();
foreach ($statusConfig as $sc) {
    try {
        $cnt = $db->count('reservation', array('status' => $sc['name']));
        $statusCounts[$sc['name']] = $cnt;
    } catch(PDOException $e) {
        $statusCounts[$sc['name']] = 0;
    }
}

// 患者统计
try {
    $totalPatients = $db->query("SELECT COUNT(DISTINCT patient_phone) as cnt FROM {$prefix}reservation")->fetch()['cnt'];
} catch(PDOException $e) { $totalPatients = 0; }

// 随访统计
try {
    $totalFollowUps = $db->count('follow_up');
    $todayFollowUps = $db->count('follow_up', array('addtime' => array('BETWEEN', "{$todayStart},{$todayEnd}")));
} catch(PDOException $e) { $totalFollowUps = 0; $todayFollowUps = 0; }

// 待随访提醒（有next_date且日期<=今天）
try {
    $today = date('Y-m-d');
    $pendingFollowUps = $db->query(
        "SELECT f.patient_phone, f.next_date, MAX(f.patient_name) as patient_name 
         FROM {$prefix}follow_up f 
         WHERE f.next_date <= ? AND f.next_date != '' 
         GROUP BY f.patient_phone, f.next_date 
         ORDER BY f.next_date ASC LIMIT 5",
        array($today)
    )->fetchAll();
} catch(PDOException $e) { $pendingFollowUps = array(); }

// 最近预约
$recentReservations = $db->query(
    "SELECT r.*, h.name as hospital_name FROM {$prefix}reservation r LEFT JOIN {$prefix}hospital h ON r.hospital_id = h.id ORDER BY r.addtime DESC LIMIT 8"
)->fetchAll();

// 最近注册用户
$recentUsers = $db->select('user', array(), '*', 'addtime DESC', '5');

// 预约趋势(最近7天)
$trendData = array();
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $start = strtotime($date . ' 00:00:00');
    $end = strtotime($date . ' 23:59:59');
    $count = $db->count('reservation', array('addtime' => array('BETWEEN', "{$start},{$end}")));
    $trendData[] = array('date' => substr($date, 5), 'count' => $count);
}

// 找到"待确认"状态名用于待处理提醒
$pendingStatusName = '待确认';
foreach ($statusConfig as $sc) {
    if (strpos($sc['name'], '待') !== false || strpos($sc['name'], '确认') !== false) {
        $pendingStatusName = $sc['name'];
        break;
    }
}
$pendingReservations = isset($statusCounts[$pendingStatusName]) ? $statusCounts[$pendingStatusName] : 0;

ob_start();
?>
<div class="stat-cards">
    <div class="stat-card blue">
        <div class="label">今日预约</div>
        <div class="value"><?php echo $todayReservations; ?></div>
        <div class="sub">本月: <?php echo $monthReservations; ?></div>
    </div>
    <div class="stat-card orange">
        <div class="label">待处理预约</div>
        <div class="value"><?php echo $pendingReservations; ?></div>
        <div class="sub"><?php echo $pendingStatusName; ?></div>
    </div>
    <div class="stat-card green">
        <div class="label">患者总数</div>
        <div class="value"><?php echo $totalPatients; ?></div>
        <div class="sub">总预约: <?php echo $totalReservations; ?></div>
    </div>
    <div class="stat-card red">
        <div class="label">随访记录</div>
        <div class="value"><?php echo $totalFollowUps; ?></div>
        <div class="sub">今日: <?php echo $todayFollowUps; ?></div>
    </div>
</div>

<!-- 各状态预约统计 -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-body" style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
        <?php foreach($statusConfig as $sc): ?>
        <div style="text-align:center;padding:12px 20px;background:<?php echo htmlspecialchars($sc['bg']); ?>;border-radius:8px;min-width:100px;">
            <div style="font-size:22px;font-weight:700;color:<?php echo htmlspecialchars($sc['color']); ?>;"><?php echo isset($statusCounts[$sc['name']]) ? $statusCounts[$sc['name']] : 0; ?></div>
            <div style="font-size:12px;color:<?php echo htmlspecialchars($sc['color']); ?>;"><?php echo htmlspecialchars($sc['name']); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;">
    <!-- 最近预约 -->
    <div class="card">
        <div class="card-header">
            <h3>最近预约</h3>
            <a href="admin.php?module=reservation" class="btn btn-outline btn-sm">查看全部</a>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="data-table">
                <thead>
                    <tr><th>患者</th><th>手机号</th><th>医院</th><th>预约日期</th><th>状态</th><th>操作</th></tr>
                </thead>
                <tbody>
                <?php if(empty($recentReservations)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:#9ca3af;">暂无预约数据</td></tr>
                <?php else: ?>
                    <?php foreach($recentReservations as $r):
                        $sc = isset($statusColorMap[$r['status']]) ? $statusColorMap[$r['status']] : null;
                        $rowBg = $sc ? $sc['bg'] : '';
                        $rowStyle = $rowBg ? ' style="background:'.$rowBg.';"' : '';
                    ?>
                    <tr<?php echo $rowStyle; ?>>
                        <td><?php echo htmlspecialchars($r['patient_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['patient_phone']); ?></td>
                        <td><?php echo htmlspecialchars($r['hospital_name']); ?></td>
                        <td><?php echo $r['reservation_date']; ?></td>
                        <td>
                            <?php if($sc): ?>
                            <span style="display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:500;color:<?php echo htmlspecialchars($sc['color']); ?>;background:<?php echo htmlspecialchars($sc['bg']); ?>;"><?php echo htmlspecialchars($r['status']); ?></span>
                            <?php else: ?>
                            <span class="badge badge-gray"><?php echo htmlspecialchars($r['status']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($r['status'] === $pendingStatusName): ?>
                            <a href="admin.php?module=reservation&status=<?php echo urlencode($pendingStatusName); ?>" class="btn btn-success btn-sm"><?php echo htmlspecialchars($pendingStatusName); ?></a>
                            <?php else: ?>
                            <a href="admin.php?module=patient&action=detail&phone=<?php echo urlencode($r['patient_phone']); ?>" class="btn btn-outline btn-sm">患者</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 右侧面板 -->
    <div>
        <!-- 待处理提醒 -->
        <?php if($pendingReservations > 0): ?>
        <div class="card" style="border-left:4px solid #f59e0b;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:36px;font-weight:700;color:#f59e0b;"><?php echo $pendingReservations; ?></div>
                <div style="color:#92400e;margin:8px 0;">条预约<?php echo htmlspecialchars($pendingStatusName); ?></div>
                <a href="admin.php?module=reservation&status=<?php echo urlencode($pendingStatusName); ?>" class="btn btn-warning btn-sm">立即处理</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- 随访提醒 -->
        <?php if(!empty($pendingFollowUps)): ?>
        <div class="card" style="border-left:4px solid #ef4444;">
            <div class="card-header"><h3 style="color:#ef4444;font-size:14px;">待随访提醒</h3></div>
            <div class="card-body" style="padding:0;">
                <table class="data-table">
                    <thead><tr><th>患者</th><th>日期</th></tr></thead>
                    <tbody>
                    <?php foreach($pendingFollowUps as $f): ?>
                    <tr>
                        <td><a href="admin.php?module=patient&action=detail&phone=<?php echo urlencode($f['patient_phone']); ?>" style="color:#1d4ed8;text-decoration:none;"><?php echo htmlspecialchars($f['patient_name'] ?: $f['patient_phone']); ?></a></td>
                        <td><span style="color:#ef4444;font-size:12px;"><?php echo $f['next_date']; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- 预约趋势 -->
        <div class="card">
            <div class="card-header"><h3>7日预约趋势</h3></div>
            <div class="card-body">
                <div style="height:160px;display:flex;align-items:flex-end;gap:8px;padding-top:10px;">
                    <?php $maxCount = max(1, max(array_column($trendData, 'count'))); ?>
                    <?php foreach($trendData as $t): ?>
                    <div style="flex:1;text-align:center;">
                        <div style="background:#3b82f6;border-radius:3px 3px 0 0;height:<?php echo max(4, ($t['count']/$maxCount)*120); ?>px;margin:0 auto;width:100%;transition:height .3s;" title="<?php echo $t['count']; ?>条"></div>
                        <div style="font-size:11px;color:#9ca3af;margin-top:4px;"><?php echo $t['date']; ?></div>
                        <div style="font-size:11px;font-weight:600;color:#1d4ed8;"><?php echo $t['count']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 快捷操作 -->
        <div class="card">
            <div class="card-header"><h3>快捷操作</h3></div>
            <div class="card-body" style="display:flex;flex-wrap:wrap;gap:8px;">
                <a href="admin.php?module=reservation" class="btn btn-primary btn-sm">预约管理</a>
                <a href="admin.php?module=follow_up" class="btn btn-info btn-sm" style="background:#3b82f6;color:#fff;">随访管理</a>
                <a href="admin.php?module=reservation&status=<?php echo urlencode($pendingStatusName); ?>" class="btn btn-warning btn-sm"><?php echo htmlspecialchars($pendingStatusName); ?>预约</a>
                <a href="admin.php?module=patient" class="btn btn-outline btn-sm">患者列表</a>
                <a href="admin.php?module=stats" class="btn btn-outline btn-sm">数据统计</a>
            </div>
        </div>
    </div>
</div>
<?php
renderAdmin('仪表盘', 'index', ob_get_clean());
