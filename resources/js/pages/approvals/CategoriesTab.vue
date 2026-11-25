<script setup>
// The kinds of approval request the company uses: what each requires, who approves it and in which order.
// GET /approvals/categories?q=&active=&source=&page= — readable by everybody who opens the section (so a requester knows
// what a request needs before raising it); creating and changing one needs approval.manage.
// Search, filters and page live in the query string; a row opens the category (`cat=<code>`), «+ نوع جديد» its form.
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { bi, fmtMoney, fmtNum, t } from '@/i18n';
import { FIELD_KEYS, FIELD_LABELS, MODE_SHORT, SOURCE_CHIP, TONE, catName, personName, useApprovalsNav, useSearchParam } from './approvals';

const props = defineProps({
  canManage: { type: Boolean, default: false },
  /** GET /approvals/summary — whether anything here came from Odoo. */
  summary: { type: Object, default: null },
});

const nav = useApprovalsNav();
const { page, categoryOpen } = nav;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);
const active = computed(() => (['0', '1'].includes(nav.get('active')) ? nav.get('active') : ''));
const source = computed(() => (['odoo', 'ops'].includes(nav.get('source')) ? nav.get('source') : ''));
const list = useList('/approvals/categories', () => ({ q: nav.get('q'), active: active.value, source: source.value, page: page.value, pageSize: 50 }));

const activeTabs = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: '1', label: { ar: 'مفعّلة', en: 'Active' } }, { k: '0', label: { ar: 'موقوفة', en: 'Stopped' } }];
const sourceTabs = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'ops', label: { ar: 'أُنشئت هنا', en: 'Made here' } }, { k: 'odoo', label: SOURCE_CHIP }];
const requiredOf = (c) => FIELD_KEYS.filter((f) => c.fields?.[f] === 'required').map((f) => bi(FIELD_LABELS[f]));
const columns = [
  { key: 'name', header: { ar: 'النوع', en: 'Category' }, width: 'minmax(210px,1.5fr)' },
  { key: 'rule', header: { ar: 'طريقة الاعتماد', en: 'How it is approved' }, width: '150px' },
  { key: 'approvers', header: { ar: 'المعتمدون بالترتيب', en: 'Approvers, in order' }, width: 'minmax(190px,1.4fr)' },
  { key: 'requires', header: { ar: 'يشترط', en: 'Requires' }, width: 'minmax(150px,1fr)' },
  { key: 'requests', header: { ar: 'الطلبات', en: 'Requests' }, width: '104px' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '104px', bare: true },
];
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[240px]" :model-value="qText" :placeholder="{ ar: 'بحث باسم النوع أو رمزه…', en: 'Search by name or code…' }" @update:model-value="typeQ" @enter="applyQ" />
    <Tabs :model-value="active" :tabs="activeTabs" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('active', k)" />
    <Tabs v-if="summary?.hasOdoo" :model-value="source" :tabs="sourceTabs" variant="pillPurple" class="!mb-0" @update:model-value="(k) => nav.setFilter('source', k)" />
    <div class="grow" />
    <Btn v-if="canManage" tone="dark" size="sm" :label="{ ar: '+ نوع جديد', en: '+ New category' }" @click="nav.openCategory('new')" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(c) => c.code" :selected-key="categoryOpen"
               :empty-text="canManage ? { ar: 'لا أنواع بعد — أضف أول نوع من «+ نوع جديد».', en: 'No categories yet — add the first one with “+ New category”.' } : { ar: 'لا أنواع بعد — يضيفها مدير الاعتمادات.', en: 'No categories yet — the approvals manager adds them.' }"
               @page="nav.setPage" @row-click="(c) => nav.openCategory(c.code)">
      <template #cell-name="{ row }">
        <div class="min-w-0">
          <div class="truncate text-[11.5px] font-extrabold text-ink [text-align:match-parent]" dir="auto">{{ catName(row) }}</div>
          <div class="truncate text-[9.5px] text-muted"><span class="num ltr">{{ row.code }}</span><template v-if="row.description"> · <span dir="auto">{{ row.description }}</span></template></div>
        </div>
      </template>
      <template #cell-rule="{ row }">
        <div>
          <Chip small :map="MODE_SHORT" :k="row.approvalMode" />
          <div class="mt-0.5 text-[9.5px] text-muted">{{ t('الحد الأدنى', 'Minimum') }} <b class="num ltr">{{ fmtNum(row.minApprovals) }}</b></div>
        </div>
      </template>
      <template #cell-approvers="{ row }">
        <span v-if="!row.approvers.length" class="text-[10px] text-faint">{{ t('يحددهم صاحب الطلب', 'Named by the requester') }}</span>
        <div v-else class="min-w-0 text-[10.5px] leading-[1.8]">
          <template v-for="(a, i) in row.approvers" :key="a.id">
            <span v-if="i" class="text-faint"> {{ row.approvalMode === 'sequence' ? '←' : '·' }} </span>
            <span class="font-bold" :class="a.user.linked ? 'text-ink' : 'text-muted'" :title="a.user.linked ? null : t('من أودو — بلا مستخدم هنا بعد', 'Of Odoo — no user here yet')">{{ personName(a.user) }}</span><span v-if="a.required" class="text-bad" :title="t('اعتماده إلزامي', 'Required')">*</span><span v-if="a.minAmount != null" class="num ltr text-[9px] text-faint"> ≥{{ fmtMoney(a.minAmount) }}</span>
          </template>
        </div>
      </template>
      <template #cell-requires="{ row }">
        <span v-if="!requiredOf(row).length" class="text-faint">—</span>
        <span v-else class="text-[10px] leading-[1.7] text-sec">{{ requiredOf(row).join('، ') }}</span>
      </template>
      <template #cell-requests="{ row }">
        <span class="num ltr text-[10.5px] font-bold text-ink">{{ fmtNum(row.counts?.requests || 0) }}</span>
        <div v-if="row.counts?.pending" class="text-[9.5px] text-warn">{{ t('بانتظار', 'waiting') }} <span class="num ltr">{{ fmtNum(row.counts.pending) }}</span></div>
      </template>
      <template #cell-state="{ row }">
        <div class="flex flex-col items-center gap-1">
          <Chip small :label="row.active ? { ar: 'مفعّل', en: 'Active' } : { ar: 'موقوف', en: 'Stopped' }" :fg="row.active ? TONE.ok.fg : TONE.off.fg" :bg="row.active ? TONE.ok.bg : TONE.off.bg" />
          <Chip v-if="row.source === 'odoo'" small :label="SOURCE_CHIP" :fg="SOURCE_CHIP.fg" :bg="SOURCE_CHIP.bg" />
        </div>
      </template>
    </DataTable>
  </div>
  <div class="hint">
    {{ t('النوع يحدد ما يحمله الطلب (الحقول الإلزامية)، ومن يعتمده وبأي ترتيب، وكم اعتمادًا يكفي. المعتمد المعلَّم بنجمة اعتماده إلزامي، والرقم بجانب الاسم هو المبلغ الذي يبدأ عنده سؤاله. رفضٌ واحد يرفض الطلب.', 'A category says what a request carries (its required fields), who approves it and in which order, and how many approvals are enough. An approver marked with a star is required; the figure beside a name is the amount he is asked from. One refusal refuses the request.') }}
    <template v-if="summary?.odooOwns && summary?.hasOdoo">{{ ' ' + t('الأنواع القادمة من أودو تُعدَّل في أودو ما دام القسم يتابعه.', 'Categories that came from Odoo are changed in Odoo while the section follows it.') }}</template>
  </div>
</template>
