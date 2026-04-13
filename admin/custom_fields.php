<?php
/**
 * 自定义字段管理
 * 管理预约和患者模块的自定义字段
 */
if (!defined('CRM_ADMIN')) exit;

$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// AJAX操作
if ($action === 'save') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(array('code' => 400, 'msg' => '请求数据为空'));
        exit;
    }
    $id = intval($input['id']);
    $data = array(
        'field_key' => trim($input['field_key']),
        'field_name' => trim($input['field_name']),
        'field_type' => trim($input['field_type']),
        'target_table' => trim($input['target_table']),
        'options' => isset($input['options']) ? trim($input['options']) : '',
        'required' => intval($input['required']),
        'sort' => intval($input['sort']),
        'status' => intval($input['status'])
    );
    // 字段标识只允许字母数字下划线
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $data['field_key'])) {
        echo json_encode(array('code' => 400, 'msg' => '字段标识只能以字母开头，只允许字母数字下划线'));
        exit;
    }
    // 下拉选项处理
    if ($data['field_type'] === 'select') {
        if (empty($data['options'])) {
            echo json_encode(array('code' => 400, 'msg' => '下拉类型必须填写选项'));
            exit;
        }
        // 将逗号分隔转JSON数组
        $opts = array_map('trim', explode(',', $data['options']));
        $data['options'] = json_encode($opts, JSON_UNESCAPED_UNICODE);
    } else {
        $data['options'] = '';
    }
    try {
        if ($id > 0) {
            $db->update('custom_field', $data, array('id' => $id));
            addLog('编辑自定义字段', 'custom_field', $id, $data['field_name']);
        } else {
            $data['addtime'] = time();
            $id = $db->insert('custom_field', $data);
            addLog('新增自定义字段', 'custom_field', $id, $data['field_name']);
        }
        echo json_encode(array('code' => 200, 'msg' => '保存成功', 'id' => $id));
    } catch(Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate') !== false || strpos($msg, '1062') !== false) {
            $msg = '字段标识在该模块下已存在';
        }
        if (strpos($msg, "doesn't exist") !== false || strpos($msg, '1146') !== false) {
            $msg = '数据表不存在，请刷新页面后重试（系统将自动创建表）';
        }
        echo json_encode(array('code' => 500, 'msg' => $msg));
    }
    exit;
}

if ($action === 'delete') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id']);
    try {
        $db->delete('custom_field', array('id' => $id));
        $db->delete('custom_field_value', array('field_id' => $id));
        addLog('删除自定义字段', 'custom_field', $id);
        echo json_encode(array('code' => 200, 'msg' => '删除成功'));
    } catch(PDOException $e) {
        echo json_encode(array('code' => 500, 'msg' => $e->getMessage()));
    }
    exit;
}

if ($action === 'toggle') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id']);
    $status = intval($input['status']);
    try {
        $db->update('custom_field', array('status' => $status), array('id' => $id));
        echo json_encode(array('code' => 200));
    } catch(PDOException $e) {
        echo json_encode(array('code' => 500));
    }
    exit;
}

if ($action === 'getDetail') {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $row = $db->query("SELECT * FROM {$prefix}custom_field WHERE id=?", array($id))->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['field_type'] === 'select' && $row['options']) {
        $opts = json_decode($row['options'], true);
        $row['options_text'] = $opts ? implode(',', $opts) : '';
    } else {
        $row['options_text'] = '';
    }
    echo json_encode(array('code' => 200, 'data' => $row));
    exit;
}

// 列表数据
ob_start();
$filterTable = isset($_GET['target_table']) ? trim($_GET['target_table']) : '';
$params = array();
$where = '';
if ($filterTable && in_array($filterTable, array('reservation', 'patient'))) {
    $where = ' WHERE target_table=?';
    $params[] = $filterTable;
}
$fields = $db->query("SELECT * FROM {$prefix}custom_field {$where} ORDER BY target_table, sort DESC, id ASC", $params)->fetchAll(PDO::FETCH_ASSOC);

