# Redis Stream Queue

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tinywan/redis-stream.svg?style=flat-square)](https://packagist.org/packages/tinywan/redis-stream)
[![Total Downloads](https://img.shields.io/packagist/dt/tinywan/redis-stream.svg?style=flat-square)](https://packagist.org/packages/tinywan/redis-stream)
[![License](https://img.shields.io/packagist/l/tinywan/redis-stream.svg?style=flat-square)](https://packagist.org/packages/tinywan/redis-stream)

🚀 一个基于 Redis Stream 的轻量级高性能消息队列，支持单例模式和连接池管理。

## ✨ 特性

- 🎯 **基于 Redis Stream** - 利用 Redis 5.0+ 的高性能 Stream 数据结构
- 🔄 **多生产者/消费者** - 支持多个生产者和消费者同时工作
- 💾 **消息持久化** - 消息持久化存储，确保数据不丢失
- ✅ **ACK 确认机制** - 消息确认机制，保证消息可靠投递
- 🔄 **重试机制** - 内置消息重试机制，处理失败消息
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
    'stream_name' => 'my_queue',
    'consumer_group' => 'my_workers',
    'consumer_name' => 'worker_' . getmypid(),
    'block_timeout' => 5000,
    'retry_attempts' => 3,
    'retry_delay' => 1000,
];

// 创建队列实例（使用 Monolog 日志）
// 推荐使用单例模式以复用连接和提升性能
$queue = RedisStreamQueue::getInstance(
    $redisConfig,
    $queueConfig,
    MonologFactory::createLogger('my-app')
);

// 发送消息
$messageId = $queue->send('Hello, World!');
echo "消息ID: $messageId\n";

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

### 单例模式示例

```bash
# 运行单例模式演示
php singleton-example.php
```

单例模式示例演示了：
- 单例实例的创建和复用
- Redis 连接池的管理
- 性能对比（单例 vs 新建实例）
- 实例状态和连接状态监控
- 资源清理和内存管理

### Monolog 日志示例

```bash
# 运行Monolog日志配置示例
php monolog-example.php
```

### Monolog 日志配置

本项目集成了 Monolog 日志库，提供多种日志配置选项：

```php
use Tinywan\RedisStream\MonologFactory;

// 开发环境日志（详细）
$devLogger = MonologFactory::createDevelopmentLogger('my-app');

// 生产环境日志（精简）
$prodLogger = MonologFactory::createProductionLogger('my-app');

// 控制台日志（CLI）
$consoleLogger = MonologFactory::createConsoleLogger('my-app');

// 组合日志（文件 + 控制台）
$combinedLogger = MonologFactory::createCombinedLogger('my-app');

// 根据环境变量自动配置
$autoLogger = MonologFactory::createLogger('my-app');
```

#### 日志配置说明

- **开发环境**：记录所有级别日志，包含详细调用信息，保留7天
- **生产环境**：只记录 WARNING 及以上级别，简洁格式，保留30天
- **控制台**：输出到标准输出，适合 CLI 应用
- **组合**：同时输出到文件和控制台，不同级别过滤
- **自动**：根据 `APP_ENV` 环境变量自动选择配置

#### 日志文件位置

所有日志文件保存在 `logs/` 目录中：
- `development.log` - 开发环境日志
- `production.log` - 生产环境日志
- `combined.log` - 组合日志

### 运行 Monolog 示例

```bash
php monolog-example.php
```

### 运行单例模式示例

```bash
php singleton-example.php
```

单例模式示例演示了：
- 单例实例的创建和复用
- Redis 连接池的管理
- 性能对比（单例 vs 新建实例）
- 实例状态和连接状态监控
- 资源清理和内存管理

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
