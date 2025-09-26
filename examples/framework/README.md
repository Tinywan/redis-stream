# Redis Stream 在框架中的使用指南

本指南介绍如何在 webman 和 ThinkPHP 框架中使用 Redis Stream 队列，重点是复用框架的默认配置。

## 目录结构

```
├── src/
│   ├── Adapter/
│   │   ├── WebmanAdapter.php      # Webman 框架适配器
│   │   └── ThinkPHPAdapter.php    # ThinkPHP 6.1 框架适配器
│   └── ...
├── config/
│   └── redis-stream.php           # 统一配置文件
├── examples/
│   └── framework/
│       ├── WebmanQueueService.php # Webman 队列服务示例
│       └── ThinkPHPQueueService.php # ThinkPHP 队列服务示例
└── ...
```

## Webman 框架集成

### 1. 安装和配置

将以下文件复制到你的 webman 项目中：

- `src/Adapter/WebmanAdapter.php` -> `app/common/WebmanAdapter.php`
- `config/redis-stream.php` -> `config/redis-stream.php`

### 2. 配置文件

```php
// config/redis-stream.php
return [
    'redis_stream' => [
        'stream_name' => 'default_stream',
        'consumer_group' => 'default_group',
        'block_timeout' => 5000,
        'retry_attempts' => 3,
        'retry_delay' => 1000,
        'memory_limit' => 128 * 1024 * 1024,
        
        'queues' => [
            'email' => [
                'stream_name' => 'email_stream',
                'consumer_group' => 'email_group',
                'retry_attempts' => 5,
                'retry_delay' => 2000,
            ],
            'task' => [
                'stream_name' => 'task_stream',
                'consumer_group' => 'task_group',
                'retry_attempts' => 3,
                'retry_delay' => 1000,
            ],
        ],
    ],
];
```

### 3. 使用示例

```php
<?php
namespace app\controller;

use support\Request;
use app\common\WebmanAdapter;

class QueueController
{
    // 发送邮件
    public function sendEmail(Request $request)
    {
        $data = [
            'to' => 'user@example.com',
            'subject' => 'Test Email',
            'body' => 'This is a test email',
            'priority' => 'high'
        ];
        
        $producer = WebmanAdapter::createProducer('email');
        $messageId = $producer->send(json_encode($data), [
            'task_type' => 'email',
            'priority' => 'high'
        ]);
        
        return json(['message_id' => $messageId]);
    }
    
    // 启动消费者进程
    public function startConsumer()
    {
        $consumer = WebmanAdapter::createConsumer('email');
        
        $consumer->run(function($message) {
            $data = json_decode($message['message'], true);
            
            // 处理邮件发送逻辑
            $this->processEmail($data);
            
            return true;
        });
        
        return 'Consumer started';
    }
    
    private function processEmail(array $data): void
    {
        // 实际的邮件发送逻辑
        echo "Sending email to: {$data['to']}\n";
    }
}
```

### 4. 进程配置

在 `config/process.php` 中添加消费者进程：

```php
return [
    // 邮件队列消费者
    'email-queue' => [
        'handler' => process\EmailQueue::class,
        'reloadable' => false,
        'constructor' => [
            // 消费者配置
        ]
    ],
    
    // 任务队列消费者
    'task-queue' => [
        'handler' => process\TaskQueue::class,
        'reloadable' => false,
        'constructor' => [
            // 消费者配置
        ]
    ],
];
```

## ThinkPHP 6.1 框架集成

### 1. 安装和配置

将以下文件复制到你的 ThinkPHP 项目中：

- `src/Adapter/ThinkPHPAdapter.php` -> `app/common/ThinkPHPAdapter.php`
- `config/redis-stream.php` -> `config/redis-stream.php`

### 2. 服务注册

在 `app/provider.php` 中注册服务：

```php
<?php
use app\common\ThinkPHPAdapter;

return [
    'ThinkPHPAdapter' => ThinkPHPAdapter::class,
    
    // 注册队列服务
    'redis_queue' => function($queueName = 'default') {
        return ThinkPHPAdapter::createQueue($queueName);
    },
    
    'redis_producer' => function($queueName = 'default') {
        return ThinkPHPAdapter::createProducer($queueName);
    },
    
    'redis_consumer' => function($queueName = 'default') {
        return ThinkPHPAdapter::createConsumer($queueName);
    },
];
```

### 3. 助手函数

在 `app/common.php` 中添加助手函数：

