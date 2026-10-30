import type { RouteRecordRaw } from 'vue-router'
import { view } from '@multi-tenant-saas/console/module-loader'

const routes: RouteRecordRaw[] = [
  // 档案中心
  {
    path: 'archive',
    name: 'ArchiveManagement',
    component: view('Archive', 'ArchiveManagement'),
    meta: { title: '档案中心', permission: 'archive.view' },
  },
]

export default routes
