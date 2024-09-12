<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartTestAnalyzerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the test client';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Test Analyzer Command');
        $dynamic_name = 'garett1973.hopto.org';
        $port = 9999;

        $this->info('Connecting to ' . $dynamic_name . ' on port ' . $port);

        $counter = 0;
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $connection = @socket_connect($socket, $dynamic_name, $port);

        if ($connection) {
            while ($counter < 20) {
                $message = 'Test message ' . $counter;
                socket_write($socket, $message, strlen($message));
                $this->info('Sent: ' . $message);
                $resp = socket_read($socket, 1024);
                $this->info('Received: ' . $resp);
                $counter++;
                sleep(1);
            }
        }
    }
}
