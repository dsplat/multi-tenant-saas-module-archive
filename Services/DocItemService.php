<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Access\DataScopeContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AuditLoggerContract;
use MultiTenantSaas\Modules\Archive\Contracts\DocProviderContract;
use MultiTenantSaas\Modules\Archive\Contracts\DocProviderRegistryContract;
use MultiTenantSaas\Modules\Archive\Dto\DocActor;
use MultiTenantSaas\Modules\Archive\Dto\DocDownload;
use MultiTenantSaas\Modules\Archive\Dto\DocFileContent;
use MultiTenantSaas\Modules\Archive\Dto\DocPreview;
use MultiTenantSaas\Modules\Archive\Dto\DocProviderFile;
use MultiTenantSaas\Modules\Archive\Dto\DocUploadResult;
use MultiTenantSaas\Modules\Archive\Events\ArchiveAutoArchived;
use MultiTenantSaas\Modules\Archive\Events\ArchiveDocumentDeleted;
use MultiTenantSaas\Modules\Archive\Events\ArchiveDocumentRevised;
use MultiTenantSaas\Modules\Archive\Events\ArchiveDocumentUploaded;
use MultiTenantSaas\Modules\Archive\Exceptions\ArchiveException;
use MultiTenantSaas\Modules\Archive\Exceptions\ArchiveProviderFailure;
use MultiTenantSaas\Modules\Archive\Exceptions\DocProviderException;
use MultiTenantSaas\Modules\Archive\Exceptions\InvalidDocPathException;
use MultiTenantSaas\Modules\Archive\Exceptions\UploadValidationException;
use MultiTenantSaas\Modules\Archive\Models\DocCatalog;
use MultiTenantSaas\Modules\Archive\Models\DocItem;
use MultiTenantSaas\Modules\Archive\Models\DocRef;
use Throwable;

/**
 * 文档元数据 / 正文代理服务。
 *
 * 正文永远先写入 provider，成功后才落 doc_items；落库失败会尝试删除
 * 本次新写入的 provider 文件并记录孤儿，避免元数据指向不存在的正文。
 */
final class DocItemService
{
    private const MAX_IDEMPOTENCY_KEY_LENGTH = 191;

