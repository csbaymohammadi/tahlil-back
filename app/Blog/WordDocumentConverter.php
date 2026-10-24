<?php

namespace App\Blog;

use App\Exceptions\PanelAuthException;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class WordDocumentConverter
{
    /** @return array{path: string, directory: string} */
    public function convert(string $source): array
    {
        $this->assertLegacyDoc($source);
        $directory = storage_path('framework/cache/word-import-'.Str::uuid());
        $inputDirectory = $directory.'/input';
        $outputDirectory = $directory.'/output';
        foreach ([$directory, $inputDirectory, $outputDirectory, $directory.'/home', $directory.'/tmp', $directory.'/profile'] as $path) {
            if (! mkdir($path, 0700, true) && ! is_dir($path)) {
                throw new PanelAuthException(503, 'WORD_IMPORT_UNAVAILABLE', 'The document conversion service is unavailable.');
            }
        }
        $input = $inputDirectory.'/source.doc';
        $inspectScript = $directory.'/inspect_doc.py';
        if (! copy($source, $input) || ! copy(config('blog.oletools_script'), $inspectScript)) {
            $this->cleanup($directory);
            throw new PanelAuthException(503, 'WORD_IMPORT_UNAVAILABLE', 'The document conversion service is unavailable.');
        }
        $python = $this->resolveBinary(config('blog.word_python'));
        $office = $this->resolveBinary(config('blog.libreoffice'));
        $sandbox = $this->resolveSandbox();
        if (! $python || ! $office || ! $sandbox) {
            $this->cleanup($directory);
            throw new PanelAuthException(503, 'DOC_CONVERTER_SANDBOX_UNAVAILABLE', 'Legacy DOC conversion requires configured Python, LibreOffice, and an operating-system sandbox.');
        }

        $inspection = $this->runSandbox($sandbox, [$python, $inspectScript, $input], $directory, 15, [$python, $office]);
        $inspectionResult = json_decode(trim($inspection['output']), true);
        if (($inspectionResult['reason'] ?? null) === 'oletools_unavailable') {
            $this->cleanup($directory);
            throw new PanelAuthException(503, 'OLETOOLS_UNAVAILABLE', 'Legacy DOC import requires the configured oletools Python package.');
        }
        if (! $inspection['successful']) {
            $this->cleanup($directory);
            throw new PanelAuthException(503, 'WORD_IMPORT_UNAVAILABLE', 'Legacy Word document inspection is unavailable.');
        }
        if (! is_array($inspectionResult) || ($inspectionResult['ok'] ?? false) !== true) {
            $this->cleanup($directory);
            throw new WordImportValidationException([[
                'code' => 'DOC_ACTIVE_OR_ENCRYPTED', 'field' => 'file', 'label' => 'Word file',
                'location' => 'Legacy DOC document', 'message' => 'فایل قدیمی DOC رمزگذاری‌شده است یا محتوای فعال و شیء پیوست دارد.',
            ]], ['file' => ['فایل DOC رمزگذاری‌شده یا دارای محتوای فعال قابل ورود نیست.']]);
        }

        $userProfile = 'file://'.$directory.'/profile';
        $conversion = $this->runSandbox($sandbox, [
            $office, '--headless', '-env:UserInstallation='.$userProfile,
            '--convert-to', 'docx', '--outdir', $outputDirectory, $input,
        ], $directory, config('blog.legacy_doc_timeout_seconds'), [$python, $office]);
        $converted = $outputDirectory.'/source.docx';
        if (! $conversion['successful'] || ! is_file($converted) || filesize($converted) < 1) {
            $this->cleanup($directory);
            throw new WordImportValidationException([[
                'code' => 'DOC_CONVERSION_FAILED', 'field' => 'file', 'label' => 'Word file',
                'location' => 'Legacy DOC conversion', 'message' => 'تبدیل فایل DOC انجام نشد؛ سند را در Word به DOCX ذخیره و دوباره ارسال کنید.',
            ]], ['file' => ['تبدیل فایل DOC به DOCX انجام نشد.']]);
        }

        return ['path' => $converted, 'directory' => $directory];
    }

    public function cleanup(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    /** @param list<string> $command
     * @param  list<string>  $allowedExecutables
     * @return array{successful: bool, output: string}
     */
    private function runSandbox(string $sandbox, array $command, string $workdir, int $timeout, array $allowedExecutables): array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $readPaths = ['/System', '/usr/lib', '/usr/share'];
            foreach ($allowedExecutables as $executable) {
                $readPaths[] = dirname($executable);
                $real = realpath($executable);
                if ($real) {
                    $readPaths[] = dirname($real);
                }
            }
            foreach ($allowedExecutables as $executable) {
                if (str_contains(strtolower(basename($executable)), 'python')) {
                    $readPaths = [...$readPaths, ...$this->pythonRuntimePaths($executable)];
                }
            }
            $readPaths = array_values(array_unique($readPaths));
            $reads = implode(' ', array_map(fn (string $path): string => '(subpath "'.$this->escapeProfilePath($path).'")', $readPaths));
            $profile = '(version 1)(deny default)(allow process*)(allow sysctl-read)(allow file-read* '.$reads.' (subpath "'.$this->escapeProfilePath($workdir).'") (subpath "'.$this->escapeProfilePath(base_path('resources/word')).'"))(allow file-write* (subpath "'.$this->escapeProfilePath($workdir).'"))(deny network*)';
            array_unshift($command, $sandbox, '-p', $profile);
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $args = [$sandbox, '--unshare-all', '--die-with-parent', '--new-session'];
            foreach (['/usr', '/bin', '/lib', '/lib64', '/opt'] as $systemPath) {
                if (is_dir($systemPath)) {
                    array_push($args, '--ro-bind', $systemPath, $systemPath);
                }
            }
            foreach (['/etc/fonts', '/etc/alternatives'] as $systemPath) {
                if (is_dir($systemPath)) {
                    array_push($args, '--ro-bind', $systemPath, $systemPath);
                }
            }
            array_push($args, '--dev', '/dev', '--proc', '/proc', '--bind', $workdir, '/work', '--chdir', '/work');
            $command = [...$args, ...array_map(static function (string $argument) use ($workdir): string {
                return str_starts_with($argument, $workdir) ? '/work'.substr($argument, strlen($workdir)) : $argument;
            }, $command)];
        } else {
            return ['successful' => false, 'output' => ''];
        }

        $pathDirectories = array_map(static fn (string $executable): string => dirname($executable), $allowedExecutables);
        $env = ['HOME' => $workdir.'/home', 'TMPDIR' => $workdir.'/tmp', 'PATH' => implode(':', array_unique([...$pathDirectories, '/usr/bin', '/bin']))];
        $process = new Process($command, $workdir, $env, timeout: $timeout);
        try {
            $process->run(function (string $type, string $output) use ($process): void {
                if (strlen($process->getOutput()) + strlen($process->getErrorOutput()) > 1_048_576) {
                    $process->stop(0);
                }
            });
        } catch (ProcessTimedOutException) {
            return ['successful' => false, 'output' => ''];
        } catch (Throwable) {
            return ['successful' => false, 'output' => ''];
        }

        return ['successful' => $process->isSuccessful(), 'output' => substr($process->getOutput(), 0, 1_048_576)];
    }

    private function resolveSandbox(): ?string
    {
        $name = PHP_OS_FAMILY === 'Darwin' ? config('blog.sandbox_exec') : config('blog.bwrap');

        return $this->resolveBinary($name);
    }

    private function resolveBinary(string $name): ?string
    {
        if (str_contains($name, '/') && is_executable($name)) {
            return realpath($name) ?: $name;
        }
        try {
            $process = new Process(['which', $name], timeout: 2);
            $process->run();
            $path = trim($process->getOutput());

            return $process->isSuccessful() && is_executable($path) ? (realpath($path) ?: $path) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function pythonRuntimePaths(string $python): array
    {
        try {
            $process = new Process([$python, '-c', 'import json,sys; print(json.dumps(sys.path))'], timeout: 3);
            $process->run();
            $paths = json_decode(trim($process->getOutput()), true);

            return $process->isSuccessful() && is_array($paths)
                ? array_values(array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== '' && is_dir($path)))
                : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function assertLegacyDoc(string $path): void
    {
        $signature = file_get_contents($path, false, null, 0, 8);
        if ($signature !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new WordImportValidationException([[
                'code' => 'WORD_SIGNATURE_INVALID', 'field' => 'file', 'label' => 'Word file',
                'location' => 'File signature', 'message' => 'پسوند و محتوای واقعی فایل Word با هم سازگار نیستند.',
            ]], ['file' => ['محتوای فایل با پسوند DOC سازگار نیست.']]);
        }
    }

    private function escapeProfilePath(string $path): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $path);
    }
}
