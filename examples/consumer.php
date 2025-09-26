<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\MonologFactory;
use Tinywan\RedisStream\Consumer;

// Redis 连接配置
$redisConfig = [
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => null,
    'database' => 0,
    'timeout' => 5,
];

// 日志配置
$enableFileLogging = getenv('REDIS_STREAM_FILE_LOG') === 'true' || in_array('--file-log', $argv);
$enableDebug = getenv('REDIS_STREAM_DEBUG') === 'true' || in_array('--debug', $argv);

// 队列配置
$queueConfig = [
    'stream_name' => 'task_queue',
    'consumer_group' => 'task_workers',
    'consumer_name' => 'worker_' . getmypid(),
    'block_timeout' => 5000,
    'retry_attempts' => 5,  // 任务重试5次
    'retry_delay' => 2000,  // 重试间隔2秒
];

$taskQueue = RedisStreamQueue::getInstance(
    $redisConfig,
    $queueConfig,
    MonologFactory::createLogger('task-queue', $enableFileLogging, $enableDebug)
);

// 获取logger实例
$logger = $taskQueue->getLogger();

// 显示配置信息
echo "=== 任务队列配置 ===\n";
echo "Redis配置: " . json_encode($taskQueue->getRedisConfig(), JSON_PRETTY_PRINT) . "\n";
echo "队列配置: " . json_encode($taskQueue->getQueueConfig(), JSON_PRETTY_PRINT) . "\n";
echo "日志配置: 文件日志=" . ($enableFileLogging ? '启用' : '禁用') . ", 调试模式=" . ($enableDebug ? '启用' : '禁用') . "\n";
echo "===================\n\n";

// 记录消费者启动日志
$logger->info('Task consumer started', [
    'pid' => getmypid(),
    'stream_name' => $taskQueue->getStreamName(),
    'consumer_group' => $taskQueue->getConsumerGroup(),
    'consumer_name' => $taskQueue->getConsumerName(),
    'memory_limit' => '256MB'
]);

