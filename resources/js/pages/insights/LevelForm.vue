<script setup>
// Create / edit an expiry alert level (expiryalert.manage) — POST /insights/expiry/levels, PATCH /insights/expiry/levels/{id}:
// { name, daysBeforeExpiry, notificationType (inapp|email|both), sequence?, color? (0..11), userIds[] (≥ 1 active user) }.
// The users to choose from: GET /insights/lookups (brandowner.manage) or GET /users (user.manage) — see
// `useRecipientUsers`. Holding neither, the recipients a level already has can be kept or taken off, not added.
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Drawer, ErrorBanner, Field, NumberInput, PillChoice, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { PALETTE_SIZE, channelOptions, levelColor, useRecipientUsers, userName } from './insights';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** the level row when editing; null to create */
  level: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const people = useRecipientUsers(auth, () => props.open);
const f = reactive({ name: '', daysBeforeExpiry: 30, notificationType: 'inapp', sequence: 10, color: 0, recipients: [] });
const errors = ref({});
const act = useAction();
watch(() => props.open, (v) => {
  if (!v) return;
  const l = props.level;
  Object.assign(f, {
    name: l?.name || '', daysBeforeExpiry: l ? l.daysBeforeExpiry : 30, notificationType: l?.notificationType || 'inapp', sequence: l ? l.sequence : 10, color: l ? Number(l.color) || 0 : 0,
    recipients: (l?.recipients || []).map((u) => ({ id: String(u.id), username: u.username, nameAr: u.nameAr, nameEn: u.nameEn })),
  });
  errors.value = {}; act.clearError();
}, { immediate: true });

const chosen = computed(() => new Set(f.recipients.map((u) => u.id)));
const offered = computed(() => people.users.value.filter((u) => !chosen.value.has(u.id)).map((u) => ({ v: u.id, l: `${userName(u)} (${u.username})` })));
function addUser(id) {
  const u = people.users.value.find((x) => x.id === id);
  if (u && !chosen.value.has(u.id)) f.recipients = [...f.recipients, u];
}
const removeUser = (id) => { f.recipients = f.recipients.filter((u) => u.id !== id); };
const colors = Array.from({ length: PALETTE_SIZE }, (_, i) => i);

async function submit() {
  const errs = {};
  if (f.name.trim().length < 2) errs.name = t('الاسم حرفان على الأقل', 'At least two characters');
  if (f.daysBeforeExpiry == null || f.daysBeforeExpiry < 0 || f.daysBeforeExpiry > 3650) errs.daysBeforeExpiry = t('بين 0 و3650 يومًا', 'Between 0 and 3650 days');
  if (!f.recipients.length) errs.userIds = t('اختر مستلمًا واحدًا على الأقل', 'Pick at least one recipient');
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const body = { name: f.name.trim(), daysBeforeExpiry: Math.round(f.daysBeforeExpiry), notificationType: f.notificationType, sequence: Math.round(Number(f.sequence) || 0), color: Number(f.color) || 0, userIds: f.recipients.map((u) => u.id) };
  const res = await act.run(() => (props.level ? api.patch(`/insights/expiry/levels/${encodeURIComponent(props.level.id)}`, body) : api.postIdempotent('/insights/expiry/levels', body)), {
    success: props.level ? t('حُفظ المستوى', 'Level saved') : t('أُضيف المستوى', 'Level added'), invalidate: ['insights'],
  });
  if (res) { emit('done', res); emit('close'); }
}
watch(() => act.error.value, (e) => { if (e) { const fe = e.fieldErrors(); if (Object.keys(fe).length) errors.value = { ...errors.value, ...fe }; } });
</script>

