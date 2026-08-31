<x-filament-panels::page>
    <style>
        /* The theming demo — every colour the canvas draws with resolves
           through one of these, so overriding them in a panel theme is the
           whole customisation story. */
        [data-demo='theming'] .fi-circuit {
            --fi-circuit-surface: #f5f3ff;
            --fi-circuit-border: #ddd6fe;
            --fi-circuit-grid: #e9d5ff;
            --fi-circuit-node-bg: #ffffff;
            --fi-circuit-text: #2e1065;
            --fi-circuit-muted: #7e69b8;
            --fi-circuit-edge: #a78bfa;
            --fi-circuit-accent: #7c3aed;
        }

        :where(.dark) [data-demo='theming'] .fi-circuit {
            --fi-circuit-surface: #1e1b4b;
            --fi-circuit-border: #3730a3;
            --fi-circuit-grid: #312e81;
            --fi-circuit-node-bg: #262262;
            --fi-circuit-text: #ede9fe;
            --fi-circuit-muted: #a5b4fc;
            --fi-circuit-edge: #818cf8;
            --fi-circuit-accent: #a78bfa;
        }
    </style>

    {{ $this->form }}

    <x-filament-actions::modals />
</x-filament-panels::page>
