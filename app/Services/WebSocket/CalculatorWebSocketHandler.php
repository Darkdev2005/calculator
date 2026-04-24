<?php

namespace App\Services\WebSocket;

use App\Exceptions\CalculatorException;
use App\Models\CalculationHistory;
use App\Models\CalculationSection;
use App\Services\CalculatorService;
use App\Services\WebSocketAuthTokenService;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

class CalculatorWebSocketHandler
{
    private const HISTORY_LIMIT_DEFAULT = 20;

    private const HISTORY_LIMIT_MAX = 100;

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
                'actions' => ['calculate', 'history', 'history.clear', 'stats', 'sections.list', 'sections.create', 'ping'],
                'note' => ['max_length' => 255],
            ],
        ]);

        $this->handleSections($connection, $user->id);

        $defaultSection = $this->getDefaultSection($user->id);
        $this->handleStats($connection, $user->id, ['section_id' => $defaultSection->id]);
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

        try {
            match ($action) {
                'calculate' => $this->handleCalculate($userId, $message),
                'history' => $this->handleHistory($connection, $userId, $message),
                'history.clear' => $this->handleClearHistory($userId, $message),
                'stats' => $this->handleStats($connection, $userId, $message),
                'sections.list' => $this->handleSections($connection, $userId),
                'sections.create' => $this->handleCreateSection($connection, $userId, $message),
                'ping' => $this->send($connection, ['type' => 'pong']),
                default => $this->send($connection, [
                    'type' => 'calculation.error',
                    'message' => 'Unsupported action.',
                ]),
            };
        } catch (CalculatorException $exception) {
            $this->send($connection, [
                'type' => 'calculation.error',
                'message' => $exception->getMessage(),
            ]);
        }
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
        $section = $this->resolveSection($userId, $message['section_id'] ?? null);

        $calculated = $this->calculatorService->calculate(
            (string) ($message['operation'] ?? ''),
            $message['left'] ?? null,
            $message['right'] ?? null,
            $message['precision'] ?? null,
        );

        $history = CalculationHistory::query()->create([
            'user_id' => $userId,
            'calculation_section_id' => $section->id,
            'operation' => $calculated['symbol'],
            'operand_left' => (float) $message['left'],
            'operand_right' => (float) $message['right'],
            'result' => $calculated['result'],
            'note' => $this->resolveNote($message['note'] ?? null),
            'calculated_at' => now(),
        ]);

        $history->setRelation('section', $section);

        $this->broadcastToUser($userId, [
            'type' => 'calculation.result',
            'entry' => $this->formatHistoryEntry($history),
        ]);

        $this->broadcastSectionHistory($userId, $section, self::HISTORY_LIMIT_DEFAULT);
        $this->broadcastStats($userId, $section->id);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleHistory(TcpConnection $connection, int $userId, array $message): void
    {
        $section = $this->resolveSection($userId, $message['section_id'] ?? null);
        $limit = $this->resolveHistoryLimit($message['limit'] ?? null);

        $this->send($connection, $this->buildHistorySnapshot($userId, $section, $limit));
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleClearHistory(int $userId, array $message): void
    {
        $section = $this->resolveSection($userId, $message['section_id'] ?? null);

        CalculationHistory::query()
            ->where('user_id', $userId)
            ->where('calculation_section_id', $section->id)
            ->delete();

        $this->broadcastSectionHistory($userId, $section, self::HISTORY_LIMIT_DEFAULT);

        $this->broadcastToUser($userId, [
            'type' => 'history.cleared',
            'section' => ['id' => $section->id, 'name' => $section->name],
            'message' => "Section '{$section->name}' history has been cleared.",
        ]);

        $this->broadcastStats($userId, $section->id);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleStats(TcpConnection $connection, int $userId, array $message): void
    {
        $section = $this->resolveSection($userId, $message['section_id'] ?? null);

        $this->send($connection, [
            'type' => 'stats.snapshot',
            'stats' => $this->buildStats($userId, $section),
        ]);
    }

    private function handleSections(TcpConnection $connection, int $userId): void
    {
        $defaultSection = $this->getDefaultSection($userId);

        $sections = CalculationSection::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get()
            ->map(fn (CalculationSection $section) => [
                'id' => $section->id,
                'name' => $section->name,
            ])
            ->values()
            ->all();

        $this->send($connection, [
            'type' => 'sections.snapshot',
            'sections' => $sections,
            'default_section_id' => $defaultSection->id,
        ]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleCreateSection(TcpConnection $connection, int $userId, array $message): void
    {
        $name = $this->resolveSectionName($message['name'] ?? null);

        $section = CalculationSection::query()->firstOrCreate([
            'user_id' => $userId,
            'name' => $name,
        ]);

        $this->send($connection, [
            'type' => 'section.created',
            'section' => ['id' => $section->id, 'name' => $section->name],
        ]);

        $this->handleSections($connection, $userId);
    }

    private function resolveSection(int $userId, mixed $sectionId): CalculationSection
    {
        if ($sectionId === null || $sectionId === '') {
            return $this->getDefaultSection($userId);
        }

        if (! is_numeric($sectionId)) {
            throw CalculatorException::invalidSection();
        }

        $section = CalculationSection::query()
            ->where('user_id', $userId)
            ->whereKey((int) $sectionId)
            ->first();

        if (! $section) {
            throw CalculatorException::invalidSection();
        }

        return $section;
    }

    private function getDefaultSection(int $userId): CalculationSection
    {
        $name = trim((string) config('calculator.default_section_name', 'Kundalik harajatlarim'));

        if ($name === '') {
            $name = 'Kundalik harajatlarim';
        }

        return CalculationSection::query()->firstOrCreate([
            'user_id' => $userId,
            'name' => $name,
        ]);
    }

    private function resolveSectionName(mixed $name): string
    {
        if (! is_string($name)) {
            throw CalculatorException::invalidSection();
        }

        $normalized = trim($name);

        if ($normalized === '' || mb_strlen($normalized) > 120) {
            throw CalculatorException::invalidSection();
        }

        return $normalized;
    }

    private function resolveNote(mixed $note): ?string
    {
        if ($note === null) {
            return null;
        }

        if (! is_string($note)) {
            throw CalculatorException::invalidNote();
        }

        $normalized = trim($note);

        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > 255) {
            throw CalculatorException::invalidNote();
        }

        return $normalized;
    }

    private function resolveHistoryLimit(mixed $limit): int
    {
        if ($limit === null || $limit === '') {
            return self::HISTORY_LIMIT_DEFAULT;
        }

        $parsed = filter_var($limit, FILTER_VALIDATE_INT);

        if ($parsed === false || $parsed < 1) {
            return self::HISTORY_LIMIT_DEFAULT;
        }

        return min($parsed, self::HISTORY_LIMIT_MAX);
    }

    private function buildStats(int $userId, CalculationSection $section): array
    {
        $query = CalculationHistory::query()
            ->where('user_id', $userId)
            ->where('calculation_section_id', $section->id);

        $latest = (clone $query)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->first();

        return [
            'section' => ['id' => $section->id, 'name' => $section->name],
            'total_operations' => (clone $query)->count(),
            'latest_result' => $latest ? (float) $latest->result : null,
            'latest_calculated_at' => $latest?->calculated_at?->toIso8601String(),
        ];
    }

    private function buildHistorySnapshot(int $userId, CalculationSection $section, int $limit): array
    {
        $entries = CalculationHistory::query()
            ->with('section')
            ->where('user_id', $userId)
            ->where('calculation_section_id', $section->id)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CalculationHistory $history) => $this->formatHistoryEntry($history))
            ->values()
            ->all();

        return [
            'type' => 'history.snapshot',
            'section' => ['id' => $section->id, 'name' => $section->name],
            'entries' => $entries,
        ];
    }

    private function formatHistoryEntry(CalculationHistory $history): array
    {
        $section = $history->section;

        return [
            'id' => $history->id,
            'operation' => $history->operation,
            'left' => (float) $history->operand_left,
            'right' => (float) $history->operand_right,
            'result' => (float) $history->result,
            'note' => $history->note,
            'section' => $section ? ['id' => $section->id, 'name' => $section->name] : null,
            'calculated_at' => $history->calculated_at?->toIso8601String(),
        ];
    }

    private function broadcastStats(int $userId, int $sectionId): void
    {
        $section = CalculationSection::query()
            ->where('user_id', $userId)
            ->whereKey($sectionId)
            ->first();

        if (! $section) {
            return;
        }

        $this->broadcastToUser($userId, [
            'type' => 'stats.snapshot',
            'stats' => $this->buildStats($userId, $section),
        ]);
    }

    private function broadcastSectionHistory(int $userId, CalculationSection $section, int $limit): void
    {
        $this->broadcastToUser($userId, $this->buildHistorySnapshot($userId, $section, $limit));
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
