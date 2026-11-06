<?php

namespace App\Console\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\ServeCommand as LaravelServeCommand;

class ServeCommand extends LaravelServeCommand
{
    /**
     * @return list<string>
     */
    protected function serverCommand(): array
    {
        $command = parent::serverCommand();

        array_splice($command, 1, 0, [
            '-d', 'upload_max_filesize=20M',
            '-d', 'post_max_size=128M',
            '-d', 'max_file_uploads=20',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.validate_timestamps=0',
            '-d', 'opcache.file_update_protection=0',
            '-d', 'opcache.blacklist_filename='.$this->developmentCacheBlacklist(),
        ]);

        return $command;
    }

    private function developmentCacheBlacklist(): string
    {
        $paths = [];
        foreach (['app', 'bootstrap', 'config', 'routes', 'resources', 'public', 'tests'] as $directory) {
            $path = base_path($directory);
            $paths[] = str_replace('\\', '/', $path).'/';
            $resolved = realpath($path);
            if ($resolved !== false) {
                $paths[] = str_replace('\\', '/', $resolved).'/';
            }
        }
        foreach (['artisan', 'server.php'] as $file) {
            $paths[] = str_replace('\\', '/', base_path($file));
        }
        $blacklist = storage_path('framework/cache/opcache-development.blacklist');
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($blacklist));
        $files->replace($blacklist, implode("\n", array_unique($paths))."\n");

        return $blacklist;
    }
}
