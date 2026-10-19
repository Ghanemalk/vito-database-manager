<?php

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'VitoDbManager\\')) {
        $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen('VitoDbManager\\'))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

final class T
{
    public static int $pass = 0;

    /** @var list<string> */
    public static array $fail = [];

    public static function ok(string $label, bool $cond, mixed $detail = null): void
    {
        if ($cond) {
            self::$pass++;

            return;
        }
        $msg = $label.($detail !== null ? ' — '.(is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : '');
        self::$fail[] = $msg;
        fwrite(STDOUT, "  FAIL {$msg}\n");
    }

    public static function eq(string $label, mixed $expected, mixed $actual): void
    {
        self::ok($label, $expected === $actual, ['expected' => $expected, 'actual' => $actual]);
    }

    /**
     * Runs $fn and checks it throws a DbmException of $kind.
     */
    public static function throws(string $label, string $kind, callable $fn): ?\VitoDbManager\Support\DbmException
    {
        try {
            $fn();
        } catch (\VitoDbManager\Support\DbmException $e) {
            self::ok($label, $e->kind === $kind, ['kind' => $e->kind, 'message' => $e->getMessage()]);

            return $e;
        }
        self::ok($label, false, 'no exception');

        return null;
    }

    public static function done(string $name): int
    {
        $n = count(self::$fail);
        fwrite(STDOUT, sprintf("%s: %d passed, %d failed\n", $name, self::$pass, $n));

        return $n === 0 ? 0 : 1;
    }
}
