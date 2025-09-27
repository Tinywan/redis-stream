# 🚀 A Lightweight Queue Based On Redis Stream

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tinywan/redis-stream.svg?style=flat-square)](https://packagist.org/packages/tinywan/redis-stream)
[![Total Downloads](https://img.shields.io/packagist/dt/tinywan/redis-stream.svg?style=flat-square)](https://packagist.org/packages/tinywan/redis-stream)
[![License](https://img.shields.io/packagist/l/tinywan/redis-stream.svg?style=flat-square)](https://packagist.org/packages/tinywan/redis-stream)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-blue.svg)](https://www.php.net)
[![Redis Version](https://img.shields.io/badge/redis-%3E%3D5.0-red.svg)](https://redis.io)
[![Tests](https://img.shields.io/badge/tests-81%20passing-brightgreen.svg)](https://github.com/Tinywan/redis-stream/actions)

> 🚀 A high-performance, lightweight message queue based on Redis Stream with singleton pattern, connection pooling, and delayed message support.

## ✨ Features

- ⚡ **High Performance** - Built on Redis 5.0+ Stream data structure for maximum performance
- 🔄 **Multi-Producer/Consumer** - Support multiple producers and consumers working simultaneously
- 💾 **Message Persistence** - Reliable message persistence ensures no data loss
- ✅ **ACK Mechanism** - Comprehensive acknowledgment mechanism for guaranteed delivery
- 🔄 **Retry Logic** - Built-in retry mechanism for handling failed messages
- ⏰ **Delayed Messages** - Support delayed and scheduled messages with flexible time control
- 🧪 **Comprehensive Testing** - Complete PHPUnit test suite coverage (81 tests, 289 assertions)
- 📝 **PSR-3 Logging** - Standard PSR-3 logging interface with Monolog integration
- 🏗️ **Singleton Pattern** - Singleton pattern support to avoid duplicate instance creation
- 🏊 **Connection Pooling** - Redis connection pooling with automatic connection reuse and management
- 🔧 **Easy Configuration** - Simple configuration with sensible defaults

## 📋 Requirements

- PHP >= 7.4
- Redis >= 5.0
- Composer >= 2.0
- ext-redis extension
- ext-json extension

## 🚀 Installation

Install via Composer:

```bash
composer require tinywan/redis-stream
```

Or add to your `composer.json`:

```json
{
    "require": {
        "tinywan/redis-stream": "^1.0"
    }
}
```

## 🎯 Quick Start

### Basic Usage

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\MonologFactory;

// Create queue instance with default configuration
$queue = RedisStreamQueue::getInstance();

// Send immediate message
$messageId = $queue->send('Hello, World!');
echo "Message ID: $messageId\n";

// Send delayed message (execute after 30 seconds)
$delayedMessageId = $queue->send('Delayed message', [], 30);
echo "Delayed Message ID: $delayedMessageId\n";

// Send scheduled message (execute at specific timestamp)
$timestamp = time() + 3600; // 1 hour later
$scheduledMessageId = $queue->send('Scheduled message', [], $timestamp);
echo "Scheduled Message ID: $scheduledMessageId\n";

// Consume messages
$message = $queue->consume(function($message) {
    echo "Processing message: " . $message['message'] . "\n";
    return true; // Acknowledge message
});

if ($message) {
    echo "Successfully consumed message: " . $message['id'] . "\n";
}
```

### Running Examples

Task queue examples:

```bash
# Create tasks
php task-queue.php producer

# Process tasks
php task-queue.php consumer

# Check queue status
php task-queue.php status
```

Message handler examples:

```bash
# Create test messages
php message-handler.php producer

# Process messages with custom handlers
php message-handler.php consumer

# Demo handler functionality
php message-handler.php demo

# Check queue status
php message-handler.php status
```

## 🧪 Testing

Run the complete test suite:

```bash
# Run all tests
./vendor/bin/phpunit

# Run unit tests only
./vendor/bin/phpunit --testsuite Unit

# Run integration tests only
./vendor/bin/phpunit --testsuite Integration

# Generate coverage report
./vendor/bin/phpunit --coverage-html coverage/
```

## 🚀 Deployment

### Supervisor Configuration

It's recommended to use Supervisor to manage long-running consumer processes:

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

## ⚙️ Configuration

### RedisStreamQueue Singleton Factory Method

```php
RedisStreamQueue::getInstance(
    array $redisConfig,    // Redis connection configuration
    array $queueConfig,    // Queue configuration  
    ?LoggerInterface $logger = null  // Optional logger
): RedisStreamQueue
```

**Singleton Pattern Benefits:**
- 🚀 **Performance Boost**: Avoid duplicate instance and connection creation
- 💾 **Memory Savings**: Same configuration queue instances share memory
- 🔗 **Connection Reuse**: Manage Redis connections through connection pooling
- 🎯 **State Management**: Unified queue instance state management

### Redis Configuration ($redisConfig)

| Parameter | Default | Description |
|-----------|---------|-------------|
| host | 127.0.0.1 | Redis host address |
| port | 6379 | Redis port |
| password | null | Redis password |
| database | 0 | Redis database |
| timeout | 5 | Connection timeout (seconds) |

### Queue Configuration ($queueConfig)

| Parameter | Default | Description |
|-----------|---------|-------------|
| stream_name | redis_stream_queue | Stream name |
| consumer_group | redis_stream_group | Consumer group name |
| consumer_name | consumer_{pid} | Consumer name |
| block_timeout | 5000 | Block timeout (milliseconds) |
| retry_attempts | 3 | Retry attempts |
| retry_delay | 1000 | Retry delay (milliseconds) |
| delayed_queue_suffix | _delayed | Delayed stream name suffix |
| scheduler_interval | 1 | Scheduler check interval (seconds) |
| max_batch_size | 100 | Maximum batch size per processing |

### Simplified Usage

If using default configuration, you can pass empty arrays:

```php
// Use all default configurations
$queue = new RedisStreamQueue([], [], $logger);

// Only customize Redis configuration, use default queue configuration
$queue = new RedisStreamQueue(
    ['host' => '192.168.1.100', 'port' => 6380], 
    [], 
    $logger
);

// Only customize queue configuration, use default Redis configuration
$queue = RedisStreamQueue::getInstance(
    [], 
    ['stream_name' => 'my_queue'], 
    $logger
);
```

## 🔧 Connection Pooling

The project includes a built-in Redis connection pool manager `RedisConnectionPool` that provides:

- **Automatic Connection Reuse**: Redis connections with same configuration are reused
- **Connection Health Check**: Automatically detect connection status and remove invalid connections
- **Connection Pool Monitoring**: Provide connection pool status and connection information queries
- **Resource Cleanup**: Support manual connection cleanup and automatic resource management

#### Connection Pool Usage Example

```php
// Get connection pool instance (singleton)
$pool = RedisConnectionPool::getInstance();

// Get Redis connection
$redis = $pool->getConnection([
    'host' => '127.0.0.1',
    'port' => 6379,
    'database' => 0
]);

// View connection pool status
$status = $pool->getPoolStatus();
echo "Connection Pool Status: " . json_encode($status, JSON_PRETTY_PRINT);

// Clean all connections
$clearedCount = $pool->clearAllConnections();
```

## 🏗️ Singleton Pattern Management

RedisStreamQueue provides complete singleton pattern management:

#### Instance Management Methods

```php
// Get instance status
$status = RedisStreamQueue::getInstancesStatus();
echo "Total Instances: " . $status['total_instances'];

// Clean all instances
$clearedCount = RedisStreamQueue::clearInstances();
echo "Cleaned {$clearedCount} instances";

// Get current connection information
$connectionInfo = $queue->getConnectionInfo();
echo "Connection Status: " . ($connectionInfo['is_alive'] ? 'Active' : 'Inactive');

// Get connection pool status
$poolStatus = $queue->getConnectionPoolStatus();
```

## ⏰ Delayed Messages

Redis Stream Queue supports flexible delayed message functionality, allowing you to control message execution time through parameters.

### Delayed Message API

```php
// Send immediate message
$messageId = $queue->send('Immediate message');

// Send delayed message (execute after 30 seconds)
$delayedId = $queue->send('Delayed message', [], 30);

// Send scheduled message (execute at specific timestamp)
$timestamp = time() + 3600; // 1 hour later
$scheduledId = $queue->send('Scheduled message', [], $timestamp);

// Use Producer class to send delayed messages
$producer = new Producer($queue);
$producer->send('Producer delayed message', [], 60);
```

### Parameter Description

Delayed messages are controlled by the third parameter:

- **0 or negative**: Execute immediately
- **Positive number less than current timestamp**: Delay seconds (supports any duration, e.g., 86400 = 1 day, 31536000 = 1 year)
- **Positive number greater than current timestamp**: Specific execution timestamp

### Message Scheduler

The system includes a built-in automatic scheduler that periodically checks the delayed queue and transfers expired messages to the main queue:

```php
// Manually run scheduler (usually runs automatically in consumers)
$processedCount = $queue->runDelayedScheduler();

// Get delayed queue status
$delayedCount = $queue->getDelayedStreamLength();
$upcomingCount = $queue->getUpcomingMessageCount(3600); // Messages within 1 hour
```

### Framework Integration Examples

```php
// ThinkPHP Integration
$queueService = new QueueService();
$queueService->sendEmail([
    'to' => 'user@example.com',
    'subject' => 'Welcome Email'
], 1800); // Send after 30 minutes

// Webman Integration
$queueService = new QueueService();
$queueService->sendDelayedEmail([
    'to' => 'user@example.com',
    'subject' => 'Delayed Email'
], 3600); // Send after 1 hour
```

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request. For major changes, please open an issue first to discuss what you would like to change.

Please make sure to update tests as appropriate and follow the existing code style.

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## 🙏 Acknowledgments

- [Redis](https://redis.io/) - High performance data store
- [Monolog](https://github.com/Seldaek/monolog) - Logging for PHP
- [PHPUnit](https://phpunit.de/) - PHP testing framework

---

<div align="center">
Made with ❤️ by <a href="https://github.com/Tinywan">Tinywan</a>
</div>