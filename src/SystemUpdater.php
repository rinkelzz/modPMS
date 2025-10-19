<?php

namespace ModPMS;

class SystemUpdater
{
    private string $projectRoot;
    private string $branch;
    private ?string $remoteUrl;

    public function __construct(string $projectRoot, string $branch = 'main', ?string $remoteUrl = null)
    {
        $this->projectRoot = $projectRoot;
        $this->branch = $branch;
        $this->remoteUrl = $remoteUrl;
    }

    public function gitAvailable(): bool
    {
        if (!$this->canRunShellCommands()) {
            return false;
        }

        $output = null;
        $result = null;
        @exec('which git', $output, $result);

        return $result === 0 && !empty($output);
    }

    public function performUpdate(): array
    {
        if (!$this->canRunShellCommands()) {
            return [
                'success' => false,
                'message' => 'Shell-Befehle sind auf dem Server deaktiviert. Bitte aktualisieren Sie manuell.',
            ];
        }

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

        $commands = [];

        if ($this->remoteUrl !== null && $this->remoteUrl !== '') {
            $remoteUrl = $this->sanitizeRemoteUrl($this->remoteUrl);

            if ($remoteUrl === null) {
                return [
                    'success' => false,
                    'message' => 'Ungültige Repository-URL für Updates.',
                ];
            }

            $commands[] = sprintf('git remote set-url origin %s', escapeshellarg($remoteUrl));
        }

        $commands = array_merge($commands, [
            'git fetch --all',
            sprintf('git reset --hard origin/%s', $branch),
            sprintf('git pull origin %s', $branch),
        ]);

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

    private function canRunShellCommands(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = ini_get('disable_functions');
        if ($disabled === false || $disabled === '') {
            return true;
        }

        $disabledFunctions = array_map('trim', explode(',', $disabled));

        return !in_array('exec', $disabledFunctions, true);
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

    private function sanitizeRemoteUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (preg_match('/^[\w.+-]+@[\w.-]+:[\w.\/-]+$/', $url)) {
            return $url;
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (!in_array($parts['scheme'], ['https', 'http', 'git'], true)) {
            return null;
        }

        return $url;
    }
}
