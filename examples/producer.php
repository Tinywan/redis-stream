<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\MonologFactory;
use Tinywan\RedisStream\Producer;

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
    MonologFactory::createLogger('task-queue', 'development')
);

// 显示配置信息
echo "=== 任务队列配置 ===\n";
echo "Redis配置: " . json_encode($taskQueue->getRedisConfig(), JSON_PRETTY_PRINT) . "\n";
echo "队列配置: " . json_encode($taskQueue->getQueueConfig(), JSON_PRETTY_PRINT) . "\n";
echo "===================\n\n";

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
    
    $producer->send(json_encode($taskData), [
        'task_type' => $taskType,
        'task_id' => $taskId,
        'priority' => $data['priority'] ?? 'normal'
    ]);
    
    echo "✅ Task created: $taskId ($taskType) - Priority: " . ($data['priority'] ?? 'normal') . "\n";
}

// 启动生产者
$producer = new Producer($taskQueue);

echo "🚀 Creating sample tasks...\n\n";

// 创建高优先级邮件任务
createTask($producer, 'email', [
    'to' => 'admin@example.com',
]);

// 创建图片处理任务
createTask($producer, 'image', [
    'filename' => 'avatar_123.jpg',
]);

// 创建报表生成任务
createTask($producer, 'report', [
    'report_name' => 'monthly_revenue_2024',
]);

// 创建通知任务
createTask($producer, 'notification', [
    'notification_type' => 'push',
]);

echo "\n✅ All tasks created successfully!\n";