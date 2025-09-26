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
    MonologFactory::createLogger('task-queue', 'development')
);

// 显示配置信息
echo "===================\n\n";

// 具体任务处理函数
function processEmailTask(array $data): bool
{
    echo "📧 Sending email to: {$data['to']}\n";
    return true;
}

function processImageTask(array $data): bool
{
    echo "🖼️  Processing image: {$data['filename']}\n";
    return true;
}

function processReportTask(array $data): bool
{
    echo "📊 Generating report: {$data['report_name']}\n";
    return true;
}

function processNotificationTask(array $data): bool
{
    echo "🔔 Sending notification\n";
    return true;
}

// 任务消费者
function processTasks(Consumer $consumer): void
{
    echo "🚀 Starting task processor...\n";
    echo "   Stream: " . $consumer->getQueue()->getStreamName() . "\n";
    echo "   Group: " . $consumer->getQueue()->getConsumerGroup() . "\n";
    echo "   Consumer: " . $consumer->getQueue()->getConsumerName() . "\n\n";
    
    $consumer->run(function($message) {
        $task = json_decode($message['message'], true);
        
        echo "📋 Processing task: {$task['task_id']} ({$task['type']})\n";
        
        $startTime = microtime(true);
        
        try {
            switch ($task['type']) {
                case 'email':
                    $result = processEmailTask($task['data']);
                    break;
                case 'image':
                    $result = processImageTask($task['data']);
                    break;
                case 'report':
                    $result = processReportTask($task['data']);
                    break;
                case 'notification':
                    $result = processNotificationTask($task['data']);
                    break;
                default:
                    echo "❌ Unknown task type: {$task['type']}\n";
                    return false;
            }
            
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            if ($result) {
                echo "✅ Task completed successfully in {$duration}ms\n";
            } else {
                echo "❌ Task failed, will retry...\n";
            }
            
            echo "─" . str_repeat("─", 50) . "\n";
            
            return $result;
            
        } catch (Throwable $e) {
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            echo "💥 Task failed with exception after {$duration}ms\n";
            echo "   Error: {$e->getMessage()}\n";
            echo "─" . str_repeat("─", 50) . "\n";
            
            return false;
        }
    });
}

// 启动消费者
$consumer = new Consumer($taskQueue);

// 设置内存限制为 256MB
$consumer->setMemoryLimit(256 * 1024 * 1024);

processTasks($consumer);