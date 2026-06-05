<?php
declare(strict_types=1);

namespace App;

/**
 * Wraps LibreOffice headless conversion with concurrency control.
 *
 * Concurrency is bounded by OFFICE_MAX_CONCURRENT_CONVERSIONS (default 2).
 * Each slot is implemented as a non-blocking flock on a temp file, so
 * multiple PHP processes (FrankenPHP workers, php-fpm, etc.) share the same
 * pool automatically.
 *
 * Conversion timeout is controlled by OFFICE_CONVERSION_TIMEOUT (default 60s).
 * Accepts bare integers (seconds) or a duration string: 30s, 5m, 1h.
 */
class Converter
{
    private static ?int $maxConcurrent  = null;
    private static ?int $timeoutSeconds = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Convert $src to $toFormat, writing the result into $outDir.
     *
     * @param string $src      Absolute path to the source file.
     * @param string $outDir   Directory where LibreOffice writes the output.
     * @param string $toFormat Target format: 'xlsx' or 'ods'.
     * @return string Absolute path to the generated file.
     * @throws \RuntimeException On unsupported format, slot timeout, or conversion failure.
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

        // Each conversion gets its own UserInstallation profile so that
        // concurrent soffice processes do not share (and corrupt) state.
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

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes       = [];
        $proc        = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($proc)) {
            self::rrmdir($profile);
            self::releaseSlot($lock);
            throw new \RuntimeException("failed to start soffice process");
        }

        fclose($pipes[0]);
        $stdout   = stream_get_contents($pipes[1]);
        $stderr   = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);
        $combined = trim($stdout . "\n" . $stderr);

        self::rrmdir($profile);
        self::releaseSlot($lock);

        if ($exitCode !== 0) {
            throw new \RuntimeException("conversion failed (exit $exitCode): $combined");
        }

        $ext  = pathinfo($src, PATHINFO_EXTENSION);
        $stem = $ext !== '' ? substr(basename($src), 0, -(strlen($ext) + 1)) : basename($src);
        $dst  = rtrim($outDir, '/') . '/' . $stem . '.' . $toFormat;

        if (!is_file($dst)) {
            throw new \RuntimeException("expected $toFormat was not generated: $dst");
        }

        return $dst;
    }

    /**
     * Convenience wrapper: convert any file to XLSX (used for XLSB sources).
     */
    public static function convertXlsb(string $src, string $outDir): string
    {
        return self::convertTo($src, $outDir, 'xlsx');
    }

    /**
     * Recursively delete a directory and all its contents.
     */
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
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Concurrency control
    // -------------------------------------------------------------------------

    /**
     * Try to acquire one of the N conversion slots using non-blocking flock.
     *
     * Spins for up to 30 s (150 ms sleep per iteration) before giving up.
     * Returns an open file resource on success, or null on timeout.
     *
     * @return resource|null
     */
    private static function acquireSlot(): mixed
    {
        $max     = self::getMaxConcurrent();
        $start   = microtime(true);
        $waitMax = 30.0;

        while (true) {
            for ($i = 0; $i < $max; $i++) {
                $path = sys_get_temp_dir() . "/.lo-slot-{$i}.lock";
                $fp   = @fopen($path, 'c+');
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

            usleep(150_000);
        }
    }

    /** @param resource $fp */
    private static function releaseSlot($fp): void
    {
        if (is_resource($fp)) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    // -------------------------------------------------------------------------
    // Config (cached after first read)
    // -------------------------------------------------------------------------

    private static function getMaxConcurrent(): int
    {
        if (self::$maxConcurrent === null) {
            self::$maxConcurrent = max(1, (int)(getenv('OFFICE_MAX_CONCURRENT_CONVERSIONS') ?: '2'));
        }
        return self::$maxConcurrent;
    }

    private static function getTimeoutSeconds(): int
    {
        if (self::$timeoutSeconds !== null) {
            return self::$timeoutSeconds;
        }

        $raw = strtolower(trim(getenv('OFFICE_CONVERSION_TIMEOUT') ?: '60s'));

        if (preg_match('/^(\d+)(s|m|h)?$/', $raw, $m)) {
            $val  = (int) $m[1];
            $unit = $m[2] ?? 's';
            if ($unit === 'm') $val *= 60;
            elseif ($unit === 'h') $val *= 3600;
            self::$timeoutSeconds = $val;
        } else {
            self::$timeoutSeconds = 60;
        }

        return self::$timeoutSeconds;
    }
}
