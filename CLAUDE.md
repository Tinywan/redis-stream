## 项目概述

这是一个基于 Redis Streams 的轻量级 PHP 队列实现，支持多生产者和消费者，具有消息持久化、确认机制、重试机制和可靠投递功能。支持延时消息、指定时间执行、消息重放和审计功能。

## 架构设计

### 必需依赖

- PHP 7.4+
- Redis 扩展，需要 Redis 5.0+
- Composer 2.0
- Monolog（用于日志记录）

### 核心组件

- **RedisStreamQueue** (`src/RedisStreamQueue.php`): 主要队列类，处理 Redis 连接、流操作和消息生命周期管理
- **Producer** (`src/Producer.php`): 发送消息的高级接口（单条和批量）
- **Consumer** (`src/Consumer.php`): 消费消息的高级接口，具有重试逻辑和内存管理
- **MessageHandlerInterface** (`src/MessageHandlerInterface.php`): 自定义消息处理器接口
- **RedisConnectionPool** (`src/RedisConnectionPool.php`): Redis 连接池管理器
- **MonologFactory** (`src/MonologFactory.php`): Monolog 日志工厂类
- **RedisStreamException** (`src/Exception/RedisStreamException.php`): 自定义异常类

### 关键特性

- **Redis Stream 集成**: 使用 Redis 5.0+ 流进行持久化消息存储
- **消费者组**: 支持多个消费者和自动组创建
- **消息确认**: 可靠投递的 ACK/NACK 机制
- **重试逻辑**: 可配置的重试次数和延迟
- **内存管理**: 长时间运行消费者的内置内存限制检查
- **PSR 日志**: 集成 PSR-3 兼容的日志支持
- **单例模式**: 高性能的单例模式和连接池管理
- **Monolog 集成**: 完整的日志记录系统
- **延时消息**: 支持延时发送和指定时间执行消息
- **统一API**: 简化的API设计，支持任意长度的延时时间
- **消息重放**: 支持重新处理历史消息，包括已确认的消息
- **消息审计**: 提供只读模式审计所有消息，不影响消息状态
- **灵活消费**: 支持指定位置消费，满足不同业务场景
- **$lastid 支持**: 支持 Redis Stream 的不同 lastid 参数模式

## 配置说明

### 配置格式

队列支持分离式配置（推荐）：

```php
// Redis 连接配置
$redisConfig = [
    'host' => '127.0.0.1',          // Redis 主机地址
    'port' => 6379,                 // Redis 端口
    'password' => null,             // Redis 密码
    'database' => 0,                // Redis 数据库
    'timeout' => 5,                 // 连接超时时间（秒）
];

// 队列配置
$queueConfig = [
    'stream_name' => 'redis_stream_queue',    // 流名称
    'consumer_group' => 'redis_stream_group', // 消费者组名称
    'consumer_name' => 'consumer_' . getmypid(), // 消费者名称
    'block_timeout' => 5000,        // 阻塞超时时间（毫秒）
    'retry_attempts' => 3,          // 重试次数
    'retry_delay' => 1000,          // 重试延迟（毫秒）
];
```

### 单例模式使用

```php
// 推荐使用单例模式以获得最佳性能
$queue = RedisStreamQueue::getInstance($redisConfig, $queueConfig, $logger);
```

### 配置方法

- `getRedisConfig()`: 获取 Redis 连接配置
- `getQueueConfig()`: 获取队列配置
- `getConfig()`: 获取完整配置数组
- `getConnectionPoolStatus()`: 获取连接池状态
- `getInstancesStatus()`: 获取所有实例状态

### 日志系统

队列基于 Monolog 实现日志记录功能，默认使用控制台日志记录器。支持以下日志级别：
- emergency, alert, critical, error, warning, notice, info, debug

通过 MonologFactory 可以创建不同类型的日志记录器：
- `createConsoleLogger()`: 控制台日志记录器
- `createFileLogger()`: 文件日志记录器
- `createDevelopmentLogger()`: 开发环境日志记录器
- `createProductionLogger()`: 生产环境日志记录器
- `createLogger()`: 根据环境自动创建日志记录器

## 常用开发任务

### 运行示例

运行基本示例：
```bash
php example.php
```

运行任务队列示例：
```bash
# 创建任务
php task-queue.php producer

# 处理任务
php task-queue.php consumer

# 查看队列状态
php task-queue.php status
```

运行单例模式演示：
```bash
php singleton-example.php
```

运行 Monolog 日志示例：
```bash
php monolog-example.php
```

### 基本使用模式

**生产者示例：**
```php
// 使用分离配置（推荐）
$redisConfig = ['host' => '127.0.0.1', 'port' => 6379];
$queueConfig = ['stream_name' => 'my_queue', 'consumer_group' => 'my_group'];

// 使用 MonologFactory 创建日志记录器
$logger = Tinywan\RedisStream\MonologFactory::createConsoleLogger('my-app');
$queue = RedisStreamQueue::getInstance($redisConfig, $queueConfig, $logger);
$messageId = $queue->send('message data', ['metadata' => 'value']);
```

**消费者示例：**
```php
// 直接消费
$message = $queue->consume();
if ($message) {
    // 处理消息
    $queue->ack($message['id']); // 确认消息
}

// 使用回调函数
$message = $queue->consume(function($message) {
    // 处理消息
    return true; // 成功时自动确认
});
```

