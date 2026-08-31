<?php

namespace Workbench\App\Filament\Pages;

use Devletes\Circuit\Actions\NodeAction;
use Devletes\Circuit\Forms\Components\CircuitCanvas;
use Devletes\Circuit\Infolists\Components\CircuitEntry;
use Devletes\Circuit\Support\NodeRegistry;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

/**
 * One section per README heading, each tagged with `data-demo` so
 * scripts/capture-screenshots.mjs can clip it in both colour modes.
 */
class Showcase extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $title = 'Circuit showcase';

    protected string $view = 'filament.pages.showcase';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'canvas' => static::approvalGraph(),
            'bodies' => static::bodiesGraph(),
            'actions' => static::approvalGraph(),
            'edges' => static::conditionGraph(),
            'validation' => static::brokenGraph(),
            'horizontal' => static::approvalGraph(),
            'minimal' => static::approvalGraph(),
            'minimap' => static::wideGraph(),
            'slideover' => static::approvalGraph(),
            'theming' => static::approvalGraph(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->demo('canvas', 'The canvas', CircuitCanvas::make('canvas')
                    ->nodeTypes(app(NodeRegistry::class))
                    ->nodeConfigModalWidth(Width::Large)
                    ->height(460)),

                $this->demo('bodies', 'Node bodies — a summary line, or an infolist', CircuitCanvas::make('bodies')
                    ->nodeTypes(app(NodeRegistry::class))
                    ->height(460)),

                $this->demo('actions', 'Node actions', CircuitCanvas::make('actions')
                    ->nodeTypes(app(NodeRegistry::class))
                    ->height(460)
                    ->nodeActions([
                        CircuitCanvas::configureNodeAction(),
                        ActionGroup::make([
                            NodeAction::make('duplicate')
                                ->label('Duplicate node')
                                ->icon('heroicon-m-document-duplicate')
                                ->action(fn () => null),
                            NodeAction::make('runFromHere')
                                ->label('Run from here')
                                ->icon('heroicon-m-play')
                                ->action(fn () => null),
                            CircuitCanvas::deleteNodeAction(),
                        ]),
                    ])),

                $this->demo('edges', 'Edge outcomes and conditions', CircuitCanvas::make('edges')
                    ->nodeTypes(app(NodeRegistry::class))
                    ->height(460)
                    ->edgeSchema(fn (): array => static::conditionSchema())
                    ->edgeLabel(fn (array $edge): ?string => static::conditionLabel($edge))),

                $this->demo('validation', 'Validation', CircuitCanvas::make('validation')
                    ->nodeTypes(app(NodeRegistry::class))
                    ->height(460)),

                $this->demo('horizontal', 'A left-to-right flow', CircuitCanvas::make('horizontal')
                    ->nodeTypes(app(NodeRegistry::class))
                    ->horizontal()
                    ->height(340)),

                $this->demo('minimal', 'Tools turned off', CircuitCanvas::make('minimal')
                    ->nodeTypes(app(NodeRegistry::class))
                    ->height(420)
                    ->orientable(false)
                    ->undoable(false)
                    ->tidyable(false)
                    ->zoomable(false)
                    ->resizable(false)
                    ->showMinimap(false)),

                $this->demo('minimap', 'The minimap', CircuitCanvas::make('minimap')
                    ->nodeTypes(app(NodeRegistry::class))
                    ->height(460)
                    ->minimapSize(240, 150)),

                $this->demo('slideover', 'Node config in a slide-over', CircuitCanvas::make('slideover')
                    ->nodeTypes(app(NodeRegistry::class))
                    ->height(460)
                    ->nodeConfigInSlideOver()),

                $this->demo('theming', 'Theming', CircuitCanvas::make('theming')
                    ->nodeTypes(app(NodeRegistry::class))
                    ->height(460)),

                // An Entry reads a record, not the form's state path, so on a
                // plain page its graph is handed over directly.
                $this->demo('entry', 'A read-only entry', CircuitEntry::make('entry')
                    ->nodeTypes(app(NodeRegistry::class))
                    ->state(fn (): array => static::conditionGraph())
                    ->edgeLabel(fn (array $edge): ?string => static::conditionLabel($edge))
                    ->height(460)),
            ])
            ->statePath('data');
    }

    protected function demo(string $id, string $heading, mixed $component): Section
    {
        return Section::make($heading)
            ->extraAttributes(['data-demo' => $id])
            ->schema([$component->hiddenLabel()]);
    }

    /** @return array<int, mixed> */
    public static function conditionSchema(): array
    {
        return [
            Select::make('field')
                ->label('Field')
                ->options(['amount' => 'Amount', 'department' => 'Department'])
                ->native(false),
            Select::make('op')
                ->label('Operator')
                ->options(['>' => 'is over', '<' => 'is under', '=' => 'equals'])
                ->native(false),
            TextInput::make('value')->label('Value'),
        ];
    }

    public static function conditionLabel(array $edge): ?string
    {
        $condition = $edge['condition'] ?? [];

        if (blank($condition['field'] ?? null)) {
            return null;
        }

        return trim(sprintf(
            '%s %s %s',
            str($condition['field'])->headline()->toString(),
            match ($condition['op'] ?? null) {
                '>' => 'over',
                '<' => 'under',
                default => 'is',
            },
            $condition['value'] ?? '',
        ));
    }

    /** The happy path: one approval fanning out into its two outcomes. */
    public static function approvalGraph(): array
    {
        return static::graph(
            nodes: [
                ['n1', 'start', [], null],
                ['n2', 'approval', ['approver' => 'line_manager', 'escalate_after' => 48, 'notify_requester' => true], 'Line manager'],
                ['n3', 'notify', ['channel' => 'mail', 'recipient' => 'payroll@example.com'], 'payroll@example.com'],
                ['n4', 'notify', ['channel' => 'slack', 'recipient' => '#requests'], '#requests'],
                ['n5', 'end', [], null],
            ],
            edges: [
                ['e1', 'n1', 'n2', null, null],
                ['e2', 'n2', 'n3', 'approved', null],
                ['e3', 'n2', 'n4', 'rejected', null],
                ['e4', 'n3', 'n5', null, null],
                ['e5', 'n4', 'n5', null, null],
            ],
        );
    }

    /** One type summarising in a line beside one drawing an infolist body. */
    public static function bodiesGraph(): array
    {
        return static::graph(
            nodes: [
                ['n1', 'start', [], null],
                ['n2', 'task', ['title' => 'Collect receipts', 'assignee' => 'Aria Bennett', 'blocking' => true], 'Collect receipts'],
                ['n3', 'approval', ['approver' => 'line_manager', 'escalate_after' => 24], 'Line manager'],
                ['n4', 'task', ['title' => 'File to the archive', 'blocking' => false], 'File to the archive'],
                ['n5', 'end', [], null],
            ],
            edges: [
                ['e1', 'n1', 'n2', null, null],
                ['e2', 'n2', 'n3', null, null],
                ['e3', 'n3', 'n4', 'approved', null],
                ['e4', 'n3', 'n5', 'rejected', null],
                ['e5', 'n4', 'n5', null, null],
            ],
        );
    }

    /**
     * Both kinds of pill in one graph: outcome labels off the approval, and
     * condition labels off a type that declares no outcomes.
     */
    public static function conditionGraph(): array
    {
        return static::graph(
            nodes: [
                ['n1', 'start', [], null],
                ['n2', 'approval', ['approver' => 'line_manager', 'escalate_after' => 24, 'notify_requester' => true], 'Line manager'],
                ['n3', 'notify', ['channel' => 'mail', 'recipient' => 'finance@example.com'], 'finance@example.com'],
                ['n4', 'approval', ['approver' => 'finance'], 'Finance'],
                ['n5', 'end', [], null],
            ],
            edges: [
                ['e1', 'n1', 'n2', null, null],
                // Both at once — the pill shows the outcome, and the slide-over
                // offers the outcome select alongside the condition schema.
                ['e2', 'n2', 'n3', 'approved', ['field' => 'amount', 'op' => '>', 'value' => '1000']],
                ['e3', 'n2', 'n5', 'rejected', null],
                ['e4', 'n3', 'n4', null, ['field' => 'amount', 'op' => '>', 'value' => '5000']],
                ['e5', 'n3', 'n5', null, ['field' => 'amount', 'op' => '<', 'value' => '5000']],
                ['e6', 'n4', 'n5', null, null],
            ],
        );
    }

    /** Deliberately broken: an unconfigured approver, an orphan, a dead end. */
    public static function brokenGraph(): array
    {
        return static::graph(
            nodes: [
                ['n1', 'start', [], null],
                ['n2', 'approval', [], null],
                ['n3', 'notify', ['channel' => 'mail', 'recipient' => 'ops@example.com'], 'ops@example.com'],
                ['n4', 'notify', ['channel' => 'sms', 'recipient' => '+15550100'], '+15550100'],
                ['n5', 'end', [], null],
            ],
            edges: [
                ['e1', 'n1', 'n2', null, null],
                ['e2', 'n2', 'n3', 'approved', null],
            ],
        );
    }

    /** Wide enough that the minimap has something to frame. */
    public static function wideGraph(): array
    {
        return static::graph(
            nodes: [
                ['n1', 'start', [], null],
                ['n2', 'approval', ['approver' => 'line_manager', 'escalate_after' => 24, 'notify_requester' => true], 'Line manager'],
                ['n3', 'approval', ['approver' => 'department_head', 'notify_requester' => true], 'Department head'],
                ['n4', 'approval', ['approver' => 'finance', 'notify_requester' => false], 'Finance'],
                ['n5', 'wait', ['hours' => 48], '48 hours'],
                ['n6', 'notify', ['channel' => 'slack', 'recipient' => '#approvals'], '#approvals'],
                ['n7', 'end', [], null],
            ],
            edges: [
                ['e1', 'n1', 'n2', null, null],
                ['e2', 'n2', 'n3', 'approved', null],
                ['e3', 'n2', 'n5', 'rejected', null],
                ['e4', 'n3', 'n4', 'approved', null],
                ['e5', 'n3', 'n6', 'rejected', null],
                ['e6', 'n4', 'n6', 'approved', null],
                ['e7', 'n4', 'n7', 'rejected', null],
                ['e8', 'n5', 'n6', null, null],
                ['e9', 'n6', 'n7', null, null],
            ],
        );
    }

    protected static function graph(array $nodes, array $edges): array
    {
        return [
            'nodes' => array_map(static fn (array $n): array => [
                'id' => $n[0],
                'type' => $n[1],
                'config' => $n[2],
                'summary' => $n[3],
                // A node with no position asks the canvas to lay the graph out
                // on first paint — the same tidy-up the toolbar button runs,
                // against measured card sizes rather than guessed ones.
                'position' => null,
            ], $nodes),
            'edges' => array_map(static fn (array $e): array => [
                'id' => $e[0],
                'source' => $e[1],
                'target' => $e[2],
                'outcome' => $e[3],
                'condition' => $e[4],
            ], $edges),
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
        ];
    }
}
