<?php
/**
 * 预约管理API
 */
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';

class ReservationController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * 获取预约状态配置
     */
    private function getStatusConfig() {
        $prefix = Database::getConfig()['db']['prefix'];
        $statusConfig = array();
        try {
            $cf = $this->db->find('config', array('config_key' => 'reservation_status_config'));
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
        return $statusConfig;
    }
    
    /**
     * 创建预约(小程序端)
     * POST /api/reservation/create
     */
    public function create() {
        $session = Auth::verify();
        $input = json_decode(file_get_contents('php://input'), true);
        
        $data = array(
            'user_id' => $session['user_id'],
            'hospital_id' => isset($input['hospital_id']) ? intval($input['hospital_id']) : 0,
            'patient_name' => isset($input['patient_name']) ? trim($input['patient_name']) : '',
            'patient_phone' => isset($input['patient_phone']) ? trim($input['patient_phone']) : '',
            'patient_idcard' => isset($input['patient_idcard']) ? trim($input['patient_idcard']) : '',
            'department' => isset($input['department']) ? trim($input['department']) : '',
            'doctor' => isset($input['doctor']) ? trim($input['doctor']) : '',
            'reservation_date' => isset($input['reservation_date']) ? trim($input['reservation_date']) : '',
            'time_period' => isset($input['time_period']) ? trim($input['time_period']) : '上午',
            'remark' => isset($input['remark']) ? trim($input['remark']) : '',
            'status' => '待确认',
            'addtime' => time(),
            'updatetime' => time()
        );
        
        // 验证必填字段
        if (!$data['hospital_id']) {
            Response::error('请选择医院', 400);
        }
        if (empty($data['patient_name'])) {
            Response::error('请填写就诊人姓名', 400);
        }
        if (empty($data['patient_phone'])) {
            Response::error('请填写联系电话', 400);
        }
        if (empty($data['reservation_date'])) {
            Response::error('请选择预约日期', 400);
        }
        
        // 验证手机号格式
        if (!preg_match('/^1[3-9]\d{9}$/', $data['patient_phone'])) {
            Response::error('手机号格式不正确', 400);
        }
        
        $id = $this->db->insert('reservation', $data);
        
        Response::success(array('id' => $id), '预约成功');
    }
    
    /**
     * 获取我的预约列表
     * GET /api/reservation/myList
     */
    public function myList() {
        $session = Auth::verify();
        
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $pageSize = isset($_GET['pageSize']) ? intval($_GET['pageSize']) : 10;
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        
        $where = array('user_id' => $session['user_id']);
        if ($status !== '') {
            $where['status'] = $status;
        }
        
        $offset = ($page - 1) * $pageSize;
        $limit = "{$offset}, {$pageSize}";
        
        $total = $this->db->count('reservation', $where);
        $list = $this->db->select('reservation', $where, '*', 'addtime DESC', $limit);
        
        // 获取医院名称和状态配置
        $statusConfig = $this->getStatusConfig();
        $statusColorMap = array();
        foreach ($statusConfig as $sc) { $statusColorMap[$sc['name']] = $sc; }
        
        foreach ($list as &$item) {
            $hospital = $this->db->find('hospital', array('id' => $item['hospital_id']));
            $item['hospital_name'] = $hospital ? $hospital['name'] : '';
            $item['status_text'] = $item['status'];
            $sc = isset($statusColorMap[$item['status']]) ? $statusColorMap[$item['status']] : null;
            if ($sc) {
                $item['status_color'] = $sc['color'];
                $item['status_bg'] = $sc['bg'];
            }
        }
        
        Response::page($list, $total, $page, $pageSize);
    }
    
    /**
     * 取消预约
     * POST /api/reservation/cancel
     */
    public function cancel() {
        $session = Auth::verify();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = isset($input['id']) ? intval($input['id']) : 0;
        
        if (!$id) {
            Response::error('缺少预约ID', 400);
        }
        
        $reservation = $this->db->find('reservation', array('id' => $id));
        
        if (!$reservation) {
            Response::error('预约不存在', 404);
        }
        
        if ($reservation['user_id'] != $session['user_id']) {
            Response::error('无权操作此预约', 403);
        }
        
        // 只允许取消"待确认"状态的预约
        if ($reservation['status'] !== '待确认') {
            Response::error('只能取消待确认的预约', 400);
        }
        
        $this->db->update('reservation', array(
            'status' => '已取消',
            'updatetime' => time()
        ), array('id' => $id));
        
        Response::success(null, '取消成功');
    }
    
    /**
     * 获取预约列表(管理员)
     * GET /api/reservation/list
     */
    public function getList() {
        Auth::verifyAdmin();
        
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $pageSize = isset($_GET['pageSize']) ? intval($_GET['pageSize']) : 10;
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        $hospitalId = isset($_GET['hospital_id']) ? intval($_GET['hospital_id']) : 0;
        $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
        
        $where = array();
        if ($status !== '') {
            $where['status'] = $status;
        }
        if ($hospitalId) {
            $where['hospital_id'] = $hospitalId;
        }
        if ($keyword) {
            $where['patient_name'] = array('LIKE', "%{$keyword}%");
        }
        
        $offset = ($page - 1) * $pageSize;
        $limit = "{$offset}, {$pageSize}";
        
        $total = $this->db->count('reservation', $where);
        $list = $this->db->select('reservation', $where, '*', 'addtime DESC', $limit);
        
        // 获取医院信息、状态配置
        $statusConfig = $this->getStatusConfig();
        $statusColorMap = array();
        foreach ($statusConfig as $sc) { $statusColorMap[$sc['name']] = $sc; }
        
        foreach ($list as &$item) {
            $hospital = $this->db->find('hospital', array('id' => $item['hospital_id']));
            $item['hospital_name'] = $hospital ? $hospital['name'] : '';
            $item['status_text'] = $item['status'];
            $sc = isset($statusColorMap[$item['status']]) ? $statusColorMap[$item['status']] : null;
            if ($sc) {
                $item['status_color'] = $sc['color'];
                $item['status_bg'] = $sc['bg'];
            }
        }
        
        Response::page($list, $total, $page, $pageSize);
    }
    
    /**
     * 更新预约状态(管理员)
     * POST /api/reservation/updateStatus
     */
    public function updateStatus() {
        Auth::verifyAdmin();
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = isset($input['id']) ? intval($input['id']) : 0;
        $status = isset($input['status']) ? trim($input['status']) : '';
        $remark = isset($input['remark']) ? trim($input['remark']) : '';
        
        if (!$id) {
            Response::error('缺少预约ID', 400);
        }
        
        if (empty($status)) {
            Response::error('状态值不能为空', 400);
        }
        
        // 验证状态是否在配置中
        $statusConfig = $this->getStatusConfig();
        $validStatus = array_column($statusConfig, 'name');
        if (!in_array($status, $validStatus)) {
            Response::error('状态值不正确，有效值: ' . implode(', ', $validStatus), 400);
        }
        
        $reservation = $this->db->find('reservation', array('id' => $id));
        if (!$reservation) {
            Response::error('预约不存在', 404);
        }
        
        $data = array(
            'status' => $status,
            'admin_remark' => $remark,
            'updatetime' => time()
        );
        
        $this->db->update('reservation', $data, array('id' => $id));
        
        Response::success(null, '更新成功');
    }
    
    /**
     * 获取状态配置列表(供前端使用)
     * GET /api/reservation/statusConfig
     */
    public function statusConfig() {
        $config = $this->getStatusConfig();
        Response::success($config);
    }
    
    /**
     * 获取状态文本（已废弃，保留兼容）
     */
    private function getStatusText($status) {
        return is_string($status) ? $status : '未知';
    }
}

// 处理请求
$controller = new ReservationController();
$action = isset($_GET['action']) ? trim($_GET['action']) : 'create';

if (method_exists($controller, $action)) {
    $controller->$action();
} else {
    Response::error('接口不存在', 404);
}
