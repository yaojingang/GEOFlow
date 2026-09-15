<?php

namespace App\Support\Site;

use Illuminate\View\FileViewFinder;
use InvalidArgumentException;

final class ManagedThemeViewFinder extends FileViewFinder
{
    public function __construct(private string $themeId, private string $root, private array $manifest, private FileViewFinder $original)
    {
        parent::__construct(app('files'), $original->getPaths());
        foreach ($original->getHints() as $namespace => $hints) {
            $this->addNamespace($namespace, $hints);
        }
    }

    public function baseFinder(): FileViewFinder
    {
        return $this->original instanceof self ? $this->original->baseFinder() : $this->original;
    }

    public function hasRoot(string $root): bool
    {
        return $this->root === $root;
    }

    public function find($name)
    {
        if (str_starts_with($name, 'theme.'.$this->themeId.'.') || str_starts_with($name, 'site.')) {
            $relative = 'resources/views/'.str_replace('.', '/', $name).'.blade.php';
            if (! isset($this->manifest[$relative])) {
                throw new InvalidArgumentException('View ['.$name.'] not present in this immutable revision.');
            }

            return $this->root.'/'.$relative;
        }

        return parent::find($name);
    }
}
