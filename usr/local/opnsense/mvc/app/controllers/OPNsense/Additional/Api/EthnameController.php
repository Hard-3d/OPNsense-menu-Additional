<?php

namespace OPNsense\Additional\Api;

use OPNsense\Base\ApiControllerBase;

class EthnameController extends ApiControllerBase
{
    private const ETHNAME_PACKAGE = 'ethname';
    private const ETHNAME_FILE = '/etc/rc.conf.d/ethname';
    private const SYSHOOK_DIR = '/usr/local/etc/rc.syshook.d/early';
    private const SYSHOOK_FILE = '/usr/local/etc/rc.syshook.d/early/02-ethname';
    private const MAX_FILE_SIZE = 262144;

    private function runCommand(string $command, int &$exitCode = null): string
    {
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        return trim(implode("\n", $output));
    }

    private function isEthnameInstalled(): bool
    {
        $exitCode = 0;
        $this->runCommand('/usr/sbin/pkg info -e ' . escapeshellarg(self::ETHNAME_PACKAGE), $exitCode);
        return $exitCode === 0 || file_exists('/usr/local/etc/rc.d/ethname');
    }

    private function installEthnameIfNeeded(): array
    {
        $messages = [];
        $installedNow = false;

        if (!$this->isEthnameInstalled()) {
            $exitCode = 0;
            $output = $this->runCommand('/usr/sbin/pkg install -y ' . escapeshellarg(self::ETHNAME_PACKAGE), $exitCode);

            if ($exitCode !== 0) {
                return [
                    'ok' => false,
                    'installed_now' => false,
                    'message' => 'Не удалось установить пакет ethname',
                    'details' => $output
                ];
            }

            $installedNow = true;
            $messages[] = 'Пакет ethname установлен';
        }

        if (!is_dir(self::SYSHOOK_DIR) && !mkdir(self::SYSHOOK_DIR, 0755, true)) {
            return [
                'ok' => false,
                'installed_now' => $installedNow,
                'message' => 'Не удалось создать каталог: ' . self::SYSHOOK_DIR,
                'details' => ''
            ];
        }

        $syshookContent = "#!/bin/sh\n\n";
        $syshookContent .= "# Manual call to ethname \"service\" not enabled in /etc/rc.conf*:\n";
        $syshookContent .= "/usr/local/etc/rc.d/ethname onestart\n";

        $needWriteSyshook = true;
        if (file_exists(self::SYSHOOK_FILE)) {
            $currentSyshook = file_get_contents(self::SYSHOOK_FILE);
            if ($currentSyshook === $syshookContent) {
                $needWriteSyshook = false;
            }
        }

        if ($needWriteSyshook) {
            if (file_put_contents(self::SYSHOOK_FILE, $syshookContent, LOCK_EX) === false) {
                return [
                    'ok' => false,
                    'installed_now' => $installedNow,
                    'message' => 'Не удалось записать файл: ' . self::SYSHOOK_FILE,
                    'details' => ''
                ];
            }
            $messages[] = 'Создан или обновлён файл ' . self::SYSHOOK_FILE;
        }

        @chown(self::SYSHOOK_FILE, 'root');
        @chgrp(self::SYSHOOK_FILE, 'wheel');
        @chmod(self::SYSHOOK_FILE, 0755);

        if (!file_exists(self::ETHNAME_FILE)) {
            $ethnameContent = "ethname_enable=\"NO\"\n";
            $ethnameContent .= "ethname_timeout=30\n";

            if (file_put_contents(self::ETHNAME_FILE, $ethnameContent, LOCK_EX) === false) {
                return [
                    'ok' => false,
                    'installed_now' => $installedNow,
                    'message' => 'Не удалось создать файл: ' . self::ETHNAME_FILE,
                    'details' => ''
                ];
            }
            $messages[] = 'Создан файл ' . self::ETHNAME_FILE;
        }

        @chown(self::ETHNAME_FILE, 'root');
        @chgrp(self::ETHNAME_FILE, 'wheel');
        @chmod(self::ETHNAME_FILE, 0644);

        return [
            'ok' => true,
            'installed_now' => $installedNow,
            'message' => empty($messages) ? 'Ethname установлен и настроен' : implode('; ', $messages),
            'details' => ''
        ];
    }

