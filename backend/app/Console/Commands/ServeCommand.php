<?php

namespace App\Console\Commands;

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
        ]);

        return $command;
    }
}
