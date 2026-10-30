<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | 档案中心 配置
    |--------------------------------------------------------------------------
    | 通过 config('archive.xxx') 读取。基类 mergeModuleConfig() 会以
    | 模块名（archive）为键合并本文件。
    */

    'enabled' => true,

    // 路由前缀（留空则挂在 api/v1 下）
    'route_prefix' => '',

    // 列表默认每页条数
    'per_page' => 20,

    // 默认文档 provider（Gitlab 模块注册同名工厂；未注册时 fail closed）
    'default_provider' => 'gitlab',

    // 路径规范化限制
    'path' => [
        'max_segment_length' => 100,
        'max_total_length' => 500,
        'max_depth' => 8,
    ],

    // 目录批量移动（provider 文件逐项移动的持久化操作清单）
    'move' => [
        // 单次目录移动允许的文件数上限；超过直接拒绝，不做假成功。
        // 默认 100：与 GitLab 单次 commit 的常规规模匹配，超限应拆分目录。
        'max_files' => 100,
    ],

    'upload' => [
        // 单文件上限（字节），默认 50MB
        'max_size' => 50 * 1024 * 1024,

        // 同名策略：new_revision（默认）/ rename / reject
        'same_name_strategy' => 'new_revision',

        // 服务端 MIME 白名单（客户端上报的 MIME 仅作提示，HTTP 入口用服务端探测值）
        'allowed_mime_types' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/markdown',
            'text/x-markdown',
            'text/csv',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/zip',
            'application/x-zip-compressed',
            'application/x-7z-compressed',
            'application/x-rar-compressed',
            'application/vnd.rar',
            'application/octet-stream',
        ],

        'allowed_extensions' => [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'md', 'markdown', 'txt', 'csv',
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'zip', '7z', 'rar',
        ],

        // 扩展名 → 允许 MIME；octet-stream 作为浏览器/代理的通用回退，仍需扩展名在白名单内
        'mime_extension_map' => [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'md' => ['text/markdown', 'text/x-markdown', 'text/plain'],
            'markdown' => ['text/markdown', 'text/x-markdown', 'text/plain'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv', 'text/plain'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            '7z' => ['application/x-7z-compressed'],
            'rar' => ['application/x-rar-compressed', 'application/vnd.rar'],
        ],
    ],

    'preview' => [
        // Markdown 预览读取上限（超过则拒绝，避免把大文件读入内存）
        'max_markdown_bytes' => 2 * 1024 * 1024,
        // PDF / 图片内联预览上限
        'max_pdf_bytes' => 20 * 1024 * 1024,
        'max_image_bytes' => 10 * 1024 * 1024,

        'markdown_mime_types' => ['text/markdown', 'text/x-markdown', 'text/plain'],
        'image_mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    ],

    'auto_archive' => [
        // new_revision（默认）/ skip / reject
        'strategy' => 'new_revision',
    ],

    'ai' => [
        // 摘要工具最多读取的字节数（先抽取/截断，不把全文发模型）
        'summary_max_bytes' => 64 * 1024,
        'summary_max_chars' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | 目录模板
    |--------------------------------------------------------------------------
    | path 支持占位符：{{project_code}} / {{project_name}} / {{year}} / {{tenant}}。
    | 渲染后仍会重新做路径规范化；非法路径记为失败节点，可重试。
    */
    'templates' => [
        'initiation' => [
            'label' => '立项',
            'catalogs' => [
                ['path' => '{{project_code}}-{{project_name}}', 'catalog_type' => 'initiation'],
                ['path' => '{{project_code}}-{{project_name}}/01-立项申请', 'catalog_type' => 'initiation'],
                ['path' => '{{project_code}}-{{project_name}}/02-立项评审', 'catalog_type' => 'initiation'],
            ],
        ],
        'midterm' => [
            'label' => '中期',
            'catalogs' => [
                ['path' => '{{project_code}}-{{project_name}}/03-中期检查', 'catalog_type' => 'midterm'],
                ['path' => '{{project_code}}-{{project_name}}/03-中期检查/会议纪要', 'catalog_type' => 'midterm'],
            ],
        ],
        'closure' => [
            'label' => '结项',
            'catalogs' => [
                ['path' => '{{project_code}}-{{project_name}}/04-结项验收', 'catalog_type' => 'closure'],
                ['path' => '{{project_code}}-{{project_name}}/04-结项验收/验收证明', 'catalog_type' => 'closure'],
            ],
        ],
        'change' => [
            'label' => '变更',
            'catalogs' => [
                ['path' => '{{project_code}}-{{project_name}}/05-变更管理', 'catalog_type' => 'change'],
            ],
        ],
        'delivery' => [
            'label' => '交付',
            'catalogs' => [
                ['path' => '{{project_code}}-{{project_name}}/06-交付记录', 'catalog_type' => 'delivery'],
            ],
        ],
        'other' => [
            'label' => '其他',
            'catalogs' => [
                ['path' => '{{project_code}}-{{project_name}}/99-其他', 'catalog_type' => 'other'],
            ],
        ],
    ],
];
