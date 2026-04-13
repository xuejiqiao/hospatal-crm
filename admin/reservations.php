<?php
/**
 * 预约管理页面 - 完整CRUD + 审核 + 操作日志
 */
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$admin = AdminAuth::getAdmin();

// ===== AJAX 请求处理 =====

// 更新预约状态
if ($action === 'updateStatus' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id']);
        $status = trim($input['status']);
        $remark = isset($input['remark']) ? trim($input['remark']) : '';
        if ($id > 0 && $status !== '') {
            $db->update('reservation', array('status' => $status, 'admin_remark' => $remark, 'updatetime' => time()), array('id' => $id));
            addLog('update_status', 'reservation', $id, "预约#{$id} 状态变更为{$status}" . ($remark ? "，备注:{$remark}" : ''));
            echo json_encode(array('code' => 200, 'message' => '操作成功'));
        } else {
            echo json_encode(array('code' => 400, 'message' => '参数错误'));
        }
    } catch(Exception $e) {
        echo json_encode(array('code' => 500, 'message' => '状态更新失败: ' . $e->getMessage()));
    }
    exit;
}

// 保存预约（新建/编辑）
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? intval($input['id']) : 0;
    $data = array(
        'hospital_id' => intval($input['hospital_id']),
        'department_id' => intval($input['department_id']),
        'department' => trim($input['department']),
        'doctor' => trim($input['doctor']),
        'patient_name' => trim($input['patient_name']),
        'patient_phone' => trim($input['patient_phone']),
        'patient_idcard' => isset($input['patient_idcard']) ? trim($input['patient_idcard']) : '',
        'reservation_date' => trim($input['reservation_date']),
        'time_period' => isset($input['time_period']) ? trim($input['time_period']) : '上午',
        'sample_date' => isset($input['sample_date']) ? trim($input['sample_date']) : '',
        'test_date' => isset($input['test_date']) ? trim($input['test_date']) : '',
        'test_result' => isset($input['test_result']) ? trim($input['test_result']) : '',
        'report_address' => isset($input['report_address']) ? trim($input['report_address']) : '',
        'wechat' => isset($input['wechat']) ? trim($input['wechat']) : '',
        'remark' => isset($input['remark']) ? trim($input['remark']) : '',
        'fee' => isset($input['fee']) ? floatval($input['fee']) : 0,
        'updatetime' => time()
    );
    
    // 验证
    if (empty($data['patient_name'])) { echo json_encode(array('code'=>400,'message'=>'请填写患者姓名')); exit; }
    if (empty($data['patient_phone'])) { echo json_encode(array('code'=>400,'message'=>'请填写联系电话')); exit; }
    if (empty($data['reservation_date'])) { echo json_encode(array('code'=>400,'message'=>'请选择预约日期')); exit; }
    if (!$data['hospital_id']) { echo json_encode(array('code'=>400,'message'=>'请选择医院')); exit; }
    
    if ($id > 0) {
        // 编辑 - 允许修改状态
        $data['status'] = isset($input['status']) ? trim($input['status']) : $data['status'];
        $db->update('reservation', $data, array('id' => $id));
        addLog('edit', 'reservation', $id, "编辑预约#{$id}，患者:{$data['patient_name']}");
    } else {
        // 新建
        $data['user_id'] = 0; // 后台创建无关联用户
        $status = isset($input['status']) ? trim($input['status']) : '已预约';
        $data['status'] = $status;
        $data['addtime'] = time();
        $id = $db->insert('reservation', $data);
        addLog('create', 'reservation', $id, "新建预约#{$id}，患者:{$data['patient_name']}");
    }
    
    // 保存自定义字段值
    $customFields = isset($input['custom_fields']) ? $input['custom_fields'] : array();
    if (!empty($customFields)) {
        try {
            foreach ($customFields as $fieldId => $fieldValue) {
                $fieldId = intval($fieldId);
                $fieldValue = trim($fieldValue);
                if ($fieldId > 0) {
                    // 用REPLACE INTO实现upsert
                    $db->query(
                        "REPLACE INTO {$prefix}custom_field_value (field_id, target_table, target_id, field_value, addtime) VALUES (?, 'reservation', ?, ?, ?)",
                        array($fieldId, $id, $fieldValue, time())
                    );
                }
            }
        } catch(PDOException $e) {
            // 自定义字段保存失败不影响主流程
        }
    }
    
    echo json_encode(array('code' => 200, 'message' => '保存成功'));
    exit;
    } catch(Exception $e) {
        echo json_encode(array('code' => 500, 'message' => '保存失败: ' . $e->getMessage()));
        exit;
    }
}

// 删除预约
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id']);
    if ($id > 0) {
        $old = $db->find('reservation', array('id' => $id));
        $db->delete('reservation', array('id' => $id));
        addLog('delete', 'reservation', $id, "删除预约#{$id}，患者:" . ($old ? $old['patient_name'] : '未知'));
        echo json_encode(array('code' => 200, 'message' => '删除成功'));
    } else {
        echo json_encode(array('code' => 400, 'message' => '参数错误'));
    }
    exit;
}

