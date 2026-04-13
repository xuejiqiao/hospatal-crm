<?php
/**
 * 数据统计页面
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

// 预约状态分布 - 使用动态状态配置
$statusData = array();
foreach ($statusConfig as $sc) {
    $cnt = 0;
    try { $cnt = $db->count('reservation', array('status' => $sc['name'])); } catch(PDOException $e) {}
    $statusData[] = array('name' => $sc['name'], 'color' => $sc['color'], 'bg' => $sc['bg'], 'count' => $cnt);
}

// 已成单费用统计
$feeStats = array(
    'total_fee' => 0,
    'month_fee' => 0,
    'today_fee' => 0,
    'avg_fee' => 0,
    'count' => 0,
    'month_count' => 0
);
try {
    // 总已成单费用
    $feeRow = $db->query("SELECT COALESCE(SUM(fee),0) as total_fee, COUNT(*) as cnt FROM {$prefix}reservation WHERE status='已成单'")->fetch();
    $feeStats['total_fee'] = floatval($feeRow['total_fee']);
    $feeStats['count'] = intval($feeRow['cnt']);
    $feeStats['avg_fee'] = $feeStats['count'] > 0 ? round($feeStats['total_fee'] / $feeStats['count'], 2) : 0;
    
    // 本月已成单费用
    $monthStart = strtotime(date('Y-m-01 00:00:00'));
    $monthEnd = time();
    $monthFeeRow = $db->query(
        "SELECT COALESCE(SUM(fee),0) as month_fee, COUNT(*) as cnt FROM {$prefix}reservation WHERE status='已成单' AND addtime BETWEEN ? AND ?",
        array($monthStart, $monthEnd)
    )->fetch();
    $feeStats['month_fee'] = floatval($monthFeeRow['month_fee']);
    $feeStats['month_count'] = intval($monthFeeRow['cnt']);
    
    // 今日已成单费用
    $todayStart = strtotime(date('Y-m-d 00:00:00'));
    $todayEnd = strtotime(date('Y-m-d 23:59:59'));
    $todayFeeRow = $db->query(
        "SELECT COALESCE(SUM(fee),0) as today_fee FROM {$prefix}reservation WHERE status='已成单' AND addtime BETWEEN ? AND ?",
        array($todayStart, $todayEnd)
    )->fetch();
    $feeStats['today_fee'] = floatval($todayFeeRow['today_fee']);
} catch(PDOException $e) {}

// 已成单按月费用趋势（最近12个月）
$monthlyFeeTrend = array();
for ($i = 11; $i >= 0; $i--) {
    $mStart = strtotime(date('Y-m-01 00:00:00', strtotime("-{$i} months")));
    $mEnd = strtotime(date('Y-m-t 23:59:59', strtotime("-{$i} months")));
    try {
        $mRow = $db->query(
            "SELECT COALESCE(SUM(fee),0) as m_fee, COUNT(*) as m_cnt FROM {$prefix}reservation WHERE status='已成单' AND addtime BETWEEN ? AND ?",
            array($mStart, $mEnd)
        )->fetch();
        $monthlyFeeTrend[] = array(
            'month' => date('Y-m', $mStart),
            'fee' => floatval($mRow['m_fee']),
            'count' => intval($mRow['m_cnt'])
        );
    } catch(PDOException $e) {
        $monthlyFeeTrend[] = array('month' => date('Y-m', $mStart), 'fee' => 0, 'count' => 0);
    }
}
$maxMonthFee = max(1, max(array_column($monthlyFeeTrend, 'fee')));

// 已成单医院费用排名
$hospitalFeeRank = array();
try {
    $hospitalFeeRank = $db->query(
        "SELECT h.name, COUNT(r.id) as count, COALESCE(SUM(r.fee),0) as total_fee 
         FROM {$prefix}hospital h 
         INNER JOIN {$prefix}reservation r ON h.id=r.hospital_id AND r.status='已成单'
         WHERE h.status=1 
         GROUP BY h.id 
         ORDER BY total_fee DESC LIMIT 10"
    )->fetchAll();
} catch(PDOException $e) {}

// 医院预约排名
$hospitalRank = $db->query("SELECT h.name, COUNT(r.id) as count FROM {$prefix}hospital h LEFT JOIN {$prefix}reservation r ON h.id=r.hospital_id WHERE h.status=1 GROUP BY h.id ORDER BY count DESC LIMIT 10")->fetchAll();

// 最近30天趋势
$dailyTrend = array();
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $start = strtotime($date . ' 00:00:00');
    $end = strtotime($date . ' 23:59:59');
    $count = $db->count('reservation', array('addtime' => array('BETWEEN', "{$start},{$end}")));
    $dailyTrend[] = array('date' => substr($date, 5), 'count' => $count);
}
$maxDaily = max(1, max(array_column($dailyTrend, 'count')));

// 时段分布
$timeDistribution = $db->query("SELECT time_period, COUNT(*) as count FROM {$prefix}reservation GROUP BY time_period")->fetchAll();

ob_start();
?>
<!-- 已成单费用概览 -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h3>已成单费用统计</h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
            <div style="text-align:center;padding:20px;background:linear-gradient(135deg,#059669,#10b981);border-radius:10px;color:#fff;">
                <div style="font-size:12px;opacity:.85;">总成单金额</div>
                <div style="font-size:26px;font-weight:700;margin-top:4px;">¥<?php echo number_format($feeStats['total_fee'], 2); ?></div>
                <div style="font-size:11px;opacity:.7;margin-top:4px;"><?php echo $feeStats['count']; ?> 笔成单</div>
            </div>
            <div style="text-align:center;padding:20px;background:linear-gradient(135deg,#2563eb,#3b82f6);border-radius:10px;color:#fff;">
                <div style="font-size:12px;opacity:.85;">本月成单金额</div>
                <div style="font-size:26px;font-weight:700;margin-top:4px;">¥<?php echo number_format($feeStats['month_fee'], 2); ?></div>
                <div style="font-size:11px;opacity:.7;margin-top:4px;"><?php echo $feeStats['month_count']; ?> 笔成单</div>
            </div>
            <div style="text-align:center;padding:20px;background:linear-gradient(135deg,#d97706,#f59e0b);border-radius:10px;color:#fff;">
                <div style="font-size:12px;opacity:.85;">今日成单金额</div>
                <div style="font-size:26px;font-weight:700;margin-top:4px;">¥<?php echo number_format($feeStats['today_fee'], 2); ?></div>
            </div>
            <div style="text-align:center;padding:20px;background:linear-gradient(135deg,#7c3aed,#8b5cf6);border-radius:10px;color:#fff;">
                <div style="font-size:12px;opacity:.85;">平均成单金额</div>
                <div style="font-size:26px;font-weight:700;margin-top:4px;">¥<?php echo number_format($feeStats['avg_fee'], 2); ?></div>
            </div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <!-- 预约状态分布 -->
    <div class="card">
        <div class="card-header"><h3>预约状态分布</h3></div>
        <div class="card-body">
            <?php foreach($statusData as $s): ?>
            <div style="display:flex;align-items:center;margin-bottom:12px;">
                <div style="width:80px;font-size:13px;font-weight:500;color:<?php echo htmlspecialchars($s['color']); ?>;"><?php echo htmlspecialchars($s['name']); ?></div>
                <div style="flex:1;background:#f3f4f6;border-radius:4px;height:24px;overflow:hidden;">
                    <div style="background:<?php echo htmlspecialchars($s['color']); ?>;height:100%;width:<?php echo $s['count'] > 0 ? max(2, ($s['count']/max(1,max(array_column($statusData,'count'))))*100) : 0; ?>%;border-radius:4px;transition:width .3s;"></div>
                </div>
                <div style="width:50px;text-align:right;font-size:13px;font-weight:600;"><?php echo $s['count']; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 医院预约排名 -->
    <div class="card">
        <div class="card-header"><h3>医院预约排名</h3></div>
        <div class="card-body" style="padding:0;">
            <table class="data-table">
                <thead><tr><th>排名</th><th>医院</th><th>预约数</th></tr></thead>
                <tbody>
                <?php foreach($hospitalRank as $i => $h): ?>
                <tr>
                    <td><span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;font-size:12px;font-weight:700;<?php echo $i<3?'background:#1d4ed8;color:#fff;':'background:#f3f4f6;color:#6b7280;'; ?>"><?php echo $i+1; ?></span></td>
                    <td><?php echo htmlspecialchars($h['name']); ?></td>
                    <td><strong><?php echo $h['count']; ?></strong></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 30天趋势 -->
<div class="card">
    <div class="card-header"><h3>最近30天预约趋势</h3></div>
    <div class="card-body">
        <div style="height:200px;display:flex;align-items:flex-end;gap:2px;overflow-x:auto;padding-bottom:20px;">
            <?php foreach($dailyTrend as $idx => $d): ?>
            <div style="min-width:16px;flex:1;text-align:center;">
                <div style="background:linear-gradient(to top,#1d4ed8,#3b82f6);border-radius:2px 2px 0 0;height:<?php echo max(2, ($d['count']/$maxDaily)*160); ?>px;margin:0 auto;width:100%;" title="<?php echo $d['date'].': '.$d['count'].'条'; ?>"></div>
                <?php if($idx % 5 === 0): ?><div style="font-size:9px;color:#9ca3af;margin-top:2px;transform:rotate(-45deg);white-space:nowrap;"><?php echo $d['date']; ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 时段分布 -->
<div class="card">
    <div class="card-header"><h3>预约时段分布</h3></div>
    <div class="card-body" style="display:flex;gap:30px;justify-content:center;">
        <?php foreach($timeDistribution as $t): ?>
        <div style="text-align:center;">
            <div style="font-size:32px;font-weight:700;color:#1d4ed8;"><?php echo $t['count']; ?></div>
            <div style="font-size:14px;color:#6b7280;margin-top:4px;"><?php echo htmlspecialchars($t['time_period']); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 已成单月度费用趋势 -->
<div style="display:grid;grid-template-columns:3fr 2fr;gap:20px;">
    <div class="card">
        <div class="card-header"><h3>已成单月度费用趋势（近12月）</h3></div>
        <div class="card-body">
            <div style="height:220px;display:flex;align-items:flex-end;gap:4px;overflow-x:auto;padding-bottom:20px;">
                <?php foreach($monthlyFeeTrend as $idx => $m): ?>
                <div style="min-width:40px;flex:1;text-align:center;">
                    <div style="font-size:10px;color:#059669;font-weight:600;margin-bottom:2px;">¥<?php echo $m['fee'] > 0 ? number_format($m['fee'], 0) : ''; ?></div>
                    <div style="background:linear-gradient(to top,#059669,#10b981);border-radius:3px 3px 0 0;height:<?php echo max(2, ($m['fee']/$maxMonthFee)*160); ?>px;margin:0 auto;width:100%;opacity:<?php echo $m['fee'] > 0 ? '1' : '0.3'; ?>;" title="<?php echo $m['month'].': ¥'.number_format($m['fee'],2).' ('.$m['count'].'笔)'; ?>"></div>
                    <div style="font-size:9px;color:#9ca3af;margin-top:4px;white-space:nowrap;"><?php echo substr($m['month'], 5); ?>月</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header"><h3>医院成单金额排名</h3></div>
        <div class="card-body" style="padding:0;">
            <table class="data-table">
                <thead><tr><th>排名</th><th>医院</th><th>成单数</th><th>金额</th></tr></thead>
                <tbody>
                <?php foreach($hospitalFeeRank as $i => $h): ?>
                <tr>
                    <td><span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;font-size:12px;font-weight:700;<?php echo $i<3?'background:#059669;color:#fff;':'background:#f3f4f6;color:#6b7280;'; ?>"><?php echo $i+1; ?></span></td>
                    <td><?php echo htmlspecialchars($h['name']); ?></td>
                    <td><?php echo $h['count']; ?></td>
                    <td><strong style="color:#059669;">¥<?php echo number_format($h['total_fee'], 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($hospitalFeeRank)): ?>
                <tr><td colspan="4" style="text-align:center;padding:20px;color:#9ca3af;">暂无成单数据</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php renderAdmin('数据统计','stats',ob_get_clean());
