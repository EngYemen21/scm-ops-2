<!doctype html>
{{-- Public privacy policy for the store listings (App Store / Google Play). Plain HTML: it must load without signing in. --}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>سياسة الخصوصية — B2B ops</title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <style>
        :root { --ink: #20242e; --muted: #7d7990; --line: #e9e6f0; --bg: #f4f3f8; --brand: #1E2130; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.8 "Segoe UI", Tahoma, Arial, sans-serif; }
        header { background: var(--brand); padding: 18px 16px; text-align: center; }
        header img { height: 34px; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 22px 22px 8px; margin-bottom: 18px; }
        h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 17px; margin: 18px 0 6px; }
        .muted { color: var(--muted); font-size: 13px; } ul { padding-inline-start: 20px; } li { margin: 4px 0; }
        [lang=en] { direction: ltr; text-align: left; }
    </style>
</head>
<body>
<header><img src="/logo-white.png" alt="B2B ops"></header>
<main>
    <div class="card">
        <h1>سياسة الخصوصية — تطبيق B2B ops</h1>
        <p class="muted">آخر تحديث: {{ $updated }}</p>
        <p>B2B ops نظام تشغيلي داخلي لإدارة سلسلة الإمداد (المخزون، المبيعات، الاستلام، التجهيز، النقل والتسليم)، مخصّص لموظفي
            الجهة المالكة للحساب ومن تمنحهم صلاحية الدخول. لا يتضمن التطبيق تسجيلًا عامًا للمستخدمين.</p>
        <h2>البيانات التي نجمعها</h2>
        <ul>
            <li><b>بيانات الحساب:</b> اسم المستخدم والاسم والدور والصلاحيات — لتسجيل الدخول وتطبيق الصلاحيات.</li>
            <li><b>بيانات العمل:</b> ما يدخله المستخدم أثناء عمله (طلبات، حركات مخزون، رحلات، إثباتات تسليم) — وهي بيانات الجهة المالكة.</li>
            <li><b>الموقع الجغرافي:</b> عند تسجيل إثبات التسليم فقط، وبعد إذنك، لتوثيق مكان التسليم. لا يتتبع التطبيق موقعك في الخلفية.</li>
            <li><b>الكاميرا:</b> لقراءة الباركود ورموز QR وتصوير إثبات التسليم، بعد إذنك. لا تُحفظ الصور إلا ما ترفقه أنت بإثبات التسليم.</li>
            <li><b>سجل التدقيق:</b> العمليات التي تُجرى داخل النظام مع وقتها ومنفّذها، لأغراض الرقابة الداخلية.</li>
        </ul>
        <h2>كيف نستخدمها</h2>
        <p>لتشغيل النظام فقط: تنفيذ العمليات، تطبيق الصلاحيات، والتقارير الداخلية. <b>لا نبيع البيانات ولا نشاركها</b> مع أي طرف لأغراض
            إعلانية، ولا يحتوي التطبيق على إعلانات أو أدوات تتبّع إعلاني.</p>
        <h2>مقدّمو الخدمة</h2>
        <p>تُستضاف البيانات لدى مزوّدي البنية التحتية (الخوادم وقاعدة البيانات)، وتُعرض الخرائط عبر Mapbox، ومواقع المركبات من مزوّد
            التتبع المتعاقد معه. يعالج هؤلاء البيانات نيابةً عنا ولتشغيل الخدمة فقط.</p>
        <h2>الحماية والاحتفاظ</h2>
        <p>الاتصال مشفّر (HTTPS)، وكلمات المرور مخزّنة بشكل مجزّأ غير قابل للاسترجاع، والوصول محكوم بالأدوار. تُحفظ بيانات العمل طوال
            مدة استخدام الجهة للنظام، ومسارات المركبات 7 أيام.</p>
        <h2>حقوقك</h2>
        <p>يمكنك طلب الاطلاع على بياناتك أو تصحيحها أو حذف حسابك عبر مسؤول النظام في جهتك أو بالتواصل معنا على
            <a href="mailto:{{ $contact }}">{{ $contact }}</a>.</p>
    </div>

    <div class="card" lang="en">
        <h1>Privacy Policy — B2B ops</h1>
        <p class="muted">Last updated: {{ $updated }}</p>
        <p>B2B ops is an internal supply-chain operations system (inventory, sales, receiving, fulfilment, transport and
            delivery) for the staff of the account-owning organisation and the people it grants access to. There is no public sign-up.</p>
        <h2>Data we collect</h2>
        <ul>
            <li><b>Account data:</b> username, name, role and permissions — to sign in and enforce access rights.</li>
            <li><b>Business data:</b> what users enter while working (orders, stock movements, trips, proofs of delivery).</li>
            <li><b>Location:</b> only when a proof of delivery is recorded, with your permission. No background tracking.</li>
            <li><b>Camera:</b> to scan barcodes / QR codes and photograph a delivery, with your permission.</li>
            <li><b>Audit log:</b> actions performed in the system, with time and user, for internal control.</li>
        </ul>
        <h2>How we use it</h2>
        <p>Only to operate the system. <b>We do not sell or share data</b> for advertising; the app contains no ads or ad tracking.</p>
        <h2>Service providers</h2>
        <p>Hosting and database providers, Mapbox for maps, and the contracted vehicle-tracking provider process data on our
            behalf solely to run the service.</p>
        <h2>Security and retention</h2>
        <p>Traffic is encrypted (HTTPS), passwords are stored hashed, access is role-based. Business data is kept while the
            organisation uses the system; vehicle trails for 7 days.</p>
        <h2>Your rights</h2>
        <p>Ask your organisation's administrator, or contact <a href="mailto:{{ $contact }}">{{ $contact }}</a>, to access or
            correct your data or delete your account.</p>
    </div>
</main>
</body>
</html>
