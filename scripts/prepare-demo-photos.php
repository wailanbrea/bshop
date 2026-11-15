<?php

// Synthetic product pictures cropped only from the owner's supplied AI reference.
// Private fixtures: publish through the same authenticated upload UI as a shop user.
$source = 'E:/KEYLASHOP/Nueva carpeta/referencias/01_VENTA.png';
$info = getimagesize($source);
if ($info[0] !== 1536 || $info[1] !== 1024) {
    throw new RuntimeException('Reference image dimensions changed.');
}
$image = imagecreatefrompng($source);
$directory = dirname(__DIR__).'/.runtime/demo-product-photos-v2';
if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
    throw new RuntimeException('Private fixture directory unavailable.');
}
$crops = [
    'camiseta' => [314, 386, 166, 166], 'jeans' => [505, 386, 164, 166],
    'vestido' => [692, 386, 164, 166], 'tenis' => [880, 386, 162, 166],
    'bolso' => [314, 704, 166, 140], 'gafas' => [505, 704, 164, 140],
    'aretes' => [692, 704, 164, 140], 'perfume' => [880, 704, 162, 140],
];
foreach ($crops as $name => [$x, $y, $width, $height]) {
    // Exclude the mockup's heart glyph: it is UI chrome, not part of a product photo.
    $x += 4;
    $width -= 40;
    $file = $directory.'/'.$name.'.jpg';
    if (file_exists($file)) {
        echo 'Existing fixture preserved: '.$name."\n";

        continue;
    }
    $crop = imagecrop($image, compact('x', 'y', 'width', 'height'));
    if (! $crop || ! imagejpeg($crop, $file, 92)) {
        throw new RuntimeException('Crop failed.');
    }
    imagedestroy($crop);
    echo 'Prepared private demo picture: '.$name."\n";
}
imagedestroy($image);
