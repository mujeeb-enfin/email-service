<?php

namespace Config;

use CodeIgniter\Database\Config;

class Database extends Config
{
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;
    public string $defaultGroup = 'default';

    public array $default = [];

    public function __construct()
    {
        parent::__construct();
        $this->default = [
            'DSN'       => '',
            'hostname'  => env('database.default.hostname', 'localhost'),
            'username'  => env('database.default.username', 'root'),
            'password'  => env('database.default.password', ''),
            'database'  => env('database.default.database', 'ci4'),
            'DBDriver'  => env('database.default.DBDriver', 'MySQLi'),
            'DBPrefix'  => '',
            'pConnect'  => false,
            'DBDebug'   => (ENVIRONMENT !== 'production'),
            'charset'   => 'utf8mb4',
            'DBCollat'  => 'utf8mb4_general_ci',
            'swapPre'   => '',
            'encrypt'   => false,
            'compress'  => false,
            'strictOn'  => false,
            'failover'  => [],
            'port'      => 3306,
            'numberNative' => false,
            'foundRows'    => false,
            'dateFormat'   => [
                'date'     => 'Y-m-d',
                'datetime' => 'Y-m-d H:i:s',
                'time'     => 'H:i:s',
            ],
        ];

        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}
