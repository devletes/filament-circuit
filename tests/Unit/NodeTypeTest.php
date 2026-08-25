<?php

namespace Devletes\Circuit\Tests\Unit;

use Devletes\Circuit\Support\NodeType;
use Devletes\Circuit\Tests\TestCase;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;

class NodeTypeTest extends TestCase
{
    public function test_the_label_falls_back_to_a_headline_of_the_name(): void
    {
        $this->assertSame('Field Update', NodeType::make('field_update')->getLabel());
        $this->assertSame('Escalate', NodeType::make('escalate')->label('Escalate')->getLabel());
    }

    public function test_an_entry_point_accepts_no_incoming_connections(): void
    {
        $type = NodeType::make('start')->maxIncoming(4)->initial();

        $this->assertTrue($type->isInitial());
        $this->assertSame(0, $type->getMaxIncoming());
    }

    public function test_an_exit_point_emits_no_outgoing_connections(): void
    {
        $type = NodeType::make('end')->maxOutgoing(4)->terminal();

        $this->assertTrue($type->isTerminal());
        $this->assertSame(0, $type->getMaxOutgoing());
    }

    public function test_the_schema_is_resolved_lazily(): void
    {
        $calls = 0;

        $type = NodeType::make('task')->schema(function () use (&$calls): array {
            $calls++;

            return [TextInput::make('title')];
        });

        $this->assertSame(0, $calls, 'the closure must not run until the schema is asked for');
        $this->assertCount(1, $type->getSchema());
        $this->assertSame(1, $calls);
    }

    public function test_a_type_with_no_fields_reports_itself_as_unconfigurable(): void
    {
        $this->assertFalse(NodeType::make('start')->toArray()['configurable']);
        $this->assertTrue(NodeType::make('task')->schema([TextInput::make('title')])->toArray()['configurable']);
    }

    public function test_the_client_payload_carries_what_alpine_needs(): void
    {
        $array = NodeType::make('approval')
            ->color('primary')
            ->description('Must approve to continue')
            ->outcomes(['approved' => 'Approved'])
            ->toArray();

        $this->assertSame('approval', $array['name']);
        $this->assertSame('Approval', $array['label']);
        $this->assertSame('primary', $array['color']);
        $this->assertSame('Must approve to continue', $array['description']);

        // An empty map has to serialise as {} so the client can key into it.
        $this->assertIsObject($array['outcomes']);
        $this->assertSame('{}', json_encode(NodeType::make('plain')->toArray()['outcomes']));
    }

    public function test_it_summarises_a_config_only_when_told_how(): void
    {
        $this->assertNull(NodeType::make('task')->summarise(['title' => 'Sign']));

        $type = NodeType::make('task')->summariseUsing(fn (array $config): ?string => $config['title'] ?? null);

        $this->assertSame('Sign', $type->summarise(['title' => 'Sign']));
        $this->assertNull($type->summarise([]));
    }

    public function test_an_infolist_is_only_advertised_when_one_is_declared(): void
    {
        $this->assertFalse(NodeType::make('task')->hasInfolist());
        $this->assertSame([], NodeType::make('task')->getInfolist());

        $type = NodeType::make('task')->infolist(fn (array $config): array => [
            TextEntry::make('title'),
        ]);

        $this->assertTrue($type->hasInfolist());
        $this->assertCount(1, $type->getInfolist(['title' => 'Sign']));
    }

    public function test_an_infolist_given_as_an_array_is_cloned_per_node(): void
    {
        $entry = TextEntry::make('title');
        $type = NodeType::make('task')->infolist([$entry]);

        $first = $type->getInfolist(['title' => 'One'])[0];
        $second = $type->getInfolist(['title' => 'Two'])[0];

        // Shared instances would end up bound to whichever node rendered last.
        $this->assertNotSame($entry, $first);
        $this->assertNotSame($first, $second);
    }

    public function test_config_validation_is_opt_in_and_drops_empty_messages(): void
    {
        $this->assertSame([], NodeType::make('task')->validateConfig([]));

        $type = NodeType::make('task')->validateConfigUsing(fn (array $config): array => [
            blank($config['title'] ?? null) ? 'A title is required.' : null,
            '',
        ]);

        $this->assertSame(['A title is required.'], $type->validateConfig([]));
        $this->assertSame([], $type->validateConfig(['title' => 'Sign']));
    }
}
