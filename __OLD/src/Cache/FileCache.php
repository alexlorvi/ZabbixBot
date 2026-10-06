<?php
declare(strict_types=1);

namespace ZbxBot\Cache;

/**
 * Простий файловий кеш: один файл на ключ, атомарний запис, блокування для update().
 * Значення зберігаються як JSON, тому тільки скаляри та масиви.
 */
class FileCache
{
    /** @var callable */
    private $clock;

    public function __construct(private string $dir, ?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
        if (!self::usable($this->dir)) {
            // Каталог кешу недоступний на запис (напр. var/ не створено і веб-серверу не можна його створити):
            // кеш має працювати, а не валити бота - переходимо на тимчасовий каталог.
            $fallback = sys_get_temp_dir().'/zbxbot-cache-'.substr(sha1($this->dir), 0, 8);
            if (!self::usable($fallback)) {
                throw new \RuntimeException("Cache dir {$this->dir} is not writable");
            }
            error_log("zbx-bot: cache dir {$this->dir} is not writable, using $fallback");
            $this->dir = $fallback;
        }
    }

    private static function usable(string $dir): bool
    {
        return (is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir)) && is_writable($dir);
    }

    public function now(): int
    {
        return (int)($this->clock)();
    }

    /** Значення, якщо воно не старше $ttl секунд; інакше null. PHP_INT_MAX - віддати навіть застаріле. */
    public function get(string $key, int $ttl): mixed
    {
        $rec = $this->read($this->path($key));
        if ($rec === null) {
            return null;
        }
        return ($this->now() - $rec['t']) <= $ttl ? $rec['v'] : null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->write($this->path($key), $value);
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    public function clear(): void
    {
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Прочитати-змінити-записати під ексклюзивним блокуванням.
     * $fn отримує поточне значення (або null) і повертає нове.
     */
    public function update(string $key, callable $fn): mixed
    {
        $path = $this->path($key);
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException("Cannot open lock for $key");
        }
        try {
            flock($lock, LOCK_EX);
            $rec = $this->read($path);
            $new = $fn($rec['v'] ?? null);
            $this->write($path, $new);
            return $new;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Прибрати файли, які не змінювались довше $maxAge секунд. */
    public function gc(int $maxAge): int
    {
        $removed = 0;
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            if (is_file($file) && $this->now() - (int)filemtime($file) > $maxAge) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    private function path(string $key): string
    {
        return $this->dir.'/'.sha1($key).'.json';
    }

    /** @return array{t:int,v:mixed}|null */
    private function read(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $rec = json_decode($raw, true);
        return (is_array($rec) && isset($rec['t']) && array_key_exists('v', $rec)) ? $rec : null;
    }

    private function write(string $path, mixed $value): void
    {
        $tmp = $path.'.'.getmypid().'.tmp';
        $json = json_encode(['t' => $this->now(), 'v' => $value], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !chmod($tmp, 0600) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write cache file $path");
        }
    }
}
