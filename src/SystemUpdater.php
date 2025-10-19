<?php

namespace ModPMS;

class SystemUpdater
{
    private string $projectRoot;
    private string $branch;

    public function __construct(string $projectRoot, string $branch = 'main')
    {
        $this->projectRoot = $projectRoot;
        $this->branch = $branch;
    }

    public function gitAvailable(): bool
    {
        $output = null;
        $result = null;
        @exec('which git', $output, $result);

        return $result === 0 && !empty($output);
    }

    public function performUpdate(): array
    {
        if (!$this->gitAvailable()) {
            return [
                'success' => false,
                'message' => 'Git ist auf dem Server nicht verfügbar. Bitte manuell aktualisieren.',
            ];
        }

        $branch = $this->sanitizeBranch($this->branch);
        if ($branch === null) {
            return [
                'success' => false,
                'message' => 'Ungültiger Branch-Name für Updates.',
            ];
        }

        $commands = [
            'git fetch --all',
            sprintf('git reset --hard origin/%s', $branch),
            sprintf('git pull origin %s', $branch),
        ];

        $output = [];
        foreach ($commands as $command) {
            $cmdOutput = [];
            $status = 0;
            exec(sprintf('cd %s && %s', escapeshellarg($this->projectRoot), $command), $cmdOutput, $status);
            $output[] = [
                'command' => $command,
                'status' => $status,
                'output' => $cmdOutput,
            ];

            if ($status !== 0) {
                return [
                    'success' => false,
                    'message' => 'Update fehlgeschlagen. Siehe Details.',
                    'details' => $output,
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Update erfolgreich durchgeführt.',
            'details' => $output,
        ];
    }

    private function sanitizeBranch(string $branch): ?string
    {
        if ($branch === '') {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9._\-\/]+$/', $branch)) {
            return null;
        }

        return $branch;
    }
}