// 具体任务处理函数
function processEmailTask(array $data, \Monolog\Logger $logger): bool
{
    $startTime = microtime(true);
    
    try {
        echo "📧 Sending email to: {$data['to']}\n";
        
        // 模拟邮件发送
        sleep(1);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录邮件发送日志
        $logger->info('Email task processed successfully', [
            'task_type' => 'email',
            'recipient' => $data['to'],
            'duration_ms' => $duration,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        return true;
        
    } catch (Throwable $e) {
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录邮件发送失败日志
        $logger->error('Email task failed', [
            'task_type' => 'email',
            'recipient' => $data['to'],
            'error' => $e->getMessage(),
            'duration_ms' => $duration
        ]);
        
        return false;
    }
}

function processImageTask(array $data, \Monolog\Logger $logger): bool
{
    $startTime = microtime(true);
    
    try {
        echo "🖼️  Processing image: {$data['filename']}\n";
        
        // 模拟图片处理
        sleep(2);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录图片处理日志
        $logger->info('Image task processed successfully', [
            'task_type' => 'image',
            'filename' => $data['filename'],
            'duration_ms' => $duration,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        return true;
        
    } catch (Throwable $e) {
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录图片处理失败日志
        $logger->error('Image task failed', [
            'task_type' => 'image',
            'filename' => $data['filename'],
            'error' => $e->getMessage(),
            'duration_ms' => $duration
        ]);
        
        return false;
    }
}

function processReportTask(array $data, \Monolog\Logger $logger): bool
{
    $startTime = microtime(true);
    
    try {
        echo "📊 Generating report: {$data['report_name']}\n";
        
        // 模拟报表生成
        sleep(3);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录报表生成日志
        $logger->info('Report task processed successfully', [
            'task_type' => 'report',
            'report_name' => $data['report_name'],
            'duration_ms' => $duration,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        return true;
        
    } catch (Throwable $e) {
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录报表生成失败日志
        $logger->error('Report task failed', [
            'task_type' => 'report',
            'report_name' => $data['report_name'],
            'error' => $e->getMessage(),
            'duration_ms' => $duration
        ]);
        
        return false;
    }
}

function processNotificationTask(array $data, \Monolog\Logger $logger): bool
{
    $startTime = microtime(true);
    
    try {
        echo "🔔 Sending notification\n";
        
        // 模拟通知发送
        sleep(1);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录通知发送日志
        $logger->info('Notification task processed successfully', [
            'task_type' => 'notification',
            'notification_type' => $data['notification_type'] ?? 'push',
            'duration_ms' => $duration,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        return true;
        
    } catch (Throwable $e) {
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录通知发送失败日志
        $logger->error('Notification task failed', [
            'task_type' => 'notification',
            'notification_type' => $data['notification_type'] ?? 'push',
            'error' => $e->getMessage(),
            'duration_ms' => $duration
        ]);
        
        return false;
    }
}

// 任务消费者
function processTasks(Consumer $consumer): void
{
    $logger = $consumer->getQueue()->getLogger();
    
    echo "🚀 Starting task processor...\n";
    echo "   Stream: " . $consumer->getQueue()->getStreamName() . "\n";
    echo "   Group: " . $consumer->getQueue()->getConsumerGroup() . "\n";
    echo "   Consumer: " . $consumer->getQueue()->getConsumerName() . "\n\n";
    
    // 记录处理器启动日志
    $logger->info('Task processor started', [
        'stream' => $consumer->getQueue()->getStreamName(),
        'group' => $consumer->getQueue()->getConsumerGroup(),
        'consumer' => $consumer->getQueue()->getConsumerName()
    ]);
    
    $processedCount = 0;
    $successCount = 0;
    $failureCount = 0;
    
    $consumer->run(function($message) use (&$processedCount, &$successCount, &$failureCount, $logger) {
        $task = json_decode($message['message'], true);
        
        echo "📋 Processing task: {$task['task_id']} ({$task['type']})\n";
        
        $startTime = microtime(true);
        $processedCount++;
        
        try {
            switch ($task['type']) {
                case 'email':
                    $result = processEmailTask($task['data'], $logger);
                    break;
                case 'image':
                    $result = processImageTask($task['data'], $logger);
                    break;
                case 'report':
                    $result = processReportTask($task['data'], $logger);
                    break;
                case 'notification':
                    $result = processNotificationTask($task['data'], $logger);
                    break;
                default:
                    echo "❌ Unknown task type: {$task['type']}\n";
                    $logger->warning('Unknown task type encountered', [
                        'task_id' => $task['task_id'],
                        'task_type' => $task['type'],
                        'message_id' => $message['id']
                    ]);
                    $failureCount++;
                    return false;
            }
            
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            if ($result) {
                $successCount++;
                echo "✅ Task completed successfully in {$duration}ms\n";
                
                // 记录成功日志
                $logger->info('Task completed successfully', [
                    'task_id' => $task['task_id'],
                    'task_type' => $task['type'],
                    'duration_ms' => $duration,
                    'attempts' => $message['attempts'],
                    'message_id' => $message['id']
                ]);
            } else {
                $failureCount++;
                echo "❌ Task failed, will retry...\n";
                
                // 记录失败日志
                $logger->warning('Task failed, will retry', [
                    'task_id' => $task['task_id'],
                    'task_type' => $task['type'],
                    'duration_ms' => $duration,
                    'attempts' => $message['attempts'],
                    'message_id' => $message['id']
                ]);
            }
            
            echo "─" . str_repeat("─", 50) . "\n";
            
            return $result;
            
        } catch (Throwable $e) {
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            $failureCount++;
            
            echo "💥 Task failed with exception after {$duration}ms\n";
            echo "   Error: {$e->getMessage()}\n";
            echo "─" . str_repeat("─", 50) . "\n";
            
            // 记录异常日志
            $logger->error('Task failed with exception', [
                'task_id' => $task['task_id'],
                'task_type' => $task['type'],
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
                'attempts' => $message['attempts'],
                'message_id' => $message['id']
            ]);
            
            return false;
        }
    });
    
    // 记录停止日志
    $logger->info('Task processor stopped', [
        'processed_count' => $processedCount,
        'success_count' => $successCount,
        'failure_count' => $failureCount,
        'success_rate' => $processedCount > 0 ? round(($successCount / $processedCount) * 100, 2) : 0
    ]);
}

// 启动消费者
$consumer = new Consumer($taskQueue);

// 设置内存限制为 256MB
$consumer->setMemoryLimit(256 * 1024 * 1024);

processTasks($consumer);