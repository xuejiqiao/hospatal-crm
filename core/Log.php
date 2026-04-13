<?php
/**
 * 日志记录类
 */
class Log {
    private static $config;
    
    /**
     * 获取配置（通过Database类统一获取，避免require_once重复加载问题）
     */
    private static function getConfig() {
        if (self::$config === null) {
            self::$config = Database::getConfig();
        }
        return self::$config;
    }
    
    /**
     * 记录错误日志
     */
    public static function error($message) {
        self::write('error', $message);
    }
    
    /**
     * 记录信息日志
     */
    public static function info($message) {
        self::write('info', $message);
    }
    
    /**
     * 记录调试日志
     */
    public static function debug($message) {
        self::write('debug', $message);
    }
    
    /**
     * 写入日志
     */
    private static function write($level, $message) {
        $config = self::getConfig();
        
        if (empty($config['log']['enable'])) {
            return;
        }
        
        $logPath = $config['log']['path'];
        if (!is_dir($logPath)) {
            @mkdir($logPath, 0755, true);
        }
        
        $logFile = $logPath . date('Y-m-d') . '.log';
        $time = date('Y-m-d H:i:s');
        $logMessage = "[{$time}] [{$level}] {$message}" . PHP_EOL;
        
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}
