<?php

function isDefaultProductImage(?string $imageUrl): bool
{
    if ($imageUrl === null || $imageUrl === '') {
        return true;
    }

    return $imageUrl === 'default.png'
        || strpos($imageUrl, 'default.png') !== false;
}

function productImageDisplayPath(?string $imageUrl, string $basePath = '../'): string
{
    if (isDefaultProductImage($imageUrl)) {
        return $basePath . 'props/default.png';
    }

    $imageUrl = trim((string) $imageUrl);
    if (strpos($imageUrl, '../') === 0) {
        return $imageUrl;
    }
    if (strpos($imageUrl, 'props/') === 0) {
        return $basePath . $imageUrl;
    }

    return $basePath . 'products/' . basename($imageUrl);
}

function productImageHasCustomFile(?string $imageUrl): bool
{
    return !isDefaultProductImage($imageUrl);
}
