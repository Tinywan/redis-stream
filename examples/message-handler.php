<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/MessageHandlers.php';

use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\Producer;
use Tinywan\RedisStream\Consumer;
use App\MessageHandler\MessageHandlerRouter;

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
$queueConfig['consumer_name'] = 'handler_' . getmypid();
$queueConfig['debug'] = $enableDebug;

$taskQueue = RedisStreamQueue::getInstance($redisConfig, $queueConfig);
$logger = $taskQueue->getLogger();

// 显示配置信息
echo "=== MessageHandlerInterface 示例 ===\n";
echo "环境: $env\n";
echo "Redis配置: " . json_encode($taskQueue->getRedisConfig(), JSON_PRETTY_PRINT) . "\n";
echo "队列配置: " . json_encode($taskQueue->getQueueConfig(), JSON_PRETTY_PRINT) . "\n";
echo "日志配置: 调试模式=" . ($enableDebug ? '启用' : '禁用') . "\n";
echo "================================\n\n";

// 记录启动日志
$logger->info('MessageHandlerInterface example started', [
    'pid' => getmypid(),
    'stream_name' => $taskQueue->getStreamName(),
    'consumer_group' => $taskQueue->getConsumerGroup(),
    'consumer_name' => $taskQueue->getConsumerName()
]);

// 创建测试消息
function createTestMessage(Producer $producer, string $type, array $data, int $delayOrTimestamp = 0): void
{
    $messageId = uniqid('msg_');
    $messageData = [
        'message_id' => $messageId,
        'type' => $type,
        'data' => $data,
        'created_at' => date('Y-m-d H:i:s'),
        'priority' => $data['priority'] ?? 'normal'
    ];
    
    $startTime = microtime(true);
    
    try {
        $redisMessageId = $producer->send(json_encode($messageData), [
            'message_type' => $type,
            'message_id' => $messageId,
            'priority' => $data['priority'] ?? 'normal'
        ], $delayOrTimestamp);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录消息创建日志
        $producer->getQueue()->getLogger()->info('Test message created successfully', [
            'message_id' => $messageId,
            'message_type' => $type,
            'redis_message_id' => $redisMessageId,
            'priority' => $data['priority'] ?? 'normal',
            'duration_ms' => $duration,
            'data_size' => strlen(json_encode($messageData))
        ]);
        
        echo "✅ Message created: $messageId ($type) - Duration: {$duration}ms\n";
        
    } catch (Throwable $e) {
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        // 记录错误日志
        $producer->getQueue()->getLogger()->error('Failed to create test message', [
            'message_id' => $messageId,
            'message_type' => $type,
            'error' => $e->getMessage(),
            'duration_ms' => $duration
        ]);
        
        echo "❌ Failed to create message: $messageId - Error: " . $e->getMessage() . "\n";
    }
}

