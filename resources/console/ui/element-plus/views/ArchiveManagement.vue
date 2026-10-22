<template>
  <div class="archive-page">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>档案中心</span>
          <div class="header-actions">
            <el-input-number
              v-model="projectId"
              :min="1"
              :controls="false"
              placeholder="项目 ID（可选）"
              style="width: 150px"
              @change="reloadAll"
            />
            <el-button type="primary" :disabled="!selectedCatalogId" @click="openUpload">
              上传文档
            </el-button>
          </div>
        </div>
      </template>

      <div class="layout">
        <aside class="catalog-pane">
          <div class="pane-title">
            <span>档案目录</span>
            <el-button link type="primary" @click="selectCatalog(null)">全部</el-button>
          </div>
          <el-tree
            v-loading="catalogLoading"
            :data="tree"
            node-key="catalog_id"
            :props="{ label: 'name', children: 'children' }"
            highlight-current
            default-expand-all
            empty-text="暂无目录"
            @node-click="selectCatalog"
          />
        </aside>

        <section class="document-pane">
          <div class="filter-bar">
            <el-input
              v-model="keyword"
              placeholder="标题关键词"
              clearable
              style="width: 220px"
              @keyup.enter="loadDocuments"
            />
            <el-button type="primary" @click="loadDocuments">查询</el-button>
            <span v-if="selectedCatalog" class="selected-path">{{ selectedCatalog.path }}</span>
          </div>

          <el-table v-loading="documentLoading" :data="documents" border stripe>
            <el-table-column prop="title" label="文档" min-width="220" show-overflow-tooltip />
            <el-table-column prop="doc_type" label="类型" width="100" />
            <el-table-column label="大小" width="110">
              <template #default="{ row }">{{ formatSize(row.size) }}</template>
            </el-table-column>
            <el-table-column label="修订" width="80">
              <template #default="{ row }">v{{ row.current_revision }}</template>
            </el-table-column>
            <el-table-column label="更新时间" width="180">
              <template #default="{ row }">{{ formatTime(row.updated_at) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="220" fixed="right">
              <template #default="{ row }">
                <el-button link type="primary" @click="download(row)">下载</el-button>
                <el-button link type="primary" @click="preview(row)">预览</el-button>
                <el-button link type="danger" @click="remove(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>

          <el-pagination
            class="pager"
            layout="total, prev, pager, next"
            :total="total"
            :current-page="page"
            :page-size="perPage"
            @current-change="handlePageChange"
          />
        </section>
      </div>
    </el-card>

    <el-dialog v-model="uploadVisible" title="上传文档" width="520px" :close-on-click-modal="false">
      <el-form label-width="80px">
        <el-form-item label="目录">
          <el-input :model-value="selectedCatalog?.path ?? ''" disabled />
        </el-form-item>
        <el-form-item label="文件">
          <el-upload :auto-upload="false" :limit="1" :on-change="onFileChange" :on-remove="onFileRemove">
            <el-button>选择文件</el-button>
            <template #tip>
              <div class="el-upload__tip">支持 PDF / Office / 文本 / 图片 / 压缩包，默认单文件 50MB</div>
            </template>
          </el-upload>
        </el-form-item>
        <el-form-item label="标题">
          <el-input v-model="uploadTitle" maxlength="255" placeholder="留空使用文件名" />
        </el-form-item>
        <el-form-item label="可见性">
          <el-select v-model="visibility" style="width: 100%">
            <el-option label="项目成员" value="project" />
            <el-option label="管理层" value="managers" />
            <el-option label="审批人" value="approvers" />
            <el-option label="仅本人" value="private" />
          </el-select>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="uploadVisible = false">取消</el-button>
        <el-button type="primary" :loading="uploading" :disabled="!uploadFile" @click="submitUpload">
          上传
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { UploadFile, UploadFiles } from 'element-plus'
import {
  deleteDocument,
  downloadDocument,
  getCatalogTree,
  getDocumentList,
  previewDocument,
  uploadDocument,
} from '@modules/Archive/api/archive'
import type { DocCatalogNode, DocItem, DocVisibility } from '@modules/Archive/api/archive'

const catalogLoading = ref(false)
const documentLoading = ref(false)
const uploading = ref(false)

const tree = ref<DocCatalogNode[]>([])
const documents = ref<DocItem[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)

