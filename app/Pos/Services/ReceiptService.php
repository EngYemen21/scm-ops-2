<?php

namespace App\Pos\Services;

use App\Pos\Models\PosOrder;
use App\Pos\Models\PosRegister;
use App\Pos\Support\Money;
use App\Services\Barcodes\BarcodeService;
use App\Services\Core\SettingsService;
use App\Support\AppError;

/**
 * The printable receipt of an order — plain HTML the browser prints, no printer driver: an 80 mm till slip or an A4
 * tax invoice, Arabic first and English beside it, with the VAT breakdown. A sale prints as a simplified tax invoice
 * (A4: tax invoice), a refund as a credit note naming the invoice it credits. When the register carries the seller's
 * VAT number the slip carries the QR code of the Saudi e-invoicing first phase (TLV: seller, VAT number, time, total,
 * VAT — base64); without it the receipt says that the number is not set instead of printing an invalid code.
 */
class ReceiptService
{
    public function __construct(private readonly BarcodeService $barcodes, private readonly SettingsService $settings) {}

    public function html(string $idOrNumber, string $format = '80mm'): string
    {
        $o = PosOrder::with(['lines' => fn ($q) => $q->orderBy('line_no'), 'payments' => fn ($q) => $q->orderBy('line_no'), 'payments.method:id,code,name_ar,name_en', 'customer:id,code,name_ar,name_en,vat_no', 'refundOf:id,number,invoice_number'])
            ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('POS_ORDER_NOT_FOUND', 'الطلب غير موجود', 'Order not found');
        $register = PosRegister::find($o->register_id);
        $company = (array) ($this->settings->get('company') ?? []);
        $seller = [
            'ar' => $register?->seller_name_ar ?: ($company['nameAr'] ?? ''), 'en' => $register?->seller_name_en ?: ($company['nameEn'] ?? ''),
            'vat' => $register?->seller_vat_no, 'cr' => $register?->seller_cr, 'address' => $register?->seller_address,
        ];
        $refund = $o->kind === 'refund';
        $a4 = $format === 'a4';
        $title = $refund ? ['إشعار دائن', 'Credit note'] : ($a4 ? ['فاتورة ضريبية', 'Tax invoice'] : ['فاتورة ضريبية مبسطة', 'Simplified tax invoice']);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => number_format(Money::float(Money::cents($v)), 2, '.', ',');
        $at = $o->ordered_at?->copy()->setTimezone(config('app.timezone', 'UTC'));
        $vatBp = Money::bp($o->vat_pct ?? 0);

        $rows = '';
        foreach ($o->lines as $l) {
            $qty = rtrim(rtrim(number_format((float) $l->qty, 3, '.', ''), '0'), '.');
            $lineVat = Money::vat(Money::cents($l->line_total), $vatBp);
            $disc = Money::cents($l->discount_pct) > 0 ? $e(Money::str(Money::cents($l->discount_pct))).'%' : '';
            $rows .= $a4
                ? '<tr><td>'.$e($l->sku).'</td><td>'.$e($l->name).'<div class="en">'.$e($l->name_en ?? '').'</div></td><td class="n">'.$e($qty).'</td><td class="n">'.$m($l->unit_price).'</td><td class="n">'.$disc.'</td><td class="n">'.$m($l->line_total).'</td><td class="n">'.$m(Money::str($lineVat)).'</td><td class="n">'.$m(Money::str(Money::cents($l->line_total) + $lineVat)).'</td></tr>'
                : '<tr><td colspan="3">'.$e($l->name).($l->name_en ? '<div class="en">'.$e($l->name_en).'</div>' : '').'</td></tr><tr class="sub"><td class="n">'.$e($qty).' × '.$m($l->unit_price).($disc ? ' − '.$disc : '').'</td><td></td><td class="n">'.$m($l->line_total).'</td></tr>';
        }
        $pays = '';
        foreach ($o->payments as $p) {
            $pays .= '<tr><td>'.$e($p->method?->name_ar ?? $p->method_code).' / '.$e($p->method?->name_en ?? $p->method_code).($p->reference ? ' · '.$e($p->reference) : '').'</td><td class="n">'.$m($p->amount).'</td></tr>';
        }
        if (! $refund && Money::cents($o->change_due) > 0) {
            $pays .= '<tr><td>الباقي / Change</td><td class="n">'.$m($o->change_due).'</td></tr>';
        }
        $qr = '';
        if ($seller['vat']) {
            $tlv = self::tlv([$seller['ar'] ?: $seller['en'], $seller['vat'], ($at ?? now())->copy()->utc()->format('Y-m-d\TH:i:s\Z'), Money::str(Money::cents($o->total)), Money::str(Money::cents($o->vat_amount))]);
            $qr = '<div class="qr">'.$this->barcodes->qr($tlv, 180).'</div>';
        } else {
            $qr = '<p class="muted c">الرقم الضريبي للبائع غير مُعدّ في إعداد الصندوق — لا رمز QR · Seller VAT number not set on the register — no QR code</p>';
        }
        $customer = $o->customer;
        $head = '<div class="c"><h1>'.$e($seller['ar']).'</h1>'.($seller['en'] && $seller['en'] !== $seller['ar'] ? '<div class="en">'.$e($seller['en']).'</div>' : '')
            .($seller['address'] ? '<div>'.$e($seller['address']).'</div>' : '')
            .'<div>الرقم الضريبي / VAT No: <b class="ltr">'.$e($seller['vat'] ?: '—').'</b>'.($seller['cr'] ? ' · س.ت / CR: <span class="ltr">'.$e($seller['cr']).'</span>' : '').'</div>'
            .'<h2>'.$title[0].' · '.$title[1].'</h2></div>';
        $meta = '<table class="meta">'
            .'<tr><td>رقم الفاتورة / Invoice No</td><td class="ltr">'.$e($o->invoice_number ?? '—').'</td></tr>'
            .'<tr><td>رقم الطلب / Order No</td><td class="ltr">'.$e($o->number).'</td></tr>'
            .($refund ? '<tr><td>عن الفاتورة / Credits invoice</td><td class="ltr">'.$e(($o->refundOf?->invoice_number ?? '').' · '.($o->refundOf?->number ?? '')).'</td></tr><tr><td>السبب / Reason</td><td>'.$e($o->reason).'</td></tr>' : '')
            .'<tr><td>التاريخ / Date</td><td class="ltr">'.$e($at?->format('Y-m-d H:i')).'</td></tr>'
            .'<tr><td>الكاشير / Cashier</td><td>'.$e($o->cashier_name).'</td></tr>'
            .'<tr><td>العميل / Customer</td><td>'.$e($customer?->name_ar ?? $o->customer_name).($customer?->vat_no ? ' · <span class="ltr">'.$e($customer->vat_no).'</span>' : '').'</td></tr>'
            .'</table>';
        $lineHead = $a4
            ? '<tr><th>الرمز<br>SKU</th><th>الصنف<br>Item</th><th>الكمية<br>Qty</th><th>السعر<br>Price</th><th>الخصم<br>Disc.</th><th>الصافي<br>Net</th><th>الضريبة<br>VAT</th><th>الإجمالي<br>Total</th></tr>'
            : '';
        $totals = '<table class="tot">'
            .(Money::cents($o->discount_total) > 0 ? '<tr><td>الخصم / Discount</td><td class="n">'.$m($o->discount_total).'</td></tr>' : '')
            .'<tr><td>الإجمالي قبل الضريبة / Total excl. VAT</td><td class="n">'.$m($o->subtotal).'</td></tr>'
            .'<tr><td>ضريبة القيمة المضافة '.$e(Money::str($vatBp)).'% / VAT</td><td class="n">'.$m($o->vat_amount).'</td></tr>'
            .'<tr class="grand"><td>الإجمالي شامل الضريبة / Total incl. VAT</td><td class="n">'.$m($o->total).' SAR</td></tr>'
            .'</table>';
        $footer = ($register?->receipt_footer ? '<p class="c">'.nl2br($e($register->receipt_footer)).'</p>' : '').'<p class="c muted">شكرًا لزيارتكم · Thank you</p>';
        $width = $a4 ? '190mm' : '72mm';
        $page = $a4 ? 'A4' : '80mm auto';
        $css = "@page{size:{$page};margin:".($a4 ? '10mm' : '4mm').'}*{box-sizing:border-box}body{font-family:Tahoma,Arial,sans-serif;font-size:'.($a4 ? '12px' : '11px').";color:#111;margin:0 auto;width:{$width};direction:rtl}"
            .'h1{font-size:15px;margin:2px 0}h2{font-size:13px;margin:6px 0;border-top:1px dashed #555;border-bottom:1px dashed #555;padding:3px 0}.c{text-align:center}.en{direction:ltr;color:#444;font-size:.9em}'
            .'.ltr{direction:ltr;unicode-bidi:embed}table{width:100%;border-collapse:collapse;margin:4px 0}td,th{padding:2px 3px;vertical-align:top}.n{text-align:left;direction:ltr;white-space:nowrap}'
            .'.lines th{border-bottom:1px solid #333;font-size:.9em}'.($a4 ? '.lines td{border-bottom:1px solid #ddd}' : '.lines tr.sub td{border-bottom:1px dotted #bbb;padding-bottom:4px}')
            .'.meta td:first-child{color:#555;width:45%}.tot{border-top:1px solid #333}.grand td{font-weight:bold;font-size:1.15em;border-top:1px solid #333}.qr{text-align:center;margin:6px 0}.qr svg{width:'.($a4 ? '120px' : '150px').';height:auto}.muted{color:#666;font-size:.9em}';

        return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>'.$e($o->number).'</title><style>'.$css.'</style></head><body>'
            .$head.$meta.'<table class="lines">'.$lineHead.$rows.'</table>'.$totals
            .'<table class="pay"><tr><th colspan="2" style="text-align:start">'.($refund ? 'المبالغ المردودة / Refunded' : 'المدفوعات / Payments').'</th></tr>'.$pays.'</table>'
            .$qr.$footer.'</body></html>';
    }

    /** TLV of the first-phase QR: tag, length in bytes, UTF-8 value — base64. */
    public static function tlv(array $values): string
    {
        $out = '';
        foreach (array_values($values) as $i => $v) {
            $v = (string) $v;
            $out .= chr($i + 1).chr(min(255, strlen($v))).substr($v, 0, 255);
        }

        return base64_encode($out);
    }
}
