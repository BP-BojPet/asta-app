<?php
$GLOBALS['ASTA_BASE'] = '../';
require __DIR__ . '/../lib.php';
db();
require_admin(); // Admin & Vorsitz

$md = @file_get_contents(__DIR__ . '/../README.md');

page_header('Anleitung', true);
?>
<p class="small"><a href="index.php">‹ Verwaltung</a></p>
<div class="events-toolbar"><h1><i class="ti ti-book-2" style="color:var(--petrol)"></i> Anleitung</h1></div>
<div class="card markdown">
  <?php if ($md === false || trim($md) === ''): ?>
    <p class="empty"><i class="ti ti-info-circle"></i> Die Anleitung (README.md) wurde nicht gefunden.</p>
  <?php else: ?>
    <?= render_markdown($md) ?>
  <?php endif; ?>
</div>
<?php
page_footer();
