<!doctype html>
{{-- translate="no": browser auto-translation rewrites text nodes and breaks a reactive DOM. --}}
<html lang="ar" dir="rtl" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1E2130">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="B2B ops">
    <link rel="apple-touch-icon" href="/favicon.png">
    <meta name="google" content="notranslate">
    <title>B2B ops — نظام عمليات سلسلة الإمداد</title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700;800&family=Quicksand:wght@500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
    <script>
      // Fail-safe: if the application has not mounted 12 s after the page loaded (blocked script, stale cache, broken
      // network), say so instead of leaving a blank screen, and offer a full reload.
      setTimeout(function () {
        if (document.documentElement.dataset.mounted) return;
        var d = document.createElement('div');
        d.style.cssText = 'position:fixed;inset:0;display:flex;align-items:center;justify-content:center;font-family:Almarai,sans-serif;background:#f4f3f8;color:#20242e;text-align:center;padding:24px;z-index:9999';
        d.innerHTML = '<div><div style="font-size:18px;font-weight:800">تعذّر تحميل التطبيق</div><div style="margin:8px 0 16px;color:#7d7990;font-size:13px">لم تُحمَّل ملفات الواجهة — قد تكون نسخة قديمة محفوظة في المتصفح أو الاتصال متقطع.</div><button onclick="location.reload()" style="background:#1E2130;color:#fff;border:0;border-radius:10px;padding:10px 22px;font-weight:700;font-size:14px;cursor:pointer">إعادة التحميل</button></div>';
        document.body.appendChild(d);
      }, 12000);
    </script>
    <script>
      // Fail-safe: if the application has not mounted 12 s after the page loaded (blocked script, stale cache, broken
      // network), say so instead of leaving a blank screen, and offer a full reload.
      setTimeout(function () {
        if (document.documentElement.dataset.mounted) return;
        var d = document.createElement('div');
        d.style.cssText = 'position:fixed;inset:0;display:flex;align-items:center;justify-content:center;font-family:Almarai,sans-serif;background:#f4f3f8;color:#20242e;text-align:center;padding:24px;z-index:9999';
        d.innerHTML = '<div><div style="font-size:18px;font-weight:800">تعذّر تحميل التطبيق</div><div style="margin:8px 0 16px;color:#7d7990;font-size:13px">لم تُحمَّل ملفات الواجهة — قد تكون نسخة قديمة محفوظة في المتصفح أو الاتصال متقطع.</div><button onclick="location.reload()" style="background:#1E2130;color:#fff;border:0;border-radius:10px;padding:10px 22px;font-weight:700;font-size:14px;cursor:pointer">إعادة التحميل</button></div>';
        document.body.appendChild(d);
      }, 12000);
    </script>
</body>
</html>
