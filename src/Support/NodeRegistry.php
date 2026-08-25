<?php

namespace Devletes\Circuit\Support;

use FilesystemIterator;
use Illuminate\Container\Container;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Collects {@see NodeDefinition} classes and serves them to both sides of a
 * graph: `nodeTypes()` feeds the canvas, `get()` feeds whatever engine
 * interprets the saved nodes.
 *
 * Typically bound as a singleton with folder auto-discovery:
 *
 *     $this->app->singleton(NodeRegistry::class, fn (): NodeRegistry => (new NodeRegistry)
 *         ->discoverIn(app_path('Workflows/Nodes'), 'App\\Workflows\\Nodes'));
 */
class NodeRegistry
{
    /** @var array<string, NodeDefinition> keyed by type name */
    protected array $definitions = [];

    public static function make(): static
    {
        return new static;
    }

    /**
     * @param  NodeDefinition|class-string<NodeDefinition>  ...$definitions
     */
    public function register(NodeDefinition|string ...$definitions): static
    {
        foreach ($definitions as $definition) {
            if (is_string($definition)) {
                $definition = Container::getInstance()->make($definition);
            }

            $this->definitions[$definition::type()] = $definition;
        }

        return $this;
    }

    /**
     * Register every concrete NodeDefinition subclass found in a folder
     * (recursively). Filenames map to classes PSR-4 style under $namespace.
     * A missing folder is not an error — the registry just stays as it was.
     */
    public function discoverIn(string $directory, string $namespace): static
    {
        if (! is_dir($directory)) {
            return $this;
        }

        $namespace = rtrim($namespace, '\\');
        $directory = rtrim($directory, '/\\');

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($directory) + 1, -4);
            $classes[] = $namespace.'\\'.strtr($relative, ['/' => '\\', DIRECTORY_SEPARATOR => '\\']);
        }

        // Alphabetical before the sort()-based ordering, so discovery order
        // never depends on the filesystem.
        sort($classes);

        foreach ($classes as $class) {
            if (! class_exists($class)
                || ! is_subclass_of($class, NodeDefinition::class)
                || (new ReflectionClass($class))->isAbstract()
            ) {
                continue;
            }

            $this->register($class);
        }

        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function get(string $type): NodeDefinition
    {
        return $this->definitions[$type]
            ?? throw new InvalidArgumentException("No node definition registered for type [{$type}].");
    }

    /** @return array<string, NodeDefinition> keyed by type name, in sort() order */
    public function all(): array
    {
        $definitions = $this->definitions;

        uasort(
            $definitions,
            fn (NodeDefinition $a, NodeDefinition $b): int => [$a->sort(), $a::type()] <=> [$b->sort(), $b::type()],
        );

        return $definitions;
    }

    /** @return array<int, NodeType> in sort() order, ready for ->nodeTypes() */
    public function nodeTypes(): array
    {
        return array_values(array_map(
            fn (NodeDefinition $definition): NodeType => $definition->toNodeType(),
            $this->all(),
        ));
    }
}