$typeLabels = array('text' => '输入框', 'select' => '下拉选择', 'textarea' => '文本域', 'date' => '日期', 'number' => '数字');
$tableLabels = array('reservation' => '预约管理', 'patient' => '患者管理');
?>
<style>
.cf-card{background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:20px;padding:20px;}
.cf-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.cf-header h3{margin:0;font-size:18px;color:#1d4ed8;}
.cf-filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.cf-filters select,.cf-filters button{padding:7px 14px;border-radius:6px;font-size:13px;border:1px solid #d1d5db;}
.cf-filters select:focus{outline:none;border-color:#3b82f6;}
.btn-add{background:#1d4ed8;color:#fff;border:none!important;padding:8px 18px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500;transition:background .2s;}
.btn-add:hover{background:#1e40af;}
.cf-table{width:100%;border-collapse:collapse;font-size:13px;}
.cf-table th{background:#f8fafc;padding:10px 12px;text-align:left;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;white-space:nowrap;}
.cf-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle;}
.cf-table tr:hover td{background:#f8fafc;}
.badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:500;}
.badge-blue{background:#dbeafe;color:#1d4ed8;}
.badge-green{background:#dcfce7;color:#166534;}
.badge-gray{background:#f1f5f9;color:#64748b;}
.badge-orange{background:#ffedd5;color:#9a3412;}
.badge-purple{background:#ede9fe;color:#5b21b6;}
.btn-sm{padding:4px 10px;border-radius:4px;font-size:12px;cursor:pointer;border:1px solid #d1d5db;background:#fff;transition:all .15s;margin-right:4px;}
.btn-sm:hover{background:#f1f5f9;}
.btn-sm.btn-danger{color:#dc2626;border-color:#fecaca;}
.btn-sm.btn-danger:hover{background:#fef2f2;}
.btn-sm.btn-success{color:#16a34a;border-color:#bbf7d0;}
.btn-sm.btn-success:hover{background:#f0fdf4;}
/* Modal */
.modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:1000;justify-content:center;align-items:center;}
.modal-overlay.active{display:flex;}
.modal-box{background:#fff;border-radius:12px;width:520px;max-width:90vw;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-header{padding:18px 24px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;}
.modal-header h3{margin:0;font-size:16px;color:#1e293b;}
.modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:#94a3b8;padding:0;line-height:1;}
.modal-close:hover{color:#475569;}
.modal-body{padding:24px;}
.form-row{margin-bottom:16px;}
.form-row label{display:block;margin-bottom:5px;font-weight:500;font-size:13px;color:#374151;}
.form-row label .req{color:#dc2626;}
.form-row input,.form-row select,.form-row textarea{width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;transition:border .2s;}
.form-row input:focus,.form-row select:focus,.form-row textarea:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}
.form-row textarea{min-height:60px;resize:vertical;}
.form-hint{font-size:12px;color:#94a3b8;margin-top:4px;}
.form-inline{display:flex;gap:16px;}
.form-inline .form-row{flex:1;}
.modal-footer{padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:10px;}
.btn-cancel{padding:8px 20px;border-radius:6px;border:1px solid #d1d5db;background:#fff;cursor:pointer;font-size:13px;}
.btn-cancel:hover{background:#f8fafc;}
.btn-save{padding:8px 24px;border-radius:6px;border:none;background:#1d4ed8;color:#fff;cursor:pointer;font-size:13px;font-weight:500;}
.btn-save:hover{background:#1e40af;}
.empty-tip{text-align:center;padding:40px;color:#94a3b8;}
.empty-tip p{margin:8px 0;}
</style>

<div class="cf-card">
    <div class="cf-header">
        <h3>自定义字段管理</h3>
        <div class="cf-filters">
            <select id="filterTable" onchange="filterList()">
                <option value="">全部模块</option>
                <option value="reservation" <?php echo $filterTable==='reservation'?'selected':''; ?>>预约管理</option>
                <option value="patient" <?php echo $filterTable==='patient'?'selected':''; ?>>患者管理</option>
            </select>
            <button class="btn-add" onclick="openCfModal()">+ 添加字段</button>
        </div>
    </div>
    
    <?php if (empty($fields)): ?>
    <div class="empty-tip">
        <p style="font-size:36px;margin-bottom:12px;">📋</p>
        <p>暂无自定义字段</p>
        <p style="font-size:12px;">点击「添加字段」为预约或患者模块创建自定义字段</p>
    </div>
    <?php else: ?>
    <table class="cf-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>字段标识</th>
                <th>字段名称</th>
                <th>类型</th>
                <th>所属模块</th>
                <th>下拉选项</th>
                <th>必填</th>
                <th>排序</th>
                <th>状态</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($fields as $f): ?>
            <tr>
                <td><?php echo $f['id']; ?></td>
                <td><code style="background:#f1f5f9;padding:2px 6px;border-radius:3px;font-size:12px;"><?php echo htmlspecialchars($f['field_key']); ?></code></td>
                <td><strong><?php echo htmlspecialchars($f['field_name']); ?></strong></td>
                <td><span class="badge badge-orange"><?php echo isset($typeLabels[$f['field_type']]) ? $typeLabels[$f['field_type']] : $f['field_type']; ?></span></td>
                <td><span class="badge badge-purple"><?php echo isset($tableLabels[$f['target_table']]) ? $tableLabels[$f['target_table']] : $f['target_table']; ?></span></td>
                <td>
                    <?php if($f['field_type']==='select' && $f['options']): 
                        $opts = json_decode($f['options'], true);
                        echo $opts ? '<span style="font-size:12px;color:#64748b;">' . htmlspecialchars(implode('、', $opts)) . '</span>' : '-';
                    else: ?>-<?php endif; ?>
                </td>
                <td><?php echo $f['required'] ? '<span class="badge badge-green">是</span>' : '<span class="badge badge-gray">否</span>'; ?></td>
                <td><?php echo $f['sort']; ?></td>
                <td>
                    <button class="btn-sm <?php echo $f['status']?'btn-success':'btn-danger'; ?>" onclick="toggleStatus(<?php echo $f['id']; ?>, <?php echo $f['status']?0:1; ?>)">
                        <?php echo $f['status'] ? '启用' : '禁用'; ?>
                    </button>
                </td>
                <td>
                    <button class="btn-sm" onclick="editItem(<?php echo $f['id']; ?>)">编辑</button>
                    <button class="btn-sm btn-danger" onclick="deleteItem(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars($f['field_name']); ?>')">删除</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- 编辑弹窗 -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle">添加自定义字段</h3>
            <button class="modal-close" onclick="closeCfModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editId" value="0">
            <div class="form-row">
                <label>所属模块 <span class="req">*</span></label>
                <select id="editTargetTable" onchange="onTableChange()">
                    <option value="reservation">预约管理</option>
                    <option value="patient">患者管理</option>
                </select>
            </div>
            <div class="form-inline">
                <div class="form-row">
                    <label>字段标识 <span class="req">*</span></label>
                    <input type="text" id="editFieldKey" placeholder="如: id_card" maxlength="60">
                    <div class="form-hint">英文字母开头，只允许字母数字下划线</div>
                </div>
                <div class="form-row">
                    <label>字段名称 <span class="req">*</span></label>
                    <input type="text" id="editFieldName" placeholder="如: 身份证号" maxlength="100">
                </div>
            </div>
            <div class="form-inline">
                <div class="form-row">
                    <label>字段类型 <span class="req">*</span></label>
                    <select id="editFieldType" onchange="onTypeChange()">
                        <option value="text">输入框</option>
                        <option value="number">数字</option>
                        <option value="select">下拉选择</option>
                        <option value="textarea">文本域</option>
                        <option value="date">日期</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>排序</label>
                    <input type="number" id="editSort" value="0" min="0">
                    <div class="form-hint">数值越大越靠前</div>
                </div>
            </div>
            <div class="form-row" id="optionsRow" style="display:none;">
                <label>下拉选项 <span class="req">*</span></label>
                <textarea id="editOptions" placeholder="用逗号分隔，如: 选项1,选项2,选项3"></textarea>
                <div class="form-hint">多个选项用英文逗号分隔</div>
            </div>
            <div class="form-inline">
                <div class="form-row">
                    <label>是否必填</label>
                    <select id="editRequired">
                        <option value="0">否</option>
                        <option value="1">是</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>状态</label>
                    <select id="editStatus">
                        <option value="1">启用</option>
                        <option value="0">禁用</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeCfModal()">取消</button>
            <button class="btn-save" onclick="saveItem()">保存</button>
        </div>
    </div>
</div>

<script>
function filterList() {
    var t = document.getElementById('filterTable').value;
    location.href = 'admin.php?module=custom_field' + (t ? '&target_table=' + t : '');
}

function openCfModal(id) {
    document.getElementById('editId').value = '0';
    document.getElementById('editFieldKey').value = '';
    document.getElementById('editFieldName').value = '';
    document.getElementById('editFieldType').value = 'text';
    document.getElementById('editTargetTable').value = 'reservation';
    document.getElementById('editSort').value = '0';
    document.getElementById('editRequired').value = '0';
    document.getElementById('editStatus').value = '1';
    document.getElementById('editOptions').value = '';
    document.getElementById('optionsRow').style.display = 'none';
    document.getElementById('modalTitle').textContent = '添加自定义字段';
    if (id) {
        document.getElementById('modalTitle').textContent = '编辑自定义字段';
        apiGet('admin.php?module=custom_field&action=getDetail&id=' + id, function(res) {
            var d = (res && res.code === 200 && res.data) ? res.data : null;
            if (d) {
                document.getElementById('editId').value = d.id;
                document.getElementById('editFieldKey').value = d.field_key;
                document.getElementById('editFieldName').value = d.field_name;
                document.getElementById('editFieldType').value = d.field_type;
                document.getElementById('editTargetTable').value = d.target_table;
                document.getElementById('editSort').value = d.sort;
                document.getElementById('editRequired').value = d.required;
                document.getElementById('editStatus').value = d.status;
                document.getElementById('editOptions').value = d.options_text || '';
                onTypeChange();
            }
        });
    }
    document.getElementById('editModal').classList.add('active');
}

function closeCfModal() {
    document.getElementById('editModal').classList.remove('active');
}

function editItem(id) {
    openCfModal(id);
}

function onTypeChange() {
    var t = document.getElementById('editFieldType').value;
    document.getElementById('optionsRow').style.display = (t === 'select') ? 'block' : 'none';
}

function onTableChange() {}

function saveItem() {
    var data = {
        id: document.getElementById('editId').value,
        field_key: document.getElementById('editFieldKey').value.trim(),
        field_name: document.getElementById('editFieldName').value.trim(),
        field_type: document.getElementById('editFieldType').value,
        target_table: document.getElementById('editTargetTable').value,
        options: document.getElementById('editOptions').value.trim(),
        sort: document.getElementById('editSort').value,
        required: document.getElementById('editRequired').value,
        status: document.getElementById('editStatus').value
    };
    if (!data.field_key || !data.field_name) {
        alert('字段标识和名称不能为空');
        return;
    }
    apiPost('admin.php?module=custom_field&action=save', data, function(res) {
        if (res && res.code === 200) {
            closeCfModal();
            location.reload();
        } else {
            alert((res && res.msg) || '保存失败，请检查字段标识是否重复或联系管理员');
        }
    });
}

function deleteItem(id, name) {
    if (!confirm('确定删除字段「' + name + '」？删除后该字段的所有数据也将被清除！')) return;
    apiPost('admin.php?module=custom_field&action=delete', {id: id}, function(res) {
        if (res.code === 200) location.reload();
        else alert(res.msg || '删除失败');
    });
}

function toggleStatus(id, status) {
    apiPost('admin.php?module=custom_field&action=toggle', {id: id, status: status}, function(res) {
        if (res.code === 200) location.reload();
    });
}
</script>
<?php
renderAdmin('自定义字段管理', 'custom_field', ob_get_clean());
