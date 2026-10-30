<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Archive\Dto\DocPreview;
use MultiTenantSaas\Modules\Archive\Http\Controllers\Concerns\ResolvesDocActor;
use MultiTenantSaas\Modules\Archive\Http\Resources\DocItemResource;
use MultiTenantSaas\Modules\Archive\Services\DocItemService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 档案文档：列表 / 上传 / 下载 / 预览 / 删除。
 *
 * 下载与预览都经服务端代理 provider 流，不向客户端暴露 token 或永久 URL；
 * provider 不支持 Range 时显式返回 416 + Accept-Ranges: none。
 */
class DocumentController extends BaseController
{
    use ResolvesDocActor;

    public function __construct(private readonly DocItemService $items) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer|min:1',
            'catalog_id' => 'nullable|integer|min:1',
            'doc_type' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:active,archived,deleted',
            'visibility' => 'nullable|string|in:project,managers,approvers,private',
            'source' => 'nullable|string|in:manual,system,import',
            'keyword' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $page = $this->items->paginate($this->tenantId(), $validated, $this->docActor(['view' => true]));

        return $this->successResponse([
            'data' => array_map(
                fn ($item): array => (new DocItemResource($item))->resolve(),
                $page->items(),
            ),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $item = $this->items->find($this->tenantId(), $id, $this->docActor(['view' => true]));

        return $this->successResponse((new DocItemResource($item))->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'catalog_id' => 'required|integer|min:1',
            'file' => 'required_without:content|file|max:51200',
            'content' => 'required_without:file|string',
            'filename' => 'required_without:file|nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'doc_type' => 'nullable|string|max:50',
            'visibility' => 'nullable|string|in:project,managers,approvers,private',
            'source' => 'nullable|string|in:manual,system,import',
            'idempotency_key' => 'nullable|string|max:191',
            'same_name_strategy' => 'nullable|string|in:new_revision,rename,reject',
            'checksum_sha256' => 'nullable|string|size:64',
            'retention_until' => 'nullable|date',
            'legal_hold' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $input = $validated;
        unset($input['file'], $input['content'], $input['filename']);

        $file = $request->file('file');

        if ($file instanceof UploadedFile) {
            $input['stream'] = (string) $file->getRealPath();
            $input['filename'] = $file->getClientOriginalName();
            $input['metadata'] = array_merge((array) ($validated['metadata'] ?? []), [
                'reported_mime_type' => $file->getClientMimeType(),
            ]);
        } else {
            $input['content'] = (string) ($validated['content'] ?? '');
            $input['filename'] = (string) ($validated['filename'] ?? '');
        }

        $result = $this->items->upload(
            $this->tenantId(),
            $input,
            $this->docActor(['view' => true, 'upload' => true]),
        );

        $payload = [
            'document' => (new DocItemResource($result->item))->resolve(),
            'action' => $result->action,
            'idempotent' => $result->idempotent,
        ];

        $message = match ($result->action) {
            'created' => '文档已上传',
            'revised' => '文档已生成新修订',
            default => '幂等重放：返回既有文档',
        };

        return $result->isCreated()
            ? $this->createdResponse($payload, $message)
            : $this->successResponse($payload, $message);
    }

    public function download(Request $request, string $id): StreamedResponse|Response
    {
        $download = $this->items->download(
            $this->tenantId(),
            $id,
            $this->docActor(['view' => true, 'download' => true]),
        );

        if ($request->headers->has('Range')) {
            return $this->rangeNotSupported($download->size);
        }

        return response()->streamDownload(
            function () use ($download): void {
                $this->output($download->stream);
            },
            $download->filename,
            [
                'Content-Type' => $download->mimeType,
                'Content-Length' => (string) $download->size,
                'Content-Disposition' => $this->disposition($download->filename, 'attachment'),
                'Accept-Ranges' => 'none',
                'X-Content-Type-Options' => 'nosniff',
                'X-Checksum-Sha256' => (string) $download->checksumSha256,
            ],
        );
    }

    public function preview(Request $request, string $id): Response|StreamedResponse
    {
        $preview = $this->items->preview(
            $this->tenantId(),
            $id,
            $this->docActor(['view' => true, 'download' => true]),
        );

        if ($preview->mode === DocPreview::MODE_HTML) {
            return response((string) $preview->html, 200, [
                'Content-Type' => $preview->contentType,
                'Content-Disposition' => $this->disposition($preview->filename, 'inline'),
                'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        if ($request->headers->has('Range')) {
            return $this->rangeNotSupported($preview->size);
        }

        return response()->stream(
            function () use ($preview): void {
                $this->output($preview->stream);
            },
            200,
            [
                'Content-Type' => $preview->contentType,
                'Content-Length' => (string) $preview->size,
                'Content-Disposition' => $this->disposition($preview->filename, 'inline'),
                'Accept-Ranges' => 'none',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $item = $this->items->delete(
            $this->tenantId(),
            $id,
            $this->docActor(['view' => true, 'delete' => true]),
            $request->boolean('force'),
        );

        return $this->successResponse((new DocItemResource($item))->resolve(), '文档已标记删除');
    }

    private function rangeNotSupported(int $size): Response
    {
        return response('', 416, [
            'Content-Range' => 'bytes */' . $size,
            'Accept-Ranges' => 'none',
        ]);
    }

    private function output(mixed $stream): void
    {
        if (is_resource($stream)) {
            fpassthru($stream);
            fclose($stream);

            return;
        }

        echo (string) $stream;
    }

    private function disposition(string $filename, string $type): string
    {
        $filename = str_replace(["\r", "\n", '"', '\\'], '', $filename);
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?: 'document';

        return $type . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }
}
