<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tinywan\RedisStream\DelayedQueue;
use Tinywan\RedisStream\DelayedScheduler;
use Tinywan\RedisStream\Consumer;

// 加载配置文件
$redisConfigs = require __DIR__ . '/../src/config/redis.php';
$queueConfigs = require __DIR__ . '/../src/config/redis-stream-queue.php';

// 环境配置
$env = getenv('APP_ENV') ?: 'development';
$enableDebug = getenv('REDIS_STREAM_DEBUG') === 'true' || in_array('--debug', $argv);

// 选择配置
$redisConfig = $redisConfigs['default'];
$delayedConfig = $queueConfigs['delayed_queue'];

// 动态配置
$delayedConfig['consumer_name'] = 'delayed_demo_' . getmypid();
$delayedConfig['debug'] = $enableDebug;

// 创建延时队列实例
$delayedQueue = DelayedQueue::getInstance($redisConfig, $delayedConfig);

// 获取logger实例
$logger = $delayedQueue->getLogger();

// 显示配置信息
echo "=== 延时队列演示 ===\n";
echo "环境: $env\n";
echo "延时流: " . $delayedQueue->getDelayedStreamName() . "\n";
echo "就绪流: " . $delayedQueue->getReadyStreamName() . "\n";
echo "消费者组: " . $delayedQueue->getConsumerGroup() . "\n";
echo "日志配置: 调试模式=" . ($enableDebug ? '启用' : '禁用') . "\n";
echo "==================\n\n";

// 记录启动日志
$logger->info('Delayed queue demo started', [
    'pid' => getmypid(),
    'delayed_stream' => $delayedQueue->getDelayedStreamName(),
    'ready_stream' => $delayedQueue->getReadyStreamName(),
    'consumer_group' => $delayedQueue->getConsumerGroup(),
    'consumer_name' => $delayedQueue->getConsumerName()
]);

// 创建延时消息
function createDelayedMessage(DelayedQueue $queue, string $type, array $data, int $delaySeconds): void
{
    $messageId = uniqid('delayed_');
    $messageData = [
        'message_id' => $messageId,
        'type' => $type,
        'data' => $data,
        'created_at' => date('Y-m-d H:i:s'),
        'delay_seconds' => $delaySeconds,
        'execute_at' => date('Y-m-d H:i:s', time() + $delaySeconds)
    ];
    
    $startTime = microtime(true);
    
    try {
        $redisMessageId = $queue->sendDelayed(json_encode($messageData), $delaySeconds, [
            'message_type' => $type,
            'message_id' => $messageId,
            'priority' => $data['priority'] ?? 'normal'
        ]);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录消息创建日志
        $queue->getLogger()->info('Delayed message created successfully', [
            'message_id' => $messageId,
            'message_type' => $type,
            'redis_message_id' => $redisMessageId,
            'delay_seconds' => $delaySeconds,
            'execute_at' => $messageData['execute_at'],
            'duration_ms' => $duration,
            'data_size' => strlen(json_encode($messageData))
        ]);
        
        echo "✅ Delayed message created: $messageId ($type) - Delay: {$delaySeconds}s - Execute at: {$messageData['execute_at']}\n";
        
    } catch (Throwable $e) {
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录错误日志
        $queue->getLogger()->error('Failed to create delayed message', [
            'message_id' => $messageId,
            'message_type' => $type,
            'error' => $e->getMessage(),
            'duration_ms' => $duration
        ]);
        
        echo "❌ Failed to create delayed message: $messageId - Error: " . $e->getMessage() . "\n";
    }
}

// 创建定时消息
function createScheduledMessage(DelayedQueue $queue, string $type, array $data, string $executeTime): void
{
    $timestamp = strtotime($executeTime);
    if ($timestamp === false) {
        echo "❌ Invalid execute time format: $executeTime\n";
        return;
    }
    
    $messageId = uniqid('scheduled_');
    $messageData = [
        'message_id' => $messageId,
        'type' => $type,
        'data' => $data,
        'created_at' => date('Y-m-d H:i:s'),
        'execute_at' => date('Y-m-d H:i:s', $timestamp)
    ];
    
    $startTime = microtime(true);
    
    try {
        $redisMessageId = $queue->sendAt(json_encode($messageData), $timestamp, [
            'message_type' => $type,
            'message_id' => $messageId,
            'priority' => $data['priority'] ?? 'normal'
        ]);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录消息创建日志
        $queue->getLogger()->info('Scheduled message created successfully', [
            'message_id' => $messageId,
            'message_type' => $type,
            'redis_message_id' => $redisMessageId,
            'execute_at' => $messageData['execute_at'],
            'duration_ms' => $duration,
            'data_size' => strlen(json_encode($messageData))
        ]);
        
        echo "✅ Scheduled message created: $messageId ($type) - Execute at: {$messageData['execute_at']}\n";
        
    } catch (Throwable $e) {
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录错误日志
        $queue->getLogger()->error('Failed to create scheduled message', [
            'message_id' => $messageId,
            'message_type' => $type,
            'error' => $e->getMessage(),
            'duration_ms' => $duration
        ]);
        
        echo "❌ Failed to create scheduled message: $messageId - Error: " . $e->getMessage() . "\n";
    }
}

