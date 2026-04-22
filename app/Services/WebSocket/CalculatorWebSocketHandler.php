<?php

namespace App\Services\WebSocket;

use App\Exceptions\CalculatorException;
use App\Models\CalculationHistory;
use App\Services\CalculatorService;
use App\Services\WebSocketAuthTokenService;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

class CalculatorWebSocketHandler
{
    /** @var array<int, array<int, TcpConnection>> */
    private array $connectionsByUserId = [];

    /** @var array<int, int> */
    private array $userIdByConnectionId = [];

    public function __construct(
        private readonly CalculatorService $calculatorService,
        private readonly WebSocketAuthTokenService $tokenService,
    ) {
    }

    public function onConnect(TcpConnection $connection, Request $request): void
    {
        $expectedPath = '/'.trim((string) config('calculator.websocket.path'), '/');
        $actualPath = '/'.trim((string) $request->path(), '/');

        if ($actualPath !== $expectedPath) {
            $this->send($connection, [
                'type' => 'connection.error',
                'message' => 'Invalid WebSocket path.',
            ]);
            $connection->close();

            return;
        }

        $user = $this->tokenService->resolveUser($request->get('token'));

        if (! $user) {
            $this->send($connection, [
                'type' => 'connection.error',
                'message' => 'Unauthorized WebSocket connection.',
            ]);
            $connection->close();

            return;
        }

        $this->connectionsByUserId[$user->id][$connection->id] = $connection;
        $this->userIdByConnectionId[$connection->id] = $user->id;

        $this->send($connection, [
            'type' => 'connection.ready',
            'message' => 'Authenticated WebSocket connection established.',
            'capabilities' => [
                'operations' => ['add', 'subtract', 'multiply', 'divide', 'power', 'modulo'],
                'precision' => ['min' => 0, 'max' => 10],
                'actions' => ['calculate', 'history', 'history.clear', 'stats', 'ping'],
            ],
        ]);

        $this->handleStats($connection, $user->id);
    }

    public function onMessage(TcpConnection $connection, string $payload): void
    {
        $userId = $this->userIdByConnectionId[$connection->id] ?? null;

        if (! $userId) {
            $this->send($connection, [
                'type' => 'connection.error',
                'message' => 'Connection is not authenticated.',
            ]);
            $connection->close();

            return;
        }

        try {
            $message = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->send($connection, [
                'type' => 'calculation.error',
                'message' => 'Payload must be valid JSON.',
            ]);

            return;
        }

        $action = $message['action'] ?? null;

        if (! is_string($action)) {
            $this->send($connection, [
                'type' => 'calculation.error',
                'message' => 'Action is required.',
            ]);

            return;
        }

        match ($action) {
            'calculate' => $this->handleCalculate($userId, $message),
            'history' => $this->handleHistory($connection, $userId, $message),
            'history.clear' => $this->handleClearHistory($userId),
            'stats' => $this->handleStats($connection, $userId),
            'ping' => $this->send($connection, ['type' => 'pong']),
            default => $this->send($connection, [
                'type' => 'calculation.error',
                'message' => 'Unsupported action.',
            ]),
        };
    }

    public function onClose(TcpConnection $connection): void
    {
        $connectionId = $connection->id;
        $userId = $this->userIdByConnectionId[$connectionId] ?? null;

        if (! $userId) {
            return;
        }

        unset($this->connectionsByUserId[$userId][$connectionId], $this->userIdByConnectionId[$connectionId]);

        if (empty($this->connectionsByUserId[$userId])) {
            unset($this->connectionsByUserId[$userId]);
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleCalculate(int $userId, array $message): void
    {
        try {
            $calculated = $this->calculatorService->calculate(
                (string) ($message['operation'] ?? ''),
                $message['left'] ?? null,
                $message['right'] ?? null,
                $message['precision'] ?? null,
            );
        } catch (CalculatorException $exception) {
            $this->broadcastToUser($userId, [
                'type' => 'calculation.error',
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $history = CalculationHistory::query()->create([
            'user_id' => $userId,
            'operation' => $calculated['symbol'],
            'operand_left' => (float) $message['left'],
            'operand_right' => (float) $message['right'],
            'result' => $calculated['result'],
            'calculated_at' => now(),
        ]);

        $this->broadcastToUser($userId, [
            'type' => 'calculation.result',
            'entry' => $this->formatHistoryEntry($history),
        ]);

        $this->broadcastStats($userId);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleHistory(TcpConnection $connection, int $userId, array $message): void
    {
        $limit = min((int) ($message['limit'] ?? 20), 100);

        $entries = CalculationHistory::query()
            ->where('user_id', $userId)
            ->orderByDesc('calculated_at')
            ->limit(max($limit, 1))
            ->get()
            ->map(fn (CalculationHistory $history) => $this->formatHistoryEntry($history))
            ->values()
            ->all();

        $this->send($connection, [
            'type' => 'history.snapshot',
            'entries' => $entries,
        ]);
    }

    private function handleClearHistory(int $userId): void
    {
        CalculationHistory::query()->where('user_id', $userId)->delete();

        $this->broadcastToUser($userId, [
            'type' => 'history.snapshot',
            'entries' => [],
        ]);

        $this->broadcastToUser($userId, [
            'type' => 'history.cleared',
            'message' => 'History has been cleared.',
        ]);

        $this->broadcastStats($userId);
    }

    private function handleStats(TcpConnection $connection, int $userId): void
    {
        $this->send($connection, [
            'type' => 'stats.snapshot',
            'stats' => $this->buildStats($userId),
        ]);
    }

    private function buildStats(int $userId): array
    {
        $latest = CalculationHistory::query()
            ->where('user_id', $userId)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->first();

        return [
            'total_operations' => CalculationHistory::query()->where('user_id', $userId)->count(),
            'latest_result' => $latest ? (float) $latest->result : null,
            'latest_calculated_at' => $latest?->calculated_at?->toIso8601String(),
        ];
    }

    private function formatHistoryEntry(CalculationHistory $history): array
    {
        return [
            'id' => $history->id,
            'operation' => $history->operation,
            'left' => (float) $history->operand_left,
            'right' => (float) $history->operand_right,
            'result' => (float) $history->result,
            'calculated_at' => $history->calculated_at?->toIso8601String(),
        ];
    }

    private function broadcastStats(int $userId): void
    {
        $this->broadcastToUser($userId, [
            'type' => 'stats.snapshot',
            'stats' => $this->buildStats($userId),
        ]);
    }

    private function broadcastToUser(int $userId, array $payload): void
    {
        if (! isset($this->connectionsByUserId[$userId])) {
            return;
        }

        foreach ($this->connectionsByUserId[$userId] as $connection) {
            $this->send($connection, $payload);
        }
    }

    private function send(TcpConnection $connection, array $payload): void
    {
        $connection->send(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
