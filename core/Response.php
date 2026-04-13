<?php
/**
 * API响应类
 */
class Response {
    /**
     * 成功响应
     */
    public static function success($data = null, $message = '操作成功', $code = 200) {
        self::json($code, $message, $data);
    }
    
    /**
     * 失败响应
     */
    public static function error($message = '操作失败', $code = 400, $data = null) {
        self::json($code, $message, $data);
    }
    
    /**
     * 分页响应
     */
    public static function page($data, $total, $page = 1, $pageSize = 10, $message = '查询成功') {
        $result = array(
            'list' => $data,
            'total' => intval($total),
            'page' => intval($page),
            'pageSize' => intval($pageSize),
            'totalPages' => ceil($total / $pageSize)
        );
        self::json(200, $message, $result);
    }
    
    /**
     * JSON响应
     */
    private static function json($code, $message, $data = null) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        
        $result = array(
            'code' => $code,
            'message' => $message,
            'data' => $data
        );
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
