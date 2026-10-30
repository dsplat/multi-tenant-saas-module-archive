<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services;

use MultiTenantSaas\Modules\Archive\Exceptions\InvalidDocPathException;

/**
 * 档案路径规范化/校验（目录路径与文件名共用）。
 *
 * 规则（与需求 §4.3.2 对齐）：
 * - 去除首尾空白与重复 `/`；
 * - 拒绝绝对路径、`..`/`.` 段、控制字符、反斜杠、空段；
 * - 单段 ≤ max_segment_length，总长 ≤ max_total_length，层级 ≤ max_depth。
 */
final class DocPathNormalizer
{
    public function normalize(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw InvalidDocPathException::because('路径不能为空');
        }

        if (! mb_check_encoding($path, 'UTF-8')) {
            throw InvalidDocPathException::because('路径必须是合法 UTF-8');
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $path) === 1) {
            throw InvalidDocPathException::because('路径不得包含控制字符');
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            throw InvalidDocPathException::because('不允许绝对路径');
        }

        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw InvalidDocPathException::because('不允许盘符路径');
        }

        if (str_contains($path, '\\')) {
            throw InvalidDocPathException::because('路径分隔符只允许 /');
        }

        if (str_contains($path, '://')) {
            throw InvalidDocPathException::because('不允许 URL 形态的路径');
        }

        $path = rtrim($path, '/');

        if ($path === '') {
            throw InvalidDocPathException::because('路径不能为空');
        }

        $path = (string) preg_replace('#/{2,}#', '/', $path);

        $segments = explode('/', $path);
        $maxSegment = (int) config('archive.path.max_segment_length', 100);
        $maxDepth = (int) config('archive.path.max_depth', 8);

        if (count($segments) > $maxDepth) {
            throw InvalidDocPathException::because("目录层级超过上限（最多 {$maxDepth} 层）");
        }

        foreach ($segments as $index => $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                throw InvalidDocPathException::because('路径不得包含空段');
            }

            if ($segment === '.' || $segment === '..') {
                throw InvalidDocPathException::because('路径不得包含 . 或 .. 段');
            }

            if (mb_strlen($segment) > $maxSegment) {
                throw InvalidDocPathException::because("单层路径名超过上限（最多 {$maxSegment} 字符）");
            }

            $segments[$index] = $segment;
        }

        $normalized = implode('/', $segments);
        $maxTotal = (int) config('archive.path.max_total_length', 500);

        if (mb_strlen($normalized) > $maxTotal) {
            throw InvalidDocPathException::because("路径总长度超过上限（最多 {$maxTotal} 字符）");
        }

        return $normalized;
    }

    public function join(?string $parentPath, string $name): string
    {
        $name = $this->validateName($name);

        return $this->normalize($parentPath === null || $parentPath === '' ? $name : $parentPath . '/' . $name);
    }

    public function validateName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw InvalidDocPathException::because('名称不能为空');
        }

        if (! mb_check_encoding($name, 'UTF-8')) {
            throw InvalidDocPathException::because('名称必须是合法 UTF-8');
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw InvalidDocPathException::because('名称不得包含控制字符');
        }

        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw InvalidDocPathException::because('名称不得包含路径分隔符');
        }

        if ($name === '.' || $name === '..') {
            throw InvalidDocPathException::because('名称不得为 . 或 ..');
        }

        $maxSegment = (int) config('archive.path.max_segment_length', 100);

        if (mb_strlen($name) > $maxSegment) {
            throw InvalidDocPathException::because("名称超过上限（最多 {$maxSegment} 字符）");
        }

        return $name;
    }

    public function basename(string $path): string
    {
        $segments = explode('/', $this->normalize($path));

        return (string) end($segments);
    }

    public function depth(string $path): int
    {
        return count(explode('/', $this->normalize($path)));
    }

    /**
     * 计算目录移动后的新路径（包含子孙前缀替换）。
     */
    public function relocate(string $oldPath, string $newPath, string $candidate): string
    {
        if ($candidate === $oldPath) {
            return $newPath;
        }

        if (str_starts_with($candidate, $oldPath . '/')) {
            return $newPath . substr($candidate, strlen($oldPath));
        }

        return $candidate;
    }
}
