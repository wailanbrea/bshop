<?php

namespace App\Domain\Media;

use GdImage;

final class ImageProcessor
{
    public function inspect(string $path): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ImageRejected('unsupported_format', 'Usa JPEG, PNG o WebP. HEIC/HEIF, SVG y GIF no están admitidos.');
        }
        $info = @getimagesize($path);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || ($info['mime'] ?? null) !== $mime) {
            throw new ImageRejected('invalid_image', 'El contenido no es una imagen válida.');
        }
        [$width, $height] = $info;
        $max = (int) config('media.max_pixels');
        if ($width < 1 || $height < 1 || $width > config('media.max_dimension') || $height > config('media.max_dimension') || $width > intdiv($max, $height)) {
            throw new ImageRejected('pixel_limit', 'La imagen supera el límite de 20 MP o sus dimensiones no son seguras.');
        }
        $limit = $this->memoryLimit();
        if ($limit > 0 && memory_get_usage(true) + $width * $height * 10 + 33554432 > $limit) {
            throw new ImageRejected('memory_limit', 'La imagen requiere más memoria. Reduce sus dimensiones y vuelve a intentarlo.');
        }
        $this->rejectAnimation($path, $mime);
        $orientation = 1;
        if ($mime === 'image/jpeg') {
            if (! function_exists('exif_read_data')) {
                throw new ImageRejected('processor_unavailable', 'El servidor no tiene disponible el lector de orientación EXIF.');
            }
            $exif = @exif_read_data($path, 'IFD0', true, false);
            $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
        }

        return compact('mime', 'width', 'height', 'orientation');
    }

    public function process(string $path, string $directory): array
    {
        $info = $this->inspect($path);
        $image = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($path), 'image/png' => @imagecreatefrompng($path), 'image/webp' => @imagecreatefromwebp($path)
        };
        if (! $image instanceof GdImage) {
            throw new ImageRejected('decode_failed', 'No se pudo decodificar la imagen.');
        }
        $results = [];
        try {
            $image = $this->orient($image, $info['orientation']);
            $width = imagesx($image);
            $height = imagesy($image);
            if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
                throw new \RuntimeException('Private work directory unavailable.');
            }
            foreach (config('media.renditions') as $name => $size) {
                $scale = min(1, $size / max($width, $height));
                $w = max(1, (int) round($width * $scale));
                $h = max(1, (int) round($height * $scale));
                $target = imagecreatetruecolor($w, $h);
                try {
                    imagealphablending($target, false);
                    imagesavealpha($target, true);
                    imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
                    if (! imagecopyresampled($target, $image, 0, 0, 0, 0, $w, $h, $width, $height)) {
                        throw new \RuntimeException('Resample failed.');
                    }
                    $file = $directory.DIRECTORY_SEPARATOR.$name.'.webp';
                    $encoded = (function_exists('imagewebp') && @imagewebp($target, $file, config('media.quality')))
                        || @imagejpeg($target, $file, config('media.quality'));
                    if (! $encoded || ! is_file($file) || filesize($file) === 0) {
                        throw new \RuntimeException('WebP encoding failed.');
                    }
                    $results[$name] = ['path' => $file, 'width' => $w, 'height' => $h, 'size' => filesize($file), 'sha256' => hash_file('sha256', $file)];
                } finally {
                    imagedestroy($target);
                }
            }

            return ['mime' => $info['mime'], 'width' => $width, 'height' => $height, 'renditions' => $results];
        } finally {
            imagedestroy($image);
        }
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if ($orientation === 2 || $orientation === 4) {
            imageflip($image, $orientation === 2 ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);

            return $image;
        }
        $angle = match ($orientation) {
            3 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, imagecolorallocatealpha($image, 0, 0, 0, 127));
            if (! $rotated) {
                throw new \RuntimeException('Image orientation failed.');
            }
            imagedestroy($image);
            $image = $rotated;
            if ($orientation === 5 || $orientation === 7) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
        }

        return $image;
    }

    private function rejectAnimation(string $path, string $mime): void
    {
        if ($mime === 'image/jpeg') {
            return;
        }
        $bytes = file_get_contents($path);
        $length = strlen($bytes);
        for ($offset = $mime === 'image/png' ? 8 : 12; $offset + 8 <= $length;) {
            if ($mime === 'image/png') {
                $size = unpack('N', substr($bytes, $offset, 4))[1];
                $type = substr($bytes, $offset + 4, 4);
                $step = 12 + $size;
            } else {
                $type = substr($bytes, $offset, 4);
                $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
                $step = 8 + $size + ($size % 2);
            }
            if (in_array($type, ['acTL', 'ANIM', 'ANMF'], true)) {
                throw new ImageRejected('animated_image', 'Usa una fotografía estática; las imágenes animadas no están admitidas.');
            }
            if ($step < 8 || $step > $length - $offset) {
                break;
            }
            $offset += $step;
        }
    }

    private function memoryLimit(): int
    {
        $limit = trim(ini_get('memory_limit'));
        if ($limit === '-1') {
            return -1;
        }

        return (int) $limit * match (strtoupper(substr($limit, -1))) {
            'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1
        };
    }
}
