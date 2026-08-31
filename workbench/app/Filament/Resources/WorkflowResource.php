<?php

namespace Workbench\App\Filament\Resources;

use Devletes\Circuit\Actions\NodeAction;
use Devletes\Circuit\Forms\Components\CircuitCanvas;
use Devletes\Circuit\Infolists\Components\CircuitEntry;
use Devletes\Circuit\Support\NodeRegistry;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\WorkflowResource\Pages\EditWorkflow;
use Workbench\App\Filament\Resources\WorkflowResource\Pages\ListWorkflows;
use Workbench\App\Filament\Resources\WorkflowResource\Pages\ViewWorkflow;
use Workbench\App\Models\Workflow;

class WorkflowResource extends Resource
{
    protected static ?string $model = Workflow::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-share';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')->required(),
                        TextInput::make('description'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                CircuitCanvas::make('graph')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->nodeTypes(app(NodeRegistry::class))
                    ->height(560)
                    ->edgeSchema(fn (): array => [
                        Select::make('field')
                            ->label('Field')
                            ->options(['amount' => 'Amount', 'department' => 'Department']),
                        Select::make('op')
                            ->label('Operator')
                            ->options(['>' => 'is over', '<' => 'is under', '=' => 'equals']),
                        TextInput::make('value')->label('Value'),
                    ])
                    ->edgeLabel(fn (array $edge): ?string => filled($edge['condition']['field'] ?? null)
                        ? trim(sprintf(
                            '%s %s %s',
                            $edge['condition']['field'],
                            match ($edge['condition']['op'] ?? null) {
                                '>' => 'over',
                                '<' => 'under',
                                default => 'is',
                            },
                            $edge['condition']['value'] ?? '',
                        ))
                        : null)
                    ->nodeActions([
                        CircuitCanvas::configureNodeAction(),
                        ActionGroup::make([
                            NodeAction::make('duplicate')
                                ->label('Duplicate node')
                                ->icon('heroicon-m-document-duplicate')
                                ->action(fn () => null),
                            CircuitCanvas::deleteNodeAction(),
                        ]),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('description')->placeholder('—'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                CircuitEntry::make('graph')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->nodeTypes(app(NodeRegistry::class))
                    ->height(480),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('description'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkflows::route('/'),
            'view' => ViewWorkflow::route('/{record}'),
            'edit' => EditWorkflow::route('/{record}/edit'),
        ];
    }
}
