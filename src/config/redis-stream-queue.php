<?php
/**
 * Redis Stream Queue Configuration
 *
 * 默认配置文件，支持即时消息和延时消息
 * 
 * 使用方法：
 * 1. 根据需要调整下面的参数
 * 2. 传入 RedisStreamQueue::getInstance() 方法
 *
 * 配置参数说明：
 * stream_name: Redis Stream 的主队列名称
 * consumer_group: 消费者组名称，支持多个消费者协同处理同一个流
 * consumer_name: 消费者名称，通常包含进程ID以便区分不同的消费者实例
 * block_timeout: 阻塞超时时间（毫秒），消费者在没有消息时的等待时间
 * retry_attempts: 消息处理失败时的最大重试次数
 * retry_delay: 重试延迟时间（毫秒），每次重试之间的间隔时间
 * debug: 是否启用调试模式，启用时会记录详细的日志信息到文件
 * delayed_queue_suffix: 延时流名称后缀，自动附加到主队列名称后
 * scheduler_interval: 调度器检查间隔时间（秒）
 * max_batch_size: 每次处理的最大消息批次大小
 */

return [
    'default' => [
        'stream_name' => 'redis_stream_queue',        // 主队列名称
        'consumer_group' => 'redis_stream_group',     // 消费者组名称
        'consumer_name' => 'consumer_' . getmypid(),  // 消费者名称
        'block_timeout' => 5000,                      // 阻塞超时时间（毫秒）
        'retry_attempts' => 3,                        // 重试次数
        'retry_delay' => 1000,                        // 重试延迟（毫秒）
        'debug' => false,                              // 是否启用调试模式
        'delayed_queue_suffix' => '_delayed',          // 延时流名称后缀
        'scheduler_interval' => 1,                     // 调度器间隔时间（秒）
        'max_batch_size' => 100,                      // 每次处理最大批次大小
    ]
];

