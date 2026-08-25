<?php

namespace Devletes\Circuit\Assets;

use Filament\Support\Assets\Css;

/**
 * Filament versions package assets by the package's installed version, which
 * never changes for a path repository — so edits to the stylesheet are served
 * from cache indefinitely during development. Hashing the file contents makes
 * the version track the file itself.
 */
class HashedCss extends Css
{
    public function getVersion(): string
    {
        $path = $this->getPath();

        if (filled($path) && ! $this->isRemote() && is_file($path)) {
            return md5_file($path) ?: parent::getVersion();
        }

        return parent::getVersion();
    }
}
