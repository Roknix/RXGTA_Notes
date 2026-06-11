<?php
// Öffentlicher Steckbrief — KEINE Auth. Token aus ?t= oder via .htaccess-Rewrite.
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Suchmaschinen ausschließen — Header + Meta-Tag.
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['t'] ?? '');
// Token-Format: URL-safe Base64 (A-Z a-z 0-9 _ -), 8–64 Zeichen.
$tokenOk = (bool)preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token);

$profile = null;
if ($tokenOk) {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT cp.*, c.name AS char_name, c.server AS char_server, c.avatar_color
            FROM character_profile cp
            JOIN characters c ON c.id = cp.character_id
            WHERE cp.public_token = ? AND cp.public_enabled = 1
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if ($row) {
            $publicFields = json_decode($row['public_fields'] ?? '[]', true);
            if (!is_array($publicFields)) $publicFields = [];
            $profile = $row;
            $profile['public_fields'] = $publicFields;
        }
    } catch (Throwable $e) {
        error_log('[public-profile] ' . $e->getMessage());
    }
}

if (!$profile) {
    http_response_code(404);
}

// Biografie-Markdown wird clientseitig vom Toast UI Viewer gerendert (identisch zum Editor).
// Hier wird das Markdown nur sicher als JS-String eingebettet.

$fieldLabels = [
    'birthday'    => __('public.birthday'),
    'birthplace'  => __('public.birthplace'),
    'nationality' => __('public.nationality'),
    'occupation'  => __('public.occupation'),
    'height'      => __('public.height'),
    'family'      => __('public.family'),
    'appearance'  => __('public.appearance'),
];

$pageTitle = $profile
    ? trim($profile['char_name']) . ' · ' . __('public.title_suffix')
    : __('public.not_found_title');
$publicLocale = resolveLocale();
?>
<!DOCTYPE html>
<html lang="<?= h($publicLocale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= h($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php $cssVer = @filemtime(__DIR__ . '/assets/style.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/style.css?v=<?= (int)$cssVer ?>">
    <link rel="stylesheet" href="/assets/vendor/toastui/toastui-editor-viewer.min.css">
    <link rel="stylesheet" href="/assets/vendor/toastui/toastui-editor-dark.min.css">
    <style>
        body { background: var(--bg); }
        .public-wrap { max-width: 760px; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
        .public-head {
            display: flex; align-items: center; gap: 1rem;
            padding: 1.25rem; background: var(--card);
            border: 1px solid var(--border); border-radius: var(--radius);
            margin-bottom: 1.5rem;
        }
        .public-avatar {
            width: 64px; height: 64px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 1.4rem; flex-shrink: 0;
        }
        .public-head h1 { margin: 0 0 .15rem 0; font-size: 1.4rem; }
        .public-head .public-server { color: var(--text-muted); font-size: .9rem; }
        .public-section {
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 1.25rem 1.5rem; margin-bottom: 1.25rem;
        }
        .public-section h2 { margin: 0 0 .85rem 0; font-size: 1.05rem; color: var(--accent-l); }
        .public-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem 1.25rem; }
        @media (max-width: 560px) { .public-grid { grid-template-columns: 1fr; } }
        .public-grid .item .lbl { display: block; color: var(--text-muted); font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .15rem; }
        .public-grid .item .val { color: var(--text); font-size: .95rem; word-break: break-word; }
        /* Toast UI Viewer in der Biografie-Sektion (Dark-Theme über toastui-editor-dark.min.css). */
        .bio-body .toastui-editor-contents { font-family: inherit; font-size: .95rem; padding: 0; }
        .bio-body .toastui-editor-contents p { margin: 0 0 1rem 0; }
        .bio-body .toastui-editor-contents h1,
        .bio-body .toastui-editor-contents h2,
        .bio-body .toastui-editor-contents h3,
        .bio-body .toastui-editor-contents h4 { color: var(--accent-l); border-color: var(--border); }
        .bio-body .toastui-editor-contents a { color: var(--accent-l); }
        .bio-body .toastui-editor-contents code {
            background: var(--bg); color: #e879f9; padding: .1em .35em; border-radius: 3px;
        }
        .bio-body .toastui-editor-contents pre {
            background: var(--bg); border: 1px solid var(--border);
        }
        .bio-body .toastui-editor-contents blockquote {
            border-left-color: var(--accent); color: var(--text-2);
        }
        .public-register-cta {
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem;
            padding: .85rem 1.1rem; background: var(--card); border: 1px solid var(--border);
            border-radius: var(--radius); margin-bottom: 1.25rem;
        }
        .public-register-cta span { color: var(--text-2); font-size: .9rem; }
        .public-footer { color: var(--text-muted); font-size: .78rem; text-align: center; margin-top: 2rem; }
        .public-footer a { color: var(--text-muted); }
        .not-found { text-align: center; padding: 4rem 1rem; color: var(--text-muted); }
        .not-found h1 { color: var(--text); }
    </style>
