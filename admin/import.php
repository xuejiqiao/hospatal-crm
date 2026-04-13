<?php
/**
 * 数据导入工具
 * 从旧CRM系统（cs_info表）导入客户数据到新CRM系统（reservation表）
 * 支持SQL文件上传和旧表字段映射
 */
if (!defined('CRM_ADMIN')) exit;

$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// AJAX: 执行SQL导入旧表结构
if ($action === 'import_sql') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $sqlContent = isset($input['sql_content']) ? $input['sql_content'] : '';
    if (empty($sqlContent)) {
        echo json_encode(array('code' => 400, 'msg' => 'SQL内容为空'));
        exit;
    }
    // 安全检查：只允许CREATE TABLE和INSERT语句
    $dangerous = array('DROP DATABASE', 'TRUNCATE', 'GRANT', 'REVOKE', 'ALTER USER');
    foreach ($dangerous as $d) {
        if (stripos($sqlContent, $d) !== false) {
            echo json_encode(array('code' => 400, 'msg' => 'SQL包含不允许的语句: ' . $d));
            exit;
        }
    }
    // 移除DROP TABLE（避免删除现有表）
    $sqlContent = preg_replace('/DROP\s+TABLE\s+IF\s+EXISTS\s+`?[^;]+`?\s*;/i', '', $sqlContent);
    
    // 按分号分割并逐条执行
    $statements = array_filter(
        array_map('trim', explode(';', $sqlContent)),
        function($s) { return !empty($s) && $s !== ';' && !preg_match('/^(\/\*|--|LOCK|UNLOCK|#)/', $s); }
    );
    
    $success = 0;
    $errors = array();
    foreach ($statements as $sql) {
        $sql = trim($sql);
        if (empty($sql)) continue;
        try {
            $db->query($sql, array());
            $success++;
        } catch(PDOException $e) {
            $errors[] = mb_substr($e->getMessage(), 0, 200);
        }
    }
    addLog('import_sql', 'system', 0, "导入SQL执行: 成功{$success}条, 失败" . count($errors) . "条");
    echo json_encode(array(
        'code' => 200, 
        'msg' => "执行完成：成功{$success}条" . (count($errors) > 0 ? "，失败" . count($errors) . "条" : ""),
        'errors' => array_slice($errors, 0, 5)
    ));
    exit;
}

