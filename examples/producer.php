<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\MonologFactory;
use Tinywan\RedisStream\Producer;

// 日志配置
$enableFileLogging = getenv('REDIS_STREAM_FILE_LOG') === 'true' || in_array('--file-log', $argv);
$enableDebug = getenv('REDIS_STREAM_DEBUG') === 'true' || in_array('--debug', $argv);

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
    'consumer_name' => 'producer_' . getmypid(),
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

// 记录启动日志
$logger->info('Task producer started', [
    'pid' => getmypid(),
    'stream_name' => $taskQueue->getStreamName(),
    'consumer_group' => $taskQueue->getConsumerGroup(),
    'consumer_name' => $taskQueue->getConsumerName()
]);

// 任务生产者
function createTask(Producer $producer, string $taskType, array $data): void
{
    $taskId = uniqid('task_');
    $taskData = [
        'task_id' => $taskId,
        'type' => $taskType,
        'data' => $data,
        'created_at' => date('Y-m-d H:i:s'),
        'priority' => $data['priority'] ?? 'normal'
    ];
    
    $startTime = microtime(true);
    
    try {
        $messageId = $producer->send(json_encode($taskData), [
            'task_type' => $taskType,
            'task_id' => $taskId,
            'priority' => $data['priority'] ?? 'normal'
        ]);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录任务创建日志
        $producer->getQueue()->getLogger()->info('Task created successfully', [
            'task_id' => $taskId,
            'task_type' => $taskType,
            'message_id' => $messageId,
            'priority' => $data['priority'] ?? 'normal',
            'duration_ms' => $duration,
            'data_size' => strlen(json_encode($taskData))
        ]);
        
        echo "✅ Task created: $taskId ($taskType) - Priority: " . ($data['priority'] ?? 'normal') . " - Duration: {$duration}ms\n";
        
    } catch (Throwable $e) {
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录错误日志
        $producer->getQueue()->getLogger()->error('Failed to create task', [
            'task_id' => $taskId,
            'task_type' => $taskType,
            'error' => $e->getMessage(),
            'duration_ms' => $duration
        ]);
        
        echo "❌ Failed to create task: $taskId - Error: " . $e->getMessage() . "\n";
    }
}

// 启动生产者
$producer = new Producer($taskQueue);

echo "🚀 Creating sample tasks...\n\n";

// 创建高优先级邮件任务
createTask($producer, 'email', [
    'to' => 'admin@example.com',
    'priority' => 'high'
]);

// 创建图片处理任务
createTask($producer, 'image', [
    'filename' => 'avatar_123.jpg',
    'priority' => 'normal'
]);

// 创建报表生成任务
createTask($producer, 'report', [
    'report_name' => 'monthly_revenue_2024',
    'priority' => 'medium'
]);

// 创建通知任务
createTask($producer, 'notification', [
    'notification_type' => 'push',
    'priority' => 'low'
]);

// 记录完成日志
$logger->info('All sample tasks created successfully', [
    'total_tasks' => 4,
    'stream_length' => $taskQueue->getStreamLength(),
    'pending_count' => $taskQueue->getPendingCount()
]);

echo "\n✅ All tasks created successfully!\n";
echo "📊 Current Queue Status:\n";
echo "   Stream Length: " . $taskQueue->getStreamLength() . "\n";
echo "   Pending Count: " . $taskQueue->getPendingCount() . "\n";