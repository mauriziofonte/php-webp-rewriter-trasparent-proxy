<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

/**
 * The outcome of a WEBP conversion: the path of the written file, or the
 * reason of the failure. Immutable.
 */
final class WebpConversion
{
    /**
     * @var string|null
     */
    private $path;

    /**
     * @var string|null
     */
    private $error;

    private function __construct(?string $path, ?string $error)
    {
        $this->path = $path;
        $this->error = $error;
    }

    /**
     * @param string $path absolute path of the written WEBP file
     */
    public static function converted(string $path): self
    {
        return new self($path, null);
    }

    /**
     * @param string $error why the conversion failed, shown in the 500 response as in 2.x
     */
    public static function failed(string $error): self
    {
        return new self(null, $error);
    }

    public function isConverted(): bool
    {
        return $this->path !== null;
    }

    /**
     * The written file; null when the conversion failed.
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * Why the conversion failed; null when it succeeded.
     */
    public function getError(): ?string
    {
        return $this->error;
    }
}