### 消息结构

消息包含这些自动字段：
- `message`: 实际消息内容
- `timestamp`: 创建时的 Unix 时间戳
- `attempts`: 处理尝试次数
- `status`: 消息状态
- `id`: Redis Stream 消息 ID

### 日志配置

使用 MonologFactory 创建统一的日志记录器：

```php
use Tinywan\RedisStream\MonologFactory;

// 创建日志记录器（可配置文件日志和调试模式）
$logger = MonologFactory::createLogger(
    'my-app',           // 日志通道名称
    $enableFileLogging, // 是否启用文件日志（默认false）
    $enableDebug        // 是否启用调试模式（默认false）
);

// 示例：仅控制台日志
$consoleLogger = MonologFactory::createLogger('my-app');

// 示例：文件日志 + 控制台
$fileLogger = MonologFactory::createLogger('my-app', true, false);

// 示例：文件日志 + 调试模式
$debugLogger = MonologFactory::createLogger('my-app', true, true);
```

### 错误处理

所有操作在失败时抛出 `RedisStreamException`。库自动：
- 处理 Redis 连接失败
- 从消费者组创建冲突中恢复
- 使用 Monolog 记录错误
- 为失败处理实现重试逻辑

### 性能优化

- **单例模式**: 避免重复创建实例，性能提升 100%+
- **连接池**: Redis 连接复用，减少连接开销
- **配置缓存**: 相同配置自动复用
- **内存优化**: 智能资源管理

### 内存管理

长时间运行的消费者包含内置内存监控：
- 默认限制：128MB
- 可通过 `setMemoryLimit()` 配置
- 超过限制时自动停止

## 新功能：消息重放与审计

### $lastid 参数支持

RedisStreamQueue 的 `consume()` 方法现在支持可选的 `$lastid` 参数，允许指定消息读取的起始位置：

```php
// 默认行为：只读取新消息
$message = $queue->consume();

// 从头开始读取所有消息（包括已确认的消息）
$message = $queue->consume(null, '0-0');

// 读取最后一条消息之后的新消息
$message = $queue->consume(null, '$');

// 从指定消息ID开始读取
$message = $queue->consume(null, '1758943564547-0');
```

### 消息重放功能

`replayMessages()` 方法允许重新处理流中的所有消息，包括已被确认的消息：

```php
// 重新处理所有消息，最多处理10条，自动确认
$count = $queue->replayMessages(function($message) {
    // 处理消息逻辑
    return true;
}, 10, true);

// 重新处理但不自动确认
$count = $queue->replayMessages(function($message) {
    // 处理消息逻辑
    return false; // 不确认消息
}, 10, false);
```

### 消息审计功能

`auditMessages()` 方法提供只读模式审计所有消息，不影响消息状态：

```php
// 审计所有消息
$count = $queue->auditMessages(function($message) {
    echo "审计消息: " . $message['message'] . "\n";
    return true; // 继续审计
}, 20);
```

### 便捷消费方法

- `consumeFrom($messageId)`: 从指定消息ID开始消费
- `consumeLatest()`: 消费最新消息

### $lastid 参数说明

| 参数值 | 说明 | 使用场景 |
|--------|------|----------|
| `>` (默认) | 只读取消费者组尚未分配的新消息 | 正常消费模式 |
| `0-0` | 从头开始读取所有消息 | 数据恢复、重新处理 |
| `0` | 等同于 `0-0` | 同上 |
| `$` | 读取最后一条消息之后的新消息 | 获取最新消息 |
| `特定ID` | 从指定消息ID之后开始读取 | 定位消费 |

### 注意事项

- `replayMessages()` 使用 `XRANGE` 读取所有消息，不受消费者组状态影响
- `auditMessages()` 是只读操作，不会影响消息状态
- 在消费者组中，`'0-0'` 模式可能无法读取到已被确认的消息
- 根据业务场景选择合适的消费模式

## 运行测试

运行完整测试套件：
```bash
./vendor/bin/phpunit
```

运行单元测试：
```bash
./vendor/bin/phpunit --testsuite Unit
```

运行集成测试：
```bash
./vendor/bin/phpunit --testsuite Integration
```

生成测试覆盖率报告：
```bash
./vendor/bin/phpunit --coverage-html coverage/
```

### 测试状态

- 单元测试：57个测试
- 集成测试：12个测试
- 总计：69个测试，244个断言全部通过

## 生产部署

由于消费者的长时间运行特性，建议在生产环境中使用 Supervisor 来管理消费者进程。

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

## 代码规范

- 遵循 PSR-12 编码规范
- 使用类型声明（strict_types=1）
- 添加适当的 PHPDoc 注释
- 确保所有测试通过
- 提交前运行代码检查

## 贡献指南

1. Fork 项目
2. 创建功能分支
3. 提交代码更改
4. 运行测试确保通过
5. 提交 Pull Request

## 重要注意事项

- 消费者组会在第一次发送消息时自动创建
- 字符串消息直接存储，数组和对象会进行 JSON 编码
- 单例模式基于配置生成唯一标识符，相同配置共享实例
- 连接池自动管理 Redis 连接的生命周期
- 建议在生产环境中使用 Monolog 进行日志记录