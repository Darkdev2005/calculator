<?php

namespace App\Console\Commands;

use App\Services\WebSocket\CalculatorWebSocketHandler;
use Illuminate\Console\Command;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

class CalculatorWebSocketServerCommand extends Command
{
    protected $signature = 'calculator:websocket';

    protected $description = 'Run the real-time calculator WebSocket server.';

    public function handle(CalculatorWebSocketHandler $handler): int
    {
        $host = (string) config('calculator.websocket.host');
        $port = (int) config('calculator.websocket.port');

        $this->components->info("Starting calculator WebSocket server on ws://{$host}:{$port}");

        $worker = new Worker("websocket://{$host}:{$port}");

        $worker->name = 'calculator-websocket';
        $worker->count = 1;

        $worker->onWebSocketConnect = static function (TcpConnection $connection, Request $request) use ($handler): void {
            $handler->onConnect($connection, $request);
        };

        $worker->onMessage = static function (TcpConnection $connection, string $payload) use ($handler): void {
            $handler->onMessage($connection, $payload);
        };

        $worker->onClose = static function (TcpConnection $connection) use ($handler): void {
            $handler->onClose($connection);
        };

        Worker::runAll();

        return self::SUCCESS;
    }
}
