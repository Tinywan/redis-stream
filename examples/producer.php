<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\Producer;

// 加载配置文件
$redisConfigs = require __DIR__ . '/../src/config/redis.php';
$queueConfigs = require __DIR__ . '/../src/config/redis-stream-queue.php';

// 环境配置
$env = getenv('APP_ENV') ?: 'development';
$enableDebug = getenv('REDIS_STREAM_DEBUG') === 'true' || in_array('--debug', $argv);

// 选择配置
$redisConfig = $redisConfigs['default'];
$queueConfig = $queueConfigs['default'];

// 动态配置
$queueConfig['consumer_name'] = 'producer_' . getmypid();
$queueConfig['debug'] = $enableDebug;

$taskQueue = RedisStreamQueue::getInstance($redisConfig, $queueConfig);

// 获取logger实例
$logger = $taskQueue->getLogger();

// 显示配置信息
echo "=== 任务队列配置 ===\n";
echo "环境: $env\n";
echo "Redis配置: " . json_encode($taskQueue->getRedisConfig(), JSON_PRETTY_PRINT) . "\n";
echo "队列配置: " . json_encode($taskQueue->getQueueConfig(), JSON_PRETTY_PRINT) . "\n";
echo "日志配置: 调试模式=" . ($enableDebug ? '启用' : '禁用') . "\n";
echo "===================\n\n";

// 记录启动日志
$logger->info('Task producer started', [
    'pid' => getmypid(),
    'stream_name' => $taskQueue->getStreamName(),
    'consumer_group' => $taskQueue->getConsumerGroup(),
    'consumer_name' => $taskQueue->getConsumerName()
]);

// 任务生产者
function createTask(Producer $producer, string $taskType, array $data, int $delayOrTimestamp = 0): void
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
        ], $delayOrTimestamp);
        
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

// 创建延时邮件任务（1小时后发送）
createTask($producer, 'email', [
    'to' => 'delayed@example.com',
    'subject' => 'Delayed Email (1 hour later)',
    'priority' => 'normal'
], 3600); // 1小时 = 3600秒

// 记录完成日志
$logger->info('All sample tasks created successfully', [
    'total_tasks' => 5,
    'stream_length' => $taskQueue->getStreamLength(),
    'pending_count' => $taskQueue->getPendingCount(),
    'delayed_stream_length' => $taskQueue->getDelayedStreamLength()
]);

echo "\n✅ All tasks created successfully!\n";
echo "📊 Current Queue Status:\n";
echo "   Stream Length: " . $taskQueue->getStreamLength() . "\n";
echo "   Pending Count: " . $taskQueue->getPendingCount() . "\n";
echo "   Delayed Stream Length: " . $taskQueue->getDelayedStreamLength() . "\n";
echo "   Upcoming (1 hour): " . $taskQueue->getUpcomingMessageCount(3600) . "\n";