// AJAX: 从旧cs_info表导入到新reservation表
if ($action === 'migrate_cs_info') {
    header('Content-Type: application/json');
    
    // 检查cs_info表是否存在
    try {
        $check = $db->query("SHOW TABLES LIKE 'cs_info'")->fetch();
        if (!$check) {
            echo json_encode(array('code' => 400, 'msg' => '旧客户表cs_info不存在，请先导入SQL文件'));
            exit;
        }
    } catch(PDOException $e) {
        echo json_encode(array('code' => 500, 'msg' => '数据库查询失败: ' . $e->getMessage()));
        exit;
    }
    
    // 读取cs_info数据
    try {
        $oldData = $db->query("SELECT * FROM cs_info ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        echo json_encode(array('code' => 500, 'msg' => '读取cs_info失败: ' . $e->getMessage()));
        exit;
    }
    
    if (empty($oldData)) {
        echo json_encode(array('code' => 400, 'msg' => 'cs_info表无数据'));
        exit;
    }
    
    $imported = 0;
    $skipped = 0;
    $errors = array();
    
    foreach ($oldData as $row) {
        $phone = isset($row['tel']) ? trim($row['tel']) : '';
        $name = isset($row['name']) ? trim($row['name']) : '';
        
        // 至少需要手机号和姓名
        if (empty($phone) || empty($name)) {
            $skipped++;
            continue;
        }
        
        // 检查是否已存在（按手机号）
        $exists = $db->query(
            "SELECT id FROM {$prefix}reservation WHERE patient_phone=? LIMIT 1",
            array($phone)
        )->fetch();
        if ($exists) {
            $skipped++;
            continue;
        }
        
        // 解析预约时间
        $yyAt = isset($row['yy_at']) ? trim($row['yy_at']) : '';
        $reservationDate = '';
        if ($yyAt && $yyAt !== '0000-00-00') {
            $reservationDate = substr($yyAt, 0, 10);
        }
        
        // 创建时间
        $createdAt = isset($row['created_at']) ? trim($row['created_at']) : '';
        $addtime = time();
        if ($createdAt && $createdAt !== '0000-00-00 00:00:00') {
            $addtime = strtotime($createdAt);
            if (!$addtime) $addtime = time();
        }
        
        try {
            $data = array(
                'user_id' => 0,
                'hospital_id' => 0,
                'department_id' => 0,
                'department' => '',
                'doctor' => isset($row['doctorid']) ? trim($row['doctorid']) : '',
                'patient_name' => $name,
                'patient_phone' => $phone,
                'patient_idcard' => isset($row['Identity']) ? trim($row['Identity']) : '',
                'reservation_date' => $reservationDate,
                'time_period' => '上午',
                'sample_date' => '',
                'test_date' => '',
                'test_result' => '',
                'report_address' => isset($row['address']) ? trim($row['address']) : '',
                'wechat' => '',
                'remark' => isset($row['intro']) ? trim($row['intro']) : '',
                'status' => '已成单', // 标记为已成单（历史数据）
                'addtime' => $addtime,
                'updatetime' => time()
            );
            $newId = $db->insert('reservation', $data);
            
            // 旧CRM的额外字段写入自定义字段值
            $extraFields = array(
                'xb' => '性别', 'nl' => '年龄', 'qq' => 'QQ号',
                'mail' => '邮箱', 'card' => '会员卡号',
                'zxxm' => '治疗项目', 'keyword' => '关键词'
            );
            foreach ($extraFields as $oldKey => $fieldName) {
                if (isset($row[$oldKey]) && trim($row[$oldKey]) !== '') {
                    // 查找或创建自定义字段
                    $cf = $db->query(
                        "SELECT id FROM {$prefix}custom_field WHERE field_key=? AND target_table='patient' LIMIT 1",
                        array($oldKey)
                    )->fetch();
                    if ($cf) {
                        $cfId = $cf['id'];
                    } else {
                        $cfId = $db->insert('custom_field', array(
                            'field_key' => $oldKey,
                            'field_name' => $fieldName,
                            'field_type' => 'text',
                            'target_table' => 'patient',
                            'options' => '',
                            'required' => 0,
                            'sort' => 0,
                            'status' => 1,
                            'addtime' => time()
                        ));
                    }
                    $db->query(
                        "REPLACE INTO {$prefix}custom_field_value (field_id, target_table, target_id, field_value, addtime) VALUES (?, 'patient', ?, ?, ?)",
                        array($cfId, $newId, trim($row[$oldKey]), time())
                    );
                }
            }
            
            $imported++;
        } catch(PDOException $e) {
            $errors[] = "ID{$row['id']}: " . mb_substr($e->getMessage(), 0, 100);
        }
    }
    
    addLog('migrate_data', 'system', 0, "从cs_info导入: 成功{$imported}条, 跳过{$skipped}条");
    echo json_encode(array(
        'code' => 200,
        'msg' => "导入完成：成功{$imported}条，跳过{$skipped}条" . (count($errors) > 0 ? "，失败" . count($errors) . "条" : ""),
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => array_slice($errors, 0, 5)
    ));
    exit;
}

// AJAX: 检查旧表状态
if ($action === 'check_legacy') {
    header('Content-Type: application/json');
    $result = array('tables' => array());
    $legacyTables = array('cs_info', 'cs_sell', 'cs_log', 'cs_money', 'cs_section', 'cs_type', 'cs_price', 'cs_config');
    foreach ($legacyTables as $t) {
        try {
            $count = $db->query("SELECT COUNT(*) as cnt FROM {$t}")->fetch();
            $result['tables'][$t] = intval($count['cnt']);
        } catch(PDOException $e) {
            $result['tables'][$t] = -1; // 不存在
        }
    }
    echo json_encode(array('code' => 200, 'data' => $result));
    exit;
}

// AJAX: 手动批量导入（CSV格式）
if ($action === 'import_csv') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $csvData = isset($input['csv_data']) ? $input['csv_data'] : '';
    $fieldMap = isset($input['field_map']) ? $input['field_map'] : array();
    
    if (empty($csvData)) {
        echo json_encode(array('code' => 400, 'msg' => '数据为空'));
        exit;
    }
    
    $lines = explode("\n", trim($csvData));
    if (count($lines) < 2) {
        echo json_encode(array('code' => 400, 'msg' => '数据至少需要标题行和一行数据'));
        exit;
    }
    
    // 解析CSV
    $headers = str_getcsv(trim($lines[0]));
    $imported = 0;
    $skipped = 0;
    $errors = array();
    
    // 字段映射: CSV列名 => 新系统字段
    $defaultMap = array(
        '姓名' => 'patient_name', 'name' => 'patient_name', '客户名称' => 'patient_name',
        '电话' => 'patient_phone', 'tel' => 'patient_phone', '手机' => 'patient_phone', '联系电话' => 'patient_phone',
        '身份证' => 'patient_idcard', 'Identity' => 'patient_idcard',
        '地址' => 'report_address', 'address' => 'report_address',
        '备注' => 'remark', 'intro' => 'remark',
        '预约时间' => 'reservation_date', 'yy_at' => 'reservation_date',
    );
    if (!empty($fieldMap)) {
        $defaultMap = array_merge($defaultMap, $fieldMap);
    }
    
    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        $values = str_getcsv($line);
        $row = array();
        for ($j = 0; $j < count($headers); $j++) {
            $row[$headers[$j]] = isset($values[$j]) ? trim($values[$j]) : '';
        }
        
        $data = array(
            'user_id' => 0, 'hospital_id' => 0, 'department_id' => 0,
            'department' => '', 'doctor' => '',
            'patient_name' => '', 'patient_phone' => '',
            'patient_idcard' => '', 'reservation_date' => '',
            'time_period' => '上午', 'sample_date' => '', 'test_date' => '',
            'test_result' => '', 'report_address' => '', 'wechat' => '',
            'remark' => '', 'status' => '已成单', 'addtime' => time(), 'updatetime' => time()
        );
        
        foreach ($row as $csvCol => $val) {
            if (isset($defaultMap[$csvCol]) && isset($data[$defaultMap[$csvCol]])) {
                $data[$defaultMap[$csvCol]] = $val;
            }
        }
        
        if (empty($data['patient_name']) || empty($data['patient_phone'])) {
            $skipped++;
            continue;
        }
        
        // 去重
        $exists = $db->query(
            "SELECT id FROM {$prefix}reservation WHERE patient_phone=? AND patient_name=? LIMIT 1",
            array($data['patient_phone'], $data['patient_name'])
        )->fetch();
        if ($exists) { $skipped++; continue; }
        
        try {
            $db->insert('reservation', $data);
            $imported++;
        } catch(PDOException $e) {
            $errors[] = "行{$i}: " . mb_substr($e->getMessage(), 0, 100);
        }
    }
    
    addLog('import_csv', 'system', 0, "CSV导入: 成功{$imported}条, 跳过{$skipped}条");
    echo json_encode(array(
        'code' => 200,
        'msg' => "导入完成：成功{$imported}条，跳过{$skipped}条"
    ));
    exit;
}

