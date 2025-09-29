<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

use Tinywan\RedisStream\Exception\RedisStreamException;
use Monolog\Logger;
use Redis;
use Throwable;

/**
 * Redis Stream 队列核心类
 * 
 * 基于 Redis Stream 实现的轻量级消息队列，支持多个生产者和消费者
 * 提供消息持久化、确认机制、重试机制和可靠投递
 */
class RedisStreamQueue
{
    /** @var array 存储单例实例，key为配置标识符 */
    private static array $instances = [];
    
    /** @var RedisConnectionPool Redis 连接池 */
    private RedisConnectionPool $connectionPool;
    
    /** @var Redis Redis 客户端实例 */
    private Redis $redis;

    /** @var Logger Monolog logger instance */
    protected Logger $logger;
    
    /** @var array Redis 配置数组 */
    protected array $redisConfig;
    
    /** @var array 队列配置数组 */
    protected array $queueConfig;
    
    /** @var string 流名称 */
    protected string $streamName;
    
    /** @var string 消费者组名称 */
    protected string $consumerGroup;
    
    /** @var string 消费者名称 */
    protected string $consumerName;
    
    /** @var string 延迟队列Sorted Set名称 */
    protected string $delayedQueueName;

    /**
     * 获取单例实例
     * 
     * @param array $redisConfig Redis 连接配置
     * @param array $queueConfig 队列配置
     * @param Logger|null $logger Monolog logger instance, defaults to file logger if enabled
     * @return static RedisStreamQueue 实例
     * @throws RedisStreamException 当连接失败时抛出异常
     */
    public static function getInstance(
        array $redisConfig = [],
        array $queueConfig = [],
        ?Logger $logger = null
    ): self {
        // 生成配置的唯一标识符
        $instanceKey = self::generateInstanceKey($redisConfig, $queueConfig);
        
        // 如果实例不存在，创建新实例
        if (!isset(self::$instances[$instanceKey])) {
            self::$instances[$instanceKey] = new self($redisConfig, $queueConfig, $logger);
        }
        
        return self::$instances[$instanceKey];
    }
    
    /**
     * 构造函数（私有）
     * 
     * @param array $redisConfig Redis 连接配置
     * @param array $queueConfig 队列配置
     * @param Logger|null $logger Monolog logger instance, defaults to file logger if enabled
     * @throws RedisStreamException 当 Redis 连接失败时抛出异常
     */
    private function __construct(
        array $redisConfig = [],
        array $queueConfig = [],
        ?Logger $logger = null
    ) {
        // 合并默认配置
        $this->redisConfig = array_merge([
            'host' => '127.0.0.1',          // Redis 主机地址
            'port' => 6379,                 // Redis 端口
            'password' => null,             // Redis 密码
            'database' => 0,                // Redis 数据库
            'timeout' => 5,                 // 连接超时时间（秒）
        ], $redisConfig);

        $this->queueConfig = array_merge([
            'stream_name' => 'redis_stream_queue',    // 流名称
            'consumer_group' => 'redis_stream_group', // 消费者组名称
            'consumer_name' => 'consumer_' . getmypid(), // 消费者名称
            'block_timeout' => 5000,        // 阻塞超时时间（毫秒）
            'retry_attempts' => 3,          // 重试次数
            'retry_delay' => 1000,          // 重试延迟（毫秒）
            'debug' => false,               // 是否启用调试模式（启用时记录日志）
            'delayed_queue_suffix' => '_delayed', // 延迟队列名称后缀
            'scheduler_interval' => 1,      // 调度器扫描间隔（秒）
            'scheduler_batch_size' => 100,  // 调度器每次处理的最大数量
        ], $queueConfig);

        $this->streamName = $this->queueConfig['stream_name'];
        $this->consumerGroup = $this->queueConfig['consumer_group'];
        $this->consumerName = $this->queueConfig['consumer_name'];
        $this->delayedQueueName = $this->streamName . $this->queueConfig['delayed_queue_suffix'];
        $this->logger = $logger ?? MonologFactory::createLogger('redis-stream', $this->queueConfig['debug']);
        
        // 初始化连接池
        $this->connectionPool = RedisConnectionPool::getInstance();

        // 从连接池获取 Redis 连接并确保消费者组存在
        $this->redis = $this->connectionPool->getConnection($this->redisConfig);
        $this->ensureConsumerGroup();
    }