// 获取预约详情
if ($action === 'detail') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $r = $db->query("SELECT r.*, h.name as hospital_name FROM {$prefix}reservation r LEFT JOIN {$prefix}hospital h ON r.hospital_id=h.id WHERE r.id=?", array($id))->fetch();
    if ($r) {
        // 获取该患者的随访记录
        $follows = $db->select('follow_up', array('patient_phone' => $r['patient_phone']), '*', 'addtime DESC', '10');
        $r['follow_ups'] = $follows;
        // 获取自定义字段值
        $customValues = $db->query(
            "SELECT fv.field_id, fv.field_value, cf.field_key, cf.field_name, cf.field_type 
             FROM {$prefix}custom_field_value fv 
             INNER JOIN {$prefix}custom_field cf ON fv.field_id=cf.id 
             WHERE fv.target_table='reservation' AND fv.target_id=?",
            array($id)
        )->fetchAll(PDO::FETCH_ASSOC);
        $r['custom_fields'] = $customValues;
        echo json_encode(array('code' => 200, 'data' => $r));
    } else {
        echo json_encode(array('code' => 404, 'message' => '预约不存在'));
    }
    exit;
}

// ===== 列表页面 =====

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$pageSize = 15;
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$hospitalId = isset($_GET['hospital_id']) ? intval($_GET['hospital_id']) : 0;
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$sql = "SELECT r.*, h.name as hospital_name FROM {$prefix}reservation r LEFT JOIN {$prefix}hospital h ON r.hospital_id = h.id";
$params = array();
$conditions = array();

if ($statusFilter !== '') { $conditions[] = "r.status = ?"; $params[] = $statusFilter; }
if ($hospitalId) { $conditions[] = "r.hospital_id = ?"; $params[] = $hospitalId; }
if ($keyword) { $conditions[] = "(r.patient_name LIKE ? OR r.patient_phone LIKE ?)"; $params[] = "%{$keyword}%"; $params[] = "%{$keyword}%"; }
if ($dateFrom) { $conditions[] = "r.reservation_date >= ?"; $params[] = $dateFrom; }
if ($dateTo) { $conditions[] = "r.reservation_date <= ?"; $params[] = $dateTo; }
if (!empty($conditions)) { $sql .= " WHERE " . implode(' AND ', $conditions); }

$countSql = str_replace("SELECT r.*, h.name as hospital_name", "SELECT COUNT(*) as total", $sql);
try { $total = $db->query($countSql, $params)->fetch()['total']; } catch(PDOException $e) { $total = 0; }

$offset = ($page - 1) * $pageSize;
$totalPages = $total > 0 ? ceil($total / $pageSize) : 1;
$sql .= " ORDER BY r.addtime DESC LIMIT {$offset}, {$pageSize}";
try { $list = $db->query($sql, $params)->fetchAll(); } catch(PDOException $e) { $list = array(); }

$hospitals = $db->select('hospital', array('status' => 1), 'id, name', 'sort DESC');
$depts = $db->select('department', array('status' => 1), 'id, hospital_id, name', 'sort DESC');
$doctors = $db->select('doctor', array('status' => 1), 'id, hospital_id, department_id, name, title', 'sort DESC');

// 获取医生-科室关联数据（用于按科室筛选医生）
$doctorDeptMap = array(); // doctor_id => [dept_id, ...]
try {
    $ddRows = $db->query("SELECT doctor_id, department_id FROM {$prefix}doctor_dept")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ddRows as $dd) {
        if (!isset($doctorDeptMap[$dd['doctor_id']])) $doctorDeptMap[$dd['doctor_id']] = array();
        $doctorDeptMap[$dd['doctor_id']][] = $dd['department_id'];
    }
} catch(PDOException $e) {}

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

