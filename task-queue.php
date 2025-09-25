<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\MonologFactory;
use Tinywan\RedisStream\Producer;
use Tinywan\RedisStream\Consumer;

// 任务队列示例 - 处理异步任务
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
        echo "   Attempt: {$message['attempts']}\n";
        echo "   Priority: {$task['priority']}\n";
        echo "   Created: {$task['created_at']}\n";
        
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

// 具体任务处理函数
function processEmailTask(array $data): bool
{
    echo "📧 Sending email to: {$data['to']}\n";
    echo "   Subject: {$data['subject']}\n";
    echo "   Template: " . ($data['template'] ?? 'default') . "\n";
    
    // 模拟邮件发送
    sleep(1);
    
    // 模拟失败概率 10%
    if (rand(1, 10) === 1) {
        echo "❌ Email sending failed (SMTP connection error)\n";
        return false;
    }
    
    echo "✅ Email sent successfully to {$data['to']}\n";
    return true;
}

function processImageTask(array $data): bool
{
    echo "🖼️  Processing image: {$data['filename']}\n";
    echo "   Operation: {$data['operation']}\n";
    echo "   Size: " . ($data['width'] ?? 'unknown') . "x" . ($data['height'] ?? 'unknown') . "\n";
    echo "   Format: " . ($data['format'] ?? 'jpg') . "\n";
    
    // 模拟图片处理
    sleep(2);
    
    // 模拟失败概率 5%
    if (rand(1, 20) === 1) {
        echo "❌ Image processing failed (invalid image format)\n";
        return false;
    }
    
    echo "✅ Image processed successfully: {$data['filename']}\n";
    return true;
}

function processReportTask(array $data): bool
{
    echo "📊 Generating report: {$data['report_name']}\n";
    echo "   Format: {$data['format']}\n";
    echo "   Period: " . ($data['period'] ?? 'monthly') . "\n";
    echo "   Type: " . ($data['type'] ?? 'summary') . "\n";
    
    // 模拟报表生成
    sleep(3);
    
    // 模拟失败概率 8%
    if (rand(1, 12) === 1) {
        echo "❌ Report generation failed (data source unavailable)\n";
        return false;
    }
    
    echo "✅ Report generated successfully: {$data['report_name']}.{$data['format']}\n";
    return true;
}

function processNotificationTask(array $data): bool
{
    echo "🔔 Sending notification\n";
    echo "   Type: {$data['notification_type']}\n";
    echo "   Target: {$data['target']}\n";
    echo "   Message: {$data['message']}\n";
    
    // 模拟通知发送
    sleep(1);
    
    // 模拟失败概率 15%
    if (rand(1, 7) === 1) {
        echo "❌ Notification failed (service unavailable)\n";
        return false;
    }
    
    echo "✅ Notification sent successfully to {$data['target']}\n";
    return true;
}

// 示例用法
if (isset($argv[1]) && $argv[1] === 'producer') {
    // 启动生产者
    $producer = new Producer($taskQueue);
    
    echo "🚀 Creating sample tasks...\n\n";
    
    // 创建高优先级邮件任务
    createTask($producer, 'email', [
        'to' => 'admin@example.com',
        'subject' => '🚨 System Alert: High Priority',
        'body' => 'Critical system notification requiring immediate attention.',
        'template' => 'urgent',
        'priority' => 'high'
    ]);
    
    // 创建图片处理任务
    createTask($producer, 'image', [
        'filename' => 'avatar_123.jpg',
        'operation' => 'resize',
        'width' => 150,
        'height' => 150,
        'format' => 'jpg',
        'priority' => 'normal'
    ]);
    
    // 创建报表生成任务
    createTask($producer, 'report', [
        'report_name' => 'monthly_revenue_2024',
        'format' => 'pdf',
        'period' => 'monthly',
        'type' => 'financial',
        'month' => date('Y-m'),
        'priority' => 'medium'
    ]);
    
    // 创建通知任务
    createTask($producer, 'notification', [
        'notification_type' => 'push',
        'target' => 'user_456',
        'message' => 'Your order has been shipped!',
        'priority' => 'normal'
    ]);
    
    // 批量创建邮件任务
    echo "📧 Creating batch email tasks...\n";
    for ($i = 1; $i <= 3; $i++) {
        createTask($producer, 'email', [
            'to' => "user{$i}@example.com",
            'subject' => "Welcome Newsletter #{$i}",
            'body' => "Here's your weekly newsletter with updates.",
            'template' => 'newsletter',
            'priority' => 'low'
        ]);
    }
    
    // 批量创建图片处理任务
    echo "🖼️  Creating batch image processing tasks...\n";
    for ($i = 1; $i <= 2; $i++) {
        createTask($producer, 'image', [
            'filename' => "product_{$i}.png",
            'operation' => 'optimize',
            'width' => 800,
            'height' => 600,
            'format' => 'png',
            'priority' => 'medium'
        ]);
    }
    
    echo "\n✅ All tasks created successfully!\n";
    echo "📊 Queue Status:\n";
    echo "   Stream Length: " . $taskQueue->getStreamLength() . "\n";
    echo "   Pending Count: " . $taskQueue->getPendingCount() . "\n";
    
} elseif (isset($argv[1]) && $argv[1] === 'consumer') {
    // 启动消费者
    $consumer = new Consumer($taskQueue);
    
    // 设置内存限制为 256MB
    $consumer->setMemoryLimit(256 * 1024 * 1024);
    
    processTasks($consumer);
    
} elseif (isset($argv[1]) && $argv[1] === 'status') {
    // 查看队列状态
    echo "📊 Queue Status:\n";
    echo "   Stream: " . $taskQueue->getStreamName() . "\n";
    echo "   Group: " . $taskQueue->getConsumerGroup() . "\n";
    echo "   Stream Length: " . $taskQueue->getStreamLength() . "\n";
    echo "   Pending Count: " . $taskQueue->getPendingCount() . "\n";
    echo "   Redis Config: " . json_encode($taskQueue->getRedisConfig()) . "\n";
    
} else {
    echo "📖 Usage:\n";
    echo "  php task-queue.php producer  # Create sample tasks\n";
    echo "  php task-queue.php consumer  # Process tasks (runs continuously)\n";
    echo "  php task-queue.php status    # Show queue status\n";
    echo "\n💡 Example:\n";
    echo "  1. Run 'php task-queue.php producer' to create tasks\n";
    echo "  2. Run 'php task-queue.php consumer' to process them\n";
    echo "  3. Use Ctrl+C to stop the consumer\n";
}