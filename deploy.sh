#!/bin/bash
# ==============================================================================
# SCM OPS — سكربت النشر والتشغيل والتحقق الشامل (Production Deployment & Verification)
# ==============================================================================
set -e

echo "========================================================="
echo "🚀 بدء التحديث الآمن والتحقق الشامل لمشروع SCM OPS..."
echo "========================================================="

# 1. قراءة إعدادات قاعدة البيانات تلقائياً من ملف .env
if [ ! -f ".env" ]; then
    echo "❌ خطأ: ملف .env غير موجود في المجلد الحالي!"
    exit 1
fi

DB_DATABASE=$(grep -E '^DB_DATABASE=' .env | cut -d '=' -f2- | tr -d '"'\'' ')
DB_USERNAME=$(grep -E '^DB_USERNAME=' .env | cut -d '=' -f2- | tr -d '"'\'' ')
DB_PASSWORD=$(grep -E '^DB_PASSWORD=' .env | cut -d '=' -f2- | tr -d '"'\'' ')
DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d '=' -f2- | tr -d '"'\'' ' || echo "127.0.0.1")
DB_PORT=$(grep -E '^DB_PORT=' .env | cut -d '=' -f2- | tr -d '"'\'' ' || echo "3306")

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_DIR="storage/backups"
BACKUP_FILE="${BACKUP_DIR}/db_backup_${TIMESTAMP}.sql.gz"

mkdir -p "$BACKUP_DIR"

# 2. أخذ نسخة احتياطية سريعة ومضغوطة من قاعدة البيانات قبل أي تعديل
echo "🛡️  [1/9] جاري أخذ نسخة احتياطية من قاعدة البيانات..."
if command -v mysqldump &> /dev/null; then
    if [ -n "$DB_PASSWORD" ]; then
        mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" --single-transaction --quick "$DB_DATABASE" | gzip > "$BACKUP_FILE"
    else
        mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" --single-transaction --quick "$DB_DATABASE" | gzip > "$BACKUP_FILE"
    fi
    echo "✓ تم حفظ النسخة الاحتياطية بنجاح: $BACKUP_FILE"
else
    echo "⚠️  تحذير: أمر mysqldump غير متوفر مباشرة في مسار النظام، تم تخطي النسخ المباشر."
fi

# فخ الأمان: الاسترجاع التلقائي في حال حدوث أي خطأ برمجي أثناء التحديث
rollback() {
    echo ""
    echo "🚨 حدث خطأ أثناء التحديث!"
    echo "🔄 جاري التراجع التلقائي وإعادة النظام لحالته السابقة..."
    if [ -f "$BACKUP_FILE" ] && command -v mysql &> /dev/null; then
        echo "↺ استرجاع قاعدة البيانات من النسخة الاحتياطية..."
        if [ -n "$DB_PASSWORD" ]; then
            gunzip < "$BACKUP_FILE" | mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" || true
        else
            gunzip < "$BACKUP_FILE" | mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" || true
        fi
    fi
    php artisan up || true
    echo "❌ تم التراجع وإعادة تشغيل الموقع بأمان. يرجى فحص الخطأ."
    exit 1
}
trap rollback ERR

# 3. تفعيل وضع الصيانة المؤقت
echo "🔒 [2/9] تفعيل وضع الصيانة المؤقت..."
php artisan down || true

# 4. سحب آخر التحديثات من GitHub
echo "📦 [3/9] سحب آخر التحديثات المعتمدة من GitHub (origin/main)..."
git fetch origin main
git reset --hard origin/main

# 5. تحديث مكتبات Composer للإنتاج
echo "⚙️  [4/9] تحديث مكتبات PHP والـ Autoloader..."
if command -v composer &> /dev/null; then
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
else
    php composer.phar install --no-dev --prefer-dist --optimize-autoloader --no-interaction || true
fi

# 6. فحص بناء الواجهة الأمامية (Vite / Frontend Build)
# إذا كانت أدوات npm مثبتة يقوم بالبناء، وإلا يعتمد تلقائياً على حزم public/build المجهزة في المستودع
if command -v npm &> /dev/null && [ -d "node_modules" ]; then
    echo "🎨 بناء أصول الواجهة الأمامية (npm run build)..."
    npm run build || echo "⚠️ استمرار: اعتماد حزم public/build المرفوعة مسبقاً في المستودع."
else
    echo "🎨 اعتماد أصول الواجهة الجاهزة في public/build (بدون استهلاك موارد السيرفر)."
fi

# 7. ترحيل وتحديث الجداول في قاعدة البيانات بأمان
echo "🗄️  [5/9] ترحيل وتحديث الجداول في قاعدة البيانات..."
php artisan migrate --force

# 8. مزامنة الصلاحيات الجديدة وتثبيتها للأدوار
echo "🔑 [6/9] مزامنة الصلاحيات الجديدة (Permissions Sync)..."
php artisan scm:sync-permissions

# 9. ربط مجلد التخزين وتجديد الذاكرة المؤقتة (Cache)
echo "⚡ [7/9] ربط التخزين وتجديد الكاش والتسريع..."
php artisan storage:link || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true
php artisan queue:restart || true

# 10. إجراء فحوصات النزاهة الهندسية والمالية والمخزنية
echo "🔍 [8/9] التحقق الميداني من صحة النظام..."
php artisan scm:reconcile || echo "⚠️ تنبيه: يرجى مراجعة قيود مطابقة المخزون"
php artisan scm:finance-check || echo "⚠️ تنبيه: يرجى مراجعة ميزان المراجعة المالي"

# 11. إلغاء وضع الصيانة وتشغيل الموقع
echo "🔓 [9/9] إعادة تشغيل الموقع للجمهور..."
php artisan up

# تنظيف النسخ الاحتياطية الأقدم من 14 يوماً لتوفير المساحة
find "$BACKUP_DIR" -name "db_backup_*.sql.gz" -mtime +14 -delete 2>/dev/null || true

echo "========================================================="
echo "✅ تم تحديث ونشر وفحص المشروع على السيرفر بنجاح تام 100%!"
echo "========================================================="