<template>
  <Drawer :open="open" :title="level ? { ar: `تعديل «${level.name}»`, en: `Edit “${level.name}”` } : { ar: 'مستوى تنبيه صلاحية جديد', en: 'New expiry alert level' }" :width="540" :z-index="90" @close="emit('close')">
    <form class="form-grid" @submit.prevent="submit">
      <TextInput v-model="f.name" full required :label="{ ar: 'اسم المستوى', en: 'Level name' }" :error="errors.name" :placeholder="{ ar: 'مثل: قبل 90 يومًا', en: 'e.g. 90 days before' }" />
      <NumberInput v-model="f.daysBeforeExpiry" required :label="{ ar: 'كم يومًا قبل الانتهاء', en: 'Days before expiry' }" :min="0" :max="3650" :step="1" :error="errors.daysBeforeExpiry" />
      <NumberInput v-model="f.sequence" :label="{ ar: 'الترتيب', en: 'Order' }" :min="0" :max="10000" :step="1" :error="errors.sequence" />
      <PillChoice v-model="f.notificationType" full :options="channelOptions()" :label="{ ar: 'القناة', en: 'Channel' }" :error="errors.notificationType" />
      <div v-if="f.notificationType !== 'inapp'" class="hint amber full !mt-0">{{ t('لا خادم بريد مُعدّ: يُسجَّل التنبيه «البريد غير مُعدّ — لم يُرسل»، ويصل داخل النظام فقط مع «النظام والبريد».', 'No e-mail server is configured: the alert is recorded as "e-mail not configured — not sent", and reaches the app only with "App and e-mail".') }}</div>
      <Field full :label="{ ar: 'اللون', en: 'Colour' }">
        <div class="row wrap !gap-1.5">
          <button v-for="c in colors" :key="c" type="button" class="h-7 w-7 rounded-full border-2" :style="{ background: levelColor(c).bg, borderColor: f.color === c ? levelColor(c).fg : 'transparent' }" :aria-label="String(c)" @click="f.color = c">
            <span class="text-[10px] font-extrabold" :style="{ color: levelColor(c).fg }">{{ f.color === c ? '✓' : '' }}</span>
          </button>
        </div>
      </Field>
      <Field full required :label="{ ar: 'المستلمون', en: 'Recipients' }" :error="errors.userIds">
        <ErrorBanner :error="people.error.value" :closable="false" />
        <div v-if="people.refused.value" class="hint amber !mt-0 mb-1.5">{{ t('قائمة المستخدمين تحتاج صلاحية إدارة العلامات أو إدارة المستخدمين، ولا تملك أيًّا منهما: تستطيع إبقاء المستلمين الحاليين أو إزالتهم فقط. اطلب من مدير النظام إضافة مستلم.', 'The list of users needs the brand-manager or the user-admin permission and you hold neither: you can keep or remove the current recipients only. Ask an administrator to add one.') }}</div>
        <SelectInput v-else :model-value="''" :options="offered" :disabled="people.loading.value" :placeholder="{ ar: people.loading.value ? 'جارٍ التحميل…' : '+ أضف مستلمًا', en: people.loading.value ? 'Loading…' : '+ Add a recipient' }" @update:model-value="addUser" />
        <div class="row wrap mt-1.5 !gap-1.5">
          <span v-for="u in f.recipients" :key="u.id" class="chip" dir="auto">{{ userName(u) }} <span class="num ltr ms-1 text-[9px] text-muted">{{ u.username }}</span>
            <button type="button" class="ms-1 font-extrabold text-bad" :aria-label="t('إزالة', 'Remove')" @click="removeUser(u.id)">✕</button>
          </span>
          <span v-if="!f.recipients.length" class="text-[10px] text-faint">{{ t('لا مستلمين.', 'No recipient.') }}</span>
        </div>
        <div v-if="level?.recipientsKept?.length" class="mt-1 text-[9.5px] text-violet">{{ t(`محفوظ من أودو بلا مستخدم في النظام: ${level.recipientsKept.length}`, `Kept from Odoo with no OPS user: ${level.recipientsKept.length}`) }}</div>
      </Field>
    </form>
    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="{ ar: 'حفظ', en: 'Save' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
