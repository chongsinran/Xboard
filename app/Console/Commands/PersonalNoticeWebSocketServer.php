<?php

namespace App\Console\Commands;

use App\WebSocket\PersonalNoticeWorker;
use Illuminate\Console\Command;

class PersonalNoticeWebSocketServer extends Command
{
    protected $signature = 'personal-notice-ws
        {action=start : start | stop | restart | reload | status}
        {--d : Start in daemon mode}
        {--host=0.0.0.0 : Listen address}
        {--port=8077 : Listen port}';

    protected $description = 'Start the WebSocket server for realtime personal notices';

    public function handle(): void
    {
        global $argv;
        $action = $this->argument('action');

        $argv[1] = $action;
        if ($this->option('d')) {
            $argv[2] = '-d';
        }

        $worker = new PersonalNoticeWorker(
            (string) $this->option('host'),
            (int) $this->option('port'),
        );
        $worker->run();
    }
}
