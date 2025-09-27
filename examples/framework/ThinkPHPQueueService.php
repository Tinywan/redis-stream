<?php

declare(strict_types=1);

namespace app\service;

use Tinywan\RedisStream\Adapter\ThinkPHPAdapter;
use Tinywan\RedisStream\MessageHandlerInterface;
use think\facade\Log;

/**
 * ThinkPHP 队列服务示例
 */
class QueueService
{
    /**
     * 发送邮件任务
     */
    public function sendEmail(array $data, int $delayOrTimestamp = 0): string
    {
        $producer = ThinkPHPAdapter::createProducer('email');
        
        $message = json_encode([
            'task_id' => uniqid('email_'),
            'type' => 'email',
            'data' => $data,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        return $producer->send($message, [
            'task_type' => 'email',
            'priority' => $data['priority'] ?? 'normal',
        ], $delayOrTimestamp);
    }

    /**
     * 发送图片处理任务
     */
    public function processImage(array $data, int $delayOrTimestamp = 0): string
    {
        $producer = ThinkPHPAdapter::createProducer('task');
        
        $message = json_encode([
            'task_id' => uniqid('image_'),
            'type' => 'image',
            'data' => $data,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        return $producer->send($message, [
            'task_type' => 'image',
            'priority' => $data['priority'] ?? 'normal',
        ], $delayOrTimestamp);
    }

    /**
     * 启动邮件消费者
     */
    public function startEmailConsumer(): void
    {
        $consumer = ThinkPHPAdapter::createConsumer('email');
        
        $consumer->run(function($message) {
            $data = json_decode($message['message'], true);
            
            Log::info('Processing email task', [
                'task_id' => $data['task_id'],
                'attempts' => $message['attempts'],
            ]);
            
            try {
                // 处理邮件发送
                $this->processEmailTask($data['data']);
                
                Log::info('Email sent successfully', [
                    'task_id' => $data['task_id'],
                ]);
                
                return true;
                
            } catch (\Exception $e) {
                Log::error('Failed to send email', [
                    'task_id' => $data['task_id'],
                    'error' => $e->getMessage(),
                ]);
                
                return false;
            }
        });
    }

    /**
     * 启动任务消费者
     */
    public function startTaskConsumer(): void
    {
        $consumer = ThinkPHPAdapter::createConsumer('task');
        
        $consumer->run(function($message) {
            $data = json_decode($message['message'], true);
            
            Log::info('Processing task', [
                'task_id' => $data['task_id'],
                'type' => $data['type'],
                'attempts' => $message['attempts'],
            ]);
            
            try {
                switch ($data['type']) {
                    case 'image':
                        $result = $this->processImageTask($data['data']);
                        break;
                    case 'report':
                        $result = $this->processReportTask($data['data']);
                        break;
                    default:
                        Log::warning('Unknown task type', ['type' => $data['type']]);
                        return false;
                }
                
                Log::info('Task completed', [
                    'task_id' => $data['task_id'],
                    'type' => $data['type'],
                ]);
                
                return $result;
                
            } catch (\Exception $e) {
                Log::error('Task failed', [
                    'task_id' => $data['task_id'],
                    'type' => $data['type'],
                    'error' => $e->getMessage(),
                ]);
                
                return false;
            }
        });
    }

    /**
     * 使用自定义消息处理器
     */
    public function startConsumerWithHandler(MessageHandlerInterface $handler): void
    {
        $consumer = ThinkPHPAdapter::createConsumer('default');
        $consumer->run([$handler, 'handle']);
    }

    private function processEmailTask(array $data): void
    {
        // 模拟邮件发送
        echo "📧 Sending email to: {$data['to']}\n";
        sleep(1);
        
        // 模拟失败概率 5%
        if (rand(1, 20) === 1) {
            throw new \Exception('SMTP connection failed');
        }
        
        echo "✅ Email sent to: {$data['to']}\n";
    }

    private function processImageTask(array $data): bool
    {
        echo "🖼️  Processing image: {$data['filename']}\n";
        sleep(2);
        
        // 模拟失败概率 3%
        if (rand(1, 30) === 1) {
            throw new \Exception('Image processing failed');
        }
        
        echo "✅ Image processed: {$data['filename']}\n";
        return true;
    }

    private function processReportTask(array $data): bool
    {
        echo "📊 Generating report: {$data['report_name']}\n";
        sleep(3);
        
        echo "✅ Report generated: {$data['report_name']}\n";
        return true;
    }

    /**
     * 发送延时邮件任务
     */
    public function sendDelayedEmail(array $data, int $delaySeconds): string
    {
        return $this->sendEmail($data, $delaySeconds);
    }

    /**
     * 在指定时间发送邮件任务
     */
    public function sendEmailAt(array $data, int $timestamp): string
    {
        return $this->sendEmail($data, $timestamp);
    }

    /**
     * 发送延时图片处理任务
     */
    public function processDelayedImage(array $data, int $delaySeconds): string
    {
        return $this->processImage($data, $delaySeconds);
    }

    /**
     * 在指定时间发送图片处理任务
     */
    public function processImageAt(array $data, int $timestamp): string
    {
        return $this->processImage($data, $timestamp);
    }

    /**
     * 运行延时调度器
     */
    public function runDelayedScheduler(string $queueName = 'default'): int
    {
        $queue = ThinkPHPAdapter::createQueue($queueName);
        return $queue->runDelayedScheduler();
    }

    /**
     * 获取队列状态
     */
    public function getQueueStatus(string $queueName = 'default'): array
    {
        $queue = ThinkPHPAdapter::createQueue($queueName);
        
        return [
            'stream_name' => $queue->getStreamName(),
            'delayed_stream_name' => $queue->getDelayedStreamName(),
            'consumer_group' => $queue->getConsumerGroup(),
            'stream_length' => $queue->getStreamLength(),
            'pending_count' => $queue->getPendingCount(),
            'delayed_stream_length' => $queue->getDelayedStreamLength(),
        ];
    }
}