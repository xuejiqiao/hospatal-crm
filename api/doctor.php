<?php
/**
 * 医生管理API
 */
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';

class DoctorController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * 获取医生列表(公开，按医院/科室筛选)
     * GET /api/doctor/getList?hospital_id=1&department_id=2
     */
    public function getList() {
        $hospitalId = isset($_GET['hospital_id']) ? intval($_GET['hospital_id']) : 0;
        $departmentId = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
        $prefix = Database::getConfig()['db']['prefix'];
        
        if ($departmentId) {
            // 通过关联表查询：该科室下的医生（包括主科室和关联科室）
            $sql = "SELECT d.* FROM {$prefix}doctor d 
                    LEFT JOIN {$prefix}doctor_dept dd ON d.id=dd.doctor_id 
                    WHERE d.status=1";
            $params = array();
            if ($hospitalId) { $sql .= " AND d.hospital_id=?"; $params[] = $hospitalId; }
            $sql .= " AND (d.department_id=? OR dd.department_id=?)";
            $params[] = $departmentId;
            $params[] = $departmentId;
            $sql .= " GROUP BY d.id ORDER BY d.sort DESC, d.id DESC";
            $list = $this->db->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $where = array('status' => 1);
            if ($hospitalId) $where['hospital_id'] = $hospitalId;
            $list = $this->db->select('doctor', $where, '*', 'sort DESC, id DESC');
        }
        
        foreach ($list as &$item) {
            // 获取该医生关联的所有科室
            $deptLinks = $this->db->query(
                "SELECT dd.department_id, dp.name as department_name 
                 FROM {$prefix}doctor_dept dd 
                 INNER JOIN {$prefix}department dp ON dd.department_id=dp.id 
                 WHERE dd.doctor_id=?",
                array($item['id'])
            )->fetchAll(PDO::FETCH_ASSOC);
            $item['departments'] = $deptLinks;
            $item['department_name'] = !empty($deptLinks) ? implode(',', array_column($deptLinks, 'department_name')) : '';
        }
        
        Response::success($list);
    }
    
    /**
     * 获取医生详情
     * GET /api/doctor/getDetail?id=1
     */
    public function getDetail() {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$id) Response::error('缺少医生ID', 400);
        $prefix = Database::getConfig()['db']['prefix'];
        
        $doctor = $this->db->find('doctor', array('id' => $id));
        if (!$doctor) Response::error('医生不存在', 404);
        
        $hospital = $this->db->find('hospital', array('id' => $doctor['hospital_id']));
        $doctor['hospital_name'] = $hospital ? $hospital['name'] : '';
        
        // 获取关联科室列表
        $deptLinks = $this->db->query(
            "SELECT dd.department_id, dp.name as department_name 
             FROM {$prefix}doctor_dept dd 
             INNER JOIN {$prefix}department dp ON dd.department_id=dp.id 
             WHERE dd.doctor_id=?",
            array($id)
        )->fetchAll(PDO::FETCH_ASSOC);
        $doctor['departments'] = $deptLinks;
        $doctor['department_name'] = !empty($deptLinks) ? implode(',', array_column($deptLinks, 'department_name')) : '';
        
        Response::success($doctor);
    }
}

$controller = new DoctorController();
$action = isset($_GET['action']) ? $_GET['action'] : 'getList';

if (method_exists($controller, $action)) {
    $controller->$action();
} else {
    Response::error('接口不存在', 404);
}