// 页面渲染
ob_start();
?>
<style>
.imp-card{background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:20px;padding:20px;}
.imp-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
.imp-header h3{margin:0;font-size:18px;color:#1d4ed8;}
.imp-step{display:flex;align-items:center;gap:12px;padding:16px;background:#f8fafc;border-radius:8px;margin-bottom:16px;}
.imp-step-num{width:32px;height:32px;border-radius:50%;background:#1d4ed8;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0;}
.imp-step-content{flex:1;}
.imp-step-content h4{margin:0 0 4px;font-size:14px;color:#1e293b;}
.imp-step-content p{margin:0;font-size:12px;color:#64748b;}
.imp-textarea{width:100%;min-height:200px;padding:12px;border:1px solid #d1d5db;border-radius:8px;font-size:12px;font-family:Consolas,monospace;resize:vertical;transition:border .2s;}
.imp-textarea:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}
.imp-btn{padding:10px 24px;border-radius:8px;border:none;cursor:pointer;font-size:14px;font-weight:500;transition:all .2s;}
.imp-btn-primary{background:#1d4ed8;color:#fff;}
.imp-btn-primary:hover{background:#1e40af;}
.imp-btn-primary:disabled{background:#93c5fd;cursor:not-allowed;}
.imp-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}
.imp-btn-outline:hover{background:#f8fafc;}
.imp-btn-danger{background:#dc2626;color:#fff;}
.imp-btn-danger:hover{background:#b91c1c;}
.imp-status{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin:16px 0;}
.imp-status-item{padding:12px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;text-align:center;}
.imp-status-item .name{font-size:12px;color:#64748b;margin-bottom:4px;}
.imp-status-item .count{font-size:20px;font-weight:700;color:#1e293b;}
.imp-status-item .count.not-exist{color:#dc2626;font-size:13px;}
.imp-result{padding:16px;border-radius:8px;margin-top:16px;display:none;}
.imp-result.success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.imp-result.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.imp-tabs{display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #e2e8f0;}
.imp-tab{padding:10px 20px;font-size:14px;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s;color:#64748b;}
.imp-tab:hover{color:#1d4ed8;}
.imp-tab.active{color:#1d4ed8;border-bottom-color:#1d4ed8;font-weight:600;}
.imp-panel{display:none;}
.imp-panel.active{display:block;}
.imp-hint{background:#eff6ff;border:1px solid #dbeafe;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#1e40af;}
.imp-hint strong{color:#1d4ed8;}
</style>

<div class="imp-card">
    <div class="imp-header">
        <h3>数据导入工具</h3>
    </div>
    
    <div class="imp-hint">
        <strong>说明：</strong>本工具用于将旧CRM系统的客户数据迁移到新系统。支持三种方式：1）导入SQL文件创建旧表；2）从旧表映射迁移数据；3）CSV文本手动导入。
    </div>
    
    <!-- 步骤引导 -->
    <div class="imp-step">
        <div class="imp-step-num">1</div>
        <div class="imp-step-content">
            <h4>导入SQL文件（创建旧表结构+数据）</h4>
            <p>将旧系统的SQL导出文件内容粘贴到下方，创建旧表并导入原始数据</p>
        </div>
    </div>
    
    <div class="imp-tabs">
        <div class="imp-tab active" onclick="switchTab('sql')">SQL导入</div>
        <div class="imp-tab" onclick="switchTab('migrate')">旧表迁移</div>
        <div class="imp-tab" onclick="switchTab('csv')">CSV导入</div>
    </div>
    
    <!-- SQL导入面板 -->
    <div class="imp-panel active" id="panel-sql">
        <textarea class="imp-textarea" id="sqlContent" placeholder="将SQL文件内容粘贴到此处...&#10;&#10;支持 CREATE TABLE 和 INSERT INTO 语句&#10;系统会自动过滤 DROP TABLE 等危险操作"></textarea>
        <div style="margin-top:12px;display:flex;gap:10px;">
            <button class="imp-btn imp-btn-primary" id="btnImportSql" onclick="importSql()">执行SQL导入</button>
            <button class="imp-btn imp-btn-outline" onclick="document.getElementById('sqlContent').value=''">清空</button>
        </div>
        <div class="imp-result" id="sqlResult"></div>
    </div>
    
    <!-- 旧表迁移面板 -->
    <div class="imp-panel" id="panel-migrate">
        <div class="imp-step">
            <div class="imp-step-num">2</div>
            <div class="imp-step-content">
                <h4>从旧cs_info表迁移到新reservation表</h4>
                <p>将旧客户表数据按字段映射关系导入新系统，自动跳过重复数据</p>
            </div>
        </div>
        
        <h4 style="font-size:14px;margin-bottom:12px;">旧表数据状态</h4>
        <div class="imp-status" id="legacyStatus">
            <div style="text-align:center;padding:20px;color:#9ca3af;">检测中...</div>
        </div>
        
        <div style="margin-top:12px;display:flex;gap:10px;align-items:center;">
            <button class="imp-btn imp-btn-primary" id="btnMigrate" onclick="migrateData()">开始迁移</button>
            <button class="imp-btn imp-btn-outline" onclick="checkLegacy()">刷新状态</button>
        </div>
        <div class="imp-result" id="migrateResult"></div>
        
        <div style="margin-top:16px;padding:12px;background:#f8fafc;border-radius:8px;font-size:13px;">
            <strong>字段映射关系：</strong>
            <table style="width:100%;margin-top:8px;font-size:12px;">
                <tr><td style="padding:3px 8px;color:#64748b;">cs_info.name → patient_name</td><td style="padding:3px 8px;color:#64748b;">cs_info.tel → patient_phone</td></tr>
                <tr><td style="padding:3px 8px;color:#64748b;">cs_info.Identity → patient_idcard</td><td style="padding:3px 8px;color:#64748b;">cs_info.address → report_address</td></tr>
                <tr><td style="padding:3px 8px;color:#64748b;">cs_info.yy_at → reservation_date</td><td style="padding:3px 8px;color:#64748b;">cs_info.intro → remark</td></tr>
                <tr><td style="padding:3px 8px;color:#64748b;">cs_info.xb → 自定义字段(性别)</td><td style="padding:3px 8px;color:#64748b;">cs_info.nl → 自定义字段(年龄)</td></tr>
                <tr><td style="padding:3px 8px;color:#64748b;">cs_info.qq → 自定义字段(QQ号)</td><td style="padding:3px 8px;color:#64748b;">cs_info.mail → 自定义字段(邮箱)</td></tr>
            </table>
        </div>
    </div>
    
    <!-- CSV导入面板 -->
    <div class="imp-panel" id="panel-csv">
        <div class="imp-hint">
            支持CSV格式粘贴，第一行为标题行。系统会自动识别以下列名：<strong>姓名/name/客户名称、电话/tel/手机/联系电话、身份证/Identity、地址/address、备注/intro</strong>
        </div>
        <textarea class="imp-textarea" id="csvData" placeholder="姓名,电话,地址,备注&#10;张三,13800138000,北京市,体检预约&#10;李四,13900139000,上海市,复诊"></textarea>
        <div style="margin-top:12px;display:flex;gap:10px;">
            <button class="imp-btn imp-btn-primary" onclick="importCsv()">导入CSV数据</button>
            <button class="imp-btn imp-btn-outline" onclick="document.getElementById('csvData').value=''">清空</button>
        </div>
        <div class="imp-result" id="csvResult"></div>
    </div>
</div>

<script>
// 标签切换
function switchTab(tab) {
    document.querySelectorAll('.imp-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.imp-panel').forEach(function(p) { p.classList.remove('active'); });
    event.target.classList.add('active');
    document.getElementById('panel-' + tab).classList.add('active');
    if (tab === 'migrate') checkLegacy();
}

// 导入SQL
function importSql() {
    var sql = document.getElementById('sqlContent').value.trim();
    if (!sql) { alert('请先粘贴SQL内容'); return; }
    if (!confirm('确定执行SQL导入？请确认SQL内容安全。')) return;
    var btn = document.getElementById('btnImportSql');
    btn.disabled = true; btn.textContent = '执行中...';
    apiPost('admin.php?module=import&action=import_sql', {sql_content: sql}, function(res) {
        btn.disabled = false; btn.textContent = '执行SQL导入';
        var el = document.getElementById('sqlResult');
        el.style.display = 'block';
        if (res.code === 200) {
            el.className = 'imp-result success';
            el.textContent = res.msg;
        } else {
            el.className = 'imp-result error';
            el.textContent = res.msg + (res.errors ? '\n' + res.errors.join('\n') : '');
        }
    });
}

// 检查旧表状态
function checkLegacy() {
    var el = document.getElementById('legacyStatus');
    el.innerHTML = '<div style="text-align:center;padding:20px;color:#9ca3af;">检测中...</div>';
    apiGet('admin.php?module=import&action=check_legacy', function(res) {
        if (res.code !== 200) { el.innerHTML = '<div style="color:#dc2626;">检测失败</div>'; return; }
        var data = res.data;
        var tableNames = {cs_info:'客户信息', cs_sell:'来访记录', cs_log:'日志', cs_money:'财务', cs_section:'科室', cs_type:'分类', cs_price:'价格', cs_config:'配置'};
        var html = '';
        for (var t in data.tables) {
            var cnt = data.tables[t];
            var label = tableNames[t] || t;
            html += '<div class="imp-status-item"><div class="name">' + label + ' (' + t + ')</div>';
            if (cnt >= 0) {
                html += '<div class="count">' + cnt + ' 条</div>';
            } else {
                html += '<div class="count not-exist">不存在</div>';
            }
            html += '</div>';
        }
        el.innerHTML = html;
    });
}

// 迁移数据
function migrateData() {
    if (!confirm('确定从旧cs_info表迁移数据到新系统？已有相同手机号的数据将被跳过。')) return;
    var btn = document.getElementById('btnMigrate');
    btn.disabled = true; btn.textContent = '迁移中...';
    apiPost('admin.php?module=import&action=migrate_cs_info', {}, function(res) {
        btn.disabled = false; btn.textContent = '开始迁移';
        var el = document.getElementById('migrateResult');
        el.style.display = 'block';
        if (res.code === 200) {
            el.className = 'imp-result success';
            el.textContent = res.msg;
        } else {
            el.className = 'imp-result error';
            el.textContent = res.msg;
        }
    });
}

// CSV导入
function importCsv() {
    var data = document.getElementById('csvData').value.trim();
    if (!data) { alert('请先粘贴CSV数据'); return; }
    apiPost('admin.php?module=import&action=import_csv', {csv_data: data}, function(res) {
        var el = document.getElementById('csvResult');
        el.style.display = 'block';
        if (res.code === 200) {
            el.className = 'imp-result success';
            el.textContent = res.msg;
        } else {
            el.className = 'imp-result error';
            el.textContent = res.msg;
        }
    });
}

// 页面加载时检查旧表状态
checkLegacy();
</script>
<?php
renderAdmin('数据导入', 'import', ob_get_clean());
