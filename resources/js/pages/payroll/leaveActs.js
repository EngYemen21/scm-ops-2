// The moves of a leave request, shared by the lists (approve / refuse in a row) and the leave drawer.
//   const acts = useLeaveActs();  acts.approve(leave) · acts.refuse(leave) · acts.submit(leave) · acts.withdraw(leave) · acts.cancel(leave)
// Each one asks what the server needs (a note, a reason of at least five characters) and posts once (idempotent).
import { api, useAction } from '@/api/client';
import { t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';

export function useLeaveActs() {
  const act = useAction({ invalidate: ['payroll'] });
  const url = (l, what) => `/payroll/leaves/${encodeURIComponent(l.id)}/${what}`;
  const who = (l) => (l.employee ? `${l.employee.nameAr || l.employee.nameEn} · ` : '');

  async function approve(l) {
    const first = l.can?.step === 'first';
    const note = await ask({
      title: first ? { ar: `اعتماد المدير للطلب ${l.number}`, en: `Manager's approval of ${l.number}` } : { ar: `اعتماد الإجازة ${l.number}`, en: `Approve leave ${l.number}` },
      sub: { ar: `${who(l)}${l.days} يوم`, en: `${who(l)}${l.days} day(s)` },
      label: { ar: 'ملاحظة (اختيارية)', en: 'Note (optional)' }, okLabel: { ar: 'اعتماد', en: 'Approve' }, tone: 'primary',
    });
    if (note === null) return undefined;
    return act.run(() => api.postIdempotent(url(l, 'approve'), note ? { note } : undefined), {
      success: first ? t('اعتُمدت الخطوة الأولى — ينتظر الطلب الموارد البشرية', 'First step approved — the request now waits for HR') : t('اعتُمدت الإجازة', 'Leave approved'),
    });
  }
  async function refuse(l) {
    const reason = await ask({ title: { ar: `رفض الطلب ${l.number}`, en: `Refuse ${l.number}` }, label: { ar: 'سبب الرفض (5 أحرف على الأقل) — يراه صاحب الطلب', en: 'Reason (at least 5 characters) — the requester sees it' }, required: true, okLabel: { ar: 'رفض', en: 'Refuse' }, tone: 'danger' });
    if (reason === null) return undefined;
    return act.run(() => api.postIdempotent(url(l, 'refuse'), { reason }), { success: t('رُفض الطلب', 'Request refused') });
  }
  async function submit(l) {
    return act.run(() => api.postIdempotent(url(l, 'submit')), { success: t('أُرسل الطلب للاعتماد', 'Request sent for approval') });
  }
  async function withdraw(l) {
    if (!(await confirm({ title: { ar: `سحب الطلب ${l.number}؟`, en: `Withdraw ${l.number}?` }, sub: { ar: 'يعود مسودة يمكنك تعديلها وإرسالها من جديد', en: 'It goes back to a draft you can edit and send again' }, tone: 'dark' }))) return undefined;
    return act.run(() => api.postIdempotent(url(l, 'withdraw')), { success: t('سُحب الطلب', 'Request withdrawn') });
  }
  async function cancel(l) {
    if (l.state === 'approved') {
      const reason = await ask({ title: { ar: `إلغاء الإجازة المعتمدة ${l.number}`, en: `Cancel approved leave ${l.number}` }, sub: { ar: 'تعود أيامها إلى الرصيد', en: 'Its days go back to the balance' }, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, required: true, okLabel: { ar: 'إلغاء الإجازة', en: 'Cancel the leave' }, tone: 'danger' });
      if (reason === null) return undefined;
      return act.run(() => api.postIdempotent(url(l, 'cancel'), { reason }), { success: t('أُلغيت الإجازة وأُعيدت أيامها', 'Leave cancelled, its days returned') });
    }
    const reason = await ask({ title: { ar: `إلغاء الطلب ${l.number}؟`, en: `Cancel ${l.number}?` }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إلغاء الطلب', en: 'Cancel the request' }, tone: 'danger' });
    if (reason === null) return undefined;
    return act.run(() => api.postIdempotent(url(l, 'cancel'), reason ? { reason } : undefined), { success: t('أُلغي الطلب', 'Request cancelled') });
  }
  return { act, approve, refuse, submit, withdraw, cancel };
}
