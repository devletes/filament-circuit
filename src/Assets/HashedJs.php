<?php

namespace Devletes\Circuit\Assets;

use Filament\Support\Assets\Js;

/** Content-hash asset versioning — see {@see HashedCss} for the why. */
class HashedJs extends Js
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
