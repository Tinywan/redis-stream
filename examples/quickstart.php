<?php

/**
 * Redis Stream Queue 快速开始示例
 * 
 * 此文件展示最简单的使用方法
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tinywan\RedisStream\RedisStreamQueue;

echo "=== Redis Stream Queue 快速开始 ===\n\n";

// 1. 最简单的配置
$redisConfig = [
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => null,
    'database' => 0,
    'timeout' => 5,
];

$queueConfig = [
    'stream_name' => 'quickstart_queue',
    'consumer_group' => 'quickstart_group',
    'consumer_name' => 'quickstart_' . getmypid(),
    'block_timeout' => 5000,
    'retry_attempts' => 3,
    'retry_delay' => 1000,
    'debug' => false,
];

try {
    // 2. 创建队列实例
    $queue = RedisStreamQueue::getInstance($redisConfig, $queueConfig);
    echo "✅ 队列实例创建成功！\n";
    
    // 3. 发送消息
    echo "\n📤 发送测试消息...\n";
    $messageId = $queue->send('Hello, Redis Stream!', [
        'type' => 'greeting',
        'timestamp' => date('Y-m-d H:i:s')
    ], 0);
    echo "✅ 消息发送成功，ID: $messageId\n";
    
    // 3.1 发送延时消息
    echo "\n📤 发送延时消息（30秒后执行）...\n";
    $delayedMessageId = $queue->send('Delayed Hello, Redis Stream!', [
        'type' => 'delayed_greeting',
        'timestamp' => date('Y-m-d H:i:s')
    ], 30);
    echo "✅ 延时消息发送成功，ID: $delayedMessageId\n";
    
    // 4. 消费消息
    echo "\n📥 消费消息...\n";
    $message = $queue->consume();
    
    if ($message) {
        echo "✅ 收到消息: " . $message['message'] . "\n";
        echo "   消息ID: " . $message['id'] . "\n";
        echo "   尝试次数: " . $message['attempts'] . "\n";
        
        // 5. 确认消息
        $queue->ack($message['id']);
        echo "✅ 消息确认成功\n";
    } else {
        echo "⏳ 没有收到消息（可能已被其他消费者处理）\n";
    }
    
    // 6. 查看队列状态
    echo "\n📊 队列状态:\n";
    echo "   Stream 长度: " . $queue->getStreamLength() . "\n";
    echo "   待处理消息: " . $queue->getPendingCount() . "\n";
    echo "   延时队列长度: " . $queue->getDelayedStreamLength() . "\n";
    echo "   即将到期 (60s): " . $queue->getUpcomingMessageCount(60) . "\n";
    
    echo "\n=== 快速开始完成 ===\n";
    
    // 使用提示
    echo "\n💡 下一步:\n";
    echo "1. 查看 examples/consumer.php 了解消费者用法\n";
    echo "2. 查看 examples/producer.php 了解生产者用法\n";
    echo "3. 查看 examples/environment-example.php 了解环境配置\n";
    echo "4. 查看 src/config/ 目录了解更多配置选项\n";
    
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
    echo "\n🔧 故障排除:\n";
    echo "1. 确认 Redis 服务正在运行\n";
    echo "2. 检查 Redis 连接参数\n";
    echo "3. 确认 Redis 版本 >= 5.0\n";
    echo "4. 检查防火墙设置\n";
}