<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

use Tinywan\RedisStream\Exception\RedisStreamException;
use Monolog\Logger;
use Redis;
use Throwable;

/**
 * Redis Stream 延时队列
 * 
 * 基于 Redis Stream 实现的延时队列，支持指定时间后执行消息
 * 使用两个流：一个用于存储延时消息，一个用于就绪消息
 */
class DelayedQueue
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
    
    /** @var array 延时队列配置数组 */
    protected array $delayedConfig;
    
    /** @var string 延时流名称 */
    protected string $delayedStreamName;
    
    /** @var string 就绪流名称 */
    protected string $readyStreamName;
    
    /** @var string 消费者组名称 */
    protected string $consumerGroup;
    
    /** @var string 消费者名称 */
    protected string $consumerName;

    /**
     * 获取单例实例
     * 
     * @param array $redisConfig Redis 连接配置
     * @param array $delayedConfig 延时队列配置
     * @param Logger|null $logger Monolog logger instance
     * @return static DelayedQueue 实例
     * @throws RedisStreamException 当连接失败时抛出异常
     */
    public static function getInstance(
        array $redisConfig = [],
        array $delayedConfig = [],
        ?Logger $logger = null
    ): self {
        // 生成配置的唯一标识符
        $instanceKey = self::generateInstanceKey($redisConfig, $delayedConfig);
        
        // 如果实例不存在，创建新实例
        if (!isset(self::$instances[$instanceKey])) {
            self::$instances[$instanceKey] = new self($redisConfig, $delayedConfig, $logger);
        }
        
        return self::$instances[$instanceKey];
    }
    
    /**
     * 构造函数（私有）
     * 
     * @param array $redisConfig Redis 连接配置
     * @param array $delayedConfig 延时队列配置
     * @param Logger|null $logger Monolog logger instance
     * @throws RedisStreamException 当 Redis 连接失败时抛出异常
     */
    private function __construct(
        array $redisConfig = [],
        array $delayedConfig = [],
        ?Logger $logger = null
    ) {
        // 合并默认配置
        $this->redisConfig = array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => null,
            'database' => 0,
            'timeout' => 5,
        ], $redisConfig);

        $this->delayedConfig = array_merge([
            'delayed_stream_name' => 'delayed_stream_queue',     // 延时流名称
            'ready_stream_name' => 'ready_stream_queue',         // 就绪流名称
            'consumer_group' => 'delayed_stream_group',          // 消费者组名称
            'consumer_name' => 'delayed_consumer_' . getmypid(), // 消费者名称
            'block_timeout' => 5000,                              // 阻塞超时时间（毫秒）
            'retry_attempts' => 3,                                // 重试次数
            'retry_delay' => 1000,                                // 重试延迟（毫秒）
            'debug' => false,                                     // 是否启用调试模式
            'scheduler_interval' => 1,                           // 调度器间隔时间（秒）
            'max_batch_size' => 100,                              // 每次处理最大批次大小
        ], $delayedConfig);

        $this->delayedStreamName = $this->delayedConfig['delayed_stream_name'];
        $this->readyStreamName = $this->delayedConfig['ready_stream_name'];
        $this->consumerGroup = $this->delayedConfig['consumer_group'];
        $this->consumerName = $this->delayedConfig['consumer_name'];
        $this->logger = $logger ?? MonologFactory::createLogger('delayed-stream', $this->delayedConfig['debug']);
        
        // 初始化连接池
        $this->connectionPool = RedisConnectionPool::getInstance();

        // 从连接池获取 Redis 连接并确保流和消费者组存在
        $this->redis = $this->connectionPool->getConnection($this->redisConfig);
        $this->ensureStreamsAndGroups();
    }

    /**
     * 确保流和消费者组存在
     * 
     * @return void
     * @throws RedisStreamException 当创建失败时抛出异常
     */
    protected function ensureStreamsAndGroups(): void
    {
        try {
            // 检查延时流是否存在
            $delayedStreamExists = $this->redis->exists($this->delayedStreamName);
            if (!$delayedStreamExists) {
                $this->logger->info('Delayed stream created', [
                    'stream' => $this->delayedStreamName
                ]);
            }

            // 检查就绪流是否存在
            $readyStreamExists = $this->redis->exists($this->readyStreamName);
            if (!$readyStreamExists) {
                $this->logger->info('Ready stream created', [
                    'stream' => $this->readyStreamName
                ]);
            }

            // 确保就绪流的消费者组存在
            $this->ensureConsumerGroup($this->readyStreamName, $this->consumerGroup);
            
        } catch (Throwable $e) {
            $this->logger->error('Failed to ensure streams and groups', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to ensure streams and groups: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 确保消费者组存在
     * 
     * @param string $streamName 流名称
     * @param string $groupName 组名称
     * @return void
     * @throws RedisStreamException 当创建失败时抛出异常
     */
    protected function ensureConsumerGroup(string $streamName, string $groupName): void
    {
        try {
            // 检查流是否存在
            $streamExists = $this->redis->exists($streamName);
            
            if (!$streamExists) {
                // 如果流不存在，创建流和消费者组
                $this->redis->xGroup('CREATE', $streamName, $groupName, '0', true);
                $this->logger->info('Consumer group created with stream', [
                    'stream' => $streamName,
                    'group' => $groupName
                ]);
            } else {
                // 如果流存在但消费者组不存在，创建消费者组
                try {
                    $this->redis->xGroup('CREATE', $streamName, $groupName, '0', true);
                    $this->logger->info('Consumer group created for existing stream', [
                        'stream' => $streamName,
                        'group' => $groupName
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
     * 发送延时消息
     * 
     * @param mixed $message 消息内容
     * @param int $delaySeconds 延时秒数
     * @param array $metadata 附加元数据
     * @return string 返回生成的消息ID
     * @throws RedisStreamException 当发送失败时抛出异常
     */
    public function sendDelayed($message, int $delaySeconds, array $metadata = []): string
    {
        try {
            $executeTime = time() + $delaySeconds;
            
            // 构建消息数据
            $data = array_merge([
                'message' => is_string($message) ? $message : json_encode($message),
                'execute_time' => $executeTime,
                'delay_seconds' => $delaySeconds,
                'created_at' => time(),
                'attempts' => 0,
                'status' => 'delayed'
            ], $metadata);

            // 添加到延时流
            $messageId = $this->redis->xAdd($this->delayedStreamName, '*', $data);
            
            $this->logger->info('Delayed message sent', [
                'message_id' => $messageId,
                'delay_seconds' => $delaySeconds,
                'execute_time' => date('Y-m-d H:i:s', $executeTime),
                'stream' => $this->delayedStreamName
            ]);

            return $messageId;
        } catch (Throwable $e) {
            $this->logger->error('Failed to send delayed message', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to send delayed message: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 发送定时消息（在指定时间执行）
     * 
     * @param mixed $message 消息内容
     * @param int $timestamp 执行时间戳
     * @param array $metadata 附加元数据
     * @return string 返回生成的消息ID
     * @throws RedisStreamException 当发送失败时抛出异常
     */
    public function sendAt($message, int $timestamp, array $metadata = []): string
    {
        $currentTime = time();
        $delaySeconds = max(0, $timestamp - $currentTime);
        
        return $this->sendDelayed($message, $delaySeconds, $metadata);
    }

    /**
     * 消费就绪消息
     * 
     * @param callable|null $callback 消息处理回调函数
     * @return array|null 返回消息数据，如果没有消息则返回 null
     * @throws RedisStreamException 当消费失败时抛出异常
     */
    public function consume(?callable $callback = null): ?array
    {
        try {
            // 从就绪流中读取消息
            $messages = $this->redis->xReadGroup(
                $this->consumerGroup,
                $this->consumerName,
                [$this->readyStreamName => '>'],
                1,
                $this->delayedConfig['block_timeout']
            );

            if (empty($messages)) {
                return null;
            }

            // 处理消息数据
            $messageData = $this->processReadyMessage($messages[$this->readyStreamName]);
            
            // 如果提供了回调函数，执行处理逻辑
            if ($callback !== null) {
                $result = $callback($messageData);
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
     * 处理就绪消息
     * 
     * @param array $rawMessages 从 Redis 读取的原始消息数组
     * @return array 处理后的消息数据
     * @throws RedisStreamException 当没有找到有效消息时抛出异常
     */
    protected function processReadyMessage(array $rawMessages): array
    {
        foreach ($rawMessages as $messageId => $data) {
            $data['id'] = $messageId;
            $data['attempts'] = isset($data['attempts']) ? (int)$data['attempts'] + 1 : 1;
            
            $this->logger->info('Processing ready message', [
                'message_id' => $messageId,
                'attempts' => $data['attempts']
            ]);

            return $data;
        }
        
        throw new RedisStreamException('No valid ready message found');
    }

    /**
     * 运行调度器（将到期的延时消息转移到就绪流）
     * 
     * @param int $maxMessages 最大处理消息数量，0 表示不限制
     * @return int 转移的消息数量
     * @throws RedisStreamException 当调度失败时抛出异常
     */
    public function runScheduler(int $maxMessages = 0): int
    {
        try {
            $currentTime = time();
            $transferredCount = 0;
            $maxBatchSize = $this->delayedConfig['max_batch_size'];
            
            $this->logger->info('Starting delayed message scheduler', [
                'current_time' => date('Y-m-d H:i:s', $currentTime),
                'max_messages' => $maxMessages,
                'max_batch_size' => $maxBatchSize
            ]);

            // 读取延时消息
            $messages = $this->redis->xRange($this->delayedStreamName, '-', '+', $maxBatchSize);
            
            foreach ($messages as $messageId => $data) {
                // 检查是否达到最大处理数量
                if ($maxMessages > 0 && $transferredCount >= $maxMessages) {
                    break;
                }

                // 检查是否到期
                if (isset($data['execute_time']) && (int)$data['execute_time'] <= $currentTime) {
                    // 从延时流删除
                    $this->redis->xDel($this->delayedStreamName, [$messageId]);
                    
                    // 添加到就绪流
                    $data['status'] = 'ready';
                    $data['transferred_at'] = $currentTime;
                    $newMessageId = $this->redis->xAdd($this->readyStreamName, '*', $data);
                    
                    $transferredCount++;
                    
                    $this->logger->info('Delayed message transferred to ready stream', [
                        'original_id' => $messageId,
                        'new_id' => $newMessageId,
                        'execute_time' => date('Y-m-d H:i:s', (int)$data['execute_time']),
                        'delay_seconds' => $data['delay_seconds'] ?? 'unknown'
                    ]);
                }
            }

            $this->logger->info('Scheduler completed', [
                'transferred_count' => $transferredCount,
                'processed_messages' => count($messages)
            ]);

            return $transferredCount;
        } catch (Throwable $e) {
            $this->logger->error('Failed to run scheduler', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to run scheduler: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 确认消息处理完成
     * 
     * @param string $messageId 要确认的消息ID
     * @return bool 确认成功返回 true，失败返回 false
     * @throws RedisStreamException 当确认操作失败时抛出异常
     */
    public function ack(string $messageId): bool
    {
        try {
            $result = $this->redis->xAck($this->readyStreamName, $this->consumerGroup, [$messageId]);
            
            $this->logger->info('Message acknowledged', [
                'message_id' => $messageId,
                'stream' => $this->readyStreamName
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
     * @param string $messageId 要拒绝的消息ID
     * @param bool $retry 是否重试，默认为 true
     * @return bool 处理成功返回 true
     * @throws RedisStreamException 当拒绝操作失败时抛出异常
     */
    public function nack(string $messageId, bool $retry = true): bool
    {
        try {
            if ($retry) {
                // 获取消息内容
                $message = $this->redis->xRange($this->readyStreamName, $messageId, $messageId);
                
                if (isset($message[$messageId])) {
                    $data = $message[$messageId];
                    $data['attempts'] = isset($data['attempts']) ? (int)$data['attempts'] + 1 : 1;
                    
                    // 如果重试次数未超过限制，重新加入就绪流
                    if ($data['attempts'] <= $this->delayedConfig['retry_attempts']) {
                        // 从就绪流删除
                        $this->redis->xDel($this->readyStreamName, [$messageId]);
                        // 重新添加到就绪流
                        $newMessageId = $this->redis->xAdd($this->readyStreamName, '*', $data);
                        
                        $this->logger->info('Ready message retry enqueued', [
                            'message_id' => $messageId,
                            'new_id' => $newMessageId,
                            'attempts' => $data['attempts']
                        ]);
                        
                        return true;
                    }
                }
            }

            // 删除消息（不重试或重试次数超限）
            $this->redis->xDel($this->readyStreamName, [$messageId]);
            
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
     * 获取延时流长度
     * 
     * @return int 延时流中的消息数量
     */
    public function getDelayedStreamLength(): int
    {
        try {
            return $this->redis->xLen($this->delayedStreamName);
        } catch (Throwable $e) {
            $this->logger->error('Failed to get delayed stream length', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 获取就绪流长度
     * 
     * @return int 就绪流中的消息数量
     */
    public function getReadyStreamLength(): int
    {
        try {
            return $this->redis->xLen($this->readyStreamName);
        } catch (Throwable $e) {
            $this->logger->error('Failed to get ready stream length', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 获取待处理消息数量
     * 
     * @return int 待处理消息数量
     */
    public function getPendingCount(): int
    {
        try {
            $pending = $this->redis->xPending($this->readyStreamName, $this->consumerGroup);
            return $pending[0] ?? 0;
        } catch (Throwable $e) {
            $this->logger->error('Failed to get pending count', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 获取即将到期的消息数量
     * 
     * @param int $withinSeconds 未来多少秒内到期
     * @return int 即将到期的消息数量
     */
    public function getUpcomingMessageCount(int $withinSeconds = 60): int
    {
        try {
            $currentTime = time();
            $futureTime = $currentTime + $withinSeconds;
            $count = 0;
            
            // 读取延时消息
            $messages = $this->redis->xRange($this->delayedStreamName, '-', '+', 1000);
            
            foreach ($messages as $messageId => $data) {
                if (isset($data['execute_time']) && (int)$data['execute_time'] <= $futureTime) {
                    $count++;
                }
            }
            
            return $count;
        } catch (Throwable $e) {
            $this->logger->error('Failed to get upcoming message count', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 获取队列状态
     * 
     * @return array 队列状态信息
     */
    public function getStatus(): array
    {
        return [
            'delayed_stream_length' => $this->getDelayedStreamLength(),
            'ready_stream_length' => $this->getReadyStreamLength(),
            'pending_count' => $this->getPendingCount(),
            'upcoming_count' => $this->getUpcomingMessageCount(),
            'delayed_stream' => $this->delayedStreamName,
            'ready_stream' => $this->readyStreamName,
            'consumer_group' => $this->consumerGroup,
            'consumer_name' => $this->consumerName,
        ];
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
     * 获取 Redis 客户端实例
     * 
     * @return Redis Redis 客户端实例
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }

    /**
     * 获取延时流名称
     * 
     * @return string 延时流名称
     */
    public function getDelayedStreamName(): string
    {
        return $this->delayedStreamName;
    }

    /**
     * 获取就绪流名称
     * 
     * @return string 就绪流名称
     */
    public function getReadyStreamName(): string
    {
        return $this->readyStreamName;
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
     * 获取 Redis 配置
     * 
     * @return array Redis 连接配置
     */
    public function getRedisConfig(): array
    {
        return $this->redisConfig;
    }

    /**
     * 获取延时队列配置
     * 
     * @return array 延时队列配置
     */
    public function getDelayedConfig(): array
    {
        return $this->delayedConfig;
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
            'delayed' => $this->delayedConfig
        ];
    }

    /**
     * 生成实例的唯一标识符
     * 
     * @param array $redisConfig Redis 配置
     * @param array $delayedConfig 延时队列配置
     * @return string 实例标识符
     */
    private static function generateInstanceKey(array $redisConfig, array $delayedConfig): string
    {
        $keyConfig = [
            'redis' => [
                'host' => $redisConfig['host'] ?? '127.0.0.1',
                'port' => $redisConfig['port'] ?? 6379,
                'password' => $redisConfig['password'] ?? null,
                'database' => $redisConfig['database'] ?? 0,
            ],
            'delayed_stream' => $delayedConfig['delayed_stream_name'] ?? 'delayed_stream_queue',
            'ready_stream' => $delayedConfig['ready_stream_name'] ?? 'ready_stream_queue',
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
}