# Archive 档案中心

文档目录、文档元数据与文档引用账本。正文由外部 provider 存储（P0 为 GitLab 文档仓），平台不把 `Storage` 或 `file_uploads` 当第二权威。

## 分层与依赖

- 架构层：`common`（`config/module-architecture.php`）
- 依赖模块：无（provider 由宿主或 Gitlab 模块向 `DocProviderRegistry` 注册）
- 冲突声明：无
- 迁移：`2025_01_03_000017_archive_module.php`（3 张表）、`2025_01_04_000017_archive_module_data.php`（RBAC 权限，幂等）

## 配置 `config/archive.php`

| 键 | 说明 |
|---|---|
| `enabled` / `route_prefix` / `per_page` | 模块开关、路由前缀、分页 |
| `default_provider` | 默认文档 provider（默认 `gitlab`） |
| `path.*` | 路径段/总长/深度上限，非法路径拒绝 |
| `upload.max_size` / `allowed_mime_types` / `allowed_extensions` / `mime_extension_map` | 上传白名单与扩展名-MIME 映射；文本类扩展名以扩展名为准 |
| `upload.same_name_strategy` | 同名文档策略（默认 `new_revision`） |
| `templates.*` | 目录模板（立项/中期/结项/变更/交付/其他），支持 `{{project_code}}` / `{{project_name}}` / `{{year}}` / `{{tenant}}` 占位符 |

## 路由（`api/v1`）

- `archive/catalogs`：树、创建、移动/改名、删除
- `archive/templates`：列表、应用模板
- `archive/documents`：列表、上传、详情、下载、预览、删除
- `archive/documents/{id}/refs`、`archive/refs`：文档引用增删查
- `archive/export`：批量导出

## 能力点

默认权限集：`archive.view` / `archive.manage` / `archive.upload` / `archive.download` / `archive.export`。

- `common.archive.catalog.view` / `catalog.manage`
- `common.archive.document.view` / `document.upload` / `document.download` / `document.delete`
- `common.archive.ref.manage` / `template.apply` / `export`
- 兼容别名：`list` / `read` → `document.view`；`create` → `document.upload`；`update` / `delete` → 管理权限

## Provider 契约

- `Contracts/DocProviderContract`：读写、移动、删除、建目录、健康检查
- `Contracts/DocProviderRegistryContract`：宿主注册 provider key
- `Dto/DocProviderFile`、`Dto/DocFileContent`、`Exceptions/DocProviderException`

Gitlab 模块会自动注册 `gitlab` provider；未配置 `doc_project_id` 时相关操作返回 `unsupported`，不会回退到本地存储。

## 数据表

`doc_catalogs`、`doc_items`、`doc_refs`。文档项只存元数据与 provider 引用（路径、commit、校验和）。

## 安全

- 路径统一规范化，拒绝 `..`、绝对路径、反斜杠、超长/超深路径。
- 上传同时校验大小、扩展名与 MIME（文本类扩展名优先，避免 `.md` 被 finfo 误判为 `text/html`）。
- Markdown 预览经 HTML 清洗后输出；PDF 以流式预览；Office 文档仅允许下载，不在服务端转换。
- 下载校验文档范围与权限，返回 checksum；`legal_hold` 文档禁止删除。

## 测试

```bash
php artisan test --parallel --processes=2 tests/Archive
```
