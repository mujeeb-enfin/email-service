<?php

namespace App\Libraries;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exchange\AMQPExchangeType;

class RabbitMQLibrary
{
    private $connection;
    private $channel;
    private $config;

    public function __construct()
    {
        $this->config = config('RabbitMQ');
        $this->connect();
    }

    /**
     * Establish connection to RabbitMQ
     */
    private function connect()
    {
        try {
            $this->connection = new AMQPStreamConnection(
                $this->config->host,
                $this->config->port,
                $this->config->user,
                $this->config->password,
                $this->config->vhost
            );
            
            $this->channel = $this->connection->channel();
            
            // Declare the queue
            $this->channel->queue_declare(
                $this->config->queueName,     // queue name
                false,                         // passive
                true,                          // durable
                false,                         // exclusive
                false                          // auto_delete
            );

        } catch (\Exception $e) {
            log_message('error', 'RabbitMQ Connection Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Publish message to queue
     */
    public function publish($data, $queueName = null)
    {
        try {
            $queueName = $queueName ?? $this->config->queueName;
            
            $messageBody = json_encode($data);
            
            $message = new AMQPMessage($messageBody, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type'  => 'application/json'
            ]);

            $this->channel->basic_publish($message, '', $queueName);
            
            log_message('info', "Message published to queue: {$queueName}");
            
            return true;

        } catch (\Exception $e) {
            log_message('error', 'RabbitMQ Publish Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Publish message with delay
     */
    public function publishDelayed($data, $delaySeconds, $queueName = null)
    {
        try {
            $queueName = $queueName ?? $this->config->queueName;
            $delayedQueueName = $queueName . '_delayed';
            
            // Declare delayed queue
            $this->channel->queue_declare(
                $delayedQueueName,
                false,
                true,
                false,
                false,
                false,
                [
                    'x-dead-letter-exchange' => ['S', ''],
                    'x-dead-letter-routing-key' => ['S', $queueName],
                    'x-message-ttl' => ['I', $delaySeconds * 1000]
                ]
            );

            $messageBody = json_encode($data);
            
            $message = new AMQPMessage($messageBody, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type'  => 'application/json'
            ]);

            $this->channel->basic_publish($message, '', $delayedQueueName);
            
            log_message('info', "Delayed message published to queue: {$delayedQueueName} with delay: {$delaySeconds}s");
            
            return true;

        } catch (\Exception $e) {
            log_message('error', 'RabbitMQ Delayed Publish Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Consume messages from queue
     */
    public function consume($callback, $queueName = null)
    {
        try {
            $queueName = $queueName ?? $this->config->queueName;

            $this->channel->basic_qos(null, 1, null);
            
            $this->channel->basic_consume(
                $queueName,
                '',
                false,
                false,
                false,
                false,
                $callback
            );

            log_message('info', "Waiting for messages on queue: {$queueName}");

            while ($this->channel->is_consuming()) {
                $this->channel->wait();
            }

        } catch (\Exception $e) {
            log_message('error', 'RabbitMQ Consume Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get queue message count
     */
    public function getQueueCount($queueName = null)
    {
        try {
            $queueName = $queueName ?? $this->config->queueName;
            
            list($queue, $messageCount, $consumerCount) = $this->channel->queue_declare(
                $queueName,
                true
            );
            
            return $messageCount;

        } catch (\Exception $e) {
            log_message('error', 'RabbitMQ Queue Count Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Close connection
     */
    public function close()
    {
        try {
            if ($this->channel) {
                $this->channel->close();
            }
            
            if ($this->connection) {
                $this->connection->close();
            }
            
        } catch (\Exception $e) {
            log_message('error', 'RabbitMQ Close Error: ' . $e->getMessage());
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}