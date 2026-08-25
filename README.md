# Circuit

A node-based canvas for Filament — drag, connect, and configure nodes natively
in Livewire and Alpine. What React Flow is for React, without the React.

Circuit is a **form field**. It binds to a JSON column, renders a canvas, and
saves `{ nodes, edges, viewport }`. It knows nothing about what your nodes mean.

## Why not React Flow

React Flow is excellent, and if you already run React it is the right answer.
Inside a Livewire panel it costs you a second component model and a second
styling system: every custom node is JSX, so Filament's visual language has to
be re-implemented and kept in sync by hand.

Circuit's nodes are plain DOM styled with Filament's own palette variables, so a
panel theme change carries through for free, and dark mode already works.

## Install

Not on Packagist yet, so point Composer at the repository:

```bash
composer config repositories.circuit vcs https://github.com/devletes/filament-circuit.git
composer require devletes/filament-circuit
```

For local development as a path repository:

```json
{
    "repositories": [
        { "type": "path", "url": "../filament-plugins/filament-circuit" }
    ]
}
```

Assets register themselves through `FilamentAsset`; no build step.

## Usage

```php
use Devletes\Circuit\Forms\Components\CircuitCanvas;
use Devletes\Circuit\Support\NodeType;

CircuitCanvas::make('graph')
    ->height(640)
    ->horizontal()          // left-to-right; default is vertical (top-down)
    ->nodeTypes([
        NodeType::make('start')
            ->label('Start')
            ->color('success')
            ->icon('heroicon-o-play')
            ->initial()
            ->singleton(),

        NodeType::make('approval')
            ->label('Approval')
            ->color('primary')
            ->description('Route to an approver')
            ->schema([
                Select::make('approver_type')->options([...])->required(),
            ])
            ->summariseUsing(fn (array $config): ?string => $config['approver_type'] ?? null),

        NodeType::make('end')
            ->label('End')
            ->color('gray')
            ->terminal(),
    ])
```

### Node type options

| Method | Effect |
|---|---|
| `label()` / `icon()` / `color()` / `description()` | Presentation. `color()` takes any Filament colour name |
| `initial()` | Entry point — accepts no incoming edges |
| `terminal()` | Exit point — emits no outgoing edges |
| `singleton()` | At most one per graph |
| `maxOutgoing()` / `maxIncoming()` | Edge limits, enforced live while connecting |
| `outcomes()` | Ordered map of outcome key => label — the distinct ways this node can conclude. Outgoing edges may bind to one |
| `schema()` | Filament components for editing this node's `config`. Accepts a closure for lazy options |
| `summariseUsing()` | One-line summary rendered on the node body |
| `infolist()` | Infolist components rendered on the node body, for types that need more than one line |

### Showing more than one line on a node

`summariseUsing()` gets you one line. When a type's configuration says more
than that, `infolist()` renders real Filament entries inside the node card:

```php
NodeType::make('task')
    ->schema(fn (): array => [
        TextInput::make('title')->required(),
        TextInput::make('assignee'),
        Toggle::make('blocking'),
    ])
    ->infolist(fn (): array => [
        TextEntry::make('title')->hiddenLabel()->placeholder('Untitled task'),
        TextEntry::make('assignee')->label('Assignee')->placeholder('Unassigned'),
        IconEntry::make('blocking')->label('Blocking')->boolean(),
    ]);
```

Entry names resolve against the node's own `config`, the way a
`RepeatableEntry` row reads its array — `TextEntry::make('assignee')` shows
`config.assignee`. The closure also receives `$config` and `$node` if the
components themselves need to vary.

Three things worth knowing:

- **It replaces the summary line**, so a type that declares both shows only the
  infolist. Keep `summariseUsing()` anyway: the summary is what the graph
  *stores*, and it is what a node announces to a screen reader — an
  infolist-only type reads out as bare "Task".
- **It renders from live config, not a snapshot.** The summary is computed once
  at save time and written onto the node; an infolist is re-rendered every time
  the canvas hears back from the server, so it never drifts from the data
  behind it.
