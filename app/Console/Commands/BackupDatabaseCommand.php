<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Sauvegarde la base MySQL (mysqldump compressé), applique la rétention locale et copie hors-site si configurée.';

    public function handle(DatabaseBackupService $backupService): int
    {
        $result = $backupService->run();

        $this->line($result['message']);

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
