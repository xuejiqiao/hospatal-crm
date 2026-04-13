<?php
/**
 * 患者管理 - 完整患者档案 + 预约历史 + 随访记录
 */
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

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
$statusNameList = array();
foreach ($statusConfig as $sc) {
    $statusColorMap[$sc['name']] = $sc;
    $statusNameList[] = $sc['name'];
}

// ===== 患者详情页 =====
if ($action === 'detail') {
    $phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
    if (!$phone) { echo '<div style="padding:40px;text-align:center;color:#9ca3af;">缺少患者手机号</div>'; return; }
    
    // 动态构建各状态的COUNT SQL
    $statusCountSql = '';
    foreach ($statusConfig as $sc) {
        $safeName = addslashes($sc['name']);
        $statusCountSql .= ", SUM(CASE WHEN status='{$safeName}' THEN 1 ELSE 0 END) as status_cnt_" . md5($sc['name']);
    }
    
    // 患者基本信息（从预约表聚合）
    $info = $db->query(
        "SELECT patient_phone, patient_name, COUNT(*) as total_count 
         {$statusCountSql},
         MIN(addtime) as first_time, MAX(addtime) as last_time
         FROM {$prefix}reservation WHERE patient_phone=? GROUP BY patient_phone, patient_name",
        array($phone)
    )->fetch();
    
    if (!$info) { echo '<div style="padding:40px;text-align:center;color:#9ca3af;">未找到该患者记录</div>'; return; }
    
    // 将动态状态计数映射为关联数组
    $statusCounts = array();
    foreach ($statusConfig as $sc) {
        $key = 'status_cnt_' . md5($sc['name']);
        $statusCounts[$sc['name']] = isset($info[$key]) ? intval($info[$key]) : 0;
    }
    
    // 患者预约历史
    $reservations = $db->query(
        "SELECT r.*, h.name as hospital_name FROM {$prefix}reservation r LEFT JOIN {$prefix}hospital h ON r.hospital_id=h.id WHERE r.patient_phone=? ORDER BY r.addtime DESC LIMIT 50",
        array($phone)
    )->fetchAll();
    
    // 患者随访记录
    $followUps = $db->select('follow_up', array('patient_phone' => $phone), '*', 'addtime DESC');
    
    // 患者文件列表
    $patientFiles = array();
    try {
        $patientFiles = $db->query(
            "SELECT * FROM {$prefix}patient_file WHERE patient_phone=? ORDER BY addtime DESC",
            array($phone)
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {}
    
    // 从最近预约获取微信号和邮寄地址
    $latestReservation = !empty($reservations) ? $reservations[0] : null;
    
    $followTypeMap = array('phone'=>'电话','wechat'=>'微信','visit'=>'到访');
    $followResultMap = array('normal'=>'正常','abnormal'=>'异常','no_answer'=>'未接听','cancelled'=>'取消');
    
    // 获取患者模块自定义字段定义
    $patientCustomFields = array();
    try {
        $patientCustomFields = $db->query(
            "SELECT * FROM {$prefix}custom_field WHERE target_table='patient' AND status=1 ORDER BY sort DESC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {}
    
    // 获取患者自定义字段值（EAV: patient的自定义字段关联到预约ID）
    // 查询该患者所有预约记录关联的自定义字段值，按字段取最新值
    $patientCustomValues = array();
    if (!empty($patientCustomFields)) {
        try {
            // 获取该患者所有预约ID
            $allResIds = array_column($reservations, 'id');
            if (!empty($allResIds)) {
                $idPlaceholders = implode(',', array_fill(0, count($allResIds), '?'));
                $cvRows = $db->query(
                    "SELECT fv.field_id, fv.field_value, cf.field_key, cf.field_name 
                     FROM {$prefix}custom_field_value fv 
                     INNER JOIN {$prefix}custom_field cf ON fv.field_id=cf.id 
                     WHERE fv.target_table='patient' AND fv.target_id IN ({$idPlaceholders})
                     ORDER BY fv.target_id DESC",
                    $allResIds
                )->fetchAll(PDO::FETCH_ASSOC);
                // 按field_key去重，优先取最新预约的值
                foreach ($cvRows as $cv) {
                    if (!isset($patientCustomValues[$cv['field_key']])) {
                        $patientCustomValues[$cv['field_key']] = $cv;
                    }
                }
            }
        } catch(PDOException $e) {}
    }
    
    // 获取预约模块自定义字段定义
    $reservationCustomFields = array();
    try {
        $reservationCustomFields = $db->query(
            "SELECT * FROM {$prefix}custom_field WHERE target_table='reservation' AND status=1 ORDER BY sort DESC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {}
    
    // 获取预约自定义字段值（从所有预约中，按字段取最新值）
    $reservationCustomValues = array();
    if (!empty($reservationCustomFields) && !empty($reservations)) {
        try {
            $allResIds = array_column($reservations, 'id');
            $idPlaceholders = implode(',', array_fill(0, count($allResIds), '?'));
            $resCvRows = $db->query(
                "SELECT fv.field_id, fv.field_value, cf.field_key, cf.field_name
                 FROM {$prefix}custom_field_value fv
                 INNER JOIN {$prefix}custom_field cf ON fv.field_id=cf.id
                 WHERE fv.target_table='reservation' AND fv.target_id IN ({$idPlaceholders})
                 ORDER BY fv.target_id DESC",
                $allResIds
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($resCvRows as $cv) {
                if (!isset($reservationCustomValues[$cv['field_key']])) {
                    $reservationCustomValues[$cv['field_key']] = $cv;
                }
            }
        } catch(PDOException $e) {}
    }
    
    ob_start();
    ?>
    <!-- 患者档案卡片 -->
    <div class="card">
        <div class="card-header">
            <h3>患者档案 - <?php echo htmlspecialchars($info['patient_name']); ?></h3>
            <button onclick="openFollowModal()" class="btn btn-primary btn-sm">+ 添加随访</button>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(<?php echo min(count($statusConfig), 5); ?>,1fr);gap:16px;margin-bottom:20px;">
                <div style="text-align:center;padding:16px;background:#eff6ff;border-radius:8px;">
                    <div style="font-size:28px;font-weight:700;color:#1d4ed8;"><?php echo $info['total_count']; ?></div>
                    <div style="font-size:12px;color:#6b7280;">总预约</div>
                </div>
                <?php foreach($statusConfig as $sc): 
                    $cnt = isset($statusCounts[$sc['name']]) ? $statusCounts[$sc['name']] : 0;
                ?>
                <div style="text-align:center;padding:16px;background:<?php echo htmlspecialchars($sc['bg']); ?>;border-radius:8px;">
                    <div style="font-size:28px;font-weight:700;color:<?php echo htmlspecialchars($sc['color']); ?>;"><?php echo $cnt; ?></div>
                    <div style="font-size:12px;color:<?php echo htmlspecialchars($sc['color']); ?>;"><?php echo htmlspecialchars($sc['name']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 24px;font-size:13px;">
                <!-- 基本信息 -->
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">患者姓名</span><div style="font-weight:600;color:#111;margin-top:2px;"><?php echo htmlspecialchars($info['patient_name']); ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">手机号</span><div style="font-weight:600;color:#111;margin-top:2px;"><?php echo htmlspecialchars($info['patient_phone']); ?></div></div>
                <?php if ($latestReservation): ?>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">身份证号</span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['patient_idcard'] ?: '-'); ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">微信号</span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['wechat'] ?: '-'); ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">最近就诊医院</span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['hospital_name'] ?: '-'); ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">最近就诊科室</span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['department'] ?: '-'); ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">最近就诊医生</span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['doctor'] ?: '-'); ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">最近预约日期</span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['reservation_date'] ?: '-'); ?> <?php echo htmlspecialchars($latestReservation['time_period'] ?: ''); ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">采样日期</span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['sample_date'] ?: '-'); ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">送检日期</span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['test_date'] ?: '-'); ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">检测结果</span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['test_result'] ?: '-'); ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">报告邮寄地址</span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['report_address'] ?: '-'); ?></div></div>
                <?php
                    $latestFee = isset($latestReservation['fee']) ? floatval($latestReservation['fee']) : 0;
                    $latestStatus = $latestReservation['status'];
                ?>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">最近费用金额</span><div style="color:<?php echo $latestFee > 0 ? '#059669' : '#111'; ?>;margin-top:2px;<?php echo $latestFee > 0 ? 'font-weight:600;' : ''; ?>"><?php echo $latestFee > 0 ? '¥' . number_format($latestFee, 2) : '-'; ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">最近状态</span><div style="margin-top:2px;"><?php
                    $lsc = isset($statusColorMap[$latestStatus]) ? $statusColorMap[$latestStatus] : null;
                    if ($lsc): ?><span style="display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:500;color:<?php echo htmlspecialchars($lsc['color']); ?>;background:<?php echo htmlspecialchars($lsc['bg']); ?>;"><?php echo htmlspecialchars($latestStatus); ?></span>
                    <?php else: echo htmlspecialchars($latestStatus); endif;
                ?></div></div>
                <?php if ($latestReservation['remark']): ?>
                <div style="padding:8px 12px;background:#fefce8;border-radius:6px;grid-column:span 2;"><span style="color:#92400e;">患者备注</span><div style="color:#713f12;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['remark']); ?></div></div>
                <?php endif; ?>
                <?php if ($latestReservation['admin_remark']): ?>
                <div style="padding:8px 12px;background:#eff6ff;border-radius:6px;grid-column:span 2;"><span style="color:#1e40af;">管理员备注</span><div style="color:#1e3a5f;margin-top:2px;"><?php echo htmlspecialchars($latestReservation['admin_remark']); ?></div></div>
                <?php endif; ?>
                <?php endif; ?>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">首次预约</span><div style="color:#111;margin-top:2px;"><?php echo $info['first_time'] ? date('Y-m-d H:i', $info['first_time']) : '-'; ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">最近预约</span><div style="color:#111;margin-top:2px;"><?php echo $info['last_time'] ? date('Y-m-d H:i', $info['last_time']) : '-'; ?></div></div>
                <div style="padding:8px 12px;background:#f9fafb;border-radius:6px;"><span style="color:#9ca3af;">随访记录</span><div style="color:#1d4ed8;font-weight:600;margin-top:2px;"><?php echo count($followUps); ?> 次</div></div>
                <?php
                // 成单总费用
                $totalFee = 0;
                foreach ($reservations as $rr) {
                    if ($rr['status'] === '已成单' && isset($rr['fee'])) {
                        $totalFee += floatval($rr['fee']);
                    }
                }
                ?>
                <div style="padding:8px 12px;background:#f0fdf4;border-radius:6px;"><span style="color:#059669;">成单总费用</span><div style="color:#059669;font-weight:600;margin-top:2px;"><?php echo $totalFee > 0 ? '¥' . number_format($totalFee, 2) : '-'; ?></div></div>
                <?php foreach($patientCustomValues as $cv): ?>
                <div style="padding:8px 12px;background:#f0fdf4;border-radius:6px;"><span style="color:#059669;font-size:11px;">[患者]</span> <span style="color:#9ca3af;"><?php echo htmlspecialchars($cv['field_name']); ?></span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($cv['field_value'] ?: '-'); ?></div></div>
                <?php endforeach; ?>
                <?php foreach($reservationCustomValues as $cv): ?>
                <div style="padding:8px 12px;background:#eff6ff;border-radius:6px;"><span style="color:#2563eb;font-size:11px;">[预约]</span> <span style="color:#9ca3af;"><?php echo htmlspecialchars($cv['field_name']); ?></span><div style="color:#111;margin-top:2px;"><?php echo htmlspecialchars($cv['field_value'] ?: '-'); ?></div></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- 患者文件 -->
    <div class="card" style="margin-top:20px;">
        <div class="card-header">
            <h3>患者文件 (<?php echo count($patientFiles); ?>)</h3>
            <button onclick="document.getElementById('fileInput').click()" class="btn btn-primary btn-sm">+ 上传文件</button>
            <input type="file" id="fileInput" style="display:none;" accept=".jpg,.jpeg,.png,.gif,.bmp,.webp,.pdf,.doc,.docx,.xls,.xlsx" onchange="uploadFile()">
        </div>
        <div class="card-body">
            <?php if(empty($patientFiles)): ?>
            <div style="text-align:center;padding:30px;color:#9ca3af;">暂无文件</div>
            <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;">
                <?php foreach($patientFiles as $pf):
                    $isImage = in_array($pf['file_ext'], array('jpg','jpeg','png','gif','bmp','webp'));
                    $fileIcon = $isImage ? '🖼' : ($pf['file_ext']==='pdf' ? '📄' : (in_array($pf['file_ext'], array('doc','docx')) ? '📝' : '📊'));
                    $fileSize = $pf['file_size'] > 1048576 ? round($pf['file_size']/1048576,1).'MB' : round($pf['file_size']/1024,1).'KB';
                ?>
                <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;position:relative;">
                    <?php if($isImage): ?>
                    <div style="height:140px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;overflow:hidden;cursor:pointer;" onclick="previewImage(<?php echo $pf['id']; ?>)">
                        <img src="admin.php?module=api_patient_file&action=download&id=<?php echo $pf['id']; ?>" style="max-width:100%;max-height:140px;object-fit:contain;">
                    </div>
                    <?php else: ?>
                    <div style="height:140px;background:#f9fafb;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;" onclick="window.open('admin.php?module=api_patient_file&action=download&id=<?php echo $pf['id']; ?>','_blank')">
                        <div style="font-size:40px;"><?php echo $fileIcon; ?></div>
                        <div style="font-size:12px;color:#6b7280;margin-top:4px;">点击下载</div>
                    </div>
                    <?php endif; ?>
                    <div style="padding:8px 10px;">
                        <div style="font-size:12px;font-weight:500;color:#111;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($pf['file_name']); ?>"><?php echo htmlspecialchars($pf['file_name']); ?></div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
                            <span style="font-size:11px;color:#9ca3af;"><?php echo $fileSize; ?> · <?php echo date('Y-m-d', $pf['addtime']); ?></span>
                            <button onclick="deleteFile(<?php echo $pf['id']; ?>)" style="border:none;background:none;color:#ef4444;cursor:pointer;font-size:12px;padding:0;">删除</button>
                        </div>
                        <?php if($pf['description']): ?>
                        <div style="font-size:11px;color:#6b7280;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($pf['description']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 图片预览模态框 -->
    <div class="modal-overlay" id="imagePreviewModal" onclick="closeModal('imagePreviewModal')">
        <div style="max-width:90vw;max-height:90vh;position:relative;" onclick="event.stopPropagation();">
            <img id="previewImg" src="" style="max-width:90vw;max-height:85vh;border-radius:8px;">
            <button onclick="closeModal('imagePreviewModal')" style="position:absolute;top:-10px;right:-10px;width:30px;height:30px;border-radius:50%;background:#1f2937;color:#fff;border:none;font-size:16px;cursor:pointer;">×</button>
        </div>
    </div>
    
    <!-- 预约历史 + 随访记录 双栏 -->
    <div style="display:grid;grid-template-columns:3fr 2fr;gap:20px;">
        <!-- 预约历史 -->
        <div class="card">
            <div class="card-header"><h3>预约历史</h3></div>
            <div class="card-body" style="padding:0;">
                <table class="data-table">
                    <thead><tr><th>日期</th><th>医院</th><th>科室</th><th>医生</th><th>采样日</th><th>送检日</th><th>结果</th><th>状态</th></tr></thead>
                    <tbody>
                    <?php foreach($reservations as $r):
                        $sc = isset($statusColorMap[$r['status']]) ? $statusColorMap[$r['status']] : null;
                        $rowColor = $sc ? $sc['color'] : '';
                        $rowStyle = $rowColor ? ' style="color:'.$rowColor.';"' : '';
                    ?>
                    <tr<?php echo $rowStyle; ?>>
                        <td><strong><?php echo $r['reservation_date']; ?></strong><br><span style="font-size:11px;color:#9ca3af;"><?php echo $r['time_period']; ?></span></td>
                        <td><?php echo htmlspecialchars($r['hospital_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['department']); ?></td>
                        <td><?php echo htmlspecialchars($r['doctor']); ?></td>
                        <td><?php echo $r['sample_date'] ?: '-'; ?></td>
                        <td><?php echo $r['test_date'] ?: '-'; ?></td>
                        <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($r['test_result']); ?>"><?php echo $r['test_result'] ? htmlspecialchars($r['test_result']) : '-'; ?></td>
                        <td>
                            <?php if($sc): ?>
                            <span style="display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:500;color:<?php echo htmlspecialchars($sc['color']); ?>;background:<?php echo htmlspecialchars($sc['bg']); ?>;"><?php echo htmlspecialchars($r['status']); ?></span>
                            <?php else: ?>
                            <span class="badge badge-gray"><?php echo htmlspecialchars($r['status']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 随访记录 -->
        <div class="card">
            <div class="card-header"><h3>随访记录</h3></div>
            <div class="card-body">
                <?php if(empty($followUps)): ?>
                <div style="text-align:center;padding:30px;color:#9ca3af;">暂无随访记录</div>
                <?php else: ?>
                <?php foreach($followUps as $f): ?>
                <div style="border-left:3px solid #3b82f6;padding:8px 12px;margin-bottom:10px;background:#f9fafb;border-radius:0 6px 6px 0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-size:12px;color:#6b7280;">
                            <?php echo isset($followTypeMap[$f['follow_type']]) ? $followTypeMap[$f['follow_type']] : $f['follow_type']; ?>随访 · 
                            <span class="badge <?php echo $f['follow_result']=='normal'?'badge-success':($f['follow_result']=='abnormal'?'badge-danger':'badge-gray'); ?>" style="font-size:11px;">
                                <?php echo isset($followResultMap[$f['follow_result']]) ? $followResultMap[$f['follow_result']] : $f['follow_result']; ?>
                            </span>
                        </span>
                        <span style="font-size:11px;color:#9ca3af;"><?php echo date('Y-m-d H:i', $f['addtime']); ?></span>
                    </div>
                    <div style="margin-top:4px;font-size:13px;"><?php echo htmlspecialchars($f['content']); ?></div>
                    <?php if($f['next_date']): ?>
                    <div style="font-size:11px;color:#f59e0b;margin-top:2px;">下次随访: <?php echo $f['next_date']; ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 添加随访 模态框 -->
    <div class="modal-overlay" id="followModal">
        <div class="modal" style="width:480px;">
            <div class="modal-header"><h3>添加随访记录</h3><button class="close" onclick="closeModal('followModal')">&times;</button></div>
            <div class="modal-body">
                <div class="form-group"><label>随访方式</label>
                    <select id="fuType"><option value="phone">电话随访</option><option value="wechat">微信随访</option><option value="visit">到访随访</option></select>
                </div>
                <div class="form-group"><label>随访结果</label>
                    <select id="fuResult"><option value="normal">正常</option><option value="abnormal">异常</option><option value="no_answer">未接听</option><option value="cancelled">取消</option></select>
                </div>
                <div class="form-group"><label>随访内容 *</label><textarea id="fuContent" rows="3" placeholder="记录随访详情"></textarea></div>
                <div class="form-group"><label>下次随访日期</label><input type="date" id="fuNextDate"></div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal('followModal')" class="btn btn-outline">取消</button>
                <button onclick="saveFollowUp()" class="btn btn-primary">保存</button>
            </div>
        </div>
    </div>
    
    <script>
    var patientPhone = '<?php echo addslashes($phone); ?>';
    var patientName = '<?php echo addslashes($info['patient_name']); ?>';
    
    function openFollowModal() {
        document.getElementById('fuType').value = 'phone';
        document.getElementById('fuResult').value = 'normal';
        document.getElementById('fuContent').value = '';
        document.getElementById('fuNextDate').value = '';
        openModal('followModal');
    }
    
    function saveFollowUp() {
        var content = document.getElementById('fuContent').value;
        if (!content) { showToast('请填写随访内容','error'); return; }
        apiPost('admin.php?module=follow_up&action=save', {
            patient_phone: patientPhone,
            patient_name: patientName,
            follow_type: document.getElementById('fuType').value,
            follow_result: document.getElementById('fuResult').value,
            content: content,
            next_date: document.getElementById('fuNextDate').value
        }, function(r) {
            if (r.code === 200) { showToast('随访记录已添加','success'); setTimeout(function(){location.reload();},500); }
            else { showToast(r.message,'error'); }
        });
    }
    
    function uploadFile() {
        var input = document.getElementById('fileInput');
        if (!input.files || !input.files[0]) return;
        var formData = new FormData();
        formData.append('file', input.files[0]);
        formData.append('patient_phone', patientPhone);
        formData.append('description', '');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'admin.php?module=api_patient_file&action=upload', true);
        xhr.onload = function() {
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.code === 200) { showToast('文件上传成功','success'); setTimeout(function(){location.reload();},500); }
                else { showToast(r.message,'error'); }
            } catch(e) { showToast('上传失败','error'); }
        };
        xhr.onerror = function() { showToast('上传失败','error'); };
        xhr.send(formData);
        input.value = '';
    }
    
    function deleteFile(id) {
        if (!confirm('确定要删除此文件吗？')) return;
        apiPost('admin.php?module=api_patient_file&action=delete', {id: id}, function(r) {
            if (r.code === 200) { showToast('文件已删除','success'); setTimeout(function(){location.reload();},500); }
            else { showToast(r.message,'error'); }
        });
    }
    
    function previewImage(id) {
        document.getElementById('previewImg').src = 'admin.php?module=api_patient_file&action=download&id=' + id;
        openModal('imagePreviewModal');
    }
    </script>
    <?php
    renderAdmin('患者详情', 'patient', ob_get_clean());
    return; // 直接返回，不渲染列表
}

// ===== 患者列表页 =====
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$pageSize = 15;

$conditions = array();
$params = array();
if ($keyword) {
    $conditions[] = "(patient_name LIKE ? OR patient_phone LIKE ?)";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}
$where = !empty($conditions) ? "WHERE " . implode(' AND ', $conditions) : "";

try {
    $total = $db->query("SELECT COUNT(DISTINCT patient_phone) as total FROM {$prefix}reservation {$where}", $params)->fetch()['total'];
} catch(PDOException $e) {
    $total = 0;
}
$offset = ($page - 1) * $pageSize;
$totalPages = $total > 0 ? ceil($total / $pageSize) : 1;

// 动态构建各状态计数SQL
$statusCountSql = '';
foreach ($statusConfig as $sc) {
    $safeName = addslashes($sc['name']);
    $statusCountSql .= ", SUM(CASE WHEN status='{$safeName}' THEN 1 ELSE 0 END) as status_cnt_" . md5($sc['name']);
}

try {
    $list = $db->query(
        "SELECT patient_phone, patient_name, COUNT(*) as total_count 
         {$statusCountSql},
         MAX(addtime) as last_time
         FROM {$prefix}reservation {$where}
         GROUP BY patient_phone, patient_name ORDER BY last_time DESC LIMIT {$offset}, {$pageSize}",
        $params
    )->fetchAll();
} catch(PDOException $e) {
    $list = array();
}

// 批量获取每个患者的随访次数
$followCounts = array();
if (!empty($list)) {
    $phones = array_column($list, 'patient_phone');
    $phoneList = "'" . implode("','", array_map('addslashes', $phones)) . "'";
    try {
        $fc = $db->query("SELECT patient_phone, COUNT(*) as cnt FROM {$prefix}follow_up WHERE patient_phone IN ({$phoneList}) GROUP BY patient_phone")->fetchAll();
        foreach ($fc as $f) { $followCounts[$f['patient_phone']] = $f['cnt']; }
    } catch(PDOException $e) {}
}

ob_start();
?>
<div class="card">
    <div class="card-body">
        <form method="get" style="display:flex;gap:10px;align-items:center;">
            <input type="hidden" name="module" value="patient">
            <input type="text" name="keyword" placeholder="搜索患者姓名/手机号" value="<?php echo htmlspecialchars($keyword); ?>" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;width:200px;">
            <button type="submit" class="btn btn-primary btn-sm">搜索</button>
            <a href="admin.php?module=patient" class="btn btn-outline btn-sm">重置</a>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header"><h3>患者列表 (共<?php echo $total; ?>人)</h3></div>
    <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead><tr><th>姓名</th><th>手机号</th><th>总预约</th>
            <?php foreach($statusConfig as $sc): ?>
            <th style="color:<?php echo htmlspecialchars($sc['color']); ?>;"><?php echo htmlspecialchars($sc['name']); ?></th>
            <?php endforeach; ?>
            <th>随访</th><th>最近预约</th><th>操作</th></tr></thead>
            <tbody>
            <?php if(empty($list)): ?>
                <tr><td colspan="<?php echo 5 + count($statusConfig); ?>" style="text-align:center;padding:40px;color:#9ca3af;">暂无患者数据</td></tr>
            <?php else: ?>
            <?php foreach($list as $p): 
                // 最近预约的状态对应的字体颜色
                $latestStatus = '';
                $latestRow = $db->query("SELECT status FROM {$prefix}reservation WHERE patient_phone=? ORDER BY addtime DESC LIMIT 1", array($p['patient_phone']))->fetch();
                if ($latestRow) $latestStatus = $latestRow['status'];
                $sc = isset($statusColorMap[$latestStatus]) ? $statusColorMap[$latestStatus] : null;
                $rowColor = $sc ? $sc['color'] : '';
                $rowStyle = $rowColor ? ' style="color:'.$rowColor.';"' : '';
            ?>
            <tr<?php echo $rowStyle; ?>>
                <td><strong><?php echo htmlspecialchars($p['patient_name']); ?></strong></td>
                <td><?php echo htmlspecialchars($p['patient_phone']); ?></td>
                <td><?php echo $p['total_count']; ?></td>
                <?php foreach($statusConfig as $scItem): 
                    $key = 'status_cnt_' . md5($scItem['name']);
                    $cnt = isset($p[$key]) ? intval($p[$key]) : 0;
                ?>
                <td><?php echo $cnt; ?></td>
                <?php endforeach; ?>
                <td><span class="badge badge-info"><?php echo isset($followCounts[$p['patient_phone']]) ? $followCounts[$p['patient_phone']] : 0; ?>次</span></td>
                <td><?php echo !empty($p['last_time']) ? date('Y-m-d H:i', $p['last_time']) : '-'; ?></td>
                <td style="white-space:nowrap;">
                    <a href="admin.php?module=patient&action=detail&phone=<?php echo urlencode($p['patient_phone']); ?>" class="btn btn-outline btn-sm">档案</a>
                    <a href="admin.php?module=reservation&keyword=<?php echo urlencode($p['patient_phone']); ?>" class="btn btn-outline btn-sm">预约</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if($totalPages > 1): ?>
    <div class="card-body" style="border-top:1px solid #f3f4f6;">
        <div class="pagination">
            <div class="info">共 <?php echo $total; ?> 人，第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</div>
            <div class="pages">
                <?php if($page > 1): ?><a href="?module=patient&page=<?php echo $page-1; ?>&keyword=<?php echo urlencode($keyword); ?>">上页</a><?php endif; ?>
                <span class="current"><?php echo $page; ?></span>
                <?php if($page < $totalPages): ?><a href="?module=patient&page=<?php echo $page+1; ?>&keyword=<?php echo urlencode($keyword); ?>">下页</a><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php renderAdmin('患者管理','patient',ob_get_clean());
