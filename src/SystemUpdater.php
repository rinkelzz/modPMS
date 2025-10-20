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

        if (!$this->isGitRepository()) {
            return [
                'success' => false,
                'message' => 'Das Installationsverzeichnis ist kein Git-Repository. Bitte klonen Sie das Projekt mit Git, bevor Sie den Updater verwenden.',
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

        $remoteUrl = null;
        if ($this->remoteUrl !== null && $this->remoteUrl !== '') {
            $remoteUrl = $this->sanitizeRemoteUrl($this->remoteUrl);

            if ($remoteUrl === null) {
                return [
                    'success' => false,
                    'message' => 'Ungültige Repository-URL für Updates.',
                ];
            }
        }

        $hasOrigin = $this->remoteExists('origin');

        if ($remoteUrl !== null) {
            $remoteCommand = $hasOrigin
                ? sprintf('git remote set-url origin %s', escapeshellarg($remoteUrl))
                : sprintf('git remote add origin %s', escapeshellarg($remoteUrl));

            $commands[] = $remoteCommand;
        } elseif (!$hasOrigin) {
            return [
                'success' => false,
                'message' => 'Es ist kein Remote-Repository konfiguriert. Bitte hinterlegen Sie eine Repository-URL in config/app.php.',
            ];
        }

        $commands = array_merge($commands, [
            'git fetch --prune origin',
            sprintf('git reset --hard %s', escapeshellarg('origin/' . $branch)),
            sprintf('git pull --ff-only origin %s', escapeshellarg($branch)),
        ]);

        $output = [];
        foreach ($commands as $command) {
            [$status, $cmdOutput] = $this->executeInProject($command);
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

    private function isGitRepository(): bool
    {
        $output = [];
        $status = 0;
        exec(sprintf('cd %s && git rev-parse --is-inside-work-tree 2>&1', escapeshellarg($this->projectRoot)), $output, $status);

        return $status === 0 && isset($output[0]) && trim($output[0]) === 'true';
    }

    private function remoteExists(string $remote): bool
    {
        $output = [];
        $status = 0;
        exec(sprintf('cd %s && git remote get-url %s 2>&1', escapeshellarg($this->projectRoot), escapeshellarg($remote)), $output, $status);

        return $status === 0;
    }

    /**
     * @return array{0:int,1:array<int,string>}
     */
    private function executeInProject(string $command): array
    {
        $cmdOutput = [];
        $status = 0;
        exec(sprintf('cd %s && %s 2>&1', escapeshellarg($this->projectRoot), $command), $cmdOutput, $status);

        return [$status, $cmdOutput];
    }
}