// 消息处理器
function processReadyMessage(DelayedQueue $queue, callable $callback = null): void
{
    $logger = $queue->getLogger();
    
    echo "🚀 Starting ready message processor...\n";
    echo "   Ready Stream: " . $queue->getReadyStreamName() . "\n";
    echo "   Group: " . $queue->getConsumerGroup() . "\n";
    echo "   Consumer: " . $queue->getConsumerName() . "\n\n";
    
    $processedCount = 0;
    $successCount = 0;
    
    // 处理最多10条消息用于演示
    for ($i = 0; $i < 10; $i++) {
        $message = $queue->consume($callback);
        
        if ($message === null) {
            echo "⏳ No more ready messages\n";
            break;
        }
        
        $processedCount++;
        $messageData = json_decode($message['message'], true);
        
        echo "📋 Processing ready message: {$messageData['message_id']} ({$messageData['type']})\n";
        echo "   Created at: {$messageData['created_at']}\n";
        echo "   Execute at: {$messageData['execute_at']}\n";
        echo "   Attempts: {$message['attempts']}\n";
        
        if ($callback === null) {
            // 手动确认消息
            $queue->ack($message['id']);
            $successCount++;
            echo "✅ Message acknowledged\n";
        }
        
        echo "─" . str_repeat("─", 50) . "\n";
    }
    
    // 记录处理日志
    $logger->info('Ready message processing completed', [
        'processed_count' => $processedCount,
        'success_count' => $successCount,
        'consumer_name' => $queue->getConsumerName()
    ]);
}

