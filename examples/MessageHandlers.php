<?php

declare(strict_types=1);

namespace App\MessageHandler;

use Tinywan\RedisStream\MessageHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * 邮件消息处理器
 * 
 * 处理邮件发送任务，支持不同类型的邮件模板
 */
class EmailMessageHandler implements MessageHandlerInterface
{
    private $logger;
    private $templates = [
        'welcome' => 'Welcome to our service!',
        'reset_password' => 'Reset your password link: {link}',
        'notification' => 'You have a new notification: {message}',
        'newsletter' => 'Monthly newsletter: {content}'
    ];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle($message)
    {
        $data = json_decode($message, true);
        
        if (!isset($data['type']) || $data['type'] !== 'email') {
            $this->logger->warning('Invalid email message format', ['message' => $data]);
            return false;
        }

        $emailData = $data['data'] ?? [];
        $to = $emailData['to'] ?? null;
        $subject = $emailData['subject'] ?? 'No Subject';
        $template = $emailData['template'] ?? 'default';

        $this->logger->info('Processing email', [
            'to' => $to,
            'subject' => $subject,
            'template' => $template
        ]);

        try {
            // 模拟邮件发送
            $this->sendEmail($to, $subject, $template, $emailData);
            
            $this->logger->info('Email sent successfully', [
                'to' => $to,
                'subject' => $subject
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    private function sendEmail(string $to, string $subject, string $template, array $data): void
    {
        // 模拟邮件发送延迟
        sleep(1);
        
        // 模拟失败概率 5%
        if (rand(1, 20) === 1) {
            throw new \Exception('SMTP connection failed');
        }
        
        $body = $this->renderTemplate($template, $data);
        
        echo "📧 Email sent to: {$to}\n";
        echo "   Subject: {$subject}\n";
        echo "   Template: {$template}\n";
        echo "   Body: " . substr($body, 0, 50) . "...\n";
    }

    private function renderTemplate(string $template, array $data): string
    {
        if (!isset($this->templates[$template])) {
            return $data['body'] ?? 'Default email content';
        }

        $content = $this->templates[$template];
        
        // 简单的模板变量替换
        foreach ($data as $key => $value) {
            $content = str_replace('{' . $key . '}', $value, $content);
        }
        
        return $content;
    }
}

/**
 * 图片处理消息处理器
 * 
 * 处理图片相关的任务，如调整大小、裁剪、优化等
 */
class ImageProcessHandler implements MessageHandlerInterface
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle($message)
    {
        $data = json_decode($message, true);
        
        if (!isset($data['type']) || $data['type'] !== 'image') {
            $this->logger->warning('Invalid image message format', ['message' => $data]);
            return false;
        }

        $imageData = $data['data'] ?? [];
        $filename = $imageData['filename'] ?? 'unknown.jpg';
        $operation = $imageData['operation'] ?? 'resize';

        $this->logger->info('Processing image', [
            'filename' => $filename,
            'operation' => $operation
        ]);

        try {
            // 模拟图片处理
            $this->processImage($filename, $operation, $imageData);
            
            $this->logger->info('Image processed successfully', [
                'filename' => $filename,
                'operation' => $operation
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to process image', [
                'filename' => $filename,
                'operation' => $operation,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    private function processImage(string $filename, string $operation, array $data): void
    {
        // 模拟图片处理延迟
        $delay = $operation === 'optimize' ? 3 : 2;
        sleep($delay);
        
        // 模拟失败概率 3%
        if (rand(1, 30) === 1) {
            throw new \Exception('Image processing failed: invalid format');
        }
        
        echo "🖼️  Image processed: {$filename}\n";
        echo "   Operation: {$operation}\n";
        echo "   Size: " . ($data['width'] ?? 'unknown') . "x" . ($data['height'] ?? 'unknown') . "\n";
        echo "   Format: " . ($data['format'] ?? 'jpg') . "\n";
    }
}

/**
 * 日志消息处理器
 * 
 * 处理日志记录任务，将日志写入不同的存储
 */
class LogMessageHandler implements MessageHandlerInterface
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle($message)
    {
        $data = json_decode($message, true);
        
        if (!isset($data['type']) || $data['type'] !== 'log') {
            $this->logger->warning('Invalid log message format', ['message' => $data]);
            return false;
        }

        $logData = $data['data'] ?? [];
        $level = $logData['level'] ?? 'info';
        $message = $logData['message'] ?? 'No message';

        $this->logger->info('Processing log entry', [
            'level' => $level,
            'message' => $message
        ]);

        try {
            // 写入日志
            $this->writeLog($level, $message, $logData);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to write log', [
                'level' => $level,
                'message' => $message,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    private function writeLog(string $level, string $message, array $data): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $context = $data['context'] ?? [];
        
        echo "📝 Log entry written [{$timestamp}]\n";
        echo "   Level: {$level}\n";
        echo "   Message: {$message}\n";
        echo "   Context: " . json_encode($context) . "\n";
    }
}

/**
 * 消息处理器路由
 * 
 * 根据消息类型路由到不同的处理器
 */
class MessageHandlerRouter implements MessageHandlerInterface
{
    private $handlers = [];
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->registerDefaultHandlers();
    }

    public function registerHandler(string $type, MessageHandlerInterface $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function handle($message)
    {
        $data = json_decode($message, true);
        $type = $data['type'] ?? 'unknown';
        
        $this->logger->info('Routing message to handler', ['type' => $type]);
        
        if (!isset($this->handlers[$type])) {
            $this->logger->warning('No handler found for message type', ['type' => $type]);
            return false;
        }
        
        return $this->handlers[$type]->handle($message);
    }

    private function registerDefaultHandlers(): void
    {
        $this->handlers['email'] = new EmailMessageHandler($this->logger);
        $this->handlers['image'] = new ImageProcessHandler($this->logger);
        $this->handlers['log'] = new LogMessageHandler($this->logger);
    }
}