- **Clicks pass through to the card.** The node is a drag surface and stays
  one — so keep the body read-only, and put anything clickable in
  `->nodeActions()` instead.

Cards are 220px wide, so entries are scaled down to node chrome and long values
wrap. A type that declares no `infolist()` costs nothing: nothing is rendered
for graphs that don't use the feature.

## Class-based node types

Beyond a couple of nodes, inline chains get unwieldy. Define each type as a
class instead — one file owning presentation, topology rules, schema, summary,
and config validation:

```php
use Devletes\Circuit\Support\NodeDefinition;

class ApprovalNode extends NodeDefinition
{
    public function color(): string { return 'primary'; }

    public function schema(): array { /* Filament components */ }

    public function summarise(array $config): ?string { /* node body line */ }

    public function infolist(array $config): array { /* richer node body */ }

    public function validateConfig(array $config): array { /* app-level rules */ }
}
```

The type name derives from the class basename (`ApprovalNode` → `approval`,
`FieldUpdateNode` → `field_update`); override `type()` to change it. `sort()`
orders the palette. Every `NodeType` option has a matching overridable
(`isInitial()`, `maxOutgoing()`, `outcomes()`, …).

A `NodeRegistry` collects definitions — usually by auto-discovering a folder —
and feeds both the canvas and whatever reads the saved graph:

```php
$this->app->singleton(NodeRegistry::class, fn (): NodeRegistry => (new NodeRegistry)
    ->discoverIn(app_path('Workflows/Nodes'), 'App\\Workflows\\Nodes'));

CircuitCanvas::make('graph')->nodeTypes(app(NodeRegistry::class));
```

Dropping a new definition into the folder is all it takes. `nodeTypes()`
accepts any mix: inline `NodeType` chains, `NodeDefinition` instances or
class-strings, or a whole registry.

### Runtime scaffolding

Circuit never executes a graph, but it ships the vocabulary for engines that
do, so a node's behaviour can live on its definition:

- `ExecutesNode::execute($node, $context)` returns a `NodeResult` —
  `completed()` lets the walk continue; `waiting($reference)` suspends the run
  on something external (a task, a timer, a webhook), with `reference`
  identifying it so the completion signal routes back.
- `ResumesNode::resume($node, $context, $payload)` handles that signal —
  return `completed()` to move on, or `waiting()` to stay suspended.
- `NodeResult` round-trips through `toArray()`/`fromArray()` for engines
  persisting suspended state.
