<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\IntrospectionProcessor;

class MonologFactory
{
    /**
     * 创建统一的日志记录器
     * 
     * @param string $channel 日志通道名称
     * @param bool $debug 是否启用调试模式（启用时记录日志到文件）
     * @param string|null $logPath 自定义日志文件路径
     * @return Logger
     */
    public static function createLogger(
        string $channel = 'redis-stream', 
        bool $debug = false, 
        string $logPath = null
    ): Logger {
        $logger = new Logger($channel);
        
        // 调试模式下启用文件日志记录
        if ($debug) {
            if ($logPath === null) {
                $logPath = __DIR__ . '/../../logs/redis-stream.log';
            }
            
            // 确保日志目录存在
            $logDir = dirname($logPath);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            // 文件处理器配置
            $fileHandler = new RotatingFileHandler($logPath, 30, Logger::INFO);
            
            // 根据调试模式选择格式
            $fileFormatter = new LineFormatter(
                "[%datetime%] [%level_name%] [%channel%] %message% %context% %extra%\n",
                'Y-m-d H:i:s.v',
                true,
                true
            );
            // 调试模式下添加调用信息处理器
            $logger->pushProcessor(new IntrospectionProcessor());
            $fileHandler->setFormatter($fileFormatter);
            $logger->pushHandler($fileHandler);
        }
        
        return $logger;
    }
}