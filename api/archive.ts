import { http, extractListResult, moduleApiPrefix } from '@multi-tenant-saas/console/shared/http'

/**
 * 档案中心 API。
 *
 * 后端只保存目录/元数据/引用，正文由 provider 持有；下载与预览都走服务端代理，
 * 前端不接触 provider token 或永久地址。
 */

export type DocCatalogStatus = 'active' | 'archived'
export type DocItemStatus = 'active' | 'archived' | 'deleted'
export type DocVisibility = 'project' | 'managers' | 'approvers' | 'private'

export interface DocCatalog {
  catalog_id: number
  project_id: number | null
  parent_id: number | null
  scope_type: 'project' | 'tenant'
  name: string
  path: string
  catalog_type: string
  sort: number
  provider: string
  status: DocCatalogStatus
  template_key: string | null
  metadata: Record<string, unknown> | null
  children_count?: number | null
  items_count?: number | null
  created_at: string | null
  updated_at: string | null
}

export interface DocCatalogNode extends DocCatalog {
  children?: DocCatalogNode[]
}

export interface DocItem {
  doc_item_id: number
  project_id: number | null
  catalog_id: number
  catalog_path: string | null
  title: string
  doc_type: string
  mime_type: string
  size: number
  checksum_sha256: string
  current_revision: number
  provider: string
  provider_path: string
  source: 'manual' | 'system' | 'import'
  status: DocItemStatus
  visibility: DocVisibility
  retention_until: string | null
  legal_hold: boolean
  metadata: Record<string, unknown> | null
  created_at: string | null
  updated_at: string | null
}

export interface DocRef {
  doc_ref_id: number
  project_id: number | null
  doc_item_id: number
  ref_type: string
  ref_id: string
  relation: 'attachment' | 'evidence' | 'deliverable' | 'other'
  created_by: number | null
  created_at: string | null
}

export interface TemplateApplyResult {
  project_id: number
  template_key: string
  idempotency_key: string
  created: number
  reused: number
  catalogs: Array<{ catalog_id: number; path: string; reused: boolean }>
  failures: Array<{ path: string; reason: string; message: string }>
}

export interface UploadDocumentResult {
  document: DocItem
  action: 'created' | 'revised' | 'replayed'
  idempotent: boolean
}

export interface DocumentListParams {
  page?: number
  pageSize?: number
  project_id?: number
  catalog_id?: number
  doc_type?: string
  status?: DocItemStatus
  keyword?: string
}

export interface UploadDocumentInput {
  catalog_id: number
  file?: File
  content?: string
  filename?: string
  title?: string
  doc_type?: string
  visibility?: DocVisibility
  idempotency_key?: string
  same_name_strategy?: 'new_revision' | 'rename' | 'reject'
}

const BASE = `${moduleApiPrefix('archive')}/archive`

// ========== 目录 ==========

export async function getCatalogList(params: {
  project_id?: number
  parent_id?: number
  catalog_type?: string
  status?: DocCatalogStatus
} = {}): Promise<{ data: DocCatalog[]; total: number }> {
  const res = await http.get<{ data: DocCatalog[]; total: number }>(`${BASE}/catalogs`, { params })
  return extractListResult(res as never) as { data: DocCatalog[]; total: number }
}

export async function getCatalogTree(params: { project_id?: number } = {}): Promise<DocCatalogNode[]> {
  const res = await http.get<{ data: DocCatalogNode[] }>(`${BASE}/catalogs/tree`, { params })
  return res.data?.data ?? []
}

export async function createCatalog(data: {
  project_id?: number | null
  parent_id?: number | null
  name?: string
  path?: string
  catalog_type?: string
  sort?: number
}): Promise<DocCatalog> {
  const res = await http.post<DocCatalog>(`${BASE}/catalogs`, data)
  return res.data
}

export async function updateCatalog(
  id: number,
  data: { name?: string; parent_id?: number | null; catalog_type?: string; sort?: number },
): Promise<DocCatalog> {
  const res = await http.put<DocCatalog>(`${BASE}/catalogs/${id}`, data)
  return res.data
}

export async function deleteCatalog(id: number): Promise<void> {
  await http.delete(`${BASE}/catalogs/${id}`)
}

