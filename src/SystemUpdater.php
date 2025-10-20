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
        $branch = $this->sanitizeBranch($this->branch);
        if ($branch === null) {
            return [
                'success' => false,
                'message' => 'Ungültiger Branch-Name für Updates.',
            ];
        }

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

        $gitResult = null;
        if ($this->gitAvailable() && $this->isGitRepository()) {
            $gitResult = $this->updateViaGit($branch, $remoteUrl);

            if ($gitResult['success']) {
                return $gitResult;
            }
        }

        $zipResult = $this->updateViaZipArchive($branch, $remoteUrl ?? $this->remoteUrl);

        if ($zipResult['success']) {
            if ($gitResult !== null && isset($gitResult['details'])) {
                $zipResult['details'] = array_merge($gitResult['details'], $zipResult['details'] ?? []);
            }

            return $zipResult;
        }

        if ($gitResult !== null) {
            $details = $gitResult['details'] ?? [];
            if (isset($zipResult['details'])) {
                $details = array_merge($details, $zipResult['details']);
            }

            return [
                'success' => false,
                'message' => $zipResult['message'],
                'details' => $details,
            ];
        }

        return $zipResult;
    }

    private function updateViaGit(string $branch, ?string $remoteUrl): array
    {
        if (!$this->canRunShellCommands()) {
            return [
                'success' => false,
                'message' => 'Shell-Befehle sind auf dem Server deaktiviert. Fallback wird versucht.',
            ];
        }

        $hasOrigin = $this->remoteExists('origin');

        if ($remoteUrl === null && !$hasOrigin) {
            return [
                'success' => false,
                'message' => 'Es ist kein Remote-Repository konfiguriert. Fallback wird versucht.',
            ];
        }

        $commands = [];

        if ($remoteUrl !== null) {
            $remoteCommand = $hasOrigin
                ? sprintf('git remote set-url origin %s', escapeshellarg($remoteUrl))
                : sprintf('git remote add origin %s', escapeshellarg($remoteUrl));

            $commands[] = $remoteCommand;
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
                    'message' => 'Git-Update fehlgeschlagen. Fallback wird versucht.',
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

    private function updateViaZipArchive(string $branch, ?string $remoteUrl): array
    {
        if (!class_exists('ZipArchive')) {
            return [
                'success' => false,
                'message' => 'Die PHP-Extension "zip" ist nicht installiert. Bitte installieren Sie sie oder aktualisieren Sie manuell.',
            ];
        }

        $archiveUrl = $this->buildArchiveUrl($remoteUrl, $branch);

        if ($archiveUrl === null) {
            return [
                'success' => false,
                'message' => 'Es konnte keine gültige Download-URL für das Update ermittelt werden.',
            ];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'modpms_update_');
        if ($tmpFile === false) {
            return [
                'success' => false,
                'message' => 'Temporäre Datei für das Update konnte nicht erstellt werden.',
            ];
        }

        $downloadResult = $this->downloadToFile($archiveUrl, $tmpFile);

        if ($downloadResult['status'] !== 0) {
            @unlink($tmpFile);
            return [
                'success' => false,
                'message' => 'Das Update-Archiv konnte nicht heruntergeladen werden.',
                'details' => [['command' => 'download', 'status' => $downloadResult['status'], 'output' => [$downloadResult['message']]]],
            ];
        }

        $zip = new \ZipArchive();
        $zipOpen = $zip->open($tmpFile);

        if ($zipOpen !== true) {
            @unlink($tmpFile);
            return [
                'success' => false,
                'message' => 'Das Update-Archiv konnte nicht geöffnet werden.',
                'details' => [['command' => 'zip_open', 'status' => (int) $zipOpen, 'output' => []]],
            ];
        }

        $extractDir = sys_get_temp_dir() . '/modpms_extract_' . uniqid('', true);
        if (!@mkdir($extractDir, 0775, true)) {
            $zip->close();
            @unlink($tmpFile);
            return [
                'success' => false,
                'message' => 'Das Update konnte nicht vorbereitet werden (Ordneranlage fehlgeschlagen).',
            ];
        }

        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            @unlink($tmpFile);
            $this->removeDirectory($extractDir);

            return [
                'success' => false,
                'message' => 'Das Update-Archiv konnte nicht entpackt werden.',
            ];
        }

        $rootEntry = rtrim((string) $zip->getNameIndex(0), '/');
        $zip->close();
        @unlink($tmpFile);

        $sourceDir = $extractDir;
        if ($rootEntry !== '' && is_dir($extractDir . '/' . $rootEntry)) {
            $sourceDir = $extractDir . '/' . $rootEntry;
        }

        $copyResult = $this->copyDirectory($sourceDir, $this->projectRoot);

        $this->removeDirectory($extractDir);

        if (!$copyResult['success']) {
            return $copyResult;
        }

        return [
            'success' => true,
            'message' => 'Update erfolgreich durchgeführt.',
            'details' => [
                [
                    'command' => 'zip_update',
                    'status' => 0,
                    'output' => ['Archiv heruntergeladen von ' . $archiveUrl],
                ],
            ],
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

    private function buildArchiveUrl(?string $remoteUrl, string $branch): ?string
    {
        $source = $remoteUrl ?? $this->remoteUrl;

        if ($source === null || $source === '') {
            return null;
        }

        if (preg_match('/^[\w.+-]+@[\w.-]+:([\w.-]+\/[^\s]+)(\.git)?$/', $source, $matches)) {
            $path = $matches[1];
        } else {
            $parsed = parse_url($source);
            if ($parsed === false || !isset($parsed['host'], $parsed['path'])) {
                return null;
            }

            if ($parsed['host'] !== 'github.com') {
                return null;
            }

            $path = ltrim($parsed['path'], '/');
        }

        $path = preg_replace('/\.git$/', '', $path);

        if ($path === null || $path === '') {
            return null;
        }

        if (substr_count($path, '/') < 1) {
            return null;
        }

        return sprintf('https://github.com/%s/archive/refs/heads/%s.zip', $path, rawurlencode($branch));
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

    /**
     * @return array{status:int,message:string}
     */
    private function downloadToFile(string $url, string $destination): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $fp = fopen($destination, 'wb');

            if ($fp === false) {
                return ['status' => 1, 'message' => 'Temporäre Datei konnte nicht geschrieben werden.'];
            }

            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_USERAGENT, 'ModPMS-Updater');

            $success = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);
            fclose($fp);

            if ($success === false || $httpCode >= 400) {
                return ['status' => 1, 'message' => $error !== '' ? $error : 'HTTP-Status: ' . $httpCode];
            }

            return ['status' => 0, 'message' => ''];
        }

        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'timeout' => 60,
                'header' => "User-Agent: ModPMS-Updater\r\n",
            ],
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            return ['status' => 1, 'message' => 'Download fehlgeschlagen (file_get_contents).'];
        }

        if (@file_put_contents($destination, $data) === false) {
            return ['status' => 1, 'message' => 'Temporäre Datei konnte nicht geschrieben werden.'];
        }

        return ['status' => 0, 'message' => ''];
    }

    private function copyDirectory(string $source, string $destination): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = $iterator->getSubPathName();

            if ($this->shouldSkipPath($relativePath)) {
                continue;
            }

            $targetPath = $destination . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath) && !@mkdir($targetPath, 0775, true)) {
                    return [
                        'success' => false,
                        'message' => 'Zielordner konnte nicht erstellt werden: ' . $targetPath,
                    ];
                }

                continue;
            }

            if (!is_dir(dirname($targetPath)) && !@mkdir(dirname($targetPath), 0775, true)) {
                return [
                    'success' => false,
                    'message' => 'Zielordner konnte nicht erstellt werden: ' . dirname($targetPath),
                ];
            }

            if (!@copy($item->getPathname(), $targetPath)) {
                return [
                    'success' => false,
                    'message' => 'Datei konnte nicht kopiert werden: ' . $targetPath,
                ];
            }
        }

        return ['success' => true];
    }

    private function shouldSkipPath(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', $relativePath);

        if ($normalized === '.git' || str_starts_with($normalized, '.git/')) {
            return true;
        }

        if ($normalized === 'config/database.php') {
            return true;
        }

        return false;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
