档案中心
===============

模块标识：archive
后台入口：Console → 档案中心（路由 `archive`）
数据表：`doc_catalogs`、`doc_items`、`doc_refs`

档案中心是**文档目录 / 元数据 / 引用账本**：正文不落本模块，而由
`DocProviderContract` 实现方持有（P0 为 Gitlab 文档仓）。模块不依赖具体
provider，未注册 provider 时一律拒绝（fail closed），不回退本地文件系统。

## 能力

- 目录树：项目/租户范围、层级、路径规范化（拒绝 `..`、绝对路径、控制字符）、
  新建/重命名/移动/软归档；非空目录不可删除
- 目录模板：立项/中期/结项/变更/交付/其他；占位符
  `{{project_code}}` / `{{project_name}}` / `{{year}}` / `{{tenant}}`；
  幂等应用，复用同路径目录，部分失败可重试
- 文档元数据：上传后落 `doc_items`（大小、SHA-256、修订号、provider 引用）；
  同名默认生成新修订，也可 `rename` / `reject`
- 下载/预览：服务端代理 provider 流，不暴露 token/永久 URL；Markdown
  服务端转义渲染，PDF/图片限大小内联，Office 仅下载；不支持 Range 时显式 416
- 删除：软删除（`status=deleted`），不物理删除 provider 正文；有引用需
  `force` + 管理员权限并审计；保留期/法律保留会阻止删除
- 引用账本：业务对象（`ref_type` + `ref_id`）到文档的多态引用，唯一键幂等
- 导出：P0 同步返回元数据清单（不含正文）

## RBAC 权限

| 权限 | 说明 |
|------|------|
| `archive.view` | 查看目录/文档 |
| `archive.manage` | 管理目录、引用、强制删除 |
| `archive.upload` | 上传/自动归档 |
| `archive.download` | 下载/预览/摘要 |
| `archive.export` | 导出清单 |

能力键：`common.archive.catalog.view` / `catalog.manage` / `document.view` /
`document.upload` / `document.download` / `document.delete` / `ref.manage` /
`template.apply` / `export`；兼容别名 `common.archive.list`、`read`、`create`、
`update`、`delete` 分别映射到上述默认权限。

## API

前缀 `api/v1`，认证 `auth:sanctum`，租户由 `tenant.identify` 解析，
`operator.identify` 装载执行身份后逐路由 `capability` 授权。

| 方法 | 路径 | 能力 |
|------|------|------|
| GET | `archive/catalogs` | catalog.view |
| GET | `archive/catalogs/tree` | catalog.view |
| POST | `archive/catalogs` | catalog.manage |
| PUT | `archive/catalogs/{id}` | catalog.manage |
| DELETE | `archive/catalogs/{id}` | catalog.manage |
| GET | `archive/templates` | catalog.view |
| POST | `archive/templates/apply` | template.apply |
| GET | `archive/documents` | document.view |
| GET | `archive/documents/{id}` | document.view |
| POST | `archive/documents` | document.upload |
| GET | `archive/documents/{id}/download` | document.download |
| GET | `archive/documents/{id}/preview` | document.download |
| DELETE | `archive/documents/{id}` | document.delete |
| GET | `archive/documents/{id}/refs` | ref.manage |
| POST | `archive/refs` | ref.manage |
| DELETE | `archive/refs/{id}` | ref.manage |
| POST | `archive/export` | export |

列表响应：`{ "success": true, "data": { "data": [...], "total": N } }`。

## AI 工具（L1）

| slug | 能力 | 说明 |
|------|------|------|
| `archive_search` | document.view | 搜索文档元数据，不返回正文 |
| `archive_doc_summary` | document.download | 抽取截断片段（默认 64KB / 2000 字） |
| `archive_metadata_suggest` | document.upload | 只给元数据建议，不落库 |

## 接线契约

provider 由宿主/集成模块在 boot 时注册：

```php
app(DocProviderRegistryContract::class)->register('gitlab', function (int $tenantId, ?int $connectionId) {
    return new GitlabDocProvider(...);
});
```

本模块**不监听**项目模块事件；自动归档由调用方显式调用
`DocItemService::autoArchive()`（按 `source_type/source_id/doc_type` 幂等）。

## 皮肤点

| 皮肤点 | 覆盖方式 |
|------|------|
| 场景入口 | 下游同 slug 再注册覆盖 title/icon/priority（last-wins） |
| 视图 | 下游 routes.ts 自指视图复用本模块 API |
| AI 工具 | 下游同名 `register` 特化接管 |

## 待办（P0 范围外）

- [ ] 真实 GitLab 正文读写与目录移动（由 Gitlab 模块提供 provider 实现）
- [ ] 异步导出打包与下载任务
- [ ] 前端 Markdown 富文本/附件内联渲染（当前仅打开服务端已消毒 HTML）
