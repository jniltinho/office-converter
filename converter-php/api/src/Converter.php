<?php
declare(strict_types=1);

namespace App;

/**
 * Converter
 *
 * LibreOffice headless conversion + concurrency control.
 * (Logic originally ported from the Go version; now used by the Slim app.)
 */
class Converter
{
    private static ?int $maxConcurrent = null;
    private static ?int $timeoutSeconds = null;

    private static function getMaxConcurrent(): int
    {
        if (self::$maxConcurrent === null) {
            self::$maxConcurrent = max(1, (int)(getenv('OFFICE_MAX_CONCURRENT_CONVERSIONS') ?: '2'));
        }
        return self::$maxConcurrent;
    }

    private static function getTimeoutSeconds(): int
    {
        if (self::$timeoutSeconds === null) {
            $d = strtolower(trim(getenv('OFFICE_CONVERSION_TIMEOUT') ?: '60s'));
            if (preg_match('/^(\d+)(s|m|h)?$/', $d, $m)) {
                $val = (int)$m[1];
                $unit = $m[2] ?? 's';
                if ($unit === 'm') $val *= 60;
                elseif ($unit === 'h') $val *= 3600;
                self::$timeoutSeconds = $val;
            } else {
                self::$timeoutSeconds = 60;
            }
        }
        return self::$timeoutSeconds;
    }

    /**
     * Acquire one of N conversion slots using non-blocking flock.
     * Returns open file resource or null on timeout.
     */
    private static function acquireSlot(): mixed
    {
        $max = self::getMaxConcurrent();
        $start = microtime(true);
        $waitMax = 30.0;

        while (true) {
            for ($i = 0; $i < $max; $i++) {
                $path = sys_get_temp_dir() . "/.lo-slot-{$i}.lock";
                $fp = @fopen($path, 'c+');
                if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
                    return $fp;
                }
                if ($fp) {
                    @fclose($fp);
                }
            }
            if (microtime(true) - $start > $waitMax) {
                return null;
            }
            usleep(150 * 1000);
        }
    }

    private static function releaseSlot($fp): void
    {
        if (is_resource($fp)) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * Convert the source file to target format (xlsx or ods).
     * Returns absolute path to the generated file.
     * Throws \RuntimeException on failure.
     */
    public static function convertTo(string $src, string $outDir, string $toFormat): string
    {
        if ($toFormat !== 'xlsx' && $toFormat !== 'ods') {
            throw new \RuntimeException("unsupported target format: $toFormat (use xlsx or ods)");
        }

        $lock = self::acquireSlot();
        if ($lock === null) {
            throw new \RuntimeException("too many concurrent conversions");
        }

        $profile = sys_get_temp_dir() . '/lo-profile-' . bin2hex(random_bytes(8));
        if (!@mkdir($profile, 0700, true)) {
            self::releaseSlot($lock);
            throw new \RuntimeException("could not create temporary profile");
        }

        $timeout = self::getTimeoutSeconds();

        $cmd = [
            'timeout', '--foreground', '--signal=TERM', '--kill-after=5s', $timeout . 's',
            'soffice', '--headless',
            '--nologo',
            '--nofirststartwizard',
            '-env:UserInstallation=file://' . $profile,
            '--convert-to', $toFormat,
            '--outdir', $outDir,
            $src,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];

        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            self::rrmdir($profile);
            self::releaseSlot($lock);
            throw new \RuntimeException("failed to start soffice process");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);
        $combined = trim($stdout . "\n" . $stderr);

        self::rrmdir($profile);
        self::releaseSlot($lock);

        if ($exitCode !== 0) {
            throw new \RuntimeException("conversion failed (exit $exitCode): $combined");
        }

        $base = basename($src);
        $ext = pathinfo($base, PATHINFO_EXTENSION);
        $stem = $ext !== '' ? substr($base, 0, -strlen('.' . $ext)) : $base;
        $dst = rtrim($outDir, '/') . '/' . $stem . '.' . $toFormat;

        if (!is_file($dst)) {
            throw new \RuntimeException("expected $toFormat was not generated: $dst");
        }

        return $dst;
    }

    public static function convertXlsb(string $src, string $outDir): string
    {
        return self::convertTo($src, $outDir, 'xlsx');
    }
}
