<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tinywan\RedisStream\DelayedQueue;

// 简单的功能测试脚本
echo "=== 延时队列功能测试 ===\n\n";

// 加载配置
$redisConfigs = require __DIR__ . '/../src/config/redis.php';
$queueConfigs = require __DIR__ . '/../src/config/redis-stream-queue.php';

$redisConfig = $redisConfigs['default'];
$delayedConfig = $queueConfigs['delayed_queue'];

// 创建延时队列实例
$delayedQueue = DelayedQueue::getInstance($redisConfig, $delayedConfig);

// 测试1: 创建立即执行的消息（0秒延时）
echo "🧪 测试1: 创建立即执行的消息\n";
try {
    $messageId = $delayedQueue->sendDelayed('test immediate message', 0, ['test' => 'immediate']);
    echo "✅ 立即消息创建成功: $messageId\n";
} catch (Throwable $e) {
    echo "❌ 立即消息创建失败: " . $e->getMessage() . "\n";
}

// 测试2: 创建延时消息
echo "\n🧪 测试2: 创建延时消息\n";
try {
    $messageId = $delayedQueue->sendDelayed('test delayed message', 2, ['test' => 'delayed']);
    echo "✅ 延时消息创建成功: $messageId (2秒后执行)\n";
} catch (Throwable $e) {
    echo "❌ 延时消息创建失败: " . $e->getMessage() . "\n";
}

// 测试3: 创建定时消息
echo "\n🧪 测试3: 创建定时消息\n";
try {
    $futureTime = time() + 5;
    $messageId = $delayedQueue->sendAt('test scheduled message', $futureTime, ['test' => 'scheduled']);
    echo "✅ 定时消息创建成功: $messageId (5秒后执行)\n";
} catch (Throwable $e) {
    echo "❌ 定时消息创建失败: " . $e->getMessage() . "\n";
}

// 显示初始状态
echo "\n📊 初始队列状态:\n";
echo "延时流长度: " . $delayedQueue->getDelayedStreamLength() . "\n";
echo "就绪流长度: " . $delayedQueue->getReadyStreamLength() . "\n";
echo "即将到期 (3s): " . $delayedQueue->getUpcomingMessageCount(3) . "\n";

// 等待3秒后运行调度器
echo "\n⏳ 等待3秒...\n";
sleep(3);

// 运行调度器
echo "🔄 运行调度器...\n";
try {
    $transferred = $delayedQueue->runScheduler();
    echo "✅ 调度器运行完成，转移了 $transferred 条消息\n";
} catch (Throwable $e) {
    echo "❌ 调度器运行失败: " . $e->getMessage() . "\n";
}

// 显示调度后状态
echo "\n📊 调度后队列状态:\n";
echo "延时流长度: " . $delayedQueue->getDelayedStreamLength() . "\n";
echo "就绪流长度: " . $delayedQueue->getReadyStreamLength() . "\n";
echo "待处理消息: " . $delayedQueue->getPendingCount() . "\n";

// 测试消费消息
echo "\n🧪 测试4: 消费就绪消息\n";
try {
    $message = $delayedQueue->consume();
    if ($message) {
        echo "✅ 消费到消息: " . $message['id'] . "\n";
        echo "   消息内容: " . $message['message'] . "\n";
        echo "   尝试次数: " . $message['attempts'] . "\n";
        
        // 确认消息
        $delayedQueue->ack($message['id']);
        echo "✅ 消息已确认\n";
    } else {
        echo "⏳ 没有就绪消息\n";
    }
} catch (Throwable $e) {
    echo "❌ 消费消息失败: " . $e->getMessage() . "\n";
}

// 显示最终状态
echo "\n📊 最终队列状态:\n";
echo "延时流长度: " . $delayedQueue->getDelayedStreamLength() . "\n";
echo "就绪流长度: " . $delayedQueue->getReadyStreamLength() . "\n";
echo "待处理消息: " . $delayedQueue->getPendingCount() . "\n";

// 测试配置方法
echo "\n🧪 测试5: 配置方法访问\n";
try {
    echo "✅ Redis配置: " . json_encode($delayedQueue->getRedisConfig(), JSON_PRETTY_PRINT) . "\n";
    echo "✅ 延时队列配置: " . json_encode($delayedQueue->getDelayedConfig(), JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo "❌ 配置方法访问失败: " . $e->getMessage() . "\n";
}

echo "\n✅ 所有测试完成!\n";