    public function getAction()
    {
        $installResult = $this->installEthnameIfNeeded();

        if (!$installResult['ok']) {
            return [
                'status' => 'error',
                'message' => $installResult['message'],
                'details' => $installResult['details'],
                'content' => '',
                'path' => self::ETHNAME_FILE,
                'writable' => false,
                'installed_now' => $installResult['installed_now']
            ];
        }

        $path = self::ETHNAME_FILE;

        if (!file_exists($path)) {
            return [
                'status' => 'error',
                'message' => 'Файл не найден: ' . $path,
                'content' => '',
                'path' => $path,
                'writable' => false,
                'installed_now' => $installResult['installed_now']
            ];
        }

        if (!is_readable($path)) {
            return [
                'status' => 'error',
                'message' => 'Файл недоступен для чтения: ' . $path,
                'content' => '',
                'path' => $path,
                'writable' => is_writable($path),
                'installed_now' => $installResult['installed_now']
            ];
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return [
                'status' => 'error',
                'message' => 'Не удалось прочитать файл: ' . $path,
                'content' => '',
                'path' => $path,
                'writable' => is_writable($path),
                'installed_now' => $installResult['installed_now']
            ];
        }

        return [
            'status' => 'ok',
            'message' => $installResult['installed_now'] ? $installResult['message'] : 'Файл загружен',
            'content' => $content,
            'path' => $path,
            'writable' => is_writable($path),
            'size' => strlen($content),
            'mtime' => filemtime($path),
            'installed_now' => $installResult['installed_now']
        ];
    }

    public function setAction()
    {
        $installResult = $this->installEthnameIfNeeded();

        if (!$installResult['ok']) {
            return [
                'status' => 'error',
                'message' => $installResult['message'],
                'details' => $installResult['details']
            ];
        }

        $path = self::ETHNAME_FILE;
        $payload = $this->request->getJsonRawBody(true);
        $content = null;

        if (is_array($payload) && array_key_exists('content', $payload)) {
            $content = $payload['content'];
        } elseif ($this->request->hasPost('content')) {
            $content = $this->request->getPost('content');
        }

        if ($content === null) {
            return ['status' => 'error', 'message' => 'Не передано поле content'];
        }

        if (!is_string($content)) {
            return ['status' => 'error', 'message' => 'Некорректный тип content'];
        }

        if (strpos($content, "\0") !== false) {
            return ['status' => 'error', 'message' => 'Файл содержит недопустимый NULL-символ'];
        }

        if (strlen($content) > self::MAX_FILE_SIZE) {
            return ['status' => 'error', 'message' => 'Файл слишком большой. Максимум 256 KB'];
        }

        if (!file_exists($path)) {
            $created = touch($path);
            if (!$created) {
                return ['status' => 'error', 'message' => 'Не удалось создать файл: ' . $path];
            }
            chmod($path, 0644);
        }

        if (!is_writable($path)) {
            return ['status' => 'error', 'message' => 'Файл недоступен для записи: ' . $path];
        }

        $backupPath = sprintf('%s.backup.%s', $path, date('Ymd-His'));

        if (file_exists($path)) {
            $backupOk = copy($path, $backupPath);
            if (!$backupOk) {
                return ['status' => 'error', 'message' => 'Не удалось создать резервную копию: ' . $backupPath];
            }
            chmod($backupPath, 0644);
        }

        $written = file_put_contents($path, $content, LOCK_EX);

        if ($written === false) {
            return ['status' => 'error', 'message' => 'Не удалось записать файл: ' . $path];
        }

        @chown($path, 'root');
        @chgrp($path, 'wheel');
        @chmod($path, 0644);

        return [
            'status' => 'ok',
            'message' => 'Файл сохранён',
            'path' => $path,
            'backup' => $backupPath,
            'size' => $written
        ];
    }
}