- `validateConfig()` feeds app-level save-time validation ("a role approver
  needs a role") — topology stays the canvas's job.

The engine itself — persistence, walking, resumption — belongs to the
application. Circuit only fixes the contract so every node speaks it.

## The contract

The saved state is a single JSON document:

```jsonc
{
  "nodes": [
    { "id": "n1", "type": "approval", "position": { "x": 0, "y": 140 }, "config": { } }
  ],
  "edges": [
    { "id": "e1", "source": "n1", "target": "n2", "outcome": "approved", "condition": null }
  ],
  "viewport": { "x": 0, "y": 0, "zoom": 1 }
}
```

`config` is opaque to Circuit — it is whatever that node type's `schema()`
produces. On an edge, `outcome` binds it to one of the source node type's
declared outcomes and `condition` is an opaque app-defined payload; both are
optional, and a bare edge means "always follow" (see
[Edge outcomes & conditions](#edge-outcomes--conditions)). Edges with a
condition also carry a derived `label` — the pill text, recomputed on every
hydration.

Read it server-side with the `Graph` value object:

```php
use Devletes\Circuit\Support\Graph;

$graph = Graph::fromArray($workflow->graph);

$graph->nodesOfType('approval');   // array of nodes
$graph->successors('n1');          // ['n2']
$graph->reachableFrom(['start']);  // every connected node id
$graph->hasCycle();                // bool
```

## Read-only entries

`CircuitEntry` renders a saved graph on infolists and View pages — same canvas,
same styling, no editing affordances. Filament v4's unified schemas also allow
it inside a form schema:

```php
use Devletes\Circuit\Infolists\Components\CircuitEntry;

CircuitEntry::make('graph')
    ->nodeTypes([...])
    ->height(420);
```

## Editing node config

Double-clicking a node (or pressing <kbd>Enter</kbd> on it) mounts a built-in
Filament action: a **modal** rendering that node type's `schema()`, pre-filled
from the node's `config`. On submit, Circuit writes the config back, recomputes
`summariseUsing()`, and tells the canvas to re-read state. No wiring needed —
the field registers the action itself.

Prefer a slide-over? Opt in per canvas — the same switch exists for edge config:

```php
CircuitCanvas::make('graph')
    ->nodeConfigInSlideOver()          // node config as a slide-over
    ->edgeConfigInSlideOver()          // connection config as a slide-over
    ->nodeConfigModalWidth(Width::TwoExtraLarge)
    ->edgeConfigModalWidth('lg')
```

Both accept a bool or a Closure, and both default to `false` — a modal.

Outside a Filament schema (a bare Alpine mount), the canvas falls back to
browser events: `circuit-node-edit` fires with `{ id, type, config }`, and you
write back with `$dispatch('circuit-update-node', { id, config })`.
`circuit-node-selected` fires on every selection change in both modes.

### Adding your own sections to the node modal

A node type's `schema()` covers its own config. Context *about* the node —
where it goes next, what depends on it — belongs to the app, not the type.
`->nodeSchemaSuffix()` appends components after the type's own fields:

```php
CircuitCanvas::make('graph')
    ->nodeSchemaSuffix(fn (array $node, array $outgoing, CircuitCanvas $component): array => count($outgoing) > 1
        ? [Section::make('Outgoing connections')->schema([...])]
        : [])
```

The closure receives `$node` (the full array), `$nodeId`, `$nodeType` (the
`NodeType`), and `$outgoing` / `$incoming` — the edge arrays touching that
node; take whichever you need, by name. Return `[]` to add nothing for a
particular node. `getOutgoingEdges()` / `getIncomingEdges()` are public on the
canvas too, for use anywhere else.

The components are state-pathed under `_circuit` and that whole branch is
discarded on submit, so a suffix is **display-only whatever it renders** — it
can never write to the node's `config`, and the node modal saves exactly as it
did before.

## Node actions

Double-click-to-configure is the built-in affordance. `->nodeActions()` adds
your own, rendered **on the node card**: a flat list of `Filament\Actions\Action`
and `Filament\Actions\ActionGroup` instances, exactly as a table takes
`recordActions()`.

```php
use Devletes\Circuit\Actions\NodeAction;
use Devletes\Circuit\Forms\Components\CircuitCanvas;
use Filament\Actions\ActionGroup;

CircuitCanvas::make('graph')
    ->nodeTypes([...])
    ->nodeActions([
        // Inline: rendered as an icon-only button.
        CircuitCanvas::configureNodeAction(),

        // Grouped: one trigger (ellipsis by default) opening a dropdown.
        ActionGroup::make([
            NodeAction::make('duplicate')
                ->label('Duplicate node')
                ->icon('heroicon-m-document-duplicate')
                ->action(fn (array $node) => /* … */),

            CircuitCanvas::deleteNodeAction()
                ->hidden(fn (?array $node): bool => $node['type'] === 'start'),
        ]),
    ])
```

A Closure is accepted too, and is evaluated at render time.

An `Action` renders as an **icon-only button**; an `ActionGroup` renders as a
single trigger opening a Filament dropdown. Those are *defaults* — an action
that calls `->button()`, `->link()` or `->size()` keeps its own choice. Group
dropdowns are teleported to the body, because the canvas surface clips overflow
and a panel opened on a node near the edge would otherwise be cut off.

### Node context

Every action is mounted with `['nodeId' => …, 'nodeType' => …]`, the same
`$arguments` convention the built-in node/edge config actions use. On a plain
Filament action, take `$arguments` and resolve the node through the canvas:

```php
Action::make('rename')
    ->label(fn (array $arguments, CircuitCanvas $component): string => 'Rename '
        . ($component->getNode($arguments['nodeId'])['summary'] ?? 'node'))
    ->action(function (array $arguments, CircuitCanvas $component): void {
        $node = $component->getNode($arguments['nodeId']);   // the full node array
    });
```

`getNode(?string $id): ?array` is public on both `CircuitCanvas` and
`CircuitEntry` and returns the node as it is stored — `id`, `type`, `position`,
`config`, `summary` — or `null`. `getNodeTypeFor(?string $id): ?NodeType` gives
you the type behind it, which is how you branch on `initial()` / `terminal()`
rather than on a hardcoded name.

`Devletes\Circuit\Actions\NodeAction` skips the lookup: it injects `$node` (the
full array), `$nodeId` and `$nodeType` into every closure — `label()`, `icon()`,
`visible()`, `hidden()`, `action()`, and the rest:

```php
NodeAction::make('openTicket')
    ->icon('heroicon-m-ticket')
    ->visible(fn (?array $node): bool => filled($node['config']['ticket'] ?? null))
    ->url(fn (array $node): string => route('tickets.show', $node['config']['ticket']));
```

Visibility is evaluated **per node**, at render time, with the arguments already
bound — so an action hidden for one node type simply isn't rendered on those
cards.

### Built-ins

| Factory | What it does |
|---|---|
| `CircuitCanvas::configureNodeAction()` | Opens the built-in node-config modal — the same one a double-click mounts |
| `CircuitCanvas::deleteNodeAction()` | Removes the node and every edge touching it, through the same graph mutation the toolbar's *Delete selection* performs |

Both are `NodeAction`s, so they take the usual `->label()`, `->icon()`,
`->color()`, `->visible()` / `->hidden()` overrides. Both run entirely on the
client — no round-trip — and both are marked `mutatesGraph()`.

### Rendering & interaction

- Buttons live inside the transformed canvas layer, so they scale with zoom
  like the edge label pills.
- They appear on **hover**, on **focus** (so they can be tabbed to), and while
  the node is **selected** — group triggers included, for consistency.
- Clicking one never starts a drag, opens node config, or changes the
  selection: pointer and keyboard events are stopped at the action bar.
- Buttons are real `<button>` elements — focusable, activated with
  <kbd>Enter</kbd> / <kbd>Space</kbd>, and labelled for screen readers from the
  action's `label()`.
- Double-click-to-configure is untouched, and with no `nodeActions` supplied a
  node renders exactly as before.

Under the hood, nodes are drawn client-side by Alpine, so each node's action bar
is rendered server-side with Filament's own action markup and injected with
`x-html` — which runs Alpine's `initTree()`, so `wire:click`, dropdowns,
tooltips and modals wire themselves up normally despite the canvas's
`wire:ignore`. The map is refreshed on the same round-trip that re-validates
topology after each commit, so a node added on the client picks its actions up
as soon as the canvas hears back.

### On read-only entries

`CircuitEntry` accepts `nodeActions()` too — a "view details" modal, a link out
to whatever a node points at. Actions declaring `mutatesGraph()` (which both
built-ins do) are **dropped rather than rendered**, since a read-only canvas has
no commit path to run them through. Plain `Filament\Actions\Action` instances
are never assumed to mutate; mark yours with `NodeAction::make(…)->mutatesGraph()`
if it edits the graph.

## Edge outcomes & conditions

A node type can declare **outcomes** — the distinct ways it can conclude — as
an ordered map of key => label:

```php
NodeType::make('approval')
    ->outcomes(['approved' => 'Approved', 'rejected' => 'Rejected'])

// or on a NodeDefinition:
public function outcomes(): array
{
    return ['approved' => 'Approved', 'rejected' => 'Rejected'];
}
```

Most types declare none: a single implicit outcome, where every outgoing edge
is a plain "always follow". When the source type does declare outcomes, an
edge may bind to one via its `outcome` key; whatever executes the graph reads
it to pick which edge to follow.

An edge may also carry a `condition` — an opaque payload Circuit stores
verbatim and never interprets. Your app supplies both the editing schema and
the evaluation.

### Configuring an edge

Every configurable connection carries a control at its **midpoint** — the same
anchor the label pill uses, so it scales with zoom inside the transformed
layer. A connection with a label *is* that control (the pill is a real button);
one with nothing to show yet gets a small round **+**, dimmed at rest and full
strength on hover, on focus, or while the connection is selected. Both are
focusable, activate with <kbd>Enter</kbd> / <kbd>Space</kbd>, and are labelled
*Configure connection*.

The control appears only when there is something to open: never on a
`CircuitEntry`, and never on an edge whose source type declares no outcomes
when no `edgeSchema` is set — those edges keep the plain, non-interactive pill.
Clicking one never starts a pan and never changes the selection, so a
connection you meant to configure is not left armed for <kbd>Delete</kbd>.

Double-clicking the edge itself, and the toolbar's *Configure connection*
button while an edge is selected, still work. All of them mount the same
slide-over machinery nodes use. It offers:

- an **Outcome** select when the source type declares outcomes, with an
  *Always* placeholder for the bare choice;
- your **condition components**, contributed through `->edgeSchema()`:

```php
CircuitCanvas::make('graph')
    ->nodeTypes([...])
    ->edgeSchema(fn (array $edge, array $source, array $target, array $condition): array => [
        Select::make('field')->options([...]),
        TextInput::make('value'),
    ])
```

The closure also receives `$sourceType` / `$targetType` (`NodeType`
instances); take whichever arguments you need, by name. The components are
state-pathed under `condition` automatically, so the payload stays one opaque
array on the edge. With no `edgeSchema` and no outcomes on the source type,
edge config has nothing to offer and the slide-over simply doesn't mount —
selection and deletion still work.

### Edge labels

Any edge with an outcome or a condition renders a small pill at its midpoint,
in both `CircuitCanvas` and `CircuitEntry`. Outcome labels come from the node
type natively. Condition summaries come from `->edgeLabel()` — the payload is
opaque, so only your app can describe it:

```php
CircuitCanvas::make('graph')
    ->edgeLabel(fn (array $edge): ?string => match ($edge['condition']['op'] ?? null) {
        '>' => "{$edge['condition']['field']} over {$edge['condition']['value']}",
        default => null,
    })
```

Without the hook (or when it returns null), a condition edge falls back to a
generic *Conditional* pill. Pills scale with zoom like node chrome and mask
the line rather than fighting it on short edges. On an editable canvas a pill
on a configurable edge doubles as that edge's configure button (see
[Configuring an edge](#configuring-an-edge)); everywhere else it is inert text.

Outside a Filament schema, the same fallback events exist as for nodes:
`circuit-edge-edit` fires with `{ id, source, target, outcome, condition }`,
and you write back with
`$dispatch('circuit-update-edge', { id, outcome, condition, label })`.

## Validation

Topology is checked at save time and surfaced as a normal field validation
error:

- exactly one node of each `initial()` type, at least one `terminal()`
- no cycles
- no orphans — every node reachable from an entry point
- no dangling edges
- `maxOutgoing` / `maxIncoming` respected
- every non-terminal node has an outgoing connection
- an edge's `outcome` exists on its source node's type
- no two edges out of one node claim the same outcome (bare parallel fan-out
  stays legal; conditions are opaque and never validated)

Problems are also attributed to the offending node: it outlines red, shows the
message inline, sets `aria-invalid`, and the toolbar shows a problem-count
badge. After each commit the canvas re-validates through a renderless
round-trip (`refreshProblems`), so highlights track the live graph.

Opt out with `->validateTopology(false)` for free-form diagrams.

The canvas also refuses invalid connections *while dragging* — self-links,
duplicates, edges into an `initial()` node, edges out of a `terminal()` one, and
anything that would create a cycle. Valid drop targets highlight green.

## Interaction

| Action | Input |
|---|---|
| Pan | Drag the background — stops once only a sliver of the graph is left on screen, so it cannot be lost in empty space |
| Zoom | Scroll, or the toolbar buttons |
| Move node | Drag it (snaps to grid), or arrow keys (<kbd>Shift</kbd> for 4×) |
| Connect | Drag from a node's bottom handle onto another node |
| Select | Click, or <kbd>Tab</kbd> onto a node |
| Configure | Double-click, or <kbd>Enter</kbd> / <kbd>Space</kbd> |
| Configure edge | The control at the connection's midpoint — its label pill, or a **+** when it has no label. Double-clicking the edge, and the toolbar while it is selected, do the same |
| Delete | <kbd>Delete</kbd> / <kbd>Backspace</kbd>, or the toolbar |
| Undo / redo | <kbd>Ctrl</kbd>/<kbd>Cmd</kbd> + <kbd>Z</kbd>, <kbd>Shift</kbd> to redo (<kbd>Ctrl</kbd> + <kbd>Y</kbd> too), or the toolbar |
| Node actions | The buttons revealed on the node when it is hovered, focused or selected (see [Node actions](#node-actions)) |
| Fit / tidy | Toolbar |
| Flip direction | Toolbar — swaps between a top-down and a left-to-right flow, and tidies up after itself |
| Resize | Drag the grip on the canvas's bottom edge, or focus it and use <kbd>↑</kbd>/<kbd>↓</kbd> |

A field with a `default()` graph stays quiet until it is touched: a new record
opens on a starting point that is incomplete by definition — a Start and an End
with nothing between them has no outgoing connection and an unreachable exit —
and painting a form red before it has been used trains people to ignore the
colour. The live highlights appear the moment the graph stops matching the
default. Nothing is skipped at save time; the validation rule runs on whatever
is submitted.

Undo covers every graph edit — moving, adding, deleting, connecting, and config
written through a node's modal — up to 50 steps. It deliberately does **not**
restore the viewport: panning is not an edit, and undo throwing the view back
to where a change happened three steps ago is disorienting. The shortcut stands
down inside text fields and open modals, where undo means "undo my typing".

Nodes are focusable with ARIA roles and labels; the surface announces node and
connection counts.

Connections are drawn as straight runs with rounded corners, always leaving and
entering along the flow axis. A connection that would be drawn straight over a
card it is not attached to — a skip like `1 -> 3` in a `1 -> 2 -> 3` chain,
which would otherwise land exactly on top of the two edges either side of node
2 — bows out to a clear lane instead, one step further out for each other skip
already routed the same way. Tidy-up stacks levels by their measured height, so
a node type with an `infolist()` body pushes the next level down rather than
being landed on.

## Options

Both `CircuitCanvas` and `CircuitEntry` take the same presentation and
interaction options:

| Method | Effect |
|---|---|
| `height()` | The height the canvas opens at — a starting point, not a ceiling |
| `direction()` / `horizontal()` / `vertical()` | Which way the flow reads |
| `resizable()` | Grip on the bottom edge for dragging it taller |
| `orientable()` | Toolbar toggle between a top-down and a left-to-right flow |
| `undoable()` | Undo/redo — the buttons and the shortcuts |
| `historyLimit()` | How many states undo remembers |
| `tidyable()` | The tidy-up button that re-runs the automatic layout |
| `zoomable()` | Zoom in/out and fit-to-view, and wheel-to-zoom |
| `showMinimap()` | The minimap in the corner — blocks, the connections between them, and a frame around what is currently on screen |
| `minimapSize()` | How big that minimap is, in pixels — `minimapSize(240, 150)`, or one argument for a square |

```php
CircuitCanvas::make('graph')
    ->height(720)
    ->horizontal()
    ->undoable(false)
    ->tidyable(fn (): bool => auth()->user()->isAdmin())
```

Turning a tool off hides its control **and** disables the behaviour behind it,
so nothing stays reachable by shortcut that the interface no longer shows.

### Defaults

Every option falls through to `config/circuit.php` when a field does not set it,
so the defaults are yours to move:

```bash
php artisan vendor:publish --tag=circuit-config
```

It carries the starting heights (separately for the canvas and the read-only
entry), the resize bounds, the starting direction, the tools above, the undo
depth, and the grid. Because options resolve at read time rather than at
construction, changing the config moves every canvas that never said otherwise.
Passing `null` to any option puts it back under the config's control.

### Direction and what gets remembered

Flipping direction re-runs tidy-up, because positions authored for one axis read
as a tangle on the other — that moves nodes, and is one undo away.

The direction is **not** remembered across reloads, deliberately: it is only
coherent alongside positions laid out for it, and those live in the saved graph.
Height is remembered (per field, client-side), because it changes nothing about
the graph.

### What costs a round trip

Interaction state lives in Alpine, so panning, zooming, selecting and dragging
never wait on the server. Graph edits stage their state deferred (no request)
and schedule **one** debounced trip that returns the three things only the
server can produce: validation problems, the per-node action bars, and the node
infolist bodies.

That trip is skipped when nothing it computes could have changed. All three are
functions of which nodes exist, their type and config, and how they are
connected — never of where a node sits. So moving a node, tidying up and
flipping the direction cost nothing, while adding, deleting or reconfiguring
one costs a single request. Coordinates ride along with the next real request.

A `live()` field is the exception, and asks for a trip either way: prompt
`afterStateUpdated` is what it signed up for.

## Livewire behaviour

State is written to `$wire` only at commit points — drag end, connect, delete,
config change. Never during a pointer move. The canvas carries `wire:ignore`.

Every commit stages the graph immediately (no request), so a save that lands
mid-edit always carries the latest state. Viewport-only commits — pan, zoom,
fit — stop there: zero requests, the viewport rides along with the next real
one.

Graph edits (move, connect, delete, add, configure) schedule ONE debounced
(400ms), serialized round-trip that both syncs the staged state — so
`afterStateUpdated()` fires on live fields — and re-validates topology in the
same trip. One request is in flight at a time; edits made during a flight are
re-sent when it completes, and a slow response never overwrites a newer one.
The graph is deep-cloned at every Livewire boundary, so an in-flight response
can never write stale positions back into the canvas.

A canvas born hidden (collapsed section, inactive tab) auto-fits its viewport
the first time it gains size — unless a saved viewport exists, which is never
overridden.

## Theming

Everything resolves through CSS custom properties, and Filament's palette
variables are the defaults:

```css
.circuit {
    --circuit-surface: var(--gray-50);
    --circuit-node-bg: #fff;
    --circuit-edge: var(--gray-400);
    --circuit-accent: var(--primary-600);
}
```

Override any of them in your panel theme. Dark mode is handled under
`:where(.dark) .circuit`.

## Testing

```bash
composer install
composer test
```

The suite runs on Testbench with the real Filament stack, so the canvas is
exercised as what it is — a schema component inside a Livewire form. `tests/Unit`
covers the graph and node-type layer with no framework in the way;
`tests/Feature` drives `CircuitCanvas` and `CircuitEntry` through a Livewire
component, including the mounted config actions and save-time validation.

One ordering detail matters if you build your own harness: Filament rebinds
Livewire's `DataStore` to a subclass with a plain `bind()`, so **Livewire's
service provider has to be registered after Filament's**. Registered first, its
`instance()` binding is replaced, every resolve builds a fresh store, and
nothing Livewire writes to it survives the request. `tests/TestCase.php` has the
working order.

## Scope

Circuit is deliberately **not** an API-compatible React Flow port. It covers
what a configuration canvas needs — a few dozen nodes, authored by an admin,
saved to a column.

Not implemented, by choice:

- viewport virtualisation for very large graphs
- multi-select and marquee selection
- collaborative editing
- nested / grouped nodes
- freely positioned handles (source is bottom-centre, target is top-centre)

## License

MIT.
