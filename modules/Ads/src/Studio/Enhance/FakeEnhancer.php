<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Enhance;

/** A stand-in enhancer and upscaler for tests: finishes on the second check and returns a small JPEG. */
final class FakeEnhancer implements PhotoEnhancer, PhotoUpscaler
{
    /** @var list<string> */
    public array $started = [];

    /** @var array<string, int> */
    public array $checks = [];

    public bool $fail = false;

    public bool $isReady = true;

    public function ready(): bool
    {
        return $this->isReady;
    }

    public function start(string $bytes, string $name): string
    {
        $this->started[] = $name;

        return 'img-'.count($this->started);
    }

    public function twilight(string $ref): string
    {
        $this->started[] = 'dusk:'.$ref;

        return 'dusk-'.$ref;
    }

    public function status(string $ref): string
    {
        $this->checks[$ref] = ($this->checks[$ref] ?? 0) + 1;

        return $this->fail ? 'failed' : ($this->checks[$ref] >= 2 ? 'ready' : 'processing');
    }

    public function fetch(string $ref): string
    {
        return self::jpeg(1200, 800);
    }

    public ?string $bigger = null;

    public function upscale(string $publicUrl): ?string
    {
        return $this->bigger;
    }

    /**
     * @param  int<1, max>  $w
     * @param  int<1, max>  $h
     */
    public static function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($img, null, 80);

        return (string) ob_get_clean();
    }
}