const projectId = ref<number | undefined>()
const selectedCatalog = ref<DocCatalogNode | null>(null)
const selectedCatalogId = computed(() => selectedCatalog.value?.catalog_id ?? null)
const keyword = ref('')

const uploadVisible = ref(false)
const uploadFile = ref<File | null>(null)
const uploadTitle = ref('')
const visibility = ref<DocVisibility>('project')

function formatSize(size: number): string {
  if (!size) return '0 B'
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

function formatTime(value: string | null): string {
  return value ? value.replace('T', ' ').slice(0, 19) : '-'
}

async function loadTree(): Promise<void> {
  catalogLoading.value = true
  try {
    tree.value = await getCatalogTree({ project_id: projectId.value })
  } catch {
    ElMessage.error('加载目录失败')
  } finally {
    catalogLoading.value = false
  }
}

async function loadDocuments(): Promise<void> {
  documentLoading.value = true
  try {
    const result = await getDocumentList({
      page: page.value,
      pageSize: perPage.value,
      project_id: projectId.value,
      catalog_id: selectedCatalogId.value ?? undefined,
      keyword: keyword.value || undefined,
    })
    documents.value = result.data
    total.value = result.total
  } catch {
    ElMessage.error('加载文档失败')
  } finally {
    documentLoading.value = false
  }
}

function reloadAll(): void {
  selectedCatalog.value = null
  page.value = 1
  loadTree()
  loadDocuments()
}

function selectCatalog(node: DocCatalogNode | null): void {
  selectedCatalog.value = node
  page.value = 1
  loadDocuments()
}

function handlePageChange(next: number): void {
  page.value = next
  loadDocuments()
}

function openUpload(): void {
  uploadFile.value = null
  uploadTitle.value = ''
  visibility.value = 'project'
  uploadVisible.value = true
}

function onFileChange(file: UploadFile, _files: UploadFiles): void {
  uploadFile.value = file.raw ?? null
}

function onFileRemove(): void {
  uploadFile.value = null
}

async function submitUpload(): Promise<void> {
  if (!selectedCatalogId.value || !uploadFile.value) return

  uploading.value = true
  try {
    await uploadDocument({
      catalog_id: selectedCatalogId.value,
      file: uploadFile.value,
      title: uploadTitle.value || undefined,
      visibility: visibility.value,
    })
    ElMessage.success('上传成功')
    uploadVisible.value = false
    await loadDocuments()
  } catch (error) {
    ElMessage.error(errorMessage(error, '上传失败'))
  } finally {
    uploading.value = false
  }
}

async function download(row: DocItem): Promise<void> {
  try {
    await downloadDocument(row.doc_item_id, row.title)
  } catch (error) {
    ElMessage.error(errorMessage(error, '下载失败'))
  }
}

async function preview(row: DocItem): Promise<void> {
  try {
    await previewDocument(row.doc_item_id)
  } catch (error) {
    ElMessage.error(errorMessage(error, '该文件不支持内联预览，请下载查看'))
  }
}

async function remove(row: DocItem): Promise<void> {
  try {
    await ElMessageBox.confirm(`确认删除「${row.title}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }

  try {
    await deleteDocument(row.doc_item_id)
    ElMessage.success('已删除')
    await loadDocuments()
  } catch (error) {
    const status = (error as { response?: { status?: number } })?.response?.status
    if (status !== 409) {
      ElMessage.error(errorMessage(error, '删除失败'))
      return
    }

    try {
      await ElMessageBox.confirm('文档仍被引用或处于保留期，需强制删除。确认继续？', '需要确认', {
        type: 'warning',
      })
      await deleteDocument(row.doc_item_id, true)
      ElMessage.success('已强制删除')
      await loadDocuments()
    } catch (forceError) {
      if (forceError instanceof Error) ElMessage.error(forceError.message)
    }
  }
}

function errorMessage(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message
  return message || fallback
}

onMounted(reloadAll)
</script>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.layout {
  display: flex;
  gap: 16px;
  min-height: 480px;
}

.catalog-pane {
  width: 260px;
  border-right: 1px solid var(--el-border-color-lighter);
  padding-right: 12px;
}

.pane-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
  font-weight: 600;
}

.document-pane {
  flex: 1;
  min-width: 0;
}

.filter-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

.selected-path {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
