<?php

namespace Rutgers\PerceptiveClient\Commands;

use Illuminate\Console\Command;

class PerceptiveClientCommand extends Command
{
    public $signature = 'laravel-perceptive-content-client';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
