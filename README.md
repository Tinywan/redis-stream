# Redis Stream Queue

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tinywan/redis-stream.svg?style=flat-square)](https://packagist.org/packages/tinywan/redis-stream)
[![Total Downloads](https://img.shields.io/packagist/dt/tinywan/redis-stream.svg?style=flat-square)](https://packagist.org/packages/tinywan/redis-stream)
[![License](https://img.shields.io/packagist/l/tinywan/redis-stream.svg?style=flat-square)](https://packagist.org/packages/tinywan/redis-stream)

🚀 一个基于 Redis Stream 的轻量级高性能消息队列，支持单例模式、连接池管理和延时消息。

## ✨ 特性

- 🎯 **基于 Redis Stream** - 利用 Redis 5.0+ 的高性能 Stream 数据结构
- 🔄 **多生产者/消费者** - 支持多个生产者和消费者同时工作
- 💾 **消息持久化** - 消息持久化存储，确保数据不丢失
- ✅ **ACK 确认机制** - 消息确认机制，保证消息可靠投递
- 🔄 **重试机制** - 内置消息重试机制，处理失败消息
- ⏰ **延时消息** - 支持延时消息和定时消息，灵活的时间控制
- 🧪 **完整测试** - 完整的 PHPUnit 测试套件覆盖
- 📝 **PSR-3 日志** - 标准 PSR-3 日志接口，支持 Monolog
- 🏗️ **单例模式** - 单例模式支持，避免重复创建实例
- 🏊 **连接池管理** - Redis 连接池，自动连接复用和管理
- ⚡ **高性能** - 性能优化，单例模式性能提升 100%+

## 📋 要求

- PHP >= 7.4
- Redis >= 5.0
- Composer >= 2.0
- ext-redis 扩展
- ext-json 扩展

## 🚀 安装

使用 Composer 安装：

```bash
composer require tinywan/redis-stream
```

或者添加到 `composer.json`：

```json
{
    "require": {
        "tinywan/redis-stream": "^1.0"
    }
}
```

## 快速开始

### 基本使用

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\MonologFactory;

$queue = RedisStreamQueue::getInstance();

// 发送立即消息
$messageId = $queue->send('Hello, World!');
echo "消息ID: $messageId\n";

// 发送延时消息（30秒后执行）
$delayedMessageId = $queue->send('Delayed message', [], 30);
echo "延时消息ID: $delayedMessageId\n";

// 发送定时消息（指定时间戳执行）
$timestamp = time() + 3600; // 1小时后
$scheduledMessageId = $queue->send('Scheduled message', [], $timestamp);
echo "定时消息ID: $scheduledMessageId\n";

// 消费消息
$message = $queue->consume(function($message) {
    echo "处理消息: " . $message['message'] . "\n";
    return true; // 确认消息
});

if ($message) {
    echo "成功消费消息: " . $message['id'] . "\n";
}
```

### 使用示例

运行任务队列示例：

```bash
# 创建任务
php task-queue.php producer

# 处理任务
php task-queue.php consumer

# 查看队列状态
php task-queue.php status
```

运行消息处理器示例：

```bash
# 创建测试消息
php message-handler.php producer

# 使用自定义处理器处理消息
php message-handler.php consumer

# 演示各个处理器的功能
php message-handler.php demo

# 查看队列状态
php message-handler.php status
```

## 测试

运行测试套件：

```bash
# 运行所有测试
./vendor/bin/phpunit

# 运行单元测试
./vendor/bin/phpunit --testsuite Unit

# 运行集成测试
./vendor/bin/phpunit --testsuite Integration
```

## 服务管理

推荐使用 Supervisor 管理服务和消费者

### Supervisor 配置示例

```ini
[program:redis-stream-consumer]
command=php /path/to/your/project/task-queue.php consumer
directory=/path/to/your/project
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/redis-stream-consumer.log
```

## 配置选项

### RedisStreamQueue 单例工厂方法

```php
RedisStreamQueue::getInstance(
    array $redisConfig,    // Redis 连接配置
    array $queueConfig,    // 队列配置  
    ?LoggerInterface $logger = null  // 可选的日志记录器
): RedisStreamQueue
```

**单例模式优势：**
- 🚀 **性能提升**：避免重复创建实例和连接
- 💾 **内存节省**：相同配置的队列实例共享内存
- 🔗 **连接复用**：通过连接池管理 Redis 连接
- 🎯 **状态管理**：统一管理队列实例状态

### Redis 配置 ($redisConfig)

| 参数 | 默认值 | 说明 |
|------|--------|------|
| host | 127.0.0.1 | Redis 主机地址 |
| port | 6379 | Redis 端口 |
| password | null | Redis 密码 |
| database | 0 | Redis 数据库 |
| timeout | 5 | 连接超时时间（秒） |

### 队列配置 ($queueConfig)

| 参数 | 默认值 | 说明 |
|------|--------|------|
| stream_name | redis_stream_queue | 流名称 |
| consumer_group | redis_stream_group | 消费者组名称 |
| consumer_name | consumer_{pid} | 消费者名称 |
| block_timeout | 5000 | 阻塞超时时间（毫秒） |
| retry_attempts | 3 | 重试次数 |
| retry_delay | 1000 | 重试延迟（毫秒） |
| delayed_queue_suffix | _delayed | 延时流名称后缀 |
| scheduler_interval | 1 | 调度器检查间隔（秒） |
| max_batch_size | 100 | 每次处理最大批次大小 |

### 简化使用

如果使用默认配置，可以传递空数组：

```php
// 使用所有默认配置
$queue = new RedisStreamQueue([], [], $logger);

// 仅自定义Redis配置，使用默认队列配置
$queue = new RedisStreamQueue(
    ['host' => '192.168.1.100', 'port' => 6380], 
    [], 
    $logger
);

// 仅自定义队列配置，使用默认Redis配置
$queue = RedisStreamQueue::getInstance(
    [], 
    ['stream_name' => 'my_queue'], 
    $logger
);
```

### 连接池管理

项目内置了 Redis 连接池管理器 `RedisConnectionPool`，提供以下功能：

- **自动连接复用**：相同配置的 Redis 连接被复用
- **连接健康检查**：自动检测连接状态，移除失效连接
- **连接池监控**：提供连接池状态和连接信息查询
- **资源清理**：支持手动清理连接和自动资源管理

#### 连接池使用示例

```php
// 获取连接池实例（单例）
$pool = RedisConnectionPool::getInstance();

// 获取 Redis 连接
$redis = $pool->getConnection([
    'host' => '127.0.0.1',
    'port' => 6379,
    'database' => 0
]);

// 查看连接池状态
$status = $pool->getPoolStatus();
echo "连接池状态: " . json_encode($status, JSON_PRETTY_PRINT);

// 清理所有连接
$clearedCount = $pool->clearAllConnections();
```

### 单例模式管理

RedisStreamQueue 提供完整的单例模式管理：

#### 实例管理方法

```php
// 获取实例状态
$status = RedisStreamQueue::getInstancesStatus();
echo "实例总数: " . $status['total_instances'];

// 清理所有实例
$clearedCount = RedisStreamQueue::clearInstances();
echo "清理了 {$clearedCount} 个实例";

// 获取当前连接信息
$connectionInfo = $queue->getConnectionInfo();
echo "连接状态: " . ($connectionInfo['is_alive'] ? '活跃' : '不活跃');

// 获取连接池状态
$poolStatus = $queue->getConnectionPoolStatus();
```

## 延时消息

Redis Stream Queue 支持灵活的延时消息功能，可以通过参数控制消息的执行时间。

### 延时消息 API

```php
// 发送立即消息
$messageId = $queue->send('立即执行的消息');

// 发送延时消息（30秒后执行）
$delayedId = $queue->send('延时消息', [], 30);

// 发送定时消息（指定时间戳）
$timestamp = time() + 3600; // 1小时后
$scheduledId = $queue->send('定时消息', [], $timestamp);

// 使用 Producer 类发送延时消息
$producer = new Producer($queue);
$producer->send('生产者延时消息', [], 60);
```

### 参数说明

延时消息通过第三个参数控制：

- **0 或负数**：立即执行
- **正数且小于当前时间戳**：延时秒数（支持任意时长，如 86400 = 1天，31536000 = 1年）
- **正数且大于当前时间戳**：指定执行时间戳

### 消息调度器

系统内置自动调度器，会定期检查延时队列并将到期的消息转移到主队列：

```php
// 手动运行调度器（通常在消费者中自动运行）
$processedCount = $queue->runDelayedScheduler();

// 获取延时队列状态
$delayedCount = $queue->getDelayedStreamLength();
$upcomingCount = $queue->getUpcomingMessageCount(3600); // 1小时内的消息
```

### 框架集成示例

```php
// ThinkPHP 集成
$queueService = new QueueService();
$queueService->sendEmail([
    'to' => 'user@example.com',
    'subject' => '欢迎邮件'
], 1800); // 30分钟后发送

// Webman 集成
$queueService = new QueueService();
$queueService->sendDelayedEmail([
    'to' => 'user@example.com',
    'subject' => '延时邮件'
], 3600); // 1小时后发送
```
