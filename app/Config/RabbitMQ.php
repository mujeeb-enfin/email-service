<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class RabbitMQ extends BaseConfig
{
    public $host = 'localhost';
    public $port = 5672;
    public $user = 'guest';
    public $password = 'guest';
    public $vhost = '/';
    public $queueName = 'email_queue';

    public function __construct()
    {
        parent::__construct();

        // Override with environment variables if available
        $this->host = getenv('RABBITMQ_HOST') ?: $this->host;
        $this->port = getenv('RABBITMQ_PORT') ?: $this->port;
        $this->user = getenv('RABBITMQ_USER') ?: $this->user;
        $this->password = getenv('RABBITMQ_PASSWORD') ?: $this->password;
        $this->vhost = getenv('RABBITMQ_VHOST') ?: $this->vhost;
        $this->queueName = getenv('RABBITMQ_QUEUE_NAME') ?: $this->queueName;
    }
}