// Router. Every page is its own lazily-loaded chunk.
//
// Lazy components are resolved ONCE here, at module load, through import.meta.glob — never inside a render.
// (In the previous React client a lazy component created during render made navigation freeze on some desktop
// browsers: the URL changed but the page never re-rendered.) A page whose file does not exist yet shows ComingSoon.
import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from '../stores/auth';
import { ALL_ROUTE_META, PAGE_FILES } from './routes';

const pageModules = import.meta.glob('../pages/**/*.vue');
const ComingSoon = () => import('../pages/ComingSoon.vue');
const pageOf = (key) => pageModules[`../pages/${PAGE_FILES[key]}`] || ComingSoon;

const routes = [
  { path: '/login', name: 'login', component: () => import('../pages/LoginPage.vue'), meta: { public: true } },
  {
    path: '/',
    component: () => import('../layout/AppShell.vue'),
    children: [
      ...ALL_ROUTE_META.map((r) => ({ path: r.path.slice(1), name: r.key, component: pageOf(r.key), meta: { key: r.key, title: r.title, permission: r.permission, param: r.param } })),
      // UI kit: the worked example of the page pattern (not in the sidebar).
      { path: 'kit', name: 'kit', component: () => import('../pages/dev/KitPage.vue'), meta: { key: 'kit', title: { ar: 'UI Kit', en: 'UI kit' } } },
      { path: '', name: 'home', redirect: () => useAuth().homePath },
      { path: ':pathMatch(.*)*', name: 'fallback', redirect: () => useAuth().homePath },
    ],
  },
];

export const router = createRouter({ history: createWebHistory(), routes, scrollBehavior: () => ({ top: 0 }) });

router.beforeEach(async (to) => {
  const auth = useAuth();
  await auth.boot();
  if (to.meta.public) return auth.user && to.name === 'login' ? (typeof to.query.from === 'string' ? to.query.from : auth.homePath) : true;
  if (!auth.user) return { name: 'login', query: to.fullPath && to.fullPath !== '/' ? { from: to.fullPath } : {} };
  return true;
});
