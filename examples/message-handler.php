<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/MessageHandlers.php';

use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\MonologFactory;
use Tinywan\RedisStream\Producer;
use Tinywan\RedisStream\Consumer;
use App\MessageHandler\MessageHandlerRouter;

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
    'stream_name' => 'handler_queue',
    'consumer_group' => 'handler_workers',
    'consumer_name' => 'handler_' . getmypid(),
    'block_timeout' => 5000,
    'retry_attempts' => 3,
    'retry_delay' => 1000,
];

$logger = MonologFactory::createLogger('message-handler', 'development');
$taskQueue = RedisStreamQueue::getInstance($redisConfig, $queueConfig, $logger);

// 显示配置信息
echo "=== MessageHandlerInterface 示例 ===\n";
echo "Redis配置: " . json_encode($taskQueue->getRedisConfig(), JSON_PRETTY_PRINT) . "\n";
echo "队列配置: " . json_encode($taskQueue->getQueueConfig(), JSON_PRETTY_PRINT) . "\n";
echo "================================\n\n";

// 创建测试消息
function createTestMessage(Producer $producer, string $type, array $data): void
{
    $messageId = uniqid('msg_');
    $messageData = [
        'message_id' => $messageId,
        'type' => $type,
        'data' => $data,
        'created_at' => date('Y-m-d H:i:s'),
        'priority' => $data['priority'] ?? 'normal'
    ];
    
    $producer->send(json_encode($messageData), [
        'message_type' => $type,
        'message_id' => $messageId,
        'priority' => $data['priority'] ?? 'normal'
    ]);
    
    echo "✅ Message created: $messageId ($type)\n";
}

// 示例用法
if (isset($argv[1]) && $argv[1] === 'producer') {
    echo "🚀 Creating test messages for MessageHandlerInterface...\n\n";
    
    $producer = new Producer($taskQueue);
    
    // 创建邮件消息
    createTestMessage($producer, 'email', [
        'to' => 'user@example.com',
        'subject' => 'Welcome to our service',
        'template' => 'welcome',
        'priority' => 'high'
    ]);
    
    // 创建图片处理消息
    createTestMessage($producer, 'image', [
        'filename' => 'profile.jpg',
        'operation' => 'resize',
        'width' => 300,
        'height' => 300,
        'format' => 'jpg',
        'priority' => 'normal'
    ]);
    
    // 创建日志消息
    createTestMessage($producer, 'log', [
        'level' => 'info',
        'message' => 'User login successful',
        'context' => ['user_id' => 123, 'ip' => '192.168.1.1'],
        'priority' => 'low'
    ]);
    
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
    }
    
    echo "\n✅ All messages created successfully!\n";
    echo "📊 Queue Status:\n";
    echo "   Stream Length: " . $taskQueue->getStreamLength() . "\n";
    echo "   Pending Count: " . $taskQueue->getPendingCount() . "\n";
    
} elseif (isset($argv[1]) && $argv[1] === 'consumer') {
    echo "🚀 Starting consumer with MessageHandlerInterface...\n\n";
    
    $consumer = new Consumer($taskQueue);
    
    // 创建消息处理器路由
    $handlerRouter = new MessageHandlerRouter($logger);
    
    // 设置内存限制
    $consumer->setMemoryLimit(256 * 1024 * 1024);
    
    echo "📋 Message Handler Router Configuration:\n";
    echo "   Available handlers: " . implode(', ', ['email', 'image', 'log']) . "\n";
    echo "   Stream: " . $taskQueue->getStreamName() . "\n";
    echo "   Group: " . $taskQueue->getConsumerGroup() . "\n";
    echo "   Consumer: " . $taskQueue->getConsumerName() . "\n\n";
    
    // 使用消息处理器路由
    $consumer->run([$handlerRouter, 'handle']);
    
} elseif (isset($argv[1]) && $argv[1] === 'status') {
    echo "📊 Queue Status:\n";
    echo "   Stream: " . $taskQueue->getStreamName() . "\n";
    echo "   Group: " . $taskQueue->getConsumerGroup() . "\n";
    echo "   Stream Length: " . $taskQueue->getStreamLength() . "\n";
    echo "   Pending Count: " . $taskQueue->getPendingCount() . "\n";
    echo "   Consumer: " . $taskQueue->getConsumerName() . "\n";
    
} elseif (isset($argv[1]) && $argv[1] === 'demo') {
    echo "🎯 MessageHandlerInterface Demo\n";
    echo "================================\n\n";
    
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
    
} else {
    echo "📖 MessageHandlerInterface 示例用法:\n";
    echo "  php message-handler.php producer  # 创建测试消息\n";
    echo "  php message-handler.php consumer  # 使用自定义处理器处理消息\n";
    echo "  php message-handler.php demo      # 演示各个处理器的功能\n";
    echo "  php message-handler.php status    # 查看队列状态\n";
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