ob_start();
?>
<!-- 搜索栏 -->
<div class="card">
    <div class="card-body">
        <form method="get" action="admin.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <input type="hidden" name="module" value="reservation">
            <input type="text" name="keyword" placeholder="搜索患者姓名/手机号" value="<?php echo htmlspecialchars($keyword); ?>" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;width:200px;">
            <select name="status" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;">
                <option value="">全部状态</option>
                <?php foreach($statusConfig as $sc): ?>
                <option value="<?php echo htmlspecialchars($sc['name']); ?>" <?php echo $statusFilter===$sc['name']?'selected':''; ?>><?php echo htmlspecialchars($sc['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="hospital_id" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;">
                <option value="0">全部医院</option>
                <?php foreach($hospitals as $h): ?>
                <option value="<?php echo $h['id']; ?>" <?php echo $hospitalId==$h['id']?'selected':''; ?>><?php echo htmlspecialchars($h['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;">
            <span>至</span>
            <input type="date" name="date_to" value="<?php echo $dateTo; ?>" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;">
            <button type="submit" class="btn btn-primary btn-sm">搜索</button>
            <a href="admin.php?module=reservation" class="btn btn-outline btn-sm">重置</a>
        </form>
    </div>
</div>

<!-- 预约列表 -->
<div class="card">
    <div class="card-header">
        <h3>预约列表 (共<?php echo $total; ?>条)</h3>
        <button onclick="openAddModal()" class="btn btn-primary btn-sm">+ 新建预约</button>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr><th>ID</th><th>患者</th><th>手机号</th><th>医院</th><th>科室</th><th>医生</th><th>预约日期</th><th>时段</th><th>状态</th><th>备注</th><th>提交时间</th><th>操作</th></tr>
            </thead>
            <tbody>
            <?php if(empty($list)): ?>
                <tr><td colspan="12" style="text-align:center;padding:40px;color:#9ca3af;">暂无数据</td></tr>
            <?php else: ?>
                <?php foreach($list as $r):
                    $sc = isset($statusColorMap[$r['status']]) ? $statusColorMap[$r['status']] : null;
                    $rowColor = $sc ? $sc['color'] : '';
                    $rowStyle = $rowColor ? ' style="color:'.$rowColor.';"' : '';
                ?>
                <tr<?php echo $rowStyle; ?>>
                    <td><?php echo $r['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($r['patient_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($r['patient_phone']); ?></td>
                    <td><?php echo htmlspecialchars($r['hospital_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['department']); ?></td>
                    <td><?php echo htmlspecialchars($r['doctor']); ?></td>
                    <td><?php echo $r['reservation_date']; ?></td>
                    <td><?php echo $r['time_period']; ?></td>
                    <td>
                        <select onchange="changeStatus(<?php echo $r['id']; ?>, this.value)" style="padding:2px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;<?php echo $sc ? 'color:'.$sc['color'].';background:'.$sc['bg'].';' : ''; ?>">
                            <?php foreach($statusConfig as $sopt): ?>
                            <option value="<?php echo htmlspecialchars($sopt['name']); ?>" <?php echo $r['status']===$sopt['name']?'selected':''; ?>><?php echo htmlspecialchars($sopt['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($r['remark']); ?>"><?php echo htmlspecialchars($r['remark']); ?></td>
                    <td><?php echo date('Y-m-d H:i', $r['addtime']); ?></td>
                    <td style="white-space:nowrap;">
                        <button onclick="viewDetail(<?php echo $r['id']; ?>)" class="btn btn-outline btn-sm" title="详情">详情</button>
                        <button onclick="editReservation(<?php echo $r['id']; ?>)" class="btn btn-outline btn-sm" title="编辑">编辑</button>
                        <?php if($r['status'] !== '已成单'): ?>
                        <button onclick="deleteReservation(<?php echo $r['id']; ?>)" class="btn btn-danger btn-sm" title="删除">删除</button>
                        <?php endif; ?>
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
            <div class="info">共 <?php echo $total; ?> 条，第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</div>
            <div class="pages">
                <?php if($page > 1): ?><a href="?module=reservation&page=1&status=<?php echo urlencode($statusFilter); ?>&keyword=<?php echo urlencode($keyword); ?>">首页</a><?php endif; ?>
                <?php if($page > 1): ?><a href="?module=reservation&page=<?php echo $page-1; ?>&status=<?php echo urlencode($statusFilter); ?>&keyword=<?php echo urlencode($keyword); ?>">上页</a><?php endif; ?>
                <span class="current"><?php echo $page; ?></span>
                <?php if($page < $totalPages): ?><a href="?module=reservation&page=<?php echo $page+1; ?>&status=<?php echo urlencode($statusFilter); ?>&keyword=<?php echo urlencode($keyword); ?>">下页</a><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 新建/编辑预约 模态框 -->
<div class="modal-overlay" id="editModal">
    <div class="modal" style="width:680px;">
        <div class="modal-header"><h3 id="modalTitle">新建预约</h3><button class="close" onclick="closeModal('editModal')">&times;</button></div>
        <div class="modal-body" style="max-height:70vh;overflow-y:auto;">
            <input type="hidden" id="editId" value="0">
            
            <!-- 基本信息 -->
            <div style="font-size:13px;font-weight:600;color:#1d4ed8;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid #dbeafe;">基本信息</div>
            <!-- 查重提示区 -->
            <div id="dupWarning" style="display:none;margin-bottom:12px;padding:10px 14px;background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;font-size:13px;"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">
                <div class="form-group"><label>患者姓名 *</label><input type="text" id="editPatientName" placeholder="请输入姓名" onblur="checkDuplicate()"></div>
                <div class="form-group"><label>联系电话 *</label><input type="text" id="editPatientPhone" placeholder="请输入手机号" onblur="checkDuplicate()"></div>
                <div class="form-group"><label>微信号</label><input type="text" id="editWechat" placeholder="选填"></div>
                <div class="form-group"><label>身份证号</label><input type="text" id="editIdcard" placeholder="选填"></div>
                <div class="form-group"><label>预约状态</label>
                    <select id="editStatus">
                        <?php foreach($statusConfig as $sc): ?>
                        <option value="<?php echo htmlspecialchars($sc['name']); ?>"><?php echo htmlspecialchars($sc['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- 预约信息 -->
            <div style="font-size:13px;font-weight:600;color:#1d4ed8;margin:12px 0 8px;padding-bottom:4px;border-bottom:1px solid #dbeafe;">预约信息</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">
                <div class="form-group"><label>医院 *</label>
                    <select id="editHospitalId" onchange="loadDeptsAndDoctors()">
                        <option value="">请选择医院</option>
                        <?php foreach($hospitals as $h): ?>
                        <option value="<?php echo $h['id']; ?>"><?php echo htmlspecialchars($h['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>科室</label>
                    <select id="editDeptId" onchange="onDeptChange()">
                        <option value="">请先选择医院</option>
                    </select>
                </div>
                <div class="form-group"><label>医生</label>
                    <select id="editDoctorName">
                        <option value="">请先选择科室</option>
                    </select>
                </div>
                <div class="form-group"><label>预约日期 *</label><input type="date" id="editDate"></div>
                <div class="form-group"><label>预约时段</label>
                    <select id="editTimePeriod">
                        <option value="上午">上午</option>
                        <option value="下午">下午</option>
                    </select>
                </div>
            </div>
            
            <!-- 检测信息 -->
            <div style="font-size:13px;font-weight:600;color:#1d4ed8;margin:12px 0 8px;padding-bottom:4px;border-bottom:1px solid #dbeafe;">检测信息</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">
                <div class="form-group">
                    <label>采样日期</label>
                    <input type="date" id="editSampleDate">
                    <div style="display:flex;gap:4px;margin-top:4px;">
                        <button type="button" onclick="setQuickDate('editSampleDate',0)" class="btn btn-outline btn-sm" style="font-size:11px;padding:2px 6px;">今天</button>
                        <button type="button" onclick="setQuickDate('editSampleDate',1)" class="btn btn-outline btn-sm" style="font-size:11px;padding:2px 6px;">明天</button>
                        <button type="button" onclick="setQuickDate('editSampleDate',2)" class="btn btn-outline btn-sm" style="font-size:11px;padding:2px 6px;">后天</button>
                        <button type="button" onclick="setQuickDate('editSampleDate',7)" class="btn btn-outline btn-sm" style="font-size:11px;padding:2px 6px;">一周后</button>
                        <button type="button" onclick="setQuickDate('editSampleDate',15)" class="btn btn-outline btn-sm" style="font-size:11px;padding:2px 6px;">半月后</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>送检日期</label>
                    <input type="date" id="editTestDate">
                    <div style="display:flex;gap:4px;margin-top:4px;">
                        <button type="button" onclick="setQuickDate('editTestDate',0)" class="btn btn-outline btn-sm" style="font-size:11px;padding:2px 6px;">今天</button>
                        <button type="button" onclick="setQuickDate('editTestDate',1)" class="btn btn-outline btn-sm" style="font-size:11px;padding:2px 6px;">明天</button>
                        <button type="button" onclick="setQuickDate('editTestDate',2)" class="btn btn-outline btn-sm" style="font-size:11px;padding:2px 6px;">后天</button>
                        <button type="button" onclick="setQuickDate('editTestDate',7)" class="btn btn-outline btn-sm" style="font-size:11px;padding:2px 6px;">一周后</button>
                        <button type="button" onclick="setQuickDate('editTestDate',15)" class="btn btn-outline btn-sm" style="font-size:11px;padding:2px 6px;">半月后</button>
                    </div>
                </div>
            </div>
            <div class="form-group"><label>检测结果</label><textarea id="editTestResult" rows="2" placeholder="填写检测结果"></textarea></div>
            
            <!-- 其他信息 -->
            <div style="font-size:13px;font-weight:600;color:#1d4ed8;margin:12px 0 8px;padding-bottom:4px;border-bottom:1px solid #dbeafe;">其他信息</div>
            <div class="form-group"><label>报告邮寄地址</label><input type="text" id="editReportAddress" placeholder="报告邮寄地址"></div>
            <div class="form-group"><label>费用金额 (元)</label><input type="number" id="editFee" step="0.01" min="0" placeholder="成单费用金额" style="max-width:200px;"></div>
            <div class="form-group"><label>备注</label><textarea id="editRemark" rows="2" placeholder="备注信息"></textarea></div>
            
            <!-- 自定义字段区域（由JS动态渲染） -->
            <div id="customFieldsArea"></div>
        </div>
        <div class="modal-footer">
            <button onclick="closeModal('editModal')" class="btn btn-outline">取消</button>
            <button onclick="saveReservation()" class="btn btn-primary">保存</button>
        </div>
    </div>
</div>

<!-- 预约详情 模态框 -->
<div class="modal-overlay" id="detailModal">
    <div class="modal" style="width:720px;">
        <div class="modal-header"><h3>预约详情</h3><button class="close" onclick="closeModal('detailModal')">&times;</button></div>
        <div class="modal-body" id="detailBody" style="max-height:75vh;overflow-y:auto;">
            <p style="color:#9ca3af;">加载中...</p>
        </div>
    </div>
</div>

<script>
var depts = <?php echo json_encode($depts); ?>;
var doctors = <?php echo json_encode($doctors); ?>;
var doctorDeptMap = <?php echo json_encode($doctorDeptMap); ?>;
var statusConfig = <?php echo json_encode($statusConfig); ?>;
var customFieldsCache = null;

// 加载预约模块的自定义字段定义
function loadCustomFields(callback) {
    if (customFieldsCache) { if(callback) callback(customFieldsCache); return; }
    apiGet('admin.php?module=api_custom_fields&table=reservation', function(res) {
        customFieldsCache = (res && res.code === 200 && res.data) ? res.data : [];
        if(callback) callback(customFieldsCache);
    });
}

function renderCustomFields(values) {
    var area = document.getElementById('customFieldsArea');
    if (!customFieldsCache || customFieldsCache.length === 0) { area.innerHTML = ''; return; }
    var html = '<div style="font-size:13px;font-weight:600;color:#1d4ed8;margin:12px 0 8px;padding-bottom:4px;border-bottom:1px solid #dbeafe;">自定义字段</div>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">';
    customFieldsCache.forEach(function(f) {
        var val = (values && values[f.id]) ? values[f.id] : '';
        var reqMark = f.required ? ' *' : '';
        html += '<div class="form-group"><label>' + f.field_name + reqMark + '</label>';
        if (f.field_type === 'select') {
            var opts = [];
            try { opts = f.options ? JSON.parse(f.options) : []; } catch(e) { opts = []; }
            html += '<select id="cf_' + f.id + '"><option value="">请选择</option>';
            opts.forEach(function(o) { html += '<option value="'+o+'"'+(val===o?' selected':'')+'>'+o+'</option>'; });
            html += '</select>';
        } else if (f.field_type === 'textarea') {
            html += '<textarea id="cf_' + f.id + '" rows="2" placeholder="请输入'+f.field_name+'">'+val+'</textarea>';
        } else if (f.field_type === 'date') {
            html += '<input type="date" id="cf_' + f.id + '" value="'+val+'">';
        } else if (f.field_type === 'number') {
            html += '<input type="number" id="cf_' + f.id + '" value="'+val+'" placeholder="请输入'+f.field_name+'">';
        } else {
            html += '<input type="text" id="cf_' + f.id + '" value="'+val+'" placeholder="请输入'+f.field_name+'">';
        }
        html += '</div>';
    });
    html += '</div>';
    area.innerHTML = html;
}

function collectCustomFields() {
    var data = {};
    if (!customFieldsCache) return data;
    customFieldsCache.forEach(function(f) {
        var el = document.getElementById('cf_' + f.id);
        if (el) data[f.id] = el.value;
    });
    return data;
}

function setQuickDate(fieldId, daysOffset) {
    var d = new Date();
    d.setDate(d.getDate() + daysOffset);
    var str = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    document.getElementById(fieldId).value = str;
}

function loadDeptsAndDoctors() {
    var hid = document.getElementById('editHospitalId').value;
    var deptSel = document.getElementById('editDeptId');
    var docSel = document.getElementById('editDoctorName');
    deptSel.innerHTML = '<option value="">请选择科室</option>';
    docSel.innerHTML = '<option value="">请先选择科室</option>';
    if (!hid) return;
    apiGet('admin.php?module=doctor&action=getDepartments&hospital_id=' + hid, function(res) {
        var list = (res && res.code === 200 && res.data) ? res.data : (Array.isArray(res) ? res : []);
        for (var i = 0; i < list.length; i++) {
            var opt = document.createElement('option');
            opt.value = list[i].id; opt.text = list[i].name;
            deptSel.appendChild(opt);
        }
    });
}

function onDeptChange() {
    var hid = document.getElementById('editHospitalId').value;
    var did = document.getElementById('editDeptId').value;
    var docSel = document.getElementById('editDoctorName');
    docSel.innerHTML = '<option value="">请选择医生</option>';
    if (!did) return;
    doctors.forEach(function(d) {
        if (d.hospital_id == hid) {
            // 通过关联表查找：医生的关联科室包含当前选中科室
            var deptIds = doctorDeptMap[d.id] || [];
            var inMainDept = (d.department_id == did);
            var inLinkedDept = deptIds.indexOf(parseInt(did)) !== -1;
            if (inMainDept || inLinkedDept) {
                var opt = document.createElement('option');
                opt.value = d.name; opt.text = d.name + (d.title ? ' (' + d.title + ')' : '');
                docSel.appendChild(opt);
            }
        }
    });
}

// 列表中快速切换状态
function changeStatus(id, newStatus) {
    apiPost('admin.php?module=reservation&action=updateStatus', {id: id, status: newStatus}, function(d) {
        if(d.code===200) { showToast('状态已更新','success'); setTimeout(function(){location.reload();},500); }
        else { showToast(d.message || '更新失败','error'); location.reload(); }
    });
}

// 查重校验
function checkDuplicate() {
    var name = document.getElementById('editPatientName').value.trim();
    var phone = document.getElementById('editPatientPhone').value.trim();
    var editId = document.getElementById('editId').value;
    if (!name && !phone) { document.getElementById('dupWarning').style.display = 'none'; return; }
    var url = 'admin.php?module=api_check_duplicate';
    if (phone) url += '&phone=' + encodeURIComponent(phone);
    else if (name) url += '&name=' + encodeURIComponent(name);
    if (editId && editId !== '0') url += '&exclude_id=' + editId;
    apiGet(url, function(res) {
        var el = document.getElementById('dupWarning');
        var data = (res && res.code === 200 && res.data) ? res.data : [];
        if (data.length > 0) {
            var html = '<strong style="color:#92400e;">发现重复患者！</strong><br>';
            data.forEach(function(d) {
                html += '<div style="margin-top:4px;padding:4px 8px;background:#fff;border-radius:4px;border:1px solid #fde68a;">';
                html += '<span style="color:#1d4ed8;cursor:pointer;text-decoration:underline;" onclick="fillFromExisting('+d.id+')">#'+d.id+' '+d.patient_name+' / '+d.patient_phone+'</span>';
                html += ' <span style="color:#6b7280;">状态:'+d.status+' | 预约日:'+(d.reservation_date||'-')+'</span>';
                html += '</div>';
            });
            html += '<div style="margin-top:6px;">';
            html += '<button type="button" onclick="createSecondVisit('+data[0].id+')" class="btn btn-outline btn-sm" style="font-size:11px;margin-right:6px;">在此患者下二次预约</button>';
            html += '<button type="button" onclick="forceSave()" class="btn btn-primary btn-sm" style="font-size:11px;">强制添加新记录</button>';
            html += '</div>';
            el.innerHTML = html;
            el.style.display = 'block';
        } else {
            el.style.display = 'none';
        }
    });
}

// 从已有记录填充信息
function fillFromExisting(id) {
    apiGet('admin.php?module=reservation&action=detail&id=' + id, function(r) {
        if (r.code !== 200) return;
        var d = r.data;
        document.getElementById('editPatientName').value = d.patient_name;
        document.getElementById('editPatientPhone').value = d.patient_phone;
        document.getElementById('editWechat').value = d.wechat || '';
        document.getElementById('editIdcard').value = d.patient_idcard || '';
        document.getElementById('editReportAddress').value = d.report_address || '';
        document.getElementById('editFee').value = d.fee || '';
        document.getElementById('dupWarning').style.display = 'none';
        showToast('已填充患者信息', 'info');
    });
}

// 二次预约：基于已有患者创建新预约
function createSecondVisit(existingId) {
    apiGet('admin.php?module=reservation&action=detail&id=' + existingId, function(r) {
        if (r.code !== 200) return;
        var d = r.data;
        document.getElementById('editId').value = '0';
        document.getElementById('editPatientName').value = d.patient_name;
        document.getElementById('editPatientPhone').value = d.patient_phone;
        document.getElementById('editWechat').value = d.wechat || '';
        document.getElementById('editIdcard').value = d.patient_idcard || '';
        document.getElementById('editReportAddress').value = d.report_address || '';
        document.getElementById('editFee').value = '';
        document.getElementById('editDate').value = '';
        document.getElementById('editStatus').value = '已预约';
        document.getElementById('dupWarning').style.display = 'none';
        showToast('已填充患者信息，请填写新的预约日期', 'info');
    });
}

// 强制保存
var forceSaveFlag = false;
function forceSave() {
    forceSaveFlag = true;
    document.getElementById('dupWarning').style.display = 'none';
    showToast('已跳过重复检查，请点击保存', 'info');
}

function openAddModal() {
    document.getElementById('modalTitle').textContent = '新建预约';
    document.getElementById('editId').value = '0';
    document.getElementById('editPatientName').value = '';
    document.getElementById('editPatientPhone').value = '';
    document.getElementById('editWechat').value = '';
    document.getElementById('editHospitalId').value = '';
    document.getElementById('editDeptId').innerHTML = '<option value="">请先选择医院</option>';
    document.getElementById('editDoctorName').innerHTML = '<option value="">请先选择科室</option>';
    document.getElementById('editDate').value = '';
    document.getElementById('editTimePeriod').value = '上午';
    document.getElementById('editIdcard').value = '';
    document.getElementById('editSampleDate').value = '';
    document.getElementById('editTestDate').value = '';
    document.getElementById('editTestResult').value = '';
    document.getElementById('editReportAddress').value = '';
    document.getElementById('editFee').value = '';
    document.getElementById('editRemark').value = '';
    document.getElementById('editStatus').value = '已预约';
    document.getElementById('dupWarning').style.display = 'none';
    forceSaveFlag = false;
    openModal('editModal');
    loadCustomFields(function() { renderCustomFields({}); });
}

function editReservation(id) {
    apiGet('admin.php?module=reservation&action=detail&id=' + id, function(r) {
        if (r.code !== 200) { showToast(r.message, 'error'); return; }
        var d = r.data;
        document.getElementById('modalTitle').textContent = '编辑预约 #' + id;
        document.getElementById('editId').value = d.id;
        document.getElementById('editPatientName').value = d.patient_name;
        document.getElementById('editPatientPhone').value = d.patient_phone;
        document.getElementById('editWechat').value = d.wechat || '';
        document.getElementById('editHospitalId').value = d.hospital_id;
        document.getElementById('editDate').value = d.reservation_date;
        document.getElementById('editTimePeriod').value = d.time_period || '上午';
        document.getElementById('editIdcard').value = d.patient_idcard || '';
        document.getElementById('editSampleDate').value = d.sample_date || '';
        document.getElementById('editTestDate').value = d.test_date || '';
        document.getElementById('editTestResult').value = d.test_result || '';
        document.getElementById('editReportAddress').value = d.report_address || '';
        document.getElementById('editFee').value = d.fee || '';
        document.getElementById('editRemark').value = d.remark || '';
        document.getElementById('editStatus').value = d.status || '已预约';
        document.getElementById('dupWarning').style.display = 'none';
        loadDeptsAndDoctors();
        setTimeout(function() {
            document.getElementById('editDeptId').value = d.department_id || '';
            onDeptChange();
            setTimeout(function() {
                document.getElementById('editDoctorName').value = d.doctor || '';
            }, 100);
        }, 300);
        openModal('editModal');
        loadCustomFields(function() {
            var cfVals = {};
            if (d.custom_fields) {
                d.custom_fields.forEach(function(cf) { cfVals[cf.field_id] = cf.field_value; });
            }
            renderCustomFields(cfVals);
        });
    });
}

function saveReservation() {
    var d = {
        id: document.getElementById('editId').value,
        hospital_id: document.getElementById('editHospitalId').value,
        department_id: document.getElementById('editDeptId').value,
        department: document.getElementById('editDeptId').options[document.getElementById('editDeptId').selectedIndex] ? document.getElementById('editDeptId').options[document.getElementById('editDeptId').selectedIndex].text : '',
        doctor: document.getElementById('editDoctorName').value,
        patient_name: document.getElementById('editPatientName').value,
        patient_phone: document.getElementById('editPatientPhone').value,
        patient_idcard: document.getElementById('editIdcard').value,
        wechat: document.getElementById('editWechat').value,
        reservation_date: document.getElementById('editDate').value,
        time_period: document.getElementById('editTimePeriod').value,
        sample_date: document.getElementById('editSampleDate').value,
        test_date: document.getElementById('editTestDate').value,
        test_result: document.getElementById('editTestResult').value,
        report_address: document.getElementById('editReportAddress').value,
        fee: document.getElementById('editFee').value,
        remark: document.getElementById('editRemark').value,
        status: document.getElementById('editStatus').value,
        custom_fields: collectCustomFields()
    };
    if (!d.patient_name) { showToast('请填写患者姓名','error'); return; }
    if (!d.patient_phone) { showToast('请填写联系电话','error'); return; }
    if (!d.reservation_date) { showToast('请选择预约日期','error'); return; }
    if (!d.hospital_id) { showToast('请选择医院','error'); return; }
    apiPost('admin.php?module=reservation&action=save', d, function(r) {
        if (r.code === 200) { showToast('保存成功','success'); setTimeout(function(){location.reload();},500); }
        else { showToast(r.message,'error'); }
    });
}

function deleteReservation(id) {
    if (!confirmAction('确定删除此预约？此操作不可恢复！')) return;
    apiPost('admin.php?module=reservation&action=delete', {id:id}, function(d) {
        if(d.code===200) { showToast('已删除','success'); setTimeout(function(){location.reload();},500); }
        else { showToast(d.message,'error'); }
    });
}

function viewDetail(id) {
    apiGet('admin.php?module=reservation&action=detail&id=' + id, function(r) {
        if (r.code !== 200) { showToast(r.message, 'error'); return; }
        var d = r.data;
        var sc = null;
        if (typeof statusConfig !== 'undefined') {
            statusConfig.forEach(function(s) { if(s.name === d.status) sc = s; });
        }
        var statusBadge = sc 
            ? '<span style="display:inline-block;padding:4px 16px;border-radius:20px;font-size:13px;font-weight:600;color:'+sc.color+';background:'+sc.bg+';border:1px solid '+sc.color+'33;">'+d.status+'</span>'
            : '<span style="display:inline-block;padding:4px 16px;border-radius:20px;font-size:13px;font-weight:500;color:#6b7280;background:#f3f4f6;">'+d.status+'</span>';
        
        var html = '';
        // 顶部状态栏
        html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;background:linear-gradient(135deg,#1d4ed8 0%,#3b82f6 100%);border-radius:8px;margin-bottom:20px;color:#fff;">';
        html += '<div><div style="font-size:12px;opacity:.8;">预约编号</div><div style="font-size:22px;font-weight:700;">#' + d.id + '</div></div>';
        html += '<div style="text-align:right;"><div style="font-size:12px;opacity:.8;margin-bottom:4px;">当前状态</div>' + statusBadge + '</div>';
        html += '</div>';
        
        // 患者信息区
        html += '<div style="font-size:13px;font-weight:600;color:#1d4ed8;margin-bottom:8px;padding-bottom:6px;border-bottom:2px solid #dbeafe;">患者信息</div>';
        html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px 20px;margin-bottom:20px;">';
        html += makeInfoItem('患者姓名', d.patient_name, true);
        html += makeInfoItem('联系电话', d.patient_phone);
        html += makeInfoItem('身份证号', d.patient_idcard || '-');
        html += makeInfoItem('微信号', d.wechat || '-');
        html += makeInfoItem('报告邮寄', d.report_address || '-');
        html += makeInfoItem('预约时间', d.reservation_date + ' ' + d.time_period, true);
        html += '</div>';
        
        // 预约信息区
        html += '<div style="font-size:13px;font-weight:600;color:#1d4ed8;margin-bottom:8px;padding-bottom:6px;border-bottom:2px solid #dbeafe;">预约信息</div>';
        html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px 20px;margin-bottom:20px;">';
        html += makeInfoItem('医院', d.hospital_name || '-');
        html += makeInfoItem('科室', d.department || '-');
        html += makeInfoItem('医生', d.doctor || '-');
        html += makeInfoItem('采样日期', d.sample_date || '-');
        html += makeInfoItem('送检日期', d.test_date || '-');
        html += makeInfoItem('费用金额', d.fee ? ('¥' + parseFloat(d.fee).toFixed(2)) : '-', true);
        html += makeInfoItem('提交时间', new Date(d.addtime*1000).toLocaleString());
        html += '</div>';
        
        // 检测信息
        if (d.test_result) {
            html += '<div style="font-size:13px;font-weight:600;color:#1d4ed8;margin-bottom:8px;padding-bottom:6px;border-bottom:2px solid #dbeafe;">检测结果</div>';
            html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:14px;color:#166534;">' + d.test_result + '</div>';
        }
        
        // 备注区
        if (d.remark || d.admin_remark) {
            html += '<div style="font-size:13px;font-weight:600;color:#1d4ed8;margin-bottom:8px;padding-bottom:6px;border-bottom:2px solid #dbeafe;">备注信息</div>';
            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">';
            if (d.remark) html += '<div style="background:#fefce8;border-radius:6px;padding:10px 14px;"><div style="font-size:11px;color:#92400e;margin-bottom:4px;">患者备注</div><div style="font-size:13px;color:#713f12;">' + d.remark + '</div></div>';
            if (d.admin_remark) html += '<div style="background:#eff6ff;border-radius:6px;padding:10px 14px;"><div style="font-size:11px;color:#1e40af;margin-bottom:4px;">管理员备注</div><div style="font-size:13px;color:#1e3a5f;">' + d.admin_remark + '</div></div>';
            html += '</div>';
        }
        
        // 自定义字段
        if (d.custom_fields && d.custom_fields.length > 0) {
            html += '<div style="font-size:13px;font-weight:600;color:#1d4ed8;margin-bottom:8px;padding-bottom:6px;border-bottom:2px solid #dbeafe;">扩展字段</div>';
            html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px 20px;margin-bottom:20px;">';
            d.custom_fields.forEach(function(cf) {
                html += makeInfoItem(cf.field_name, cf.field_value || '-');
            });
            html += '</div>';
        }
        
        // 随访记录
        if (d.follow_ups && d.follow_ups.length > 0) {
            html += '<div style="font-size:13px;font-weight:600;color:#1d4ed8;margin-bottom:8px;padding-bottom:6px;border-bottom:2px solid #dbeafe;">随访记录 (' + d.follow_ups.length + ')</div>';
            d.follow_ups.forEach(function(f) {
                var typeMap = {phone:'电话',wechat:'微信',visit:'到访'};
                var resultMap = {normal:'正常',abnormal:'异常',no_answer:'未接听',cancelled:'取消'};
                var resultColor = f.follow_result==='normal'?'#059669':(f.follow_result==='abnormal'?'#dc2626':'#6b7280');
                html += '<div style="background:#f9fafb;padding:10px 14px;border-radius:8px;margin-top:8px;border-left:3px solid '+resultColor+';">';
                html += '<div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:12px;color:'+resultColor+';font-weight:500;">' + typeMap[f.follow_type] + '随访 · ' + resultMap[f.follow_result] + '</span><span style="font-size:11px;color:#9ca3af;">' + new Date(f.addtime*1000).toLocaleString() + '</span></div>';
                html += '<div style="margin-top:6px;font-size:13px;color:#374151;">' + (f.content || '无内容') + '</div>';
                if (f.next_date) html += '<div style="font-size:12px;color:#d97706;margin-top:4px;">下次随访: ' + f.next_date + '</div>';
                html += '</div>';
            });
        }
        
        // 底部操作
        html += '<div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;text-align:center;display:flex;gap:10px;justify-content:center;">';
        html += '<a href="admin.php?module=patient&action=detail&phone=' + encodeURIComponent(d.patient_phone) + '" class="btn btn-primary btn-sm">查看患者档案</a>';
        html += '<button onclick="closeModal(\'detailModal\')" class="btn btn-outline btn-sm">关闭</button>';
        html += '</div>';
        
        document.getElementById('detailBody').innerHTML = html;
        openModal('detailModal');
    });
}

function makeInfoItem(label, value, bold) {
    return '<div style="padding:6px 0;"><div style="font-size:11px;color:#9ca3af;margin-bottom:2px;">' + label + '</div><div style="font-size:14px;color:#111827;' + (bold?'font-weight:600;':'') + '">' + (value||'-') + '</div></div>';
}
</script>
<?php
renderAdmin('预约管理', 'reservation', ob_get_clean());