// ========== 模板 ==========

export async function getTemplates(): Promise<Array<{ key: string; label: string }>> {
  const res = await http.get<{ data: Array<{ key: string; label: string }>; total: number }>(
    `${BASE}/templates`,
  )
  return res.data?.data ?? []
}

export async function applyTemplate(data: {
  project_id: number
  template_key: string
  idempotency_key: string
  project_code?: string
  project_name?: string
  year?: number
}): Promise<TemplateApplyResult> {
  const res = await http.post<TemplateApplyResult>(`${BASE}/templates/apply`, data)
  return res.data
}

// ========== 文档 ==========

export async function getDocumentList(
  params: DocumentListParams,
): Promise<{ data: DocItem[]; total: number }> {
  const query: Record<string, unknown> = {
    page: params.page ?? 1,
    per_page: params.pageSize ?? 20,
  }

  if (params.project_id) query.project_id = params.project_id
  if (params.catalog_id) query.catalog_id = params.catalog_id
  if (params.doc_type) query.doc_type = params.doc_type
  if (params.status) query.status = params.status
  if (params.keyword) query.keyword = params.keyword

  const res = await http.get<{ data: DocItem[]; total: number }>(`${BASE}/documents`, {
    params: query,
  })
  return extractListResult(res as never) as { data: DocItem[]; total: number }
}

export async function getDocument(id: number): Promise<DocItem> {
  const res = await http.get<DocItem>(`${BASE}/documents/${id}`)
  return res.data
}

export async function uploadDocument(input: UploadDocumentInput): Promise<UploadDocumentResult> {
  const form = new FormData()
  form.append('catalog_id', String(input.catalog_id))

  if (input.file) {
    form.append('file', input.file)
  } else if (input.content !== undefined) {
    form.append('content', input.content)
    form.append('filename', input.filename ?? '')
  }

  if (input.title) form.append('title', input.title)
  if (input.doc_type) form.append('doc_type', input.doc_type)
  if (input.visibility) form.append('visibility', input.visibility)
  if (input.idempotency_key) form.append('idempotency_key', input.idempotency_key)
  if (input.same_name_strategy) form.append('same_name_strategy', input.same_name_strategy)

  const res = await http.post<UploadDocumentResult>(`${BASE}/documents`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return res.data
}

export async function downloadDocument(id: number, filename?: string): Promise<void> {
  const res = await http.get<Blob>(`${BASE}/documents/${id}/download`, { responseType: 'blob' })
  triggerBlobDownload(res as unknown as Blob, filename ?? `document-${id}`)
}

export async function previewDocument(id: number): Promise<void> {
  const res = await http.get<Blob>(`${BASE}/documents/${id}/preview`, { responseType: 'blob' })
  const blob = res as unknown as Blob
  const url = URL.createObjectURL(blob)
  window.open(url, '_blank', 'noopener,noreferrer')
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

export async function deleteDocument(id: number, force = false): Promise<void> {
  await http.delete(`${BASE}/documents/${id}`, { params: force ? { force: 1 } : {} })
}

// ========== 引用 ==========

export async function listDocRefs(docItemId: number): Promise<DocRef[]> {
  const res = await http.get<{ data: DocRef[]; total: number }>(
    `${BASE}/documents/${docItemId}/refs`,
  )
  return res.data?.data ?? []
}

export async function createDocRef(data: {
  doc_item_id: number
  ref_type: string
  ref_id: string
  relation?: DocRef['relation']
}): Promise<DocRef> {
  const res = await http.post<DocRef>(`${BASE}/refs`, data)
  return res.data
}

export async function deleteDocRef(id: number): Promise<void> {
  await http.delete(`${BASE}/refs/${id}`)
}

// ========== 导出 ==========

export async function exportArchiveManifest(filters: {
  project_id?: number
  catalog_id?: number
  doc_type?: string
  status?: string
  keyword?: string
} = {}): Promise<{ generated_at: string; total: number; items: DocItem[] }> {
  const res = await http.post<{ generated_at: string; total: number; items: DocItem[] }>(
    `${BASE}/export`,
    filters,
  )
  return res.data
}

function triggerBlobDownload(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}
