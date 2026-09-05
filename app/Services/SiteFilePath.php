<?php

namespace App\Services;

class SiteFilePath
{
    public function normalize(string $path, bool $allowRoot = true): string
    {
        $path = trim($path, '/');

        if ($path === '') {
            if ($allowRoot) {
                return '.';
            }

            abort(422, 'Selecciona un archivo o carpeta.');
        }

        $parts = explode('/', $path);

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,127}$/', $part)) {
                abort(422, 'La ruta contiene caracteres o segmentos no permitidos.');
            }
        }

        return implode('/', $parts);
    }

    public function join(string $directory, string $name): string
    {
        $directory = $this->normalize($directory);
        $name = $this->normalize($name, false);

        return $directory === '.' ? $name : $directory.'/'.$name;
    }

    public function parent(string $path): string
    {
        $path = $this->normalize($path, false);
        $parent = dirname($path);

        return $parent === '.' ? '.' : $this->normalize($parent);
    }
}
