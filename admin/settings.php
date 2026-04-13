<?php
/**
 * 系统设置页面
 */
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    foreach ($input as $key => $value) {
        $existing = $db->find('config', array('config_key' => $key));
        if ($existing) {
            $db->update('config', array('config_value' => $value, 'updatetime' => time()), array('id' => $existing['id']));
        } else {
            $db->insert('config', array('group_name'=>'basic', 'config_key'=>$key, 'config_value'=>$value, 'remark'=>'', 'sort'=>0, 'addtime'=>time(), 'updatetime'=>time()));
        }
    }
    echo json_encode(array('code'=>200,'message'=>'保存成功'));
    exit;
}

$configs = $db->select('config', array(), '*', 'sort ASC, id ASC');
$configMap = array();
foreach ($configs as $c) { $configMap[$c['config_key']] = $c; }

ob_start();
?>
<div class="card">
    <div class="card-header"><h3>系统设置</h3></div>
    <div class="card-body">
        <form id="settingsForm">
            <!-- 基础设置 -->
            <h4 style="margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid #1d4ed8;color:#1d4ed8;">基础设置</h4>
            <div class="form-group">
                <label>站点名称</label>
                <input type="text" name="site_name" value="<?php echo htmlspecialchars(isset($configMap['site_name'])?$configMap['site_name']['config_value']:''); ?>">
            </div>
            <div class="form-group">
                <label>联系电话</label>
                <input type="text" name="contact_phone" value="<?php echo htmlspecialchars(isset($configMap['contact_phone'])?$configMap['contact_phone']['config_value']:''); ?>">
            </div>
            
            <!-- 微信设置 -->
            <h4 style="margin:24px 0 16px;padding-bottom:8px;border-bottom:2px solid #1d4ed8;color:#1d4ed8;">微信小程序设置</h4>
            <div class="form-group">
                <label>AppID</label>
                <input type="text" name="appid" value="<?php echo htmlspecialchars(isset($configMap['appid'])?$configMap['appid']['config_value']:''); ?>">
            </div>
            
            <!-- 预约设置 -->
            <h4 style="margin:24px 0 16px;padding-bottom:8px;border-bottom:2px solid #1d4ed8;color:#1d4ed8;">预约设置</h4>
            <div class="form-group">
                <label>可提前预约天数</label>
                <input type="number" name="advance_days" value="<?php echo htmlspecialchars(isset($configMap['advance_days'])?$configMap['advance_days']['config_value']:'7'); ?>">
            </div>
            <div class="form-group">
                <label>预约取消提前小时数</label>
                <input type="number" name="cancel_hours" value="<?php echo htmlspecialchars(isset($configMap['cancel_hours'])?$configMap['cancel_hours']['config_value']:'24'); ?>">
            </div>
            <div class="form-group">
                <label>预约自动确认</label>
                <select name="auto_confirm">
                    <option value="0" <?php echo (isset($configMap['auto_confirm'])&&$configMap['auto_confirm']['config_value']=='0')?'selected':''; ?>>否</option>
                    <option value="1" <?php echo (isset($configMap['auto_confirm'])&&$configMap['auto_confirm']['config_value']=='1')?'selected':''; ?>>是</option>
                </select>
            </div>
            
            <!-- 预约状态配置 -->
            <h4 style="margin:24px 0 16px;padding-bottom:8px;border-bottom:2px solid #1d4ed8;color:#1d4ed8;">预约状态配置</h4>
            <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">配置预约列表中的可选状态及对应行颜色。状态名称将显示在下拉选择中。</div>
            <div id="statusConfigList" style="margin-bottom:12px;"></div>
            <button type="button" onclick="addStatusRow()" class="btn btn-outline btn-sm">+ 添加状态</button>
            
            <div style="margin-top:24px;">
                <button type="button" onclick="saveSettings()" class="btn btn-primary">保存设置</button>
            </div>
        </form>
    </div>
</div>

<script>
// 预约状态配置
var statusConfig = <?php 
    $sc = isset($configMap['reservation_status_config']) ? $configMap['reservation_status_config']['config_value'] : '';
    echo $sc ?: '[]';
?>;

function renderStatusConfig() {
    var html = '';
    statusConfig.forEach(function(s, i) {
        html += '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">';
        html += '<input type="text" value="'+s.name+'" placeholder="状态名称" class="sc-name" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:4px;width:120px;">';
        html += '<label style="font-size:12px;color:#6b7280;">文字色</label><input type="color" value="'+s.color+'" class="sc-color" style="width:36px;height:30px;border:1px solid #d1d5db;border-radius:4px;cursor:pointer;">';
        html += '<label style="font-size:12px;color:#6b7280;">背景色</label><input type="color" value="'+s.bg+'" class="sc-bg" style="width:36px;height:30px;border:1px solid #d1d5db;border-radius:4px;cursor:pointer;">';
        html += '<span style="padding:4px 12px;border-radius:4px;font-size:12px;color:'+s.color+';background:'+s.bg+';border:1px solid #e5e7eb;">'+s.name+'</span>';
        html += '<button type="button" onclick="removeStatusRow('+i+')" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:16px;" title="删除">&times;</button>';
        html += '</div>';
    });
    document.getElementById('statusConfigList').innerHTML = html;
}

function addStatusRow() {
    statusConfig.push({name: '新状态', color: '#374151', bg: '#f9fafb'});
    renderStatusConfig();
}

function removeStatusRow(i) {
    statusConfig.splice(i, 1);
    renderStatusConfig();
}

function collectStatusConfig() {
    var rows = document.getElementById('statusConfigList').children;
    var result = [];
    for (var i = 0; i < rows.length; i++) {
        var name = rows[i].querySelector('.sc-name').value.trim();
        var color = rows[i].querySelector('.sc-color').value;
        var bg = rows[i].querySelector('.sc-bg').value;
        if (name) result.push({name: name, color: color, bg: bg});
    }
    return JSON.stringify(result);
}

renderStatusConfig();

function saveSettings() {
    var form = document.getElementById('settingsForm');
    var data = {};
    var inputs = form.querySelectorAll('input, select');
    for (var i = 0; i < inputs.length; i++) {
        if (inputs[i].name) data[inputs[i].name] = inputs[i].value;
    }
    // 追加状态配置
    data.reservation_status_config = collectStatusConfig();
    apiPost('admin.php?module=settings&action=save', data, function(d) {
        if(d.code===200) { showToast('设置已保存','success'); setTimeout(function(){location.reload();},500); }
        else { showToast('保存失败','error'); }
    });
}
</script>
<?php renderAdmin('系统设置','settings',ob_get_clean());