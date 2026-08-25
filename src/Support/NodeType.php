<?php

namespace Devletes\Circuit\Support;

use Closure;

/**
 * Declares one kind of node the canvas can place.
 *
 * A node type is pure presentation + topology rules. It knows nothing about
 * what the node *does* — that belongs to the consuming application, which
 * reads the saved graph and interprets `type` and `config` however it likes.
 */
class NodeType
{
    protected string $label;

    protected ?string $icon = null;

    protected string $color = 'gray';

    protected ?string $description = null;

    protected bool $singleton = false;

    protected bool $addable = true;

    protected bool $initial = false;

    protected bool $terminal = false;

    protected ?int $maxOutgoing = null;

    protected ?int $maxIncoming = null;

    protected array|Closure $outcomes = [];

    protected array|Closure $schema = [];

    protected ?Closure $summariseUsing = null;

    protected array|Closure $infolist = [];

    protected ?Closure $validateConfigUsing = null;

    final public function __construct(protected string $name)
    {
        $this->label = str($name)->headline()->toString();
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /** Any Filament colour name — drives the node's accent in both themes. */
    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /** At most one node of this type may exist in a graph. */
    public function singleton(bool $condition = true): static
    {
        $this->singleton = $condition;

        return $this;
    }

    /**
     * Whether the palette offers this type. A type that is not addable still
     * renders, validates and executes wherever a stored graph already uses it —
     * this only withdraws it from the "Add node" list, which is what shipping a
     * node type that is not ready for authors needs.
     */
    public function addable(bool $condition = true): static
    {
        $this->addable = $condition;

        return $this;
    }

    /** Entry point — accepts no incoming edges. */
    public function initial(bool $condition = true): static
    {
        $this->initial = $condition;
        $this->maxIncoming = $condition ? 0 : $this->maxIncoming;

        return $this;
    }

    /** Exit point — emits no outgoing edges. */
    public function terminal(bool $condition = true): static
    {
        $this->terminal = $condition;
        $this->maxOutgoing = $condition ? 0 : $this->maxOutgoing;

        return $this;
    }

    public function maxOutgoing(?int $count): static
    {
        $this->maxOutgoing = $count;

        return $this;
    }

    public function maxIncoming(?int $count): static
    {
        $this->maxIncoming = $count;

        return $this;
    }

    /**
     * The distinct ways a node of this type can conclude, as an ordered map of
     * outcome key => label — e.g. `['approved' => 'Approved', 'rejected' =>
     * 'Rejected']`. Outgoing edges may bind to one outcome via their `outcome`
     * key; an empty map (the default) means the single implicit outcome, and
     * every outgoing edge is a plain "always follow". Accepts a closure so
     * labels can be resolved lazily.
     *
     * @param  array<string, string>|Closure(): array<string, string>  $outcomes
     */
    public function outcomes(array|Closure $outcomes): static
    {
        $this->outcomes = $outcomes;

        return $this;
    }

    /**
     * Filament schema components used to edit this node's `config`. Accepts a
     * closure so options can be resolved lazily (per tenant, per request).
     *
     * @param  array|Closure(): array  $schema
     */
    public function schema(array|Closure $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    /**
     * Renders the one-line summary shown on the node body once configured —
     * e.g. "Line Manager" rather than a raw config dump.
     *
     * @param  Closure(array $config): ?string  $callback
     */
    public function summariseUsing(Closure $callback): static
    {
        $this->summariseUsing = $callback;

        return $this;
    }

    /**
     * Read-only components rendered inside the node card, for types whose
     * configuration says more than the one line {@see summariseUsing()} affords.
     *
     * The same vocabulary a resource's `infolist()` speaks — entry names
     * resolve against the node's saved config, so `TextEntry::make('role')`
     * reads `config.role`. The closure receives that config and the whole node.
     *
     * Prefer the closure form. A literal array is one set of component
     * instances shared by every node of this type, so they are cloned per node
     * to keep their containers apart — which a closure gets for free.
     *
     * @param  array<int, mixed>|Closure(array $config, array $node): array<int, mixed>  $components
     */
    public function infolist(array|Closure $components): static
    {
        $this->infolist = $components;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    /**
     * Nodes are rendered client-side by Alpine, so the icon is resolved to
     * markup here and handed over with the rest of the type definition.
     */
    public function getIconHtml(): ?string
    {
        if (blank($this->icon) || ! function_exists('svg')) {
            return null;
        }

        try {
            return svg($this->icon, 'fi-circuit-node-icon-svg')->toHtml();
        } catch (\Throwable) {
            // An unresolvable icon should not take the whole canvas down.
            return null;
        }
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isSingleton(): bool
    {
        return $this->singleton;
    }

    public function isAddable(): bool
    {
        return $this->addable;
    }

    public function isInitial(): bool
    {
        return $this->initial;
    }

    public function isTerminal(): bool
    {
        return $this->terminal;
    }

    public function getMaxOutgoing(): ?int
    {
        return $this->maxOutgoing;
    }

    public function getMaxIncoming(): ?int
    {
        return $this->maxIncoming;
    }

    /** @return array<string, string> outcome key => label, in declaration order */
    public function getOutcomes(): array
    {
        return $this->outcomes instanceof Closure
            ? ($this->outcomes)()
            : $this->outcomes;
    }

    public function hasOutcomes(): bool
    {
        return $this->getOutcomes() !== [];
    }

    public function getOutcomeLabel(string $outcome): ?string
    {
        return $this->getOutcomes()[$outcome] ?? null;
    }

    public function getSchema(): array
    {
        return $this->schema instanceof Closure
            ? ($this->schema)()
            : $this->schema;
    }

    public function summarise(array $config): ?string
    {
        return $this->summariseUsing
            ? ($this->summariseUsing)($config)
            : null;
    }

    /**
     * Application-level problems with a node's config, as human-readable
     * messages. Topology is the canvas's job; this is for meaning — "a role
     * approver needs a role". Run as part of save-time validation, and
     * reported against the node it belongs to.
     *
     * @param  Closure(array $config): array<int, string>  $callback
     */
    public function validateConfigUsing(Closure $callback): static
    {
        $this->validateConfigUsing = $callback;

        return $this;
    }

    /** @return array<int, string> */
    public function validateConfig(array $config): array
    {
        if (! $this->validateConfigUsing) {
            return [];
        }

        return array_values(array_filter(
            (array) ($this->validateConfigUsing)($config),
            static fn (mixed $message): bool => filled($message),
        ));
    }

    public function hasInfolist(): bool
    {
        return $this->infolist instanceof Closure || $this->infolist !== [];
    }

    /**
     * This type's node-card components, resolved for one node.
     *
     * @return array<int, mixed>
     */
    public function getInfolist(array $config = [], array $node = []): array
    {
        if ($this->infolist instanceof Closure) {
            return array_values((array) ($this->infolist)($config, $node));
        }

        return array_map(
            fn (mixed $component): mixed => is_object($component) ? clone $component : $component,
            array_values($this->infolist),
        );
    }

    /** Shape handed to the Alpine component. */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'icon' => $this->icon,
            'iconHtml' => $this->getIconHtml(),
            'color' => $this->color,
            'description' => $this->description,
            'singleton' => $this->singleton,
            'addable' => $this->addable,
            'initial' => $this->initial,
            'terminal' => $this->terminal,
            'maxOutgoing' => $this->maxOutgoing,
            'maxIncoming' => $this->maxIncoming,
            // An empty map must reach Alpine as {} rather than [] so the
            // client can always treat it as a keyed object.
            'outcomes' => (object) $this->getOutcomes(),
            // A type with no fields has nothing to configure — structural
            // nodes like start and end. The client uses this to leave them
            // inert instead of opening an empty modal.
            'configurable' => $this->getSchema() !== [],
        ];
    }
}