// 示例用法
if (isset($argv[1]) && $argv[1] === 'producer') {
    echo "🚀 Creating test messages for MessageHandlerInterface...\n\n";
    
    $producer = new Producer($taskQueue);
    $totalMessages = 0;
    
    // 创建邮件消息
    createTestMessage($producer, 'email', [
        'to' => 'user@example.com',
        'subject' => 'Welcome to our service',
        'template' => 'welcome',
        'priority' => 'high'
    ]);
    $totalMessages++;
    
    // 创建图片处理消息
    createTestMessage($producer, 'image', [
        'filename' => 'profile.jpg',
        'operation' => 'resize',
        'width' => 300,
        'height' => 300,
        'format' => 'jpg',
        'priority' => 'normal'
    ]);
    $totalMessages++;
    
    // 创建日志消息
    createTestMessage($producer, 'log', [
        'level' => 'info',
        'message' => 'User login successful',
        'context' => ['user_id' => 123, 'ip' => '192.168.1.1'],
        'priority' => 'low'
    ]);
    $totalMessages++;
    
    // 批量创建邮件消息
    echo "📧 Creating batch email messages...\n";
    for ($i = 1; $i <= 3; $i++) {
        createTestMessage($producer, 'email', [
            'to' => "user{$i}@example.com",
            'subject' => "Newsletter #{$i}",
            'template' => 'newsletter',
            'content' => "This is newsletter number {$i}",
            'priority' => 'low'
        ]);
        $totalMessages++;
    }
    
    // 批量创建图片处理消息
    echo "🖼️  Creating batch image processing messages...\n";
    for ($i = 1; $i <= 2; $i++) {
        createTestMessage($producer, 'image', [
            'filename' => "product_{$i}.png",
            'operation' => 'optimize',
            'width' => 800,
            'height' => 600,
            'format' => 'png',
            'priority' => 'medium'
        ]);
        $totalMessages++;
    }
    
    // 批量创建日志消息
    echo "📝 Creating batch log messages...\n";
    for ($i = 1; $i <= 5; $i++) {
        $levels = ['info', 'warning', 'error'];
        $level = $levels[array_rand($levels)];
        
        createTestMessage($producer, 'log', [
            'level' => $level,
            'message' => "Test log message {$i}",
            'context' => ['test_id' => $i, 'timestamp' => time()],
            'priority' => 'low'
        ]);
        $totalMessages++;
    }
    
    // 创建延时消息
    echo "⏰ Creating delayed messages...\n";
    createTestMessage($producer, 'email', [
        'to' => 'delayed@example.com',
        'subject' => 'Delayed Email (30 minutes)',
        'template' => 'delayed_welcome',
        'priority' => 'normal'
    ], 1800); // 30分钟延时
    $totalMessages++;
    
    createTestMessage($producer, 'image', [
        'filename' => 'delayed_avatar.png',
        'operation' => 'thumbnail',
        'width' => 100,
        'height' => 100,
        'format' => 'png',
        'priority' => 'low'
    ], 900); // 15分钟延时
    
    // 记录批量创建完成日志
    $logger->info('Batch messages creation completed', [
        'total_messages' => $totalMessages,
        'stream_length' => $taskQueue->getStreamLength(),
        'pending_count' => $taskQueue->getPendingCount(),
        'delayed_stream_length' => $taskQueue->getDelayedStreamLength(),
        'message_types' => ['email' => 5, 'image' => 4, 'log' => 6]
    ]);
    
    echo "\n✅ All messages created successfully!\n";
    echo "📊 Queue Status:\n";
    echo "   Stream Length: " . $taskQueue->getStreamLength() . "\n";
    echo "   Pending Count: " . $taskQueue->getPendingCount() . "\n";
    echo "   Delayed Stream Length: " . $taskQueue->getDelayedStreamLength() . "\n";
    echo "   Upcoming (30 minutes): " . $taskQueue->getUpcomingMessageCount(1800) . "\n";
    echo "   Total Messages: {$totalMessages}\n";
    
} elseif (isset($argv[1]) && $argv[1] === 'consumer') {
    echo "🚀 Starting consumer with MessageHandlerInterface...\n\n";
    
    $consumer = new Consumer($taskQueue);
    
    // 创建消息处理器路由
    $handlerRouter = new MessageHandlerRouter($logger);
    
    // 设置内存限制
    $consumer->setMemoryLimit(256 * 1024 * 1024);
    
    // 记录消费者启动日志
    $logger->info('MessageHandlerInterface consumer started', [
        'pid' => getmypid(),
        'stream_name' => $taskQueue->getStreamName(),
        'consumer_group' => $taskQueue->getConsumerGroup(),
        'consumer_name' => $taskQueue->getConsumerName(),
        'memory_limit' => '256MB',
        'available_handlers' => ['email', 'image', 'log']
    ]);
    
    echo "📋 Message Handler Router Configuration:\n";
    echo "   Available handlers: " . implode(', ', ['email', 'image', 'log']) . "\n";
    echo "   Stream: " . $taskQueue->getStreamName() . "\n";
    echo "   Delayed Stream: " . $taskQueue->getDelayedStreamName() . "\n";
    echo "   Group: " . $taskQueue->getConsumerGroup() . "\n";
    echo "   Consumer: " . $taskQueue->getConsumerName() . "\n\n";
    
    // 使用消息处理器路由
    $processedCount = 0;
    $consumer->run(function($message) use ($handlerRouter, $logger, &$processedCount) {
        $processedCount++;
        
        // 记录消息处理开始
        $logger->info('Processing message with handler', [
            'message_id' => $message['id'],
            'attempts' => $message['attempts'],
            'processed_count' => $processedCount
        ]);
        
        $result = $handlerRouter->handle($message['message']);
        
        // 记录处理结果
        $logger->info('Message processing completed', [
            'message_id' => $message['id'],
            'result' => $result ? 'success' : 'failure',
            'attempts' => $message['attempts']
        ]);
        
        return $result;
    });
    
    // 记录消费者停止日志
    $logger->info('MessageHandlerInterface consumer stopped', [
        'total_processed' => $processedCount,
        'final_stream_length' => $taskQueue->getStreamLength(),
        'final_pending_count' => $taskQueue->getPendingCount()
    ]);
    
} elseif (isset($argv[1]) && $argv[1] === 'status') {
    echo "📊 Queue Status:\n";
    echo "   Stream: " . $taskQueue->getStreamName() . "\n";
    echo "   Delayed Stream: " . $taskQueue->getDelayedStreamName() . "\n";
    echo "   Group: " . $taskQueue->getConsumerGroup() . "\n";
    echo "   Stream Length: " . $taskQueue->getStreamLength() . "\n";
    echo "   Pending Count: " . $taskQueue->getPendingCount() . "\n";
    echo "   Delayed Stream Length: " . $taskQueue->getDelayedStreamLength() . "\n";
    echo "   Upcoming (1 hour): " . $taskQueue->getUpcomingMessageCount(3600) . "\n";
    echo "   Consumer: " . $taskQueue->getConsumerName() . "\n";
    
    // 记录状态查询日志
    $logger->info('Queue status queried', [
        'stream_name' => $taskQueue->getStreamName(),
        'stream_length' => $taskQueue->getStreamLength(),
        'pending_count' => $taskQueue->getPendingCount(),
        'consumer_name' => $taskQueue->getConsumerName()
    ]);
    
} elseif (isset($argv[1]) && $argv[1] === 'demo') {
    echo "🎯 MessageHandlerInterface Demo\n";
    echo "================================\n\n";
    
    // 记录演示开始日志
    $logger->info('MessageHandlerInterface demo started');
    
    echo "📧 Email Handler Demo:\n";
    $emailHandler = new \App\MessageHandler\EmailMessageHandler($logger);
    $emailMessage = json_encode([
        'type' => 'email',
        'data' => [
            'to' => 'demo@example.com',
            'subject' => 'Demo Email',
            'template' => 'welcome',
            'body' => 'Welcome to our demo!'
        ]
    ]);
    $emailResult = $emailHandler->handle($emailMessage);
    echo "   Result: " . ($emailResult ? '✅ Success' : '❌ Failed') . "\n\n";
    
    echo "🖼️  Image Handler Demo:\n";
    $imageHandler = new \App\MessageHandler\ImageProcessHandler($logger);
    $imageMessage = json_encode([
        'type' => 'image',
        'data' => [
            'filename' => 'demo.jpg',
            'operation' => 'resize',
            'width' => 200,
            'height' => 200,
            'format' => 'jpg'
        ]
    ]);
    $imageResult = $imageHandler->handle($imageMessage);
    echo "   Result: " . ($imageResult ? '✅ Success' : '❌ Failed') . "\n\n";
    
    echo "📝 Log Handler Demo:\n";
    $logHandler = new \App\MessageHandler\LogMessageHandler($logger);
    $logMessage = json_encode([
        'type' => 'log',
        'data' => [
            'level' => 'info',
            'message' => 'Demo log entry',
            'context' => ['demo' => true, 'timestamp' => time()]
        ]
    ]);
    $logResult = $logHandler->handle($logMessage);
    echo "   Result: " . ($logResult ? '✅ Success' : '❌ Failed') . "\n\n";
    
    // 记录演示结果日志
    $logger->info('MessageHandlerInterface demo completed', [
        'email_handler_result' => $emailResult,
        'image_handler_result' => $imageResult,
        'log_handler_result' => $logResult
    ]);
    
} else {
    echo "📖 MessageHandlerInterface 示例用法:\n";
    echo "  php message-handler.php producer        # 创建测试消息\n";
    echo "  php message-handler.php consumer        # 使用自定义处理器处理消息\n";
    echo "  php message-handler.php demo            # 演示各个处理器的功能\n";
    echo "  php message-handler.php status          # 查看队列状态\n";
    echo "\n🔧 日志配置选项:\n";
    echo "  --debug                        # 启用调试模式\n";
    echo "  REDIS_STREAM_DEBUG=true        # 环境变量启用调试模式\n";
    echo "\n💡 示例说明:\n";
    echo "  1. producer: 创建不同类型的测试消息\n";
    echo "  2. consumer: 使用 MessageHandlerInterface 实现处理消息\n";
    echo "  3. demo: 演示各个独立处理器的功能\n";
    echo "  4. status: 查看当前队列状态\n";
    echo "\n🔧 自定义处理器:\n";
    echo "  - EmailMessageHandler: 处理邮件发送任务\n";
    echo "  - ImageProcessHandler: 处理图片处理任务\n";
    echo "  - LogMessageHandler: 处理日志记录任务\n";
    echo "  - MessageHandlerRouter: 根据消息类型路由到不同处理器\n";
}