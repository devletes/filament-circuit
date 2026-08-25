<?php

namespace Devletes\Circuit\Support;

use InvalidArgumentException;

/**
 * The outcome of running one node — the vocabulary that lets a graph engine
 * suspend on asynchronous steps without Circuit knowing what they are.
 *
 * `completed()` means the walk may continue past this node. `waiting()` means
 * the node started something external (a task, a timer, a webhook) and the run
 * must suspend; the engine persists whatever it needs, and later resumes the
 * node when the external thing finishes (see {@see \Devletes\Circuit\Contracts\ResumesNode}).
 * `reference` identifies the external thing (e.g. a task id) so the completion
 * signal can be routed back to the right suspended run.
 *
 * Circuit itself never executes anything — this class exists so applications
 * building engines on top of a saved graph share one suspension contract.
 */
final class NodeResult
{
    public const COMPLETED = 'completed';

    public const WAITING = 'waiting';

    /**
     * @param  array<string, mixed>  $output
     */
    private function __construct(
        public readonly string $status,
        public readonly array $output = [],
        public readonly ?string $reference = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $output  data for downstream nodes / run state
     */
    public static function completed(array $output = []): self
    {
        return new self(self::COMPLETED, $output);
    }

    /**
     * @param  string|null  $reference  identifier of the external thing being waited on
     * @param  array<string, mixed>  $output
     */
    public static function waiting(?string $reference = null, array $output = []): self
    {
        return new self(self::WAITING, $output, $reference);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function isWaiting(): bool
    {
        return $this->status === self::WAITING;
    }

    /** Serializable shape, for engines persisting suspended run state. */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'output' => $this->output,
            'reference' => $this->reference,
        ];
    }

    public static function fromArray(array $raw): self
    {
        $status = $raw['status'] ?? null;

        if (! in_array($status, [self::COMPLETED, self::WAITING], true)) {
            throw new InvalidArgumentException("Unknown node result status [{$status}].");
        }

        return new self($status, $raw['output'] ?? [], $raw['reference'] ?? null);
    }
}