// 示例用法
if (isset($argv[1]) && $argv[1] === 'producer') {
    echo "🚀 Creating delayed messages for demo...\n\n";
    
    // 创建10秒后执行的邮件任务
    createDelayedMessage($delayedQueue, 'email', [
        'to' => 'user@example.com',
        'subject' => 'Delayed Email',
        'template' => 'welcome',
        'priority' => 'high'
    ], 10);
    
    // 创建30秒后执行的图片处理任务
    createDelayedMessage($delayedQueue, 'image', [
        'filename' => 'delayed_image.jpg',
        'operation' => 'resize',
        'width' => 800,
        'height' => 600,
        'priority' => 'normal'
    ], 30);
    
    // 创建1分钟后执行的报表生成任务
    createDelayedMessage($delayedQueue, 'report', [
        'report_name' => 'daily_summary_' . date('Ymd'),
        'type' => 'daily',
        'priority' => 'medium'
    ], 60);
    
    // 创建5秒后执行的短延时任务
    createDelayedMessage($delayedQueue, 'notification', [
        'type' => 'push',
        'message' => 'Short delay notification',
        'priority' => 'low'
    ], 5);
    
    // 创建定时消息（在指定时间执行）
    $futureTime = date('Y-m-d H:i:s', time() + 45);
    createScheduledMessage($delayedQueue, 'backup', [
        'type' => 'database',
        'target' => 'user_data',
        'priority' => 'high'
    ], $futureTime);
    
    // 记录创建完成日志
    $logger->info('All delayed messages created successfully', [
        'total_messages' => 5,
        'queue_status' => $delayedQueue->getStatus()
    ]);
    
    echo "\n✅ All delayed messages created successfully!\n";
    echo "📊 Current Queue Status:\n";
    echo "   Delayed Stream Length: " . $delayedQueue->getDelayedStreamLength() . "\n";
    echo "   Ready Stream Length: " . $delayedQueue->getReadyStreamLength() . "\n";
    echo "   Pending Count: " . $delayedQueue->getPendingCount() . "\n";
    echo "   Upcoming (60s): " . $delayedQueue->getUpcomingMessageCount(60) . "\n";
    
} elseif (isset($argv[1]) && $argv[1] === 'scheduler') {
    echo "🚀 Starting delayed message scheduler...\n\n";
    
    // 创建调度器
    $scheduler = new DelayedScheduler(
        $delayedQueue,
        $delayedConfig['scheduler_interval'],
        $delayedConfig['max_batch_size'],
        64 * 1024 * 1024 // 64MB memory limit
    );
    
    // 设置信号处理器
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, function() use ($scheduler) {
            echo "\n🛑 Received SIGTERM, stopping scheduler...\n";
            $scheduler->stop();
        });
        pcntl_signal(SIGINT, function() use ($scheduler) {
            echo "\n🛑 Received SIGINT, stopping scheduler...\n";
            $scheduler->stop();
        });
    }
    
    // 记录调度器启动日志
    $logger->info('Delayed scheduler started', [
        'pid' => getmypid(),
        'interval' => $delayedConfig['scheduler_interval'],
        'max_batch_size' => $delayedConfig['max_batch_size'],
        'queue_status' => $delayedQueue->getStatus()
    ]);
    
    echo "⚙️  Scheduler Configuration:\n";
    echo "   Interval: {$delayedConfig['scheduler_interval']}s\n";
    echo "   Max Batch Size: {$delayedConfig['max_batch_size']}\n";
    echo "   Memory Limit: 64MB\n";
    echo "   Delayed Stream: " . $delayedQueue->getDelayedStreamName() . "\n";
    echo "   Ready Stream: " . $delayedQueue->getReadyStreamName() . "\n\n";
    
    // 启动调度器
    $scheduler->start();
    
} elseif (isset($argv[1]) && $argv[1] === 'consumer') {
    echo "🚀 Starting ready message consumer...\n\n";
    
    // 使用回调函数处理消息
    processReadyMessage($delayedQueue, function($message) use ($logger) {
        $messageData = json_decode($message['message'], true);
        
        echo "🔄 Processing message with callback:\n";
        echo "   Type: {$messageData['type']}\n";
        echo "   Message ID: {$messageData['message_id']}\n";
        
        // 模拟处理延迟
        sleep(1);
        
        // 记录处理完成日志
        $logger->info('Message processed with callback', [
            'message_id' => $messageData['message_id'],
            'message_type' => $messageData['type'],
            'attempts' => $message['attempts']
        ]);
        
        return true; // 自动确认消息
    });
    
} elseif (isset($argv[1]) && $argv[1] === 'status') {
    echo "📊 Delayed Queue Status:\n";
    echo "   Delayed Stream: " . $delayedQueue->getDelayedStreamName() . "\n";
    echo "   Ready Stream: " . $delayedQueue->getReadyStreamName() . "\n";
    echo "   Consumer Group: " . $delayedQueue->getConsumerGroup() . "\n";
    echo "   Consumer: " . $delayedQueue->getConsumerName() . "\n";
    echo "   Delayed Stream Length: " . $delayedQueue->getDelayedStreamLength() . "\n";
    echo "   Ready Stream Length: " . $delayedQueue->getReadyStreamLength() . "\n";
    echo "   Pending Count: " . $delayedQueue->getPendingCount() . "\n";
    echo "   Upcoming (30s): " . $delayedQueue->getUpcomingMessageCount(30) . "\n";
    echo "   Upcoming (60s): " . $delayedQueue->getUpcomingMessageCount(60) . "\n";
    echo "   Upcoming (300s): " . $delayedQueue->getUpcomingMessageCount(300) . "\n";
    
    // 记录状态查询日志
    $logger->info('Delayed queue status queried', [
        'delayed_stream' => $delayedQueue->getDelayedStreamName(),
        'ready_stream' => $delayedQueue->getReadyStreamName(),
        'status' => $delayedQueue->getStatus()
    ]);
    
} elseif (isset($argv[1]) && $argv[1] === 'demo') {
    echo "🎯 Delayed Queue Demo\n";
    echo "=====================\n\n";
    
    // 记录演示开始日志
    $logger->info('Delayed queue demo started');
    
    // 1. 创建一些延时消息
    echo "📝 Step 1: Creating delayed messages...\n";
    createDelayedMessage($delayedQueue, 'demo_email', [
        'to' => 'demo@example.com',
        'subject' => 'Demo Email'
    ], 5);
    
    createDelayedMessage($delayedQueue, 'demo_task', [
        'task_name' => 'Demo Task',
        'data' => 'Sample data'
    ], 10);
    
    // 2. 运行一次调度器检查
    echo "\n⚙️  Step 2: Running scheduler check...\n";
    $transferred = $delayedQueue->runScheduler();
    echo "   Transferred messages: $transferred\n";
    
    // 3. 显示队列状态
    echo "\n📊 Step 3: Current queue status:\n";
    $status = $delayedQueue->getStatus();
    foreach ($status as $key => $value) {
        echo "   $key: $value\n";
    }
    
    // 记录演示结果日志
    $logger->info('Delayed queue demo completed', [
        'created_messages' => 2,
        'transferred_messages' => $transferred,
        'final_status' => $status
    ]);
    
} else {
    echo "📖 延时队列演示用法:\n";
    echo "  php delayed-queue.php producer        # 创建延时消息\n";
    echo "  php delayed-queue.php scheduler        # 启动调度器（持续运行）\n";
    echo "  php delayed-queue.php consumer         # 消费就绪消息\n";
    echo "  php delayed-queue.php status           # 查看队列状态\n";
    echo "  php delayed-queue.php demo             # 完整演示流程\n";
    echo "\n🔧 调试选项:\n";
    echo "  --debug                        # 启用调试模式\n";
    echo "  REDIS_STREAM_DEBUG=true        # 环境变量启用调试模式\n";
    echo "\n💡 演示说明:\n";
    echo "  1. producer: 创建不同延时时间的消息\n";
    echo "  2. scheduler: 启动调度器将到期消息转移到就绪流\n";
    echo "  3. consumer: 处理就绪流中的消息\n";
    echo "  4. status: 查看当前队列状态统计\n";
    echo "  5. demo: 完整的单次演示流程\n";
    echo "\n🔧 延时队列特性:\n";
    echo "  - 支持延时消息（sendDelayed）\n";
    echo "  - 支持定时消息（sendAt）\n";
    echo "  - 自动调度器转移到期消息\n";
    echo "  - 多级重试机制\n";
    echo "  - 内存监控和优雅停机\n";
    echo "  - 详细的日志记录\n";
}