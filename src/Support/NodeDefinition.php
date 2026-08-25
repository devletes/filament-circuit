<?php

namespace Devletes\Circuit\Support;

use ReflectionMethod;

/**
 * A node type as a class instead of an inline NodeType chain.
 *
 * One definition owns everything about one kind of node — presentation,
 * topology rules, config schema, summary, config validation — so consuming
 * applications can put each node in its own file and attach whatever runtime
 * behaviour they need to the same class (see the ExecutesNode contract).
 *
 * Definitions are gathered by {@see NodeRegistry}, typically via folder
 * auto-discovery, and handed to the canvas with `->nodeTypes($registry)`.
 */
abstract class NodeDefinition
{
    /**
     * The `type` string stored on nodes in the graph JSON. Defaults to the
     * class basename minus a `Node` suffix, snake_cased — `ApprovalNode`
     * becomes `approval`, `FieldUpdateNode` becomes `field_update`.
     */
    public static function type(): string
    {
        $basename = class_basename(static::class);
        $trimmed = preg_replace('/Node$/', '', $basename) ?: $basename;

        return str($trimmed !== '' ? $trimmed : $basename)->snake()->toString();
    }

    public function label(): string
    {
        return str(static::type())->headline()->toString();
    }

    public function icon(): ?string
    {
        return null;
    }

    /** Any Filament colour name — drives the node's accent in both themes. */
    public function color(): string
    {
        return 'gray';
    }

    public function description(): ?string
    {
        return null;
    }

    /** Entry point — accepts no incoming edges. */
    public function isInitial(): bool
    {
        return false;
    }

    /** Exit point — emits no outgoing edges. */
    public function isTerminal(): bool
    {
        return false;
    }

    /** At most one node of this type may exist in a graph. */
    public function isSingleton(): bool
    {
        return false;
    }

    public function maxIncoming(): ?int
    {
        return null;
    }

    public function maxOutgoing(): ?int
    {
        return null;
    }

    /** Palette ordering — lower first, ties broken by type name. */
    public function sort(): int
    {
        return 0;
    }

    /**
     * The distinct ways a node of this type can conclude, as an ordered map of
     * outcome key => label — e.g. `['approved' => 'Approved', 'rejected' =>
     * 'Rejected']`. Outgoing edges may bind to one outcome via their `outcome`
     * key. Most types declare none (the default): a single implicit outcome,
     * where every outgoing edge is a plain "always follow".
     *
     * @return array<string, string>
     */
    public function outcomes(): array
    {
        return [];
    }

    /**
     * Filament schema components used to edit this node's `config`.
     * Evaluated lazily, per render.
     */
    public function schema(): array
    {
        return [];
    }

    /** One-line summary rendered on the node body once configured. */
    public function summarise(array $config): ?string
    {
        return null;
    }

    /**
     * Infolist components rendered on the node body, for types whose config
     * says more than {@see summarise()} can fit on one line. Entry names
     * resolve against `$config`. Overriding this replaces the summary line on
     * the card; the summary itself is still stored on the node.
     *
     * @return array<int, mixed>
     */
    public function infolist(array $config): array
    {
        return [];
    }

    /**
     * Application-level config problems, as human-readable messages. Topology
     * is the canvas's job; this is for meaning ("a role approver needs a
     * role"). Consumed by save-time validators, not the canvas itself.
     *
     * @return array<int, string>
     */
    public function validateConfig(array $config): array
    {
        return [];
    }

    public function toNodeType(): NodeType
    {
        $type = NodeType::make(static::type())
            ->label($this->label())
            ->color($this->color())
            ->maxIncoming($this->maxIncoming())
            ->maxOutgoing($this->maxOutgoing())
            ->singleton($this->isSingleton())
            ->initial($this->isInitial())
            ->terminal($this->isTerminal())
            ->outcomes(fn (): array => $this->outcomes())
            ->schema(fn (): array => $this->schema())
            ->summariseUsing(fn (array $config): ?string => $this->summarise($config))
            ->validateConfigUsing(fn (array $config): array => $this->validateConfig($config));

        // Only definitions that actually override infolist() advertise one —
        // NodeType::hasInfolist() is the guard that keeps graphs full of
        // summary-only types out of the schema-rendering path entirely.
        if ((new ReflectionMethod($this, 'infolist'))->getDeclaringClass()->getName() !== self::class) {
            $type->infolist(fn (array $config): array => $this->infolist($config));
        }

        if ($this->icon() !== null) {
            $type->icon($this->icon());
        }

        if ($this->description() !== null) {
            $type->description($this->description());
        }

        return $type;
    }
}
