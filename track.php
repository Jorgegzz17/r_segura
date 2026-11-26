<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Seguimiento SOS | Rutas Seguras</title>
    <link rel="stylesheet" href="assets/css/brand-theme.css?v=<?= filemtime(__DIR__ . '/assets/css/brand-theme.css') ?>">
    <style>
        :root { color-scheme: light; font-family: Inter, system-ui, sans-serif; color: #292629; background: #f4f2ee; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; padding: 24px 16px; }
        main { width: min(100%, 620px); margin: 5vh auto; }
        .card { padding: clamp(22px, 5vw, 36px); border: 1px solid #e8c9c9; border-radius: 20px; background: #fff; box-shadow: 0 12px 40px rgba(48, 22, 26, .08); }
        .badge { display: inline-flex; align-items: center; gap: 8px; padding: 7px 11px; border-radius: 999px; color: #9f1239; background: #fff1f2; font-size: 12px; font-weight: 800; }
        h1 { margin: 18px 0 8px; font-size: clamp(22px, 6vw, 30px); line-height: 1.15; }
        p { line-height: 1.55; }
        .status { margin: 18px 0; padding: 14px; border-radius: 12px; color: #334155; background: #f8fafc; font-size: 14px; }
        .coordinates { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 18px 0; }
        .coordinate { padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; overflow-wrap: anywhere; }
        .coordinate small { display: block; margin-bottom: 5px; color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .coordinate strong { font-size: 14px; }
        .map-link { display: inline-flex; justify-content: center; width: 100%; padding: 14px 18px; border-radius: 12px; color: white; background: #6b1426; font-size: 14px; font-weight: 800; text-align: center; text-decoration: none; }
        .map-link[aria-disabled="true"] { opacity: .5; pointer-events: none; }
        .privacy { margin: 18px 0 0; color: #64748b; font-size: 12px; }
        @media (max-width: 380px) { body { padding: 14px 10px; } .coordinates { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="brand-page">
    <main>
        <section class="card" aria-labelledby="tracking-title">
            <span class="badge"><span aria-hidden="true">●</span> Aviso SOS</span>
            <h1 id="tracking-title">Seguimiento de emergencia</h1>
            <p>Esta página muestra la última ubicación compartida por la persona que activó el SOS.</p>
            <p id="tracking-status" class="status" role="status" aria-live="polite">Conectando con el dispositivo…</p>
            <div class="coordinates">
                <div class="coordinate"><small>Latitud</small><strong id="tracking-latitude">—</strong></div>
                <div class="coordinate"><small>Longitud</small><strong id="tracking-longitude">—</strong></div>
            </div>
            <p id="tracking-updated" class="privacy">Esperando la primera actualización.</p>
            <a id="tracking-map-link" class="map-link" href="#" aria-disabled="true" target="_blank" rel="noopener noreferrer">Abrir ubicación en mapas</a>
            <p class="privacy">El enlace deja de mostrar la ubicación cuando la persona detiene el SOS o al cumplirse dos horas. Si existe peligro inmediato, llama al 911.</p>
        </section>
    </main>
    <script src="assets/js/live-tracking.js?v=<?= filemtime(__DIR__ . '/assets/js/live-tracking.js') ?>"></script>
</body>
</html>