```php
<?php

if (!function_exists('redis_queue')) {
    function redis_queue($queueName = 'default') {
        return \app\common\ThinkPHPAdapter::createQueue($queueName);
    }
}

if (!function_exists('redis_producer')) {
    function redis_producer($queueName = 'default') {
        return \app\common\ThinkPHPAdapter::createProducer($queueName);
    }
}

if (!function_exists('redis_consumer')) {
    function redis_consumer($queueName = 'default') {
        return \app\common\ThinkPHPAdapter::createConsumer($queueName);
    }
}

if (!function_exists('redis_queue_send')) {
    function redis_queue_send($message, $metadata = [], $queueName = 'default') {
        return redis_producer($queueName)->send($message, $metadata);
    }
}
```

### 4. 控制器示例

```php
<?php
namespace app\controller;

use app\BaseController;

class QueueController extends BaseController
{
    // 发送邮件
    public function sendEmail()
    {
        $data = [
            'to' => 'user@example.com',
            'subject' => 'Test Email',
            'body' => 'This is a test email',
            'priority' => 'high'
        ];
        
        $messageId = redis_queue_send(json_encode($data), [
            'task_type' => 'email',
            'priority' => 'high'
        ], 'email');
        
        return json(['message_id' => $messageId]);
    }
    
    // 使用容器服务
    public function sendTask()
    {
        $data = [
            'type' => 'image',
            'filename' => 'test.jpg',
            'operation' => 'resize'
        ];
        
        $producer = app('redis_producer', ['task']);
        $messageId = $producer->send(json_encode($data), [
            'task_type' => 'image'
        ]);
        
        return json(['message_id' => $messageId]);
    }
}
```

### 5. 命令行消费者

创建命令行消费者 `app/command/QueueConsumer.php`：

```php
<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\common\ThinkPHPAdapter;

class QueueConsumer extends Command
{
    protected function configure()
    {
        $this->setName('queue:consume')
            ->setDescription('Start queue consumer');
    }

    protected function execute(Input $input, Output $output)
    {
        $queueName = $input->getArgument('queue') ?? 'default';
        
        $output->writeln("Starting consumer for queue: {$queueName}");
        
        $consumer = ThinkPHPAdapter::createConsumer($queueName);
        
        $consumer->run(function($message) use ($output) {
            $data = json_decode($message['message'], true);
            
            $output->writeln("Processing message: " . ($data['task_id'] ?? 'unknown'));
            
            // 处理消息逻辑
            return $this->processMessage($data);
        });
    }
    
    private function processMessage(array $data): bool
    {
        // 根据消息类型处理
        switch ($data['type'] ?? '') {
            case 'email':
                return $this->processEmail($data['data'] ?? []);
            case 'image':
                return $this->processImage($data['data'] ?? []);
            default:
                return false;
        }
    }
    
    private function processEmail(array $data): bool
    {
        // 邮件处理逻辑
        return true;
    }
    
    private function processImage(array $data): bool
    {
        // 图片处理逻辑
        return true;
    }
}
```

## 配置优先级

### Webman 配置优先级

1. `config/redis.php` 的 `default` 配置
2. `config/redis.php` 的第一个配置项
3. `config/cache.php` 的 `stores.redis` 配置（如果安装了 think-cache 扩展）
4. 默认配置

### ThinkPHP 配置优先级

1. `config/redis.php` 的 `default` 配置
2. `config/redis.php` 的第一个配置项
3. `config/cache.php` 的 `stores.redis` 配置
4. `config/session.php` 的 Redis 配置（如果 session.type 为 redis）
5. 默认配置

## 生产环境部署

### Supervisor 配置

```ini
# Webman 消费者进程
[program:webman-email-consumer]
command=php /path/to/webman/start.php start
directory=/path/to/webman
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/webman-email-consumer.log

# ThinkPHP 消费者进程
[program:thinkphp-email-consumer]
command=php /path/to/thinkphp think queue:consume email
directory=/path/to/thinkphp
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/thinkphp-email-consumer.log
```

## 监控和管理

### 获取队列状态

```php
// Webman
$status = WebmanAdapter::createQueue('email')->getStreamLength();

// ThinkPHP
$status = redis_queue('email')->getStreamLength();
```

### 获取连接池状态

```php
// Webman
$poolStatus = WebmanAdapter::getConnectionPoolStatus();

// ThinkPHP
$poolStatus = \app\common\ThinkPHPAdapter::getConnectionPoolStatus();
```

## 最佳实践

1. **配置复用**: 优先使用框架的 Redis 配置，避免重复配置
2. **日志集成**: 使用框架的日志系统，统一日志管理
3. **错误处理**: 结合框架的异常处理机制
4. **进程管理**: 使用 Supervisor 管理长时间运行的消费者进程
5. **监控**: 集成框架的监控和告警系统
6. **测试**: 在开发环境中充分测试消息处理逻辑

## 注意事项

1. 确保已安装 Redis 扩展
2. Redis 版本需要 5.0+ 以支持 Stream 功能
3. 在生产环境中配置适当的内存限制
4. 设置合适的重试次数和延迟时间
5. 定期监控队列积压情况