    public function __construct(
        private readonly DocPathNormalizer $paths,
        private readonly DocProviderRegistryContract $providers,
        private readonly DocAccessService $access,
        private readonly DocCatalogService $catalogs,
        private readonly MarkdownSanitizer $sanitizer,
        private readonly AuditLoggerContract $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $tenantId, array $filters, DocActor $actor): LengthAwarePaginator
    {
        TenantContext::assertTenantId($tenantId);

        if (! $actor->canView) {
            throw ArchiveException::forbidden('document_view_denied', '无权查看档案');
        }

        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? config('archive.per_page', 20))));

        return $this->baseQuery($tenantId, $filters, $actor)
            ->with('catalog')
            ->orderByDesc('updated_at')
            ->orderByDesc('doc_item_id')
            ->paginate($perPage);
    }

    public function find(int $tenantId, int|string $docItemId, DocActor $actor, bool $requireView = true): DocItem
    {
        TenantContext::assertTenantId($tenantId);

        $query = DocItem::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($docItemId);

        if ($scope = DataScopeContext::ambient()) {
            $scope->applyTo($query);
        }

        $item = $query->first();

        if (! $item instanceof DocItem || $item->isDeleted()) {
            throw ArchiveException::notFound('文档不存在');
        }

        if ($requireView) {
            $this->access->assertCanView($item, $actor);
        } else {
            $this->assertProjectScope($item->project_id !== null ? (int) $item->project_id : null, $actor);
        }

        return $item;
    }

    /**
     * 上传新文档 / 同名新修订。
     *
     * @param  array<string, mixed>  $input
     */
    public function upload(int $tenantId, array $input, DocActor $actor): DocUploadResult
    {
        TenantContext::assertTenantId($tenantId);
        $this->access->assertCanUpload($actor);

        $catalog = $this->catalogs->find($tenantId, (int) ($input['catalog_id'] ?? 0), $actor);

        if ($catalog->isArchived()) {
            throw ArchiveException::conflict('catalog_archived', '目标目录已归档');
        }

        $projectId = $catalog->project_id !== null ? (int) $catalog->project_id : null;
        $this->assertProjectScope($projectId, $actor);

        $filename = $this->resolveFilename($input);
        $extension = $this->extensionOf($filename);

        if ($extension === '' || ! in_array($extension, (array) config('archive.upload.allowed_extensions', []), true)) {
            throw UploadValidationException::extension($extension === '' ? '(none)' : $extension);
        }

        $providerName = (string) ($catalog->provider ?: config('archive.default_provider', 'gitlab'));
        $provider = $this->provider($tenantId, $providerName);
        $capabilities = $this->providerCall(fn () => $provider->capabilities());

        $maxSize = (int) config('archive.upload.max_size', 50 * 1024 * 1024);
        $providerMax = (int) ($capabilities['max_file_size'] ?? 0);

        if ($providerMax > 0 && ($maxSize <= 0 || $providerMax < $maxSize)) {
            $maxSize = $providerMax;
        }

        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));

        if ($idempotencyKey !== '') {
            if (mb_strlen($idempotencyKey) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
                throw UploadValidationException::invalid('幂等键过长');
            }

            $replayed = $this->findByIdempotencyKey($tenantId, $idempotencyKey);

            if ($replayed instanceof DocItem) {
                $this->access->assertCanView($replayed, $actor);

                return new DocUploadResult($replayed, DocUploadResult::ACTION_REPLAYED, true);
            }
        }

        $source = $input['stream'] ?? $input['content'] ?? null;

        if ($source === null) {
            throw UploadValidationException::invalid('缺少文件内容');
        }

        [$tmp, $tmpPath] = $this->spool($source, $maxSize);
        $size = 0;
        $checksum = '';

        try {
            $meta = stream_get_meta_data($tmp);
            $tmpPath = (string) ($meta['uri'] ?? $tmpPath);

            rewind($tmp);
            $hash = hash_init('sha256');

            while (! feof($tmp)) {
                $chunk = fread($tmp, 8192);

                if ($chunk === false) {
                    break;
                }

                $size += strlen($chunk);
                hash_update($hash, $chunk);
            }

            $checksum = hash_final($hash);
            rewind($tmp);

            if ($size === 0) {
                throw UploadValidationException::invalid('文件内容为空');
            }

            if ($maxSize > 0 && $size > $maxSize) {
                throw UploadValidationException::tooLarge($size, $maxSize);
            }

            $expected = strtolower(trim((string) ($input['checksum_sha256'] ?? '')));

            if ($expected !== '') {
                if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
                    throw UploadValidationException::invalid('校验和格式非法');
                }

                if (! hash_equals($expected, $checksum)) {
                    throw UploadValidationException::checksum();
                }
            }

            $mimeType = $this->detectMimeType($tmpPath, $filename);
            $providerMimes = array_values(array_map('strval', (array) ($capabilities['allowed_mime_types'] ?? [])));

            if ($providerMimes !== [] && ! in_array($mimeType, $providerMimes, true)) {
                throw UploadValidationException::mime($mimeType);
            }

            if ($idempotencyKey !== '') {
                // 二次检查：spool 期间可能有并发请求写入同一幂等键。
                $replayed = $this->findByIdempotencyKey($tenantId, $idempotencyKey);

                if ($replayed instanceof DocItem) {
                    return new DocUploadResult($replayed, DocUploadResult::ACTION_REPLAYED, true);
                }
            }

            $strategy = (string) ($input['same_name_strategy'] ?? config('archive.upload.same_name_strategy', 'new_revision'));
            $existing = $this->findByProviderPath($tenantId, (int) $catalog->catalog_id, $catalog->path . '/' . $filename);

            if ($existing instanceof DocItem && $strategy === 'reject') {
                throw ArchiveException::conflict('duplicate_document', '同名文档已存在');
            }

            if ($existing instanceof DocItem && $strategy === 'new_revision') {
                return $this->reviseItem($tenantId, $existing, $catalog, $provider, $tmp, $filename, $mimeType, $size, $checksum, $input, $actor, $idempotencyKey);
            }

            if ($existing instanceof DocItem && $strategy === 'rename') {
                $filename = $this->availableFilename($tenantId, $catalog, $filename);
            }

            return $this->createItem($tenantId, $catalog, $provider, $tmp, $filename, $mimeType, $size, $checksum, $input, $actor, $idempotencyKey);
        } catch (DocProviderException $exception) {
            throw ArchiveProviderFailure::from($exception);
        } finally {
            fclose($tmp);
        }
    }

    /**
     * 系统自动归档（P0：同步写 provider，不监听其它模块事件）。
     *
     * @param  array<string, mixed>  $input
     */
    public function autoArchive(int $tenantId, array $input, DocActor $actor): DocUploadResult
    {
        TenantContext::assertTenantId($tenantId);
        $this->access->assertCanUpload($actor);

        $sourceType = trim((string) ($input['source_type'] ?? ''));
        $sourceId = trim((string) ($input['source_id'] ?? ''));
        $docType = trim((string) ($input['doc_type'] ?? 'other'));

        if ($sourceType === '' || mb_strlen($sourceType) > 50) {
            throw ArchiveException::unprocessable('source_type_invalid', '自动归档来源类型非法');
        }

        if ($sourceId === '' || mb_strlen($sourceId) > 100) {
            throw ArchiveException::unprocessable('source_id_invalid', '自动归档来源 ID 非法');
        }

        $projectId = isset($input['project_id']) && $input['project_id'] !== ''
            ? (int) $input['project_id']
            : null;
        $this->assertProjectScope($projectId, $actor);

        $content = $this->sourceToString($input['content'] ?? $input['stream'] ?? null);
        $checksum = hash('sha256', $content);
        $autoKey = $this->autoKey($sourceType, $sourceId, $docType);

        $existing = DocItem::query()
            ->where('tenant_id', $tenantId)
            ->where('metadata->auto_key', $autoKey)
            ->where('status', '!=', DocItem::STATUS_DELETED)
            ->first();

        $strategy = (string) ($input['strategy'] ?? config('archive.auto_archive.strategy', 'new_revision'));

        if ($existing instanceof DocItem) {
            $this->access->assertCanView($existing, $actor);

            if ($strategy === 'reject') {
                throw ArchiveException::conflict('auto_archive_duplicate', '自动归档记录已存在');
            }

            if ($strategy === 'skip' || hash_equals((string) $existing->checksum_sha256, $checksum)) {
                return new DocUploadResult($existing, DocUploadResult::ACTION_REPLAYED, true);
            }

            $filename = basename((string) ($existing->provider_path ?: ($docType . '.md')));
            $result = $this->upload($tenantId, [
                'catalog_id' => $existing->catalog_id,
                'filename' => $filename,
                'content' => $content,
                'title' => $input['title'] ?? $existing->title,
                'doc_type' => $docType,
                'source' => DocItem::SOURCE_SYSTEM,
                'visibility' => $input['visibility'] ?? $existing->visibility,
                'metadata' => array_merge((array) ($input['metadata'] ?? []), [
                    'auto_key' => $autoKey,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                ]),
            ], $actor);

            event(new ArchiveAutoArchived(
                $tenantId,
                $result->item->doc_item_id,
                $sourceType,
                $sourceId,
                $docType,
                (int) $result->item->current_revision,
                $result->action,
            ));

            return $result;
        }

        $catalog = $this->resolveAutoCatalog($tenantId, $projectId, $sourceType, $input, $actor);
        $filename = $this->resolveFilename([
            'filename' => $input['filename'] ?? ($docType . '-' . $sourceId . '.md'),
        ]);

        $result = $this->upload($tenantId, [
            'catalog_id' => $catalog->catalog_id,
            'filename' => $filename,
            'content' => $content,
            'title' => $input['title'] ?? ($docType . ' ' . $sourceId),
            'doc_type' => $docType,
            'source' => DocItem::SOURCE_SYSTEM,
            'visibility' => $input['visibility'] ?? DocItem::VISIBILITY_PROJECT,
            'idempotency_key' => $autoKey,
            'metadata' => array_merge((array) ($input['metadata'] ?? []), [
                'auto_key' => $autoKey,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]),
        ], $actor);

        event(new ArchiveAutoArchived(
            $tenantId,
            $result->item->doc_item_id,
            $sourceType,
            $sourceId,
            $docType,
            (int) $result->item->current_revision,
            $result->action,
        ));

        return $result;
    }

    public function download(int $tenantId, int|string $docItemId, DocActor $actor): DocDownload
    {
        $item = $this->find($tenantId, $docItemId, $actor);
        $this->access->assertCanDownload($item, $actor);

        $content = $this->fetchContent($item);
        $stream = $content->stream;
        $size = $content->size > 0 ? $content->size : (int) $item->size;
        $filename = $this->downloadFilename($item);

        return new DocDownload(
            stream: $stream,
            size: $size,
            mimeType: (string) ($content->mimeType ?: $item->mime_type),
            filename: $filename,
            checksumSha256: $content->checksumSha256 ?: $item->checksum_sha256,
            rangeSupported: false,
        );
    }

    public function preview(int $tenantId, int|string $docItemId, DocActor $actor): DocPreview
    {
        $item = $this->find($tenantId, $docItemId, $actor);
        $this->access->assertCanDownload($item, $actor);

        $content = $this->fetchContent($item);
        $mimeType = (string) ($content->mimeType ?: $item->mime_type);
        $size = $content->size > 0 ? $content->size : (int) $item->size;
        $filename = $this->downloadFilename($item);

        $markdownTypes = array_map('strval', (array) config('archive.preview.markdown_mime_types', []));
        $imageTypes = array_map('strval', (array) config('archive.preview.image_mime_types', []));

        if (in_array($mimeType, $markdownTypes, true)) {
            $max = (int) config('archive.preview.max_markdown_bytes', 2 * 1024 * 1024);

            if ($max > 0 && $size > $max) {
                $this->closeStream($content->stream);

                throw new ArchiveException('preview_too_large', 'Markdown 预览超过大小限制', 413);
            }

            [$raw, $truncated] = $this->readLimited($content->stream, $max > 0 ? $max : 2 * 1024 * 1024);
            $raw = $this->normalizeText($raw);

            if ($truncated) {
                $raw .= "\n\n[内容已截断]";
            }

            return new DocPreview(
                mode: DocPreview::MODE_HTML,
                contentType: 'text/html; charset=UTF-8',
                filename: $filename,
                html: $this->sanitizer->toHtml($raw),
                size: $size,
            );
        }

        if ($mimeType === 'application/pdf') {
            $max = (int) config('archive.preview.max_pdf_bytes', 20 * 1024 * 1024);

            if ($max > 0 && $size > $max) {
                $this->closeStream($content->stream);

                throw new ArchiveException('preview_too_large', 'PDF 预览超过大小限制', 413);
            }

            return new DocPreview(DocPreview::MODE_STREAM, 'application/pdf', $filename, $content->stream, null, $size);
        }

        if (in_array($mimeType, $imageTypes, true)) {
            $max = (int) config('archive.preview.max_image_bytes', 10 * 1024 * 1024);

            if ($max > 0 && $size > $max) {
                $this->closeStream($content->stream);

                throw new ArchiveException('preview_too_large', '图片预览超过大小限制', 413);
            }

            return new DocPreview(DocPreview::MODE_STREAM, $mimeType, $filename, $content->stream, null, $size);
        }

        $this->closeStream($content->stream);

        throw new ArchiveException('preview_unsupported', '该文件类型仅支持下载', 422);
    }

    public function delete(int $tenantId, int|string $docItemId, DocActor $actor, bool $force = false): DocItem
    {
        $item = $this->find($tenantId, $docItemId, $actor, false);
        $this->access->assertCanDelete($item, $actor, $force);

        $refCount = DocRef::query()
            ->where('tenant_id', $tenantId)
            ->where('doc_item_id', $item->doc_item_id)
            ->count();

        if ($refCount > 0 && ! $force) {
            throw ArchiveException::conflict('document_has_refs', '文档仍被业务对象引用，需确认强制删除');
        }

        if ((bool) $item->legal_hold) {
            throw ArchiveException::conflict('legal_hold', '文档处于法律保留状态，不能删除');
        }

        if (! $force && $item->retention_until !== null && $item->retention_until->isFuture()) {
            throw ArchiveException::conflict('retention_active', '文档仍在保留期内，不能删除');
        }

        $old = [
            'status' => $item->status,
            'refs' => $refCount,
        ];

        $item->status = DocItem::STATUS_DELETED;
        $item->metadata = array_merge((array) ($item->metadata ?? []), [
            'deleted_at' => now()->toIso8601String(),
            'deleted_by' => $actor->subjectId,
            'forced' => $force,
        ]);
        $item->save();

        event(new ArchiveDocumentDeleted(
            $tenantId,
            $item->doc_item_id,
            $item->status,
            $force,
        ));

        $this->audit->log('delete', 'doc_item', (int) $item->doc_item_id, $old, [
            'status' => $item->status,
            'forced' => $force,
            'refs' => $refCount,
        ]);

        return $item;
    }

    /**
     * 导出清单（P0 同步返回元数据；不打包正文）。
     *
     * @param  array<string, mixed>  $filters
     * @return array{generated_at: string, total: int, items: list<array<string, mixed>>}
     */
    public function exportManifest(int $tenantId, array $filters, DocActor $actor): array
    {
        TenantContext::assertTenantId($tenantId);
        $this->access->assertCanExport($actor);

        $items = $this->baseQuery($tenantId, $filters, $actor)
            ->with('catalog')
            ->orderBy('provider_path')
            ->orderBy('doc_item_id')
            ->get();

        $rows = [];

        foreach ($items as $item) {
            try {
                $this->access->assertCanView($item, $actor);
            } catch (ArchiveException) {
                continue;
            }

            $rows[] = [
                'doc_item_id' => $item->doc_item_id,
                'project_id' => $item->project_id,
                'catalog_id' => $item->catalog_id,
                'catalog_path' => $item->catalog?->path,
                'title' => $item->title,
                'doc_type' => $item->doc_type,
                'mime_type' => $item->mime_type,
                'size' => (int) $item->size,
                'checksum_sha256' => $item->checksum_sha256,
                'current_revision' => (int) $item->current_revision,
                'provider' => $item->provider,
                'provider_path' => $item->provider_path,
                'status' => $item->status,
                'updated_at' => $item->updated_at?->toIso8601String(),
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'total' => count($rows),
            'items' => $rows,
        ];
    }

    /**
     * AI 摘要：只抽取截断片段，不返回全文。
     *
     * @return array<string, mixed>
     */
    public function summary(int $tenantId, int|string $docItemId, DocActor $actor): array
    {
        $item = $this->find($tenantId, $docItemId, $actor);
        $this->access->assertCanDownload($item, $actor);

        $content = $this->fetchContent($item);
        $mimeType = (string) ($content->mimeType ?: $item->mime_type);
        $maxBytes = (int) config('archive.ai.summary_max_bytes', 64 * 1024);
        $maxChars = (int) config('archive.ai.summary_max_chars', 2000);
        $excerpt = '';
        $truncated = false;

        $textTypes = array_merge(
            array_map('strval', (array) config('archive.preview.markdown_mime_types', [])),
            ['text/csv', 'application/json'],
        );

        if (in_array($mimeType, $textTypes, true)) {
            [$excerpt, $truncated] = $this->readLimited($content->stream, $maxBytes);
            $excerpt = $this->normalizeText($excerpt);

            if (mb_strlen($excerpt) > $maxChars) {
                $excerpt = mb_substr($excerpt, 0, $maxChars);
                $truncated = true;
            }
        } else {
            $this->closeStream($content->stream);
        }

        return [
            'doc_item_id' => $item->doc_item_id,
            'title' => $item->title,
            'doc_type' => $item->doc_type,
            'mime_type' => $mimeType,
            'size' => (int) $item->size,
            'checksum_sha256' => $item->checksum_sha256,
            'excerpt' => $excerpt,
            'truncated' => $truncated,
            'note' => $excerpt === ''
                ? '该类型不提供文本摘要，请通过下载获取正文'
                : '仅返回截断片段，请勿当作全文引用',
        ];
    }

    /**
     * AI 元数据建议：只生成建议，不落库。
     *
     * @return array<string, mixed>
     */
    public function metadataSuggest(int $tenantId, array $input, DocActor $actor): array
    {
        TenantContext::assertTenantId($tenantId);
        $this->access->assertCanUpload($actor);

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw ArchiveException::unprocessable('title_required', '建议元数据至少需要标题');
        }

        $filename = trim((string) ($input['filename'] ?? ''));
        $extension = $filename !== '' ? $this->extensionOf($filename) : '';
        $docType = strtolower((string) ($input['doc_type'] ?? ''));

        if ($docType === '') {
            $docType = $this->guessDocType($title, $extension);
        }

        $catalogType = $this->guessCatalogType($title, $docType);
        $tags = [];

        foreach (['合同', '协议', '验收', '评审', '纪要', '预算', '报告', '方案', '变更', '交付'] as $keyword) {
            if (str_contains($title, $keyword)) {
                $tags[] = $keyword;
            }
        }

        return [
            'doc_type' => $docType,
            'visibility' => DocItem::VISIBILITY_PROJECT,
            'catalog_type' => $catalogType,
            'tags' => array_values(array_unique($tags)),
            'confidence' => $tags === [] ? 0.4 : 0.7,
            'rationale' => '依据标题关键词与扩展名生成的建议，需人工确认后使用',
            'suggestions_only' => true,
        ];
    }

    /**
     * @param  resource|string  $tmp
     * @param  array<string, mixed>  $input
     */
    private function createItem(
        int $tenantId,
        DocCatalog $catalog,
        DocProviderContract $provider,
        mixed $tmp,
        string $filename,
        string $mimeType,
        int $size,
        string $checksum,
        array $input,
        DocActor $actor,
        string $idempotencyKey,
    ): DocUploadResult {
        $path = $catalog->path . '/' . $filename;

        $this->assertProviderPathLength($provider, $path);
        $this->assertProjectScope($catalog->project_id !== null ? (int) $catalog->project_id : null, $actor);

        try {
            $file = $provider->putFile($path, $tmp, $this->providerMetadata($input, $actor, false));
        } catch (DocProviderException $exception) {
            throw ArchiveProviderFailure::from($exception);
        }

        try {
            $item = DB::transaction(fn (): DocItem => DocItem::query()->create([
                'tenant_id' => $tenantId,
                'project_id' => $catalog->project_id,
                'catalog_id' => $catalog->catalog_id,
                'title' => $this->resolveTitle($input, $filename),
                'doc_type' => (string) ($input['doc_type'] ?? $this->docTypeOf($filename)),
                'mime_type' => $mimeType,
                'size' => $file->size > 0 ? $file->size : $size,
                'checksum_sha256' => $this->normalizeChecksum($file->checksumSha256) ?? $checksum,
                'current_revision' => 1,
                'provider' => (string) $catalog->provider,
                'provider_file_ref' => $file->ref,
                'provider_commit' => $file->commit,
                'provider_path' => $file->path !== '' ? $file->path : $path,
                'uploaded_by' => $actor->subjectId,
                'source' => $this->resolveSource($input),
                'status' => DocItem::STATUS_ACTIVE,
                'visibility' => $this->resolveVisibility($input),
                'retention_until' => $this->resolveRetention($input),
                'legal_hold' => (bool) ($input['legal_hold'] ?? false),
                'metadata' => $this->buildMetadata($input, $filename, $mimeType, $idempotencyKey),
            ]));
        } catch (Throwable $exception) {
            $this->compensateFailedCreate($provider, $file, $tenantId);

            if ($exception instanceof ArchiveException) {
                throw $exception;
            }

            throw new ArchiveException(
                'upload_persist_failed',
                '文档元数据写入失败，正文补偿已执行',
                500,
                $exception,
            );
        }

        event(new ArchiveDocumentUploaded(
            $tenantId,
            $item->doc_item_id,
            $item->project_id !== null ? (int) $item->project_id : null,
            $item->catalog_id,
            1,
            (string) $item->checksum_sha256,
        ));

        $this->audit->log('upload', 'doc_item', (int) $item->doc_item_id, null, [
            'path' => $item->provider_path,
            'size' => $item->size,
            'checksum_sha256' => $item->checksum_sha256,
            'revision' => 1,
        ]);

        return new DocUploadResult($item, DocUploadResult::ACTION_CREATED);
    }

    /**
     * @param  resource|string  $tmp
     * @param  array<string, mixed>  $input
     */
    private function reviseItem(
        int $tenantId,
        DocItem $existing,
        DocCatalog $catalog,
        DocProviderContract $provider,
        mixed $tmp,
        string $filename,
        string $mimeType,
        int $size,
        string $checksum,
        array $input,
        DocActor $actor,
        string $idempotencyKey,
    ): DocUploadResult {
        $path = (string) $existing->provider_path;
        $oldRevision = (int) $existing->current_revision;
        $oldChecksum = (string) $existing->checksum_sha256;
        $newRevision = $oldRevision + 1;

        try {
            $file = $provider->putFile($path, $tmp, $this->providerMetadata($input, $actor, true));
        } catch (DocProviderException $exception) {
            throw ArchiveProviderFailure::from($exception);
        }

        try {
            $item = DB::transaction(function () use (
                $existing,
                $file,
                $mimeType,
                $size,
                $checksum,
                $newRevision,
                $oldRevision,
                $input,
                $idempotencyKey,
                $filename
            ): DocItem {
                $metadata = (array) ($existing->metadata ?? []);
                $history = (array) ($metadata['revisions'] ?? []);
                $history[] = [
                    'revision' => $oldRevision,
                    'checksum_sha256' => $existing->checksum_sha256,
                    'size' => (int) $existing->size,
                    'replaced_at' => now()->toIso8601String(),
                ];
                $metadata['revisions'] = array_slice($history, -20);

                $existing->mime_type = $mimeType;
                $existing->size = $file->size > 0 ? $file->size : $size;
                $existing->checksum_sha256 = $this->normalizeChecksum($file->checksumSha256) ?? $checksum;
                $existing->current_revision = $newRevision;
                $existing->provider_file_ref = $file->ref;
                $existing->provider_commit = $file->commit;
                $existing->provider_path = $file->path !== '' ? $file->path : $existing->provider_path;
                $existing->metadata = array_merge(
                    $metadata,
                    $this->buildMetadata($input, $filename, $mimeType, $idempotencyKey),
                );
                $existing->save();

                return $existing->refresh();
            });
        } catch (Throwable $exception) {
            // 修订已覆盖 provider 正文；删除会毁掉上一版，故只记录孤儿修订。
            Log::warning('archive.revision_orphan', [
                'tenant_id' => $tenantId,
                'doc_item_id' => $existing->doc_item_id,
                'provider_ref' => $file->ref,
                'provider_path' => $file->path,
                'message' => $exception->getMessage(),
            ]);

            if ($exception instanceof ArchiveException) {
                throw $exception;
            }

            throw new ArchiveException(
                'revision_persist_failed',
                '文档修订元数据写入失败，正文修订已保留为孤儿并记录',
                500,
                $exception,
            );
        }

        event(new ArchiveDocumentRevised(
            $tenantId,
            $item->doc_item_id,
            $newRevision,
            (string) $item->checksum_sha256,
            $item->provider_commit !== null ? (string) $item->provider_commit : null,
        ));

        $this->audit->log('revise', 'doc_item', (int) $item->doc_item_id, [
            'revision' => $oldRevision,
            'checksum_sha256' => $oldChecksum,
        ], [
            'revision' => $newRevision,
            'checksum_sha256' => $item->checksum_sha256,
        ]);

        return new DocUploadResult($item, DocUploadResult::ACTION_REVISED);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveAutoCatalog(
        int $tenantId,
        ?int $projectId,
        string $sourceType,
        array $input,
        DocActor $actor,
    ): DocCatalog {
        if (! empty($input['catalog_id'])) {
            return $this->catalogs->find($tenantId, (int) $input['catalog_id'], $actor);
        }

        $safeType = $this->safeSegment($sourceType, 'misc');
        $path = 'auto-archive/' . $safeType;

        $result = $this->catalogs->ensurePath(
            $tenantId,
            $projectId,
            $path,
            'auto',
            (string) config('archive.default_provider', 'gitlab'),
            ['auto_archive' => true],
            null,
            DocActor::system(),
        );

        return $result['catalog'];
    }

    private function fetchContent(DocItem $item): DocFileContent
    {
        if ($item->provider_file_ref === null || $item->provider_file_ref === '') {
            throw ArchiveException::notFound('文档正文不存在');
        }

        $provider = $this->provider((int) $item->tenant_id, (string) $item->provider);

        try {
            return $provider->getFile((string) $item->provider_file_ref);
        } catch (DocProviderException $exception) {
            throw ArchiveProviderFailure::from($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<DocItem>
     */
    private function baseQuery(int $tenantId, array $filters, DocActor $actor): Builder
    {
        $query = DocItem::query()->where('tenant_id', $tenantId);

        if ($scope = DataScopeContext::ambient()) {
            $scope->applyTo($query);
        }

        if (isset($filters['project_id']) && $filters['project_id'] !== '') {
            $query->where('project_id', (int) $filters['project_id']);
        }

        if (! empty($filters['catalog_id'])) {
            $query->where('catalog_id', (int) $filters['catalog_id']);
        }

        if (! empty($filters['doc_type'])) {
            $query->where('doc_type', (string) $filters['doc_type']);
        }

        if (! empty($filters['visibility'])) {
            $query->where('visibility', (string) $filters['visibility']);
        }

        if (! empty($filters['source'])) {
            $query->where('source', (string) $filters['source']);
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status === '') {
            $query->where('status', '!=', DocItem::STATUS_DELETED);
        } else {
            $query->where('status', $status);
        }

        if (! empty($filters['keyword'])) {
            $keyword = (string) $filters['keyword'];
            $query->where('title', 'like', '%' . $keyword . '%');
        }

        if ($actor->projectIds !== []) {
            $query->where(function ($builder) use ($actor): void {
                $builder->whereIn('project_id', $actor->projectIds)->orWhereNull('project_id');
            });
        }

        return $query;
    }

    private function findByIdempotencyKey(int $tenantId, string $key): ?DocItem
    {
        return DocItem::query()
            ->where('tenant_id', $tenantId)
            ->where('metadata->idempotency_key', $key)
            ->where('status', '!=', DocItem::STATUS_DELETED)
            ->first();
    }

    private function findByProviderPath(int $tenantId, int $catalogId, string $path): ?DocItem
    {
        return DocItem::query()
            ->where('tenant_id', $tenantId)
            ->where('catalog_id', $catalogId)
            ->where('provider_path', $path)
            ->where('status', '!=', DocItem::STATUS_DELETED)
            ->latest('doc_item_id')
            ->first();
    }

    private function availableFilename(int $tenantId, DocCatalog $catalog, string $filename): string
    {
        $extension = $this->extensionOf($filename);
        $stem = $extension === '' ? $filename : substr($filename, 0, -(strlen($extension) + 1));

        for ($index = 1; $index <= 50; $index++) {
            $candidate = $stem . ' (' . $index . ')' . ($extension !== '' ? '.' . $extension : '');
            $exists = DocItem::query()
                ->where('tenant_id', $tenantId)
                ->where('catalog_id', $catalog->catalog_id)
                ->where('provider_path', $catalog->path . '/' . $candidate)
                ->where('status', '!=', DocItem::STATUS_DELETED)
                ->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        throw ArchiveException::conflict('duplicate_document', '同名文档过多，无法自动重命名');
    }

    /**
     * @return array{0: resource, 1: string}
     */
    private function spool(mixed $source, int $maxSize): array
    {
        $tmp = tmpfile();

        if ($tmp === false) {
            throw UploadValidationException::invalid('无法创建临时文件');
        }

        $meta = stream_get_meta_data($tmp);
        $path = (string) ($meta['uri'] ?? '');
        $handle = null;
        $closeHandle = false;

        if (is_resource($source)) {
            $handle = $source;
        } elseif (is_string($source)) {
            if (is_file($source) && is_readable($source)) {
                $handle = fopen($source, 'rb');
                $closeHandle = true;
            } else {
                $handle = fopen('php://temp', 'rb+');
                $closeHandle = true;

                if ($handle !== false) {
                    fwrite($handle, $source);
                    rewind($handle);
                }
            }
        }

        if (! is_resource($handle)) {
            fclose($tmp);

            throw UploadValidationException::invalid('无法读取文件内容');
        }

        try {
            $written = 0;

            while (! feof($handle)) {
                $chunk = fread($handle, 8192);

                if ($chunk === false) {
                    break;
                }

                $written += strlen($chunk);

                if ($maxSize > 0 && $written > $maxSize) {
                    throw UploadValidationException::tooLarge($written, $maxSize);
                }

                if (fwrite($tmp, $chunk) === false) {
                    throw UploadValidationException::invalid('写入临时文件失败');
                }
            }

            rewind($tmp);
        } catch (Throwable $exception) {
            fclose($tmp);

            throw $exception;
        } finally {
            if ($closeHandle && is_resource($handle)) {
                fclose($handle);
            }
        }

        return [$tmp, $path];
    }

    private function detectMimeType(string $path, string $filename): string
    {
        $extension = $this->extensionOf($filename);
        $map = (array) config('archive.upload.mime_extension_map', []);
        $allowed = array_map('strval', (array) config('archive.upload.allowed_mime_types', []));
        $mapped = array_map('strval', (array) ($map[$extension] ?? []));

        $detected = 'application/octet-stream';

        if (class_exists(\finfo::class) && $path !== '') {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $result = $finfo->file($path);

            if (is_string($result) && $result !== '') {
                $detected = strtolower($result);
            }
        }

        if (! in_array($detected, $allowed, true)) {
            $generic = in_array($detected, ['application/octet-stream', 'application/x-ole-storage', 'application/cdfv2'], true);
            $textExtension = in_array($extension, ['md', 'markdown', 'txt', 'csv'], true);

            if ($textExtension && str_starts_with($detected, 'text/') && $mapped !== []) {
                // 文本类扩展名以扩展名为准：md 正文里出现 HTML 片段时 finfo 会报 text/html
                $detected = $mapped[0];
            } elseif ($generic && $mapped !== []) {
                $detected = $mapped[0];
            } elseif ($detected === 'text/plain' && $mapped !== [] && in_array('text/plain', $mapped, true)) {
                $detected = $mapped[0];
            } else {
                throw UploadValidationException::mime($detected);
            }
        } elseif ($mapped !== [] && ! in_array($detected, $mapped, true)
            && ! in_array($detected, ['text/plain', 'application/octet-stream'], true)) {
            throw UploadValidationException::mime($detected . ' (扩展名 ' . $extension . ')');
        }

        if ($mapped !== [] && in_array($detected, ['text/plain'], true) && $mapped[0] !== 'text/plain') {
            return $mapped[0];
        }

        return $detected;
    }

    private function resolveFilename(array $input): string
    {
        $filename = trim((string) ($input['filename'] ?? $input['original_name'] ?? ''));

        if ($filename === '') {
            throw UploadValidationException::invalid('缺少文件名');
        }

        try {
            return $this->paths->validateName($filename);
        } catch (InvalidDocPathException $exception) {
            throw UploadValidationException::invalid($exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveTitle(array $input, string $filename): string
    {
        $title = trim((string) ($input['title'] ?? $filename));
        $title = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $title);

        if ($title === '') {
            $title = $filename;
        }

        return mb_substr($title, 0, 255);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveVisibility(array $input): string
    {
        $visibility = (string) ($input['visibility'] ?? DocItem::VISIBILITY_PROJECT);

        if (! in_array($visibility, [
            DocItem::VISIBILITY_PROJECT,
            DocItem::VISIBILITY_MANAGERS,
            DocItem::VISIBILITY_APPROVERS,
            DocItem::VISIBILITY_PRIVATE,
        ], true)) {
            throw ArchiveException::unprocessable('visibility_invalid', '可见性取值非法');
        }

        return $visibility;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveSource(array $input): string
    {
        $source = (string) ($input['source'] ?? DocItem::SOURCE_MANUAL);

        if (! in_array($source, [DocItem::SOURCE_MANUAL, DocItem::SOURCE_SYSTEM, DocItem::SOURCE_IMPORT], true)) {
            throw ArchiveException::unprocessable('source_invalid', '来源取值非法');
        }

        return $source;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveRetention(array $input): ?Carbon
    {
        $value = $input['retention_until'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            throw ArchiveException::unprocessable('retention_invalid', '保留期格式非法');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildMetadata(array $input, string $filename, string $mimeType, string $idempotencyKey): array
    {
        $metadata = array_merge((array) ($input['metadata'] ?? []), [
            'original_filename' => $filename,
            'detected_mime_type' => $mimeType,
            'uploaded_at' => now()->toIso8601String(),
        ]);

        if ($idempotencyKey !== '') {
            $metadata['idempotency_key'] = $idempotencyKey;
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function providerMetadata(array $input, DocActor $actor, bool $overwrite): array
    {
        return [
            'message' => (string) ($input['commit_message'] ?? ($overwrite ? 'archive: new revision' : 'archive: upload')),
            'author' => $actor->subjectId !== null ? (string) $actor->subjectId : 'system',
            'overwrite' => $overwrite,
        ];
    }

    private function compensateFailedCreate(DocProviderContract $provider, DocProviderFile $file, int $tenantId): void
    {
        try {
            $provider->deleteFile($file->ref);
        } catch (Throwable $exception) {
            Log::warning('archive.upload_orphan', [
                'tenant_id' => $tenantId,
                'provider_ref' => $file->ref,
                'provider_path' => $file->path,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function assertProviderPathLength(DocProviderContract $provider, string $path): void
    {
        $capabilities = $this->providerCall(fn () => $provider->capabilities());
        $max = (int) ($capabilities['max_path_length'] ?? 0);

        if ($max > 0 && mb_strlen($path) > $max) {
            throw UploadValidationException::invalid('provider 路径长度超过限制');
        }
    }

    private function downloadFilename(DocItem $item): string
    {
        $path = (string) ($item->provider_path ?? '');

        if ($path !== '') {
            $basename = basename($path);

            if ($basename !== '' && $basename !== '.' && $basename !== '..') {
                return $basename;
            }
        }

        return (string) $item->title;
    }

    private function assertProjectScope(?int $projectId, DocActor $actor): void
    {
        if ($projectId !== null && $actor->projectIds !== []
            && ! in_array($projectId, $actor->projectIds, true)) {
            throw ArchiveException::forbidden('project_scope_denied', '项目不在当前执行范围内');
        }
    }

    private function provider(int $tenantId, string $name): DocProviderContract
    {
        try {
            return $this->providers->resolve($tenantId, $name);
        } catch (DocProviderException $exception) {
            throw ArchiveProviderFailure::from($exception);
        }
    }

    private function providerCall(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DocProviderException $exception) {
            throw ArchiveProviderFailure::from($exception);
        }
    }

    private function extensionOf(string $filename): string
    {
        return strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    }

    private function docTypeOf(string $filename): string
    {
        $extension = $this->extensionOf($filename);

        return $extension !== '' ? $extension : 'other';
    }

    private function normalizeChecksum(?string $checksum): ?string
    {
        if ($checksum === null || trim($checksum) === '') {
            return null;
        }

        return strtolower(trim($checksum));
    }

    private function normalizeText(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /**
     * @param  resource|string  $stream
     * @return array{0: string, 1: bool}
     */
    private function readLimited(mixed $stream, int $maxBytes): array
    {
        if (! is_resource($stream)) {
            $raw = (string) $stream;
            $truncated = strlen($raw) > $maxBytes;

            return [substr($raw, 0, $maxBytes), $truncated];
        }

        $raw = stream_get_contents($stream, $maxBytes + 1);

        if ($raw === false) {
            $raw = '';
        }

        $truncated = strlen($raw) > $maxBytes;
        $raw = substr($raw, 0, $maxBytes);

        return [$raw, $truncated];
    }

    private function closeStream(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    private function sourceToString(mixed $source): string
    {
        if (! is_string($source) && ! is_resource($source)) {
            throw UploadValidationException::invalid('缺少自动归档内容');
        }

        if (is_string($source)) {
            if (is_file($source) && is_readable($source)) {
                $content = file_get_contents($source);

                if ($content === false) {
                    throw UploadValidationException::invalid('无法读取自动归档内容');
                }

                return $content;
            }

            return $source;
        }

        $content = stream_get_contents($source);

        if ($content === false) {
            throw UploadValidationException::invalid('无法读取自动归档内容');
        }

        return $content;
    }

    private function autoKey(string $sourceType, string $sourceId, string $docType): string
    {
        $key = 'auto:' . $sourceType . ':' . $sourceId . ':' . $docType;

        return mb_strlen($key) > self::MAX_IDEMPOTENCY_KEY_LENGTH
            ? 'auto:' . hash('sha256', $key)
            : $key;
    }

    private function safeSegment(string $value, string $fallback): string
    {
        try {
            return $this->paths->validateName($value);
        } catch (InvalidDocPathException) {
            return $fallback;
        }
    }

    private function guessDocType(string $title, string $extension): string
    {
        if (str_contains($title, '合同') || str_contains($title, '协议')) {
            return 'contract';
        }

        if (str_contains($title, '验收') || str_contains($title, '评审')) {
            return 'acceptance';
        }

        if (str_contains($title, '纪要') || str_contains($title, '会议')) {
            return 'minutes';
        }

        if (str_contains($title, '报告')) {
            return 'report';
        }

        if (str_contains($title, '方案') || str_contains($title, '计划')) {
            return 'plan';
        }

        return $extension !== '' ? $extension : 'other';
    }

    private function guessCatalogType(string $title, string $docType): string
    {
        if ($docType === 'contract' || str_contains($title, '合同')) {
            return 'contract';
        }

        if (str_contains($title, '立项')) {
            return 'initiation';
        }

        if (str_contains($title, '结项') || str_contains($title, '验收')) {
            return 'closure';
        }

        if (str_contains($title, '变更')) {
            return 'change';
        }

        return 'other';
    }
}
