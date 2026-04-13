<?php
/**
 * 数据导出页面 - 自定义字段+时间段筛选+CSV导出
 */
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// ===== 导出API =====
if ($action === 'doExport' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    $startDate = isset($input['start_date']) ? trim($input['start_date']) : '';
    $endDate = isset($input['end_date']) ? trim($input['end_date']) : '';
    $statusFilter = isset($input['status']) ? trim($input['status']) : '';
    $hospitalId = isset($input['hospital_id']) ? intval($input['hospital_id']) : 0;
    $selectedFields = isset($input['fields']) ? $input['fields'] : array();
    
    if (empty($selectedFields)) {
        echo json_encode(array('code'=>400,'message'=>'请至少选择一个导出字段'));
        exit;
    }
    
    // 构建查询条件
    $conditions = array();
    $params = array();
    
    if ($startDate) {
        $conditions[] = "r.addtime >= ?";
        $params[] = strtotime($startDate . ' 00:00:00');
    }
    if ($endDate) {
        $conditions[] = "r.addtime <= ?";
        $params[] = strtotime($endDate . ' 23:59:59');
    }
    if ($statusFilter) {
        $conditions[] = "r.status = ?";
        $params[] = $statusFilter;
    }
    if ($hospitalId) {
        $conditions[] = "r.hospital_id = ?";
        $params[] = $hospitalId;
    }
    
    $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    try {
        $rows = $db->query(
            "SELECT r.*, h.name as hospital_name 
             FROM {$prefix}reservation r 
             LEFT JOIN {$prefix}hospital h ON r.hospital_id=h.id 
             {$where} ORDER BY r.addtime DESC LIMIT 10000",
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
        
        // 获取自定义字段定义和值
        $resCustomFields = array();
        $cfDefs = array();
        try {
            $cfDefs = $db->query(
                "SELECT * FROM {$prefix}custom_field WHERE target_table='reservation' AND status=1 ORDER BY sort DESC, id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($cfDefs) && !empty($rows)) {
                $resIds = array_column($rows, 'id');
                $idList = implode(',', array_map('intval', $resIds));
                $cfValues = $db->query(
                    "SELECT fv.target_id, cf.field_key, cf.field_name, fv.field_value 
                     FROM {$prefix}custom_field_value fv 
                     INNER JOIN {$prefix}custom_field cf ON fv.field_id=cf.id 
                     WHERE fv.target_table='reservation' AND fv.target_id IN ({$idList})"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($cfValues as $cv) {
                    if (!isset($resCustomFields[$cv['target_id']])) {
                        $resCustomFields[$cv['target_id']] = array();
                    }
                    $resCustomFields[$cv['target_id']][$cv['field_key']] = $cv['field_value'];
                }
            }
        } catch(PDOException $e) {}
        
        // 字段映射
        $fieldMap = array(
            'id' => '预约编号',
            'patient_name' => '患者姓名',
            'patient_phone' => '手机号',
            'patient_idcard' => '身份证号',
            'wechat' => '微信号',
            'hospital_name' => '医院',
            'department' => '科室',
            'doctor' => '医生',
            'reservation_date' => '预约日期',
            'time_period' => '时段',
            'sample_date' => '采样日期',
            'test_date' => '送检日期',
            'test_result' => '检测结果',
            'report_address' => '报告邮寄地址',
            'fee' => '费用金额',
            'status' => '状态',
            'remark' => '患者备注',
            'admin_remark' => '管理员备注',
            'addtime' => '提交时间'
        );
        
        // 生成CSV内容
        $headers = array();
        foreach ($selectedFields as $field) {
            if (isset($fieldMap[$field])) {
                $headers[] = $fieldMap[$field];
            } elseif (strpos($field, 'cf_') === 0) {
                $fk = substr($field, 3);
                foreach ($cfDefs as $cfd) {
                    if ($cfd['field_key'] === $fk) { $headers[] = $cfd['field_name']; break; }
                }
            }
        }
        
        $csvLines = array();
        $csvLines[] = "\xEF\xBB\xBF" . implode(',', array_map(function($h){ return '"'.str_replace('"','""',$h).'"'; }, $headers));
        
        foreach ($rows as $row) {
            $values = array();
            $cf = isset($resCustomFields[$row['id']]) ? $resCustomFields[$row['id']] : array();
            foreach ($selectedFields as $field) {
                $val = '';
                if (isset($fieldMap[$field]) && $field !== 'addtime') {
                    $val = isset($row[$field]) ? $row[$field] : '';
                } elseif ($field === 'addtime') {
                    $val = isset($row['addtime']) ? date('Y-m-d H:i:s', $row['addtime']) : '';
                } elseif (strpos($field, 'cf_') === 0) {
                    $fk = substr($field, 3);
                    $val = isset($cf[$fk]) ? $cf[$fk] : '';
                }
                $values[] = '"' . str_replace('"', '""', $val) . '"';
            }
            $csvLines[] = implode(',', $values);
        }
        
        $csvContent = implode("\n", $csvLines);
        $fileName = '客户数据导出_' . date('Ymd_His') . '.csv';
        // uploads 目录在项目根目录下
        $uploadDir = dirname(__DIR__) . '/uploads/';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
        $filePath = $uploadDir . $fileName;
        file_put_contents($filePath, $csvContent);
        
        // 记录操作日志
        if (function_exists('addLog')) {
            addLog('export', 'reservation', 0, "导出客户数据 " . count($rows) . " 条");
        }
        echo json_encode(array('code'=>200,'data'=>array('file'=>$fileName,'count'=>count($rows))));
    } catch(PDOException $e) {
        echo json_encode(array('code'=>500,'message'=>'导出失败: '.$e->getMessage()));
    }
    exit;
}

// ===== 页面渲染 =====
$hospitals = $db->select('hospital', array('status'=>1), 'id, name', 'sort DESC');

// 获取预约状态配置
$statusConfig = array();
try {
    $cf = $db->find('config', array('config_key' => 'reservation_status_config'));
    if ($cf) { $statusConfig = json_decode($cf['config_value'], true); }
} catch(PDOException $e) {}
if (empty($statusConfig)) {
    $statusConfig = array(
        array('name' => '待确认'), array('name' => '已预约'),
        array('name' => '已寄送'), array('name' => '已成单'), array('name' => '已取消')
    );
}

// 获取预约模块自定义字段
$customFields = array();
try {
    $customFields = $db->query(
        "SELECT * FROM {$prefix}custom_field WHERE target_table='reservation' AND status=1 ORDER BY sort DESC, id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {}

// 可选导出字段
$exportFields = array(
    array('key' => 'id', 'name' => '预约编号', 'group' => '基础'),
    array('key' => 'patient_name', 'name' => '患者姓名', 'group' => '基础'),
    array('key' => 'patient_phone', 'name' => '手机号', 'group' => '基础'),
    array('key' => 'patient_idcard', 'name' => '身份证号', 'group' => '基础'),
    array('key' => 'wechat', 'name' => '微信号', 'group' => '基础'),
    array('key' => 'hospital_name', 'name' => '医院', 'group' => '预约'),
    array('key' => 'department', 'name' => '科室', 'group' => '预约'),
    array('key' => 'doctor', 'name' => '医生', 'group' => '预约'),
    array('key' => 'reservation_date', 'name' => '预约日期', 'group' => '预约'),
    array('key' => 'time_period', 'name' => '时段', 'group' => '预约'),
    array('key' => 'sample_date', 'name' => '采样日期', 'group' => '预约'),
    array('key' => 'test_date', 'name' => '送检日期', 'group' => '预约'),
    array('key' => 'test_result', 'name' => '检测结果', 'group' => '预约'),
    array('key' => 'report_address', 'name' => '报告邮寄地址', 'group' => '预约'),
    array('key' => 'fee', 'name' => '费用金额', 'group' => '预约'),
    array('key' => 'status', 'name' => '状态', 'group' => '预约'),
    array('key' => 'remark', 'name' => '患者备注', 'group' => '备注'),
    array('key' => 'admin_remark', 'name' => '管理员备注', 'group' => '备注'),
    array('key' => 'addtime', 'name' => '提交时间', 'group' => '备注')
);
foreach ($customFields as $cf) {
    $exportFields[] = array('key' => 'cf_' . $cf['field_key'], 'name' => $cf['field_name'], 'group' => '自定义');
}

// 按分组归类
$fieldGroups = array();
foreach ($exportFields as $ef) {
    $fieldGroups[$ef['group']][] = $ef;
}

ob_start();
?>
<div class="card">
    <div class="card-header"><h3>客户数据导出</h3></div>
    <div class="card-body">
        <!-- 筛选条件 -->
        <div style="margin-bottom:20px;">
            <div style="font-size:13px;font-weight:600;color:#1d4ed8;padding-bottom:8px;border-bottom:2px solid #dbeafe;margin-bottom:12px;">筛选条件</div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label>开始日期</label>
                    <input type="date" id="startDate">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>结束日期</label>
                    <input type="date" id="endDate">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>预约状态</label>
                    <select id="statusFilter">
                        <option value="">全部状态</option>
                        <?php foreach($statusConfig as $sc): ?>
                        <option value="<?php echo htmlspecialchars($sc['name']); ?>"><?php echo htmlspecialchars($sc['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>所属医院</label>
                    <select id="hospitalFilter">
                        <option value="0">全部医院</option>
                        <?php foreach($hospitals as $h): ?>
                        <option value="<?php echo $h['id']; ?>"><?php echo htmlspecialchars($h['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px;">
                <button type="button" onclick="setQuickDate('today')" class="btn btn-outline btn-sm">今天</button>
                <button type="button" onclick="setQuickDate('week')" class="btn btn-outline btn-sm">本周</button>
                <button type="button" onclick="setQuickDate('month')" class="btn btn-outline btn-sm">本月</button>
                <button type="button" onclick="setQuickDate('lastMonth')" class="btn btn-outline btn-sm">上月</button>
                <button type="button" onclick="setQuickDate('quarter')" class="btn btn-outline btn-sm">本季度</button>
                <button type="button" onclick="setQuickDate('year')" class="btn btn-outline btn-sm">今年</button>
            </div>
        </div>
        
        <!-- 字段选择 -->
        <div style="margin-bottom:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div style="font-size:13px;font-weight:600;color:#1d4ed8;padding-bottom:8px;border-bottom:2px solid #dbeafe;flex:1;">导出字段</div>
                <div style="display:flex;gap:6px;">
                    <button onclick="selectAll(true)" class="btn btn-outline btn-sm">全选</button>
                    <button onclick="selectAll(false)" class="btn btn-outline btn-sm">取消全选</button>
                    <button onclick="selectPreset('basic')" class="btn btn-outline btn-sm">常用字段</button>
                </div>
            </div>
            <?php foreach($fieldGroups as $groupName => $fields): ?>
            <div style="margin-bottom:10px;">
                <div style="font-size:12px;color:#6b7280;margin-bottom:6px;font-weight:500;"><?php echo $groupName; ?></div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    <?php foreach($fields as $f): ?>
                    <label style="display:inline-flex;align-items:center;padding:4px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;cursor:pointer;background:#fff;transition:all .15s;" class="field-label" data-key="<?php echo htmlspecialchars($f['key']); ?>">
                        <input type="checkbox" name="export_field" value="<?php echo htmlspecialchars($f['key']); ?>" style="margin-right:4px;" checked>
                        <?php echo htmlspecialchars($f['name']); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- 导出按钮 -->
        <div style="display:flex;gap:12px;align-items:center;">
            <button onclick="doExport()" class="btn btn-primary" id="exportBtn">导出 CSV 文件</button>
            <span id="exportStatus" style="font-size:13px;color:#6b7280;"></span>
        </div>
    </div>
</div>

<script>
function setQuickDate(type) {
    var now = new Date();
    var start, end;
    if (type === 'today') {
        start = end = formatDate(now);
    } else if (type === 'week') {
        var day = now.getDay() || 7;
        start = formatDate(new Date(now.getFullYear(), now.getMonth(), now.getDate() - day + 1));
        end = formatDate(now);
    } else if (type === 'month') {
        start = formatDate(new Date(now.getFullYear(), now.getMonth(), 1));
        end = formatDate(now);
    } else if (type === 'lastMonth') {
        start = formatDate(new Date(now.getFullYear(), now.getMonth() - 1, 1));
        end = formatDate(new Date(now.getFullYear(), now.getMonth(), 0));
    } else if (type === 'quarter') {
        var q = Math.floor(now.getMonth() / 3);
        start = formatDate(new Date(now.getFullYear(), q * 3, 1));
        end = formatDate(now);
    } else if (type === 'year') {
        start = formatDate(new Date(now.getFullYear(), 0, 1));
        end = formatDate(now);
    }
    document.getElementById('startDate').value = start;
    document.getElementById('endDate').value = end;
}

function formatDate(d) {
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}

function selectAll(checked) {
    var cbs = document.querySelectorAll('input[name="export_field"]');
    for (var i = 0; i < cbs.length; i++) { cbs[i].checked = checked; }
}

function selectPreset(type) {
    selectAll(false);
    var basicKeys = ['patient_name','patient_phone','hospital_name','department','doctor','reservation_date','status','fee','addtime'];
    if (type === 'basic') {
        var cbs = document.querySelectorAll('input[name="export_field"]');
        for (var i = 0; i < cbs.length; i++) {
            if (basicKeys.indexOf(cbs[i].value) !== -1) cbs[i].checked = true;
        }
    }
}

function doExport() {
    var fields = [];
    var cbs = document.querySelectorAll('input[name="export_field"]:checked');
    for (var i = 0; i < cbs.length; i++) { fields.push(cbs[i].value); }
    
    if (fields.length === 0) { showToast('请至少选择一个导出字段','error'); return; }
    
    var btn = document.getElementById('exportBtn');
    btn.disabled = true;
    btn.textContent = '导出中...';
    document.getElementById('exportStatus').textContent = '';
    
    apiPost('admin.php?module=export&action=doExport', {
        start_date: document.getElementById('startDate').value,
        end_date: document.getElementById('endDate').value,
        status: document.getElementById('statusFilter').value,
        hospital_id: document.getElementById('hospitalFilter').value,
        fields: fields
    }, function(r) {
        btn.disabled = false;
        btn.textContent = '导出 CSV 文件';
        if (r.code === 200) {
            document.getElementById('exportStatus').textContent = '已导出 ' + r.data.count + ' 条记录';
            showToast('导出成功，共 ' + r.data.count + ' 条', 'success');
            // 触发下载
            window.location.href = 'uploads/' + r.data.file;
        } else {
            showToast(r.message, 'error');
        }
    });
}
</script>
<?php renderAdmin('数据导出','export',ob_get_clean());
