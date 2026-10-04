<?php

namespace App\Integration\Support;

/**
 * A first guess of a mirrored product's physical attributes, read from its pack description ("كيس 40 كجم",
 * "كرتون 12×1 لتر"). OPS needs weight and dimensions for every product (truck capacity, bin fit), and the selling
 * system only knows the pack text — so the tower pre-fills the "create in OPS" form with these numbers.
 *
 * They are ESTIMATES: the weight is the stated net content (1 litre ≈ 1 kg), the box is a 4:3:2.5 carton sized for
 * that weight. A person confirms or corrects them; measured values replace them from the product screen at any time.
 */
final class PackEstimator
{
    /** kg per unit of measure as written in Arabic pack texts */
    private const MEASURES = ['كجم' => 1.0, 'كغ' => 1.0, 'كيلو' => 1.0, 'لتر' => 1.0, 'جم' => 0.001, 'غرام' => 0.001, 'مل' => 0.001];

    /** first word of the pack text → OPS unit of measure code */
    private const UOMS = ['كيس' => 'bag', 'شوال' => 'bag', 'كرتون' => 'ctn', 'سطل' => 'pail', 'صندوق' => 'box', 'علبة' => 'can',
        'تنكة' => 'tin', 'جالون' => 'btl', 'عبوة' => 'btl', 'زجاج' => 'btl', 'رزمة' => 'pack', 'شد' => 'pack', 'حبة' => 'pack'];

    private const DEFAULT_KG = 10.0;

    /**
     * @return array{weightKg: float, lengthCm: int, widthCm: int, heightCm: int, storageClass: string, uomCode: ?string, basis: string}
     *                                                                                                                                  basis: 'pack' (weight stated in the pack text) | 'name' (stated in the product name × pack count) | 'default'
     */
    public static function estimate(string $name, ?string $pack, ?string $category = null): array
    {
        $pack = self::digits((string) $pack);
        $name = self::digits($name);
        $empties = (bool) preg_match('/أكياس|عبوات|أكواب|أغطية|تغليف/u', $name.' '.$category); // containers: the measure is capacity, not weight

        $basis = 'pack';
        $kg = $empties ? null : self::statedWeight($pack);
        if ($kg === null && ! $empties) {
            $each = self::statedWeight($name);
            $count = preg_match('/(\d+)\s*(?:حبة|حبات|علبة|علب|عبوة)/u', $pack, $m) ? (int) $m[1] : 1;
            if ($each !== null && $count >= 1 && $count <= 200) {
                $kg = $each * $count;
                $basis = 'name';
            }
        }
        if ($kg === null || $kg <= 0) {
            $kg = $empties ? 5.0 : self::DEFAULT_KG;
            $basis = 'default';
        }
        $kg = round(min(max($kg, 0.1), 1000), 2);

        // a carton of ratio 4 : 3 : 2.5 holding ~1.5 litres per kg (product + packing)
        $k = (($kg * 1.5 * 1000) / (4 * 3 * 2.5)) ** (1 / 3);
        $first = preg_split('/\s+/u', trim($pack))[0] ?? '';

        return [
            'weightKg' => $kg,
            'lengthCm' => max(10, (int) round(4 * $k)), 'widthCm' => max(8, (int) round(3 * $k)), 'heightCm' => max(5, (int) round(2.5 * $k)),
            'storageClass' => self::storageClass($name.' '.$category),
            'uomCode' => self::UOMS[$first] ?? null,
            'basis' => $basis,
        ];
    }

    /** "4×4 لتر" → 16, "1 كجم × 10" → 10, "40 كجم" → 40, "24×330 مل" → 7.92; null when the text states no measure */
    public static function statedWeight(string $text): ?float
    {
        $unit = '('.implode('|', array_keys(self::MEASURES)).')';
        $num = '(\d+(?:\.\d+)?)';
        if (preg_match("/{$num}\s*[×xX*]\s*{$num}\s*{$unit}/u", $text, $m)) {
            return (float) $m[1] * (float) $m[2] * self::MEASURES[$m[3]];
        }
        if (preg_match("/{$num}\s*{$unit}\s*[×xX*]\s*{$num}/u", $text, $m)) {
            return (float) $m[1] * self::MEASURES[$m[2]] * (float) $m[3];
        }
        if (preg_match("/{$num}\s*{$unit}/u", $text, $m)) {
            return (float) $m[1] * self::MEASURES[$m[2]];
        }

        return null;
    }

    private static function storageClass(string $text): string
    {
        if (preg_match('/مجمد/u', $text)) {
            return 'frozen';
        }

        return preg_match('/لبنة|جبنة|قشطة|زبدة|طازج|دجاج|لحم/u', $text) ? 'chilled' : 'ambient';
    }

    private static function digits(string $s): string
    {
        return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);
    }
}