    /**
     * 确保消费者组存在
     * 
     * 检查流和消费者组是否存在，如果不存在则创建
     * 
     * @return void
     * @throws RedisStreamException 当消费者组创建失败时抛出异常
     */
    protected function ensureConsumerGroup(): void
    {
        try {
            // 检查流是否存在
            $streamExists = $this->redis->exists($this->streamName);
            
            if (!$streamExists) {
                // 如果流不存在，创建流和消费者组
                $this->redis->xGroup('CREATE', $this->streamName, $this->consumerGroup, '0', true);
                $this->logger->info('Consumer group created with stream', [
                    'stream' => $this->streamName,
                    'group' => $this->consumerGroup
                ]);
            } else {
                // 如果流存在但消费者组不存在，创建消费者组
                try {
                    $this->redis->xGroup('CREATE', $this->streamName, $this->consumerGroup, '0', true);
                    $this->logger->info('Consumer group created for existing stream', [
                        'stream' => $this->streamName,
                        'group' => $this->consumerGroup
                    ]);
                } catch (Throwable $e) {
                    // 如果不是消费者组已存在的错误，则抛出异常
                    if (strpos($e->getMessage(), 'BUSYGROUP') === false) {
                        $this->logger->error('Failed to create consumer group for existing stream', ['error' => $e->getMessage()]);
                        throw new RedisStreamException('Failed to create consumer group: ' . $e->getMessage(), 0, $e);
                    }
                }
            }
        } catch (Throwable $e) {
            // 如果不是消费者组已存在的错误，则抛出异常
            if (strpos($e->getMessage(), 'BUSYGROUP') === false) {
                $this->logger->error('Failed to create consumer group', ['error' => $e->getMessage()]);
                throw new RedisStreamException('Failed to create consumer group: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * 发送消息到队列
     * 
     * 将消息添加到 Redis Stream 中，支持立即发送和延迟发送
     * 
     * @param mixed $message 消息内容，可以是字符串、数组或对象
     * @param array $metadata 附加的元数据，会合并到消息中
     * @param int $delaySeconds 延迟秒数，0 表示立即发送
     * @return string 返回生成的消息ID
     * @throws RedisStreamException 当消息发送失败时抛出异常
     */
    public function send($message, array $metadata = [], int $delaySeconds = 0): string
    {
        try {
            if ($delaySeconds > 0) {
                // 延迟发送
                return $this->sendDelayed($message, $metadata, $delaySeconds);
            } else {
                // 立即发送
                return $this->sendImmediate($message, $metadata);
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to send message', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to send message: ' . $e->getMessage(), 0, $e);
        }
    }

  
    
    /**
     * 从队列中消费消息
     * 
     * 使用消费者组从 Redis Stream 中读取消息，支持回调函数处理
     * 如果回调函数返回 true，则自动确认消息
     * 
     * @param callable|null $callback 消息处理回调函数，接收消息数据作为参数
     * @param string|null $lastid 消息ID位置：
     *                            - '>' (默认): 只读取新消息
     *                            - '0-0': 从开始读取所有消息
     *                            - '0': 从开始读取所有消息
     *                            - '$': 从最后一条消息之后读取
     *                            - 具体消息ID: 从指定ID之后读取
     * @return array|null 返回消息数据，如果没有消息则返回 null
     * @throws RedisStreamException 当消息消费失败时抛出异常
     */
    public function consume(?callable $callback = null, ?string $lastid = null): ?array
    {
        try {
            // 默认使用 '>' 只读取新消息
            if ($lastid === null) {
                $lastid = '>';
            }
            
            // 从消费者组中读取消息
            $messages = $this->redis->xReadGroup(
                $this->consumerGroup,          // 消费者组
                $this->consumerName,           // 消费者名称
                [$this->streamName => $lastid], // 指定消息位置
                1,                             // 每次读取一条消息
                $this->queueConfig['block_timeout'] // 阻塞超时时间
            );

            // 如果没有消息，返回 null
            if (empty($messages)) {
                return null;
            }

            // 处理消息数据
            try {
                $messageData = $this->processMessage($messages[$this->streamName]);
            } catch (RedisStreamException $e) {
                // 如果没有有效消息，对于特定的 lastid 值返回 null
                if ($lastid === '0-0' || $lastid === '0' || $lastid === '$' || strpos($lastid, '-') !== false) {
                    $this->logger->info('No valid messages found for lastid: ' . $lastid);
                    return null;
                }
                // 其他情况重新抛出异常
                throw $e;
            }
            
            // 如果提供了回调函数，执行处理逻辑
            if ($callback !== null) {
                $result = $callback($messageData);
                // 如果回调返回 true，自动确认消息
                if ($result === true) {
                    $this->ack($messageData['id']);
                }
            }

            return $messageData;
        } catch (Throwable $e) {
            $this->logger->error('Failed to consume message', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to consume message: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 处理原始消息数据
     * 
     * 从 Redis 原始消息中提取数据，增加消息ID和重试计数
     * 
     * @param array $rawMessages 从 Redis 读取的原始消息数组
     * @return array 处理后的消息数据，包含 id、attempts 等字段
     * @throws RedisStreamException 当没有找到有效消息时抛出异常
     */
    protected function processMessage(array $rawMessages): array
    {
        foreach ($rawMessages as $messageId => $data) {
            // 将消息ID添加到数据中
            $data['id'] = $messageId;
            // 增加重试计数
            $data['attempts'] = isset($data['attempts']) ? (int)$data['attempts'] + 1 : 1;
            
            $this->logger->info('Processing message', [
                'message_id' => $messageId,
                'attempts' => $data['attempts']
            ]);

            return $data;
        }
        
        throw new RedisStreamException('No valid message found');
    }

    /**
     * 确认消息处理完成
     * 
     * 从消费者组的待处理消息列表中移除指定消息
     * 
     * @param string $messageId 要确认的消息ID
     * @return bool 确认成功返回 true，失败返回 false
     * @throws RedisStreamException 当确认操作失败时抛出异常
     */
    public function ack(string $messageId): bool
    {
        try {
            // 从消费者组中确认消息
            $result = $this->redis->xAck($this->streamName, $this->consumerGroup, [$messageId]);
            
            $this->logger->info('Message acknowledged', [
                'message_id' => $messageId,
                'stream' => $this->streamName
            ]);

            return $result > 0;
        } catch (Throwable $e) {
            $this->logger->error('Failed to acknowledge message', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            throw new RedisStreamException('Failed to acknowledge message: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 拒绝消息处理
     * 
     * 处理失败的消息，可以选择重试或直接删除
     * 如果重试次数未超过限制，重新加入队列；否则删除消息
     * 
     * @param string $messageId 要拒绝的消息ID
     * @param bool $retry 是否重试，默认为 true
     * @return bool 处理成功返回 true
     * @throws RedisStreamException 当拒绝操作失败时抛出异常
     */
    public function nack(string $messageId, bool $retry = true): bool
    {
        try {
            if ($retry) {
                // 首先检查消息是否在 pending 状态
                $pendingInfo = $this->redis->xPending($this->streamName, $this->consumerGroup, '-', '+', 1, $messageId);
                
                if (!empty($pendingInfo)) {
                    // 消息在 pending 状态，需要先确认它确实存在
                    $pendingMessage = $pendingInfo[0];
                    if ($pendingMessage[0] === $messageId) {
                        // 从 pending 状态中移除消息并重新加入队列
                        $this->redis->xAck($this->streamName, $this->consumerGroup, [$messageId]);
                        
                        // 获取消息内容（如果消息还在 stream 中）
                        $message = $this->redis->xRange($this->streamName, $messageId, $messageId);
                        
                        if (isset($message[$messageId])) {
                            $data = $message[$messageId];
                            $data['attempts'] = isset($data['attempts']) ? (int)$data['attempts'] + 1 : 1;
                            
                            // 如果重试次数未超过限制，重新加入队列
                            if ($data['attempts'] <= $this->queueConfig['retry_attempts']) {
                                // 删除原消息
                                $this->redis->xDel($this->streamName, [$messageId]);
                                // 重新添加到队列
                                $this->redis->xAdd($this->streamName, '*', $data);
                                
                                $this->logger->info('Pending message retry enqueued', [
                                    'message_id' => $messageId,
                                    'attempts' => $data['attempts'],
                                    'pending_time' => $pendingMessage[4] // 最后传递时间
                                ]);
                                
                                return true;
                            }
                        } else {
                            // 消息已不在 stream 中，但仍在 pending 中
                            $this->logger->warning('Pending message not found in stream', [
                                'message_id' => $messageId,
                                'pending_info' => $pendingMessage
                            ]);
                        }
                    }
                } else {
                    // 消息不在 pending 状态，直接处理
                    $message = $this->redis->xRange($this->streamName, $messageId, $messageId);
                    
                    if (isset($message[$messageId])) {
                        $data = $message[$messageId];
                        $data['attempts'] = isset($data['attempts']) ? (int)$data['attempts'] + 1 : 1;
                        
                        // 如果重试次数未超过限制，重新加入队列
                        if ($data['attempts'] <= $this->queueConfig['retry_attempts']) {
                            // 删除原消息
                            $this->redis->xDel($this->streamName, [$messageId]);
                            // 重新添加到队列
                            $this->redis->xAdd($this->streamName, '*', $data);
                            
                            $this->logger->info('Message retry enqueued', [
                                'message_id' => $messageId,
                                'attempts' => $data['attempts']
                            ]);
                            
                            return true;
                        }
                    }
                }
            }

            // 删除消息（不重试或重试次数超限）
            $this->redis->xDel($this->streamName, [$messageId]);
            
            $this->logger->info('Message removed (failed)', [
                'message_id' => $messageId,
                'retry' => $retry
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Failed to NACK message', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            throw new RedisStreamException('Failed to NACK message: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取待处理消息数量
     * 
     * 返回消费者组中待处理的消息数量
     * 
     * @return int 待处理消息数量，出错时返回 0
     */
    public function getPendingCount(): int
    {
        try {
            $pending = $this->redis->xPending($this->streamName, $this->consumerGroup);
            return $pending[0] ?? 0;
        } catch (Throwable $e) {
            $this->logger->error('Failed to get pending count', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 获取流长度
     * 
     * 返回 Redis Stream 中的消息总数
     * 
     * @return int 流中的消息数量，出错时返回 0
     */
    public function getStreamLength(): int
    {
        try {
            return $this->redis->xLen($this->streamName);
        } catch (Throwable $e) {
            $this->logger->error('Failed to get stream length', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 获取 Redis 客户端实例
     * 
     * @return Redis Redis 客户端实例
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }

    /**
     * 获取流名称
     * 
     * @return string 流名称
     */
    public function getStreamName(): string
    {
        return $this->streamName;
    }

    /**
     * 获取消费者组名称
     * 
     * @return string 消费者组名称
     */
    public function getConsumerGroup(): string
    {
        return $this->consumerGroup;
    }

    /**
     * 获取消费者名称
     * 
     * @return string 消费者名称
     */
    public function getConsumerName(): string
    {
        return $this->consumerName;
    }

    /**
     * 获取日志记录器
     * 
     * @return Logger Monolog logger instance
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /**
     * 获取 Redis 配置
     * 
     * @return array Redis 连接配置
     */
    public function getRedisConfig(): array
    {
        return $this->redisConfig;
    }

    /**
     * 获取队列配置
     * 
     * @return array 队列配置
     */
    public function getQueueConfig(): array
    {
        return $this->queueConfig;
    }

    /**
     * 获取完整配置
     * 
     * @return array 完整配置数组
     */
    public function getConfig(): array
    {
        return [
            'redis' => $this->redisConfig,
            'queue' => $this->queueConfig
        ];
    }
    
    /**
     * 获取连接池状态
     * 
     * @return array 连接池状态信息
     */
    public function getConnectionPoolStatus(): array
    {
        return $this->connectionPool->getPoolStatus();
    }
    
    /**
     * 获取当前连接信息
     * 
     * @return array|null 连接信息，如果不存在返回 null
     */
    public function getConnectionInfo(): ?array
    {
        return $this->connectionPool->getConnectionInfo($this->redisConfig);
    }
    
    /**
     * 清理当前配置的所有单例实例
     * 
     * @return int 清理的实例数量
     */
    public static function clearInstances(): int
    {
        $count = count(self::$instances);
        self::$instances = [];
        return $count;
    }
    
    /**
     * 获取所有单例实例的状态
     * 
     * @return array 实例状态信息
     */
    public static function getInstancesStatus(): array
    {
        $status = [
            'total_instances' => count(self::$instances),
            'instances' => [],
        ];
        
        foreach (self::$instances as $key => $instance) {
            $status['instances'][$key] = [
                'redis_config' => $instance->getRedisConfig(),
                'queue_config' => $instance->getQueueConfig(),
                'stream_name' => $instance->getStreamName(),
                'consumer_group' => $instance->getConsumerGroup(),
                'consumer_name' => $instance->getConsumerName(),
                'connection_info' => $instance->getConnectionInfo(),
            ];
        }
        
        return $status;
    }
    
    /**
     * 生成实例的唯一标识符
     * 
     * @param array $redisConfig Redis 配置
     * @param array $queueConfig 队列配置
     * @return string 实例标识符
     */
    private static function generateInstanceKey(array $redisConfig, array $queueConfig): string
    {
        // 使用Redis配置和流名称生成key，确保相同Redis相同流的队列使用同一个实例
        $keyConfig = [
            'redis' => [
                'host' => $redisConfig['host'] ?? '127.0.0.1',
                'port' => $redisConfig['port'] ?? 6379,
                'password' => $redisConfig['password'] ?? null,
                'database' => $redisConfig['database'] ?? 0,
            ],
            'stream_name' => $queueConfig['stream_name'] ?? 'redis_stream_queue',
        ];
        
        return md5(json_encode($keyConfig));
    }
    
    /**
     * 防止克隆单例
     */
    private function __clone()
    {
    }

    /**
     * 防止反序列化单例
     * @throws RedisStreamException
     */
    public function __wakeup()
    {
        throw new RedisStreamException('Cannot serialize singleton');
    }

  
    /**
     * 从指定消息ID开始消费
     * 
     * @param string $fromId 起始消息ID
     * @param callable|null $callback 消息处理回调函数
     * @return array|null 返回消息数据，如果没有消息则返回 null
     * @throws RedisStreamException 当消费失败时抛出异常
     */
    public function consumeFrom(string $fromId, ?callable $callback = null): ?array
    {
        return $this->consume($callback, $fromId);
    }

    /**
     * 消费最后一条消息之后的新消息
     * 
     * @param callable|null $callback 消息处理回调函数
     * @return array|null 返回消息数据，如果没有消息则返回 null
     * @throws RedisStreamException 当消费失败时抛出异常
     */
    public function consumeLatest(?callable $callback = null): ?array
    {
        return $this->consume($callback, '$');
    }

    // =========================================================================
    // 延迟队列功能方法 (基于ZSET+Stream架构)
    // =========================================================================

    /**
     * 立即发送消息到Stream
     *
     * @param mixed $message 消息内容
     * @param array $metadata 附加元数据
     * @return string 消息ID
     * @throws \RedisException
     * @throws RedisStreamException
     */
    private function sendImmediate($message, array $metadata = []): string
    {
        // 确保消费者组存在
        $this->ensureConsumerGroup();
        
        // 构建消息数据
        $data = array_merge([
            'message' => is_string($message) ? $message : json_encode($message),
            'timestamp' => time(),
            'attempts' => 0,
            'status' => 'pending'
        ], $metadata);

        // 添加到Stream
        $messageId = $this->redis->xAdd($this->streamName, '*', $data);
        
        $this->logger->info('Message sent to stream', [
            'message_id' => $messageId,
            'stream' => $this->streamName
        ]);

        return $messageId;
    }

    /**
     * 发送延迟消息到Sorted Set
     *
     * @param mixed $message 消息内容
     * @param array $metadata 附加元数据
     * @param int $delaySeconds 延迟秒数
     * @return string 任务ID
     * @throws \RedisException
     * @throws RedisStreamException
     */
    private function sendDelayed($message, array $metadata = [], int $delaySeconds = 0): string
    {
        $executeTime = time() + $delaySeconds;
        $taskId = uniqid('delayed_', true);
        
        // 构建任务数据
        $taskData = [
            'id' => $taskId,
            'message' => is_string($message) ? $message : json_encode($message),
            'metadata' => $metadata,
            'execute_time' => $executeTime,
            'delay_seconds' => $delaySeconds,
            'created_at' => time(),
            'status' => 'delayed'
        ];

        // 序列化任务数据
        $taskJson = json_encode($taskData);
        
        // 添加到Sorted Set，score为执行时间戳
        $result = $this->redis->zAdd($this->delayedQueueName, $executeTime, $taskJson);
        
        if ($result === false) {
            throw new RedisStreamException('Failed to add delayed task to sorted set');
        }
        
        $this->logger->info('Delayed task added to queue', [
            'task_id' => $taskId,
            'delay_seconds' => $delaySeconds,
            'execute_time' => date('Y-m-d H:i:s', $executeTime),
            'queue' => $this->delayedQueueName
        ]);

        return $taskId;
    }

    /**
     * 运行延迟队列调度器
     * 
     * 扫描到期的延迟任务并投递到Stream队列
     * 
     * @param int $maxTasks 最大处理任务数量，0表示不限制
     * @return int 处理的任务数量
     * @throws RedisStreamException
     */
    public function runDelayedScheduler(int $maxTasks = 0): int
    {
        try {
            $currentTime = time();
            $processedCount = 0;
            $maxBatchSize = $this->queueConfig['scheduler_batch_size'];
            
            $this->logger->info('Starting delayed scheduler', [
                'current_time' => date('Y-m-d H:i:s', $currentTime),
                'max_tasks' => $maxTasks,
                'max_batch_size' => $maxBatchSize
            ]);

            // 获取到期的任务 (score <= 当前时间)
            $expiredTasks = $this->redis->zRangeByScore(
                $this->delayedQueueName, 
                0, 
                $currentTime, 
                ['limit' => [0, $maxBatchSize]]
            );

            foreach ($expiredTasks as $taskJson) {
                // 检查是否达到最大处理数量
                if ($maxTasks > 0 && $processedCount >= $maxTasks) {
                    break;
                }

                try {
                    $taskData = json_decode($taskJson, true);
                    if ($taskData === null) {
                        $this->logger->warning('Invalid task data, removing', ['task_data' => $taskJson]);
                        $this->redis->zRem($this->delayedQueueName, $taskJson);
                        continue;
                    }

                    // 从延迟队列中移除任务
                    $removed = $this->redis->zRem($this->delayedQueueName, $taskJson);
                    if ($removed === 0) {
                        continue; // 任务已被其他调度器处理
                    }

                    // 投递到Stream队列
                    $this->deliverDelayedTask($taskData);
                    $processedCount++;

                    $this->logger->info('Delayed task delivered to stream', [
                        'task_id' => $taskData['id'],
                        'delay_seconds' => $taskData['delay_seconds'],
                        'message' => $taskData['message']
                    ]);

                } catch (Throwable $e) {
                    $this->logger->error('Failed to process delayed task', [
                        'error' => $e->getMessage(),
                        'task_data' => $taskJson
                    ]);
                }
            }

            $this->logger->info('Scheduler completed', [
                'processed_count' => $processedCount,
                'total_expired' => count($expiredTasks)
            ]);

            return $processedCount;

        } catch (Throwable $e) {
            $this->logger->error('Failed to run delayed scheduler', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to run delayed scheduler: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 投递延迟任务到Stream队列
     * 
     * @param array $taskData 任务数据
     * @throws RedisStreamException
     */
    private function deliverDelayedTask(array $taskData): void
    {
        // 确保消费者组存在
        $this->ensureConsumerGroup();
        
        // 构建消息数据
        $messageData = array_merge($taskData['metadata'], [
            'message' => $taskData['message'],
            'timestamp' => time(),
            'attempts' => 0,
            'status' => 'pending',
            'delayed_task_id' => $taskData['id'],
            'original_delay' => $taskData['delay_seconds'],
            'created_at' => $taskData['created_at']
        ]);

        // 添加到Stream
        $messageId = $this->redis->xAdd($this->streamName, '*', $messageData);
        
        $this->logger->debug('Delayed task delivered', [
            'task_id' => $taskData['id'],
            'message_id' => $messageId,
            'stream' => $this->streamName
        ]);
    }

    /**
     * 获取延迟队列统计信息
     * 
     * @return array 统计信息
     */
    public function getDelayedQueueStats(): array
    {
        try {
            $currentTime = time();
            $totalTasks = $this->redis->zCard($this->delayedQueueName);
            
            // 获取即将到期的任务数量（未来60秒内）
            $upcomingCount = $this->redis->zCount(
                $this->delayedQueueName, 
                (string)$currentTime, 
                (string)($currentTime + 60)
            );
            
            // 获取过期未处理的任务数量
            $expiredCount = $this->redis->zCount(
                $this->delayedQueueName, 
                '0', 
                (string)$currentTime
            );

            // 获取最早的任务时间
            $earliestTask = $this->redis->zRange($this->delayedQueueName, 0, 0, true);
            $earliestTime = $earliestTask ? (int)current($earliestTask) : null;

            return [
                'total_delayed_tasks' => $totalTasks,
                'upcoming_tasks_60s' => $upcomingCount,
                'expired_tasks' => $expiredCount,
                'earliest_task_time' => $earliestTime ? date('Y-m-d H:i:s', $earliestTime) : null,
                'queue_name' => $this->delayedQueueName,
                'current_time' => date('Y-m-d H:i:s', $currentTime)
            ];

        } catch (Throwable $e) {
            $this->logger->error('Failed to get delayed queue stats', ['error' => $e->getMessage()]);
            return [
                'total_delayed_tasks' => 0,
                'upcoming_tasks_60s' => 0,
                'expired_tasks' => 0,
                'earliest_task_time' => null,
                'queue_name' => $this->delayedQueueName,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 清理过期的延迟任务
     * 
     * @param int $maxAge 最大年龄（秒），超过此时间的任务将被清理
     * @param int $maxTasks 最大清理数量，0表示不限制
     * @return int 清理的任务数量
     */
    public function cleanupExpiredDelayedTasks(int $maxAge = 86400, int $maxTasks = 0): int
    {
        try {
            $currentTime = time();
            $expiredTime = $currentTime - $maxAge;
            $cleanedCount = 0;
            $maxBatchSize = $this->queueConfig['scheduler_batch_size'];
            
            $this->logger->info('Starting cleanup of expired delayed tasks', [
                'current_time' => date('Y-m-d H:i:s', $currentTime),
                'expired_time' => date('Y-m-d H:i:s', $expiredTime),
                'max_age' => $maxAge,
                'max_tasks' => $maxTasks
            ]);

            // 获取过期的任务
            $expiredTasks = $this->redis->zRangeByScore(
                $this->delayedQueueName, 
                0, 
                $expiredTime, 
                ['limit' => [0, $maxBatchSize]]
            );

            foreach ($expiredTasks as $taskJson) {
                // 检查是否达到最大清理数量
                if ($maxTasks > 0 && $cleanedCount >= $maxTasks) {
                    break;
                }

                try {
                    $taskData = json_decode($taskJson, true);
                    if ($taskData === null) {
                        // 无效数据，直接删除
                        $this->redis->zRem($this->delayedQueueName, $taskJson);
                        $cleanedCount++;
                        continue;
                    }

                    // 删除过期任务
                    $removed = $this->redis->zRem($this->delayedQueueName, $taskJson);
                    if ($removed > 0) {
                        $cleanedCount++;
                        
                        $this->logger->info('Expired delayed task cleaned up', [
                            'task_id' => $taskData['id'] ?? 'unknown',
                            'execute_time' => date('Y-m-d H:i:s', $taskData['execute_time']),
                            'age_days' => round(($currentTime - $taskData['execute_time']) / 86400, 2)
                        ]);
                    }

                } catch (Throwable $e) {
                    $this->logger->error('Failed to cleanup expired task', [
                        'error' => $e->getMessage(),
                        'task_data' => $taskJson
                    ]);
                }
            }

            $this->logger->info('Cleanup completed', [
                'cleaned_count' => $cleanedCount,
                'processed_tasks' => count($expiredTasks)
            ]);

            return $cleanedCount;

        } catch (Throwable $e) {
            $this->logger->error('Failed to cleanup expired delayed tasks', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to cleanup expired delayed tasks: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 启动延迟队列调度器（阻塞模式）
     * 
     * @param int $runtime 运行时间（秒），0表示一直运行
     * @param callable|null $onTick 每次调度后的回调函数
     * @return void
     */
    public function startDelayedScheduler(int $runtime = 0, ?callable $onTick = null): void
    {
        $this->logger->info('Starting delayed scheduler in blocking mode', [
            'runtime' => $runtime > 0 ? "${runtime}s" : 'unlimited',
            'interval' => $this->queueConfig['scheduler_interval']
        ]);

        $startTime = time();
        $lastScheduledAt = 0;

        try {
            while (true) {
                // 检查运行时间限制
                if ($runtime > 0 && (time() - $startTime) >= $runtime) {
                    $this->logger->info('Scheduler runtime completed');
                    break;
                }

                // 检查是否到了调度时间
                $currentTime = time();
                if ($currentTime - $lastScheduledAt >= $this->queueConfig['scheduler_interval']) {
                    $processed = $this->runDelayedScheduler();
                    $lastScheduledAt = $currentTime;

                    // 执行回调函数
                    if ($onTick !== null) {
                        $stats = $this->getDelayedQueueStats();
                        $onTick($processed, $stats);
                    }
                }

                // 短暂休眠避免CPU占用过高
                usleep(100000); // 100ms
            }
        } catch (Throwable $e) {
            $this->logger->error('Scheduler crashed', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Scheduler crashed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取延迟队列名称
     * 
     * @return string
     */
    public function getDelayedQueueName(): string
    {
        return $this->delayedQueueName;
    }

    /**
     * 获取延迟队列长度
     * 
     * @return int
     */
    public function getDelayedQueueLength(): int
    {
        try {
            return $this->redis->zCard($this->delayedQueueName);
        } catch (Throwable $e) {
            $this->logger->error('Failed to get delayed queue length', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}