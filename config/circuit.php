<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Size
    |--------------------------------------------------------------------------
    |
    | Where a canvas starts, not where it has to stay: unless `resizable` is
    | turned off, the viewer drags its bottom edge and that choice is remembered
    | per field. `min` and `max` bound how far the drag may go.
    |
    */

    'height' => [
        'canvas' => 560,
        'entry' => 480,
        'min' => 240,
        'max' => 2400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Direction
    |--------------------------------------------------------------------------
    |
    | 'vertical' reads top-down, 'horizontal' left-to-right. This is the
    | starting direction; with the `orientable` tool on, an editable canvas
    | offers a toolbar toggle for it too.
    |
    */

    'direction' => 'vertical',

    /*
    |--------------------------------------------------------------------------
    | Tools
    |--------------------------------------------------------------------------
    |
    | Which affordances a canvas offers. Each is a default that any individual
    | field overrides with the method of the same name — ->resizable(false),
    | ->undoable(false), and so on. Turning one off hides its toolbar button
    | AND disables the shortcut behind it, so nothing is reachable that the
    | interface does not show.
    |
    */

    'tools' => [
        'resizable' => true,
        'orientable' => true,
        'undoable' => true,
        'tidyable' => true,
        'zoomable' => true,
        'minimap' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Minimap
    |--------------------------------------------------------------------------
    |
    | How big the minimap in the corner is, in pixels. Whether it appears at
    | all is `tools.minimap` above; this is only its size. The graph is scaled
    | to fit inside, so a wider one shows the same thing in more detail rather
    | than more of it.
    |
    */

    'minimap' => [
        'width' => 160,
        'height' => 110,
    ],

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | How many graph states undo keeps. Deep enough to walk back out of a bad
    | idea, shallow enough that a long editing session does not hoard
    | serialised graphs.
    |
    */

    'history_limit' => 50,

    /*
    |--------------------------------------------------------------------------
    | Grid
    |--------------------------------------------------------------------------
    |
    | Dragged nodes snap to this grid, and tidy-up lays out against it.
    |
    */

    'grid' => [
        'snap' => true,
        'size' => 16,
    ],

];
