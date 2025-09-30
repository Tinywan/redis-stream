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
//echo "Redis配置: " . json_encode($taskQueue->getRedisConfig(), JSON_PRETTY_PRINT) . "\n";
//echo "队列配置: " . json_encode($taskQueue->getQueueConfig(), JSON_PRETTY_PRINT) . "\n";
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

// 创建延迟任务示例
echo "\n🕒 Creating delayed tasks...\n";

// 延迟10秒的清理任务
createTask($producer, 'cleanup', [
    'cleanup_type' => 'temp_files',
    'description' => 'Clean temporary files older than 7 days'
], 10);

// 延迟30秒的备份任务
createTask($producer, 'backup', [
    'backup_type' => 'database',
    'description' => 'Daily database backup'
], 30);

// 延迟60秒的报表任务
createTask($producer, 'report', [
    'report_name' => 'daily_summary',
    'description' => 'Generate daily summary report',
    'priority' => 'low'
], 60);

// 指定时间执行的任务 - 执行当前时间+90秒的时间戳
$futureTimestamp = time() + 90;
createTask($producer, 'scheduled', [
    'task_name' => 'send_daily_report',
    'description' => 'Send daily report to executives',
    'priority' => 'high'
], $futureTimestamp);

// 记录完成日志
$logger->info('All sample tasks created successfully', [
    'total_tasks' => 8,
    'delayed_tasks' => 4,
    'stream_length' => $taskQueue->getStreamLength(),
    'pending_count' => $taskQueue->getPendingCount(),
    'delayed_queue_length' => $taskQueue->getDelayedQueueLength()
]);

echo "\n✅ All tasks created successfully!\n";
echo "📊 Current Queue Status:\n";
echo "   Stream Length: " . $taskQueue->getStreamLength() . "\n";
echo "   Pending Count: " . $taskQueue->getPendingCount() . "\n";
echo "   Delayed Queue Length: " . $taskQueue->getDelayedQueueLength() . "\n";