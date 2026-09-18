<?php
declare(strict_types=1);

// Only generic public copy belongs here. Never expose member or event data.
$pageTitle = 'Familienkalender – Zusammen erinnern. Mehr Freude teilen.';
$description = 'Geburtstage, Hochzeitstage und besondere Anlässe gemeinsam im Blick behalten. Ein Familienkalender mit persönlichen Konten und Erinnerungen per E-Mail.';
// Stable absolute URLs come exclusively from the private installation config.
$siteUrl = rtrim(config()['base_url'], '/') . '/';
$hasPublicUrl = config()['base_url'] !== '';
$socialImagePath = 'assets/social-preview.jpg';
$socialImage = $siteUrl . $socialImagePath;
$socialImageAlt = 'Kalender mit Herz neben Magnolienblüten: Familienkalender. Zusammen erinnern. Mehr Freude teilen.';
$escapeMeta = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$structuredData = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebSite', '@id' => $siteUrl . '#website',
            'url' => $siteUrl, 'name' => 'Familienkalender',
            'description' => $description, 'inLanguage' => 'de-DE',
        ],
        [
            '@type' => 'WebPage', '@id' => $siteUrl . '#webpage',
            'url' => $siteUrl, 'name' => $pageTitle, 'description' => $description,
            'isPartOf' => ['@id' => $siteUrl . '#website'],
            'primaryImageOfPage' => ['@id' => $siteUrl . '#primaryimage'],
            'inLanguage' => 'de-DE',
        ],
        [
            '@type' => 'ImageObject', '@id' => $siteUrl . '#primaryimage',
            'url' => $socialImage, 'contentUrl' => $socialImage,
            'width' => 1200, 'height' => 630, 'encodingFormat' => 'image/jpeg',
            'caption' => $socialImageAlt, 'representativeOfPage' => true,
        ],
    ],
];
?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escapeMeta($pageTitle) ?></title>
    <meta name="description" content="<?= $escapeMeta($description) ?>">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#547768">
    <meta name="color-scheme" content="light">
    <meta name="application-name" content="Familienkalender">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Familienkalender">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="format-detection" content="telephone=no">
    <meta property="og:title" content="<?= $escapeMeta($pageTitle) ?>">
    <meta property="og:description" content="<?= $escapeMeta($description) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Familienkalender">
    <meta property="og:locale" content="de_DE">
<?php if ($hasPublicUrl): ?>
    <link rel="canonical" href="<?= $escapeMeta($siteUrl) ?>">
    <meta property="og:url" content="<?= $escapeMeta($siteUrl) ?>">
    <meta property="og:image" content="<?= $escapeMeta($socialImage) ?>">
<?php if (str_starts_with($socialImage, 'https://')): ?>
    <meta property="og:image:secure_url" content="<?= $escapeMeta($socialImage) ?>">
<?php endif; ?>
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= $escapeMeta($socialImageAlt) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $escapeMeta($pageTitle) ?>">
    <meta name="twitter:description" content="<?= $escapeMeta($description) ?>">
    <meta name="twitter:image" content="<?= $escapeMeta($socialImage) ?>">
    <meta name="twitter:image:alt" content="<?= $escapeMeta($socialImageAlt) ?>">
    <script type="application/ld+json" nonce="<?= $escapeMeta($pageNonce) ?>"><?= json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>
<?php endif; ?>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="assets/family.svg" type="image/svg+xml" sizes="any">
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png" sizes="180x180">
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/app.js" defer></script>
