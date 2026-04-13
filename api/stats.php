<?php
/**
 * 统计分析API(管理员)
 */
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';

class StatsController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * 获取预约状态配置
     */
    private function getStatusConfig() {
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
     * 获取概览统计
     * GET /api/stats/overview
     */
    public function getOverview() {
        Auth::verifyAdmin();
        
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $todayEnd = strtotime(date('Y-m-d 23:59:59'));
        $monthStart = strtotime(date('Y-m-01 00:00:00'));
        $monthEnd = time();
        
        // 今日预约数
        $todayCount = $this->db->count('reservation', array(
            'addtime' => array('BETWEEN', "{$todayStart},{$todayEnd}")
        ));
        
        // 本月预约数
        $monthCount = $this->db->count('reservation', array(
            'addtime' => array('BETWEEN', "{$monthStart},{$monthEnd}")
        ));
        
        // 总预约数
        $totalCount = $this->db->count('reservation');
        
        // 总用户数
        $totalUsers = $this->db->count('user');
        
        // 总医院数
        $totalHospitals = $this->db->count('hospital', array('status' => 1));
        
        Response::success(array(
            'today_count' => $todayCount,
            'month_count' => $monthCount,
            'total_count' => $totalCount,
            'total_users' => $totalUsers,
            'total_hospitals' => $totalHospitals
        ));
    }
    
    /**
     * 获取预约趋势(最近7天)
     * GET /api/stats/reservationTrend
     */
    public function getReservationTrend() {
        Auth::verifyAdmin();
        
        $days = 7;
        $data = array();
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $start = strtotime($date . ' 00:00:00');
            $end = strtotime($date . ' 23:59:59');
            
            $count = $this->db->count('reservation', array(
                'addtime' => array('BETWEEN', "{$start},{$end}")
            ));
            
            $data[] = array(
                'date' => $date,
                'count' => $count
            );
        }
        
        Response::success($data);
    }
    
    /**
     * 获取医院预约排名
     * GET /api/stats/hospitalRank
     */
    public function getHospitalRank() {
        Auth::verifyAdmin();
        
        $prefix = Database::getConfig()['db']['prefix'];
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        
        $sql = "SELECT h.id, h.name, COUNT(r.id) as count 
                FROM {$prefix}hospital h 
                LEFT JOIN {$prefix}reservation r ON h.id = r.hospital_id 
                WHERE h.status = 1 
                GROUP BY h.id 
                ORDER BY count DESC 
                LIMIT {$limit}";
        
        $result = $this->db->query($sql)->fetchAll();
        
        Response::success($result);
    }
    
    /**
     * 获取预约状态分布
     * GET /api/stats/statusDistribution
     */
    public function getStatusDistribution() {
        Auth::verifyAdmin();
        
        $data = array();
        $statusConfig = $this->getStatusConfig();
        
        foreach ($statusConfig as $sc) {
            $count = 0;
            try { $count = $this->db->count('reservation', array('status' => $sc['name'])); } catch(PDOException $e) {}
            $data[] = array(
                'status' => $sc['name'],
                'status_text' => $sc['name'],
                'status_color' => $sc['color'],
                'status_bg' => $sc['bg'],
                'count' => $count
            );
        }
        
        Response::success($data);
    }
    
    /**
     * 获取状态文本（已废弃，保留兼容）
     */
    private function getStatusText($status) {
        return is_string($status) ? $status : '未知';
    }
}

// 处理请求
$controller = new StatsController();
$action = isset($_GET['action']) ? trim($_GET['action']) : 'getOverview';

if (method_exists($controller, $action)) {
    $controller->$action();
} else {
    Response::error('接口不存在', 404);
}