</head>
<body>
<div class="public-wrap">
<?php if (isRegistrationVisible()): ?>
    <div class="public-register-cta">
        <span><?= h(__('public.register_cta')) ?></span>
        <a href="/register.php" class="btn btn-primary btn-sm"><?= h(__('public.register_btn')) ?></a>
    </div>
<?php endif; ?>
<?php if (!$profile): ?>
    <div class="not-found">
        <h1><?= h(__('public.not_found_title')) ?></h1>
        <p><?= h(__('public.not_found_text')) ?></p>
    </div>
<?php else:
    $publicFields = $profile['public_fields'];
    $color        = $profile['avatar_color'] ?: '#7c3aed';
    $charName     = (string)$profile['char_name'];
    $server       = (string)($profile['char_server'] ?? '');
?>
    <header class="public-head">
        <div class="public-avatar" style="background:<?= h($color) ?>"><?= h(avatarInitials($charName)) ?></div>
        <div>
            <h1><?= h($charName) ?></h1>
            <?php if ($server !== ''): ?>
                <div class="public-server"><?= h($server) ?></div>
            <?php endif; ?>
        </div>
    </header>

    <?php
    // Stammdaten (alle außer appearance + biography)
    $stammKeys = ['birthday','birthplace','nationality','occupation','height','family'];
    $stammVisible = array_filter($stammKeys, function($k) use ($publicFields, $profile) {
        return in_array($k, $publicFields, true) && trim((string)($profile[$k] ?? '')) !== '';
    });
    ?>
    <?php if ($stammVisible): ?>
    <section class="public-section">
        <h2><?= h(__('public.section.stammdaten')) ?></h2>
        <div class="public-grid">
            <?php foreach ($stammVisible as $k): ?>
                <div class="item">
                    <span class="lbl"><?= h($fieldLabels[$k]) ?></span>
                    <span class="val"><?= nl2br(h((string)$profile[$k])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (in_array('appearance', $publicFields, true) && trim((string)$profile['appearance']) !== ''): ?>
    <section class="public-section">
        <h2><?= h(__('public.section.appearance')) ?></h2>
        <div class="bio-body"><p><?= nl2br(h((string)$profile['appearance'])) ?></p></div>
    </section>
    <?php endif; ?>

    <?php if (in_array('biography', $publicFields, true) && trim((string)$profile['biography']) !== '')://
        // Markdown wird vom Toast UI Viewer clientseitig gerendert (identisch zum Editor).
        // Sanitizer von Toast UI bereinigt raw HTML → XSS-Schutz im Viewer selbst.
        $bioJson = json_encode(
            (string)$profile['biography'],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    ?>
    <section class="public-section">
        <h2><?= h(__('public.section.biography')) ?></h2>
        <div class="bio-body toastui-editor-dark"><div id="bio-viewer"></div></div>
    </section>
    <script src="/assets/vendor/toastui/toastui-editor-viewer.min.js"></script>
    <script nonce="<?= h(cspNonce()) ?>">
        (function() {
            var Viewer = window.toastui && window.toastui.Editor;
            if (!Viewer) return;
            new Viewer({
                el: document.getElementById('bio-viewer'),
                initialValue: <?= $bioJson ?>,
                theme: 'dark'
            });
        })();
    </script>
    <?php endif; ?>

    <?php
    $anyVisible = $stammVisible
        || (in_array('appearance', $publicFields, true) && trim((string)$profile['appearance']) !== '')
        || (in_array('biography', $publicFields, true) && trim((string)$profile['biography']) !== '');
    if (!$anyVisible): ?>
    <section class="public-section">
        <p style="color:var(--text-muted);margin:0"><?= h(__('public.no_fields')) ?></p>
    </section>
    <?php endif; ?>

    <p class="public-footer"><?= h(__('public.footer', APP_NAME)) ?></p>
<?php endif; ?>
</div>
</body>
</html>
