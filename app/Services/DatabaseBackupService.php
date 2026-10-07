<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    private const LOCAL_DISK = 'local';
    private const REMOTE_DISK = 's3';
    private const BACKUP_DIRECTORY = 'backups';

    public function __construct(private readonly AdminActionLogService $auditLogService)
    {
    }

    /**
     * @return array{status:string,message:string,filename:?string,uploaded_offsite:bool}
     */
    public function run(): array
    {
        if (Config::get('database.default') !== 'mysql') {
            $message = 'Sauvegarde ignorée : la connexion active n\'est pas MySQL.';
            Log::warning('database_backup.skipped', ['reason' => 'non_mysql_connection']);

            return $this->result('skipped', $message);
        }

        $filename = $this->buildFilename();

        $dumped = $this->dumpToDisk($filename);

        if (!$dumped) {
            $message = 'Échec de la sauvegarde : mysqldump a retourné une erreur.';
            Log::error('database_backup.failed', ['filename' => $filename]);

            $this->auditLogService->log(
                'database.backup_failed',
                'system',
                null,
                null,
                $message,
                ['filename' => $filename]
            );

            return $this->result('error', $message, $filename);
        }

        $uploadedOffsite = $this->uploadOffsite($filename);

        $prunedLocal = $this->pruneLocalBackups($this->retentionDays());
        $prunedRemote = $uploadedOffsite ? $this->pruneRemoteBackups($this->retentionDays()) : 0;

        $message = sprintf(
            'Sauvegarde créée (%s)%s. %d ancienne(s) sauvegarde(s) locale(s) supprimée(s)%s.',
            $filename,
            $uploadedOffsite ? ', copiée hors-site' : ' (copie hors-site non configurée)',
            $prunedLocal,
            $uploadedOffsite ? sprintf(', %d hors-site', $prunedRemote) : ''
        );

        $this->auditLogService->log(
            'database.backup_created',
            'system',
            null,
            null,
            null,
            [
                'filename' => $filename,
                'uploaded_offsite' => $uploadedOffsite,
                'pruned_local' => $prunedLocal,
                'pruned_remote' => $prunedRemote,
            ]
        );

        return $this->result('success', $message, $filename, $uploadedOffsite);
    }

    public function retentionDays(): int
    {
        return max(1, (int) env('BACKUP_RETENTION_DAYS', 14));
    }

    public function isOffsiteConfigured(): bool
    {
        return filled(env('AWS_ACCESS_KEY_ID')) && filled(env('AWS_BUCKET'));
    }

    public function pruneLocalBackups(int $retentionDays): int
    {
        return $this->pruneDisk(self::LOCAL_DISK, $retentionDays);
    }

    public function pruneRemoteBackups(int $retentionDays): int
    {
        if (!$this->isOffsiteConfigured()) {
            return 0;
        }

        return $this->pruneDisk(self::REMOTE_DISK, $retentionDays);
    }

    private function pruneDisk(string $disk, int $retentionDays): int
    {
        $cutoff = now()->subDays($retentionDays)->getTimestamp();
        $deleted = 0;

        foreach (Storage::disk($disk)->files(self::BACKUP_DIRECTORY) as $path) {
            if (Storage::disk($disk)->lastModified($path) < $cutoff) {
                Storage::disk($disk)->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function dumpToDisk(string $filename): bool
    {
        $connection = Config::get('database.connections.mysql');

        $localPath = Storage::disk(self::LOCAL_DISK)->path(self::BACKUP_DIRECTORY . '/' . $filename);
        @mkdir(dirname($localPath), 0755, true);

        $command = [
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--host=' . $connection['host'],
            '--port=' . $connection['port'],
            '--user=' . $connection['username'],
            $connection['database'],
        ];

        $process = new Process($command, env: ['MYSQL_PWD' => $connection['password']]);
        $process->setTimeout(600);

        $gzip = gzopen($localPath, 'wb9');
        if ($gzip === false) {
            return false;
        }

        try {
            $process->run(function (string $type, string $buffer) use ($gzip): void {
                if ($type === Process::OUT) {
                    gzwrite($gzip, $buffer);
                }
            });
        } finally {
            gzclose($gzip);
        }

        if (!$process->isSuccessful()) {
            @unlink($localPath);

            return false;
        }

        return true;
    }

    private function uploadOffsite(string $filename): bool
    {
        if (!$this->isOffsiteConfigured()) {
            return false;
        }

        $path = self::BACKUP_DIRECTORY . '/' . $filename;
        $stream = Storage::disk(self::LOCAL_DISK)->readStream($path);

        if ($stream === null) {
            return false;
        }

        return Storage::disk(self::REMOTE_DISK)->put($path, $stream);
    }

    private function buildFilename(): string
    {
        $database = Config::get('database.connections.mysql.database');

        return sprintf('sca-pointage-%s-%s.sql.gz', $database, now()->format('Y-m-d_His'));
    }

    /**
     * @return array{status:string,message:string,filename:?string,uploaded_offsite:bool}
     */
    private function result(string $status, string $message, ?string $filename = null, bool $uploadedOffsite = false): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'filename' => $filename,
            'uploaded_offsite' => $uploadedOffsite,
        ];
    }
}
