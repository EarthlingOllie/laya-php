<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel;

use EarthlingOllie\SystemOneClassification\Client\DecisionObserver;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionFailed;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionMade;
use EarthlingOllie\SystemOneClassification\Result\Result;
use Illuminate\Contracts\Events\Dispatcher;

use function array_column;

/** Turns one connection's decisions into DecisionMade and DecisionFailed events. */
final readonly class DispatchesDecisionEvents implements DecisionObserver
{
    public function __construct(
        private Dispatcher $events,
        private string $connection,
        private ?string $defaultModel,
        private bool $includeContext,
    ) {}

    public function decided(Decision $decision, Result $result, bool $cached): void
    {
        $this->events->dispatch(new DecisionMade(
            $this->connection,
            $this->model($decision),
            self::keys($decision),
            $result,
            $result->meta,
            $result->meta->latencyMs,
            $cached,
            $this->context($decision),
        ));
    }

    public function failed(Decision $decision, SystemOneException $exception): void
    {
        $this->events->dispatch(new DecisionFailed($this->connection, $this->model($decision), self::keys($decision), $exception, $this->context($decision)));
    }

    /** The model the decision was sent with: its pinned one wins over the connection's default (#9). */
    private function model(Decision $decision): ?string
    {
        return $decision->model ?? $this->defaultModel;
    }

    /** @return list<non-empty-string> */
    private static function keys(Decision $decision): array
    {
        return array_column($decision->questions(), 'key');
    }

    /** @return string|array<string, mixed>|null */
    private function context(Decision $decision): string|array|null
    {
        return $this->includeContext ? $decision->context->state : null;
    }
}
