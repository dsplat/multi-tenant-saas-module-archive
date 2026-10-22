<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Contracts;

use MultiTenantSaas\Modules\Archive\Dto\DocFileContent;
use MultiTenantSaas\Modules\Archive\Dto\DocProviderFile;

/**
 * 文档正文 provider 契约。
 *
 * Archive 只保存目录/元数据/引用；正文的权威存储在 provider（P0 为 GitLab 文档仓）。
 * 实现方必须把厂商异常归一化为 DocProviderException，不得把含 token 的原始错误透出。
 */
interface DocProviderContract
{
    public function name(): string;

    /**
     * @return array{
     *     max_file_size: int,
     *     allowed_mime_types: list<string>,
     *     supports_versions: bool,
     *     supports_preview: bool,
     *     supports_move: bool,
     *     max_path_length: int
     * }
     */
    public function capabilities(): array;

    /**
     * 创建目录；已存在视为成功（幂等）。
     */
    public function createDirectory(string $path): void;

    /**
     * 写入文件（新修订或覆盖由调用方策略决定）。
     *
     * @param  resource|string  $stream
     * @param  array{message?: string, author?: string, branch?: string, overwrite?: bool}  $metadata
     */
    public function putFile(string $path, mixed $stream, array $metadata = []): DocProviderFile;

    public function getFile(string $fileRef): DocFileContent;

    /**
     * 删除文件；不存在视为成功（幂等）。
     */
    public function deleteFile(string $fileRef): void;

    public function moveFile(string $fileRef, string $newPath): DocProviderFile;

    /**
     * @return list<DocProviderFile>
     */
    public function listFiles(string $path): array;

    public function stat(string $fileRef): DocProviderFile;

    /**
     * 连通性/权限检查；不得返回或记录凭据。
     */
    public function healthCheck(): bool;
}
