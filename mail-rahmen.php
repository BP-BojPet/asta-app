<?php
/**
 * Der eine Rahmen für alle Mails der App.
 *
 * Mailtexte bleiben reiner Text – so pflegt man sie in der Verwaltung, und so gehen sie als
 * Textfassung mit. Das Aussehen entsteht erst hier: Kopf mit Logo und Namen, der Text als
 * Absätze, Links in eigener Zeile als Knopf, ein Login-Code als Kasten, darunter ein Fuß.
 * Verschickt wird beides zusammen (multipart/alternative): Programme, die kein HTML zeigen,
 * nehmen den Text, und Spamfilter werten reine HTML-Mails ab.
 *
 * Bewusst OHNE lib.php und ohne Abhängigkeit – wie mail-pool.php: Terminplaner, Umfragen und
 * externe Events verschicken ohne die App-Bibliothek. Name, Logo-Adresse und App-Adresse
 * spiegelt die App deshalb ins gemeinsame Mail-Konto (mail_rahmen_spiegeln()); dort liest
 * mail_rahmen_vorgaben() sie für alle Bereiche.
 *
 * Mail-Programme sind eigen: Stile stehen inline, das Gerüst ist eine Tabelle (Outlook), und
 * nichts hängt an Bildern – wer Bilder blockiert, sieht statt des Logos den Namen.
 */

require_once __DIR__ . '/mail-pool.php';

/** Name, Logo und App-Adresse aus dem Mail-Konto (von der App gespiegelt). */
function mail_rahmen_vorgaben(): array
{
    return [
        'name'  => mail_pool_setting('rahmen_name', ''),
        'logo'  => mail_pool_setting('rahmen_logo', ''),
        'basis' => mail_pool_setting('rahmen_basis', ''),
    ];
}

/** Von der App aufgerufen: aktuelle Angaben ins Mail-Konto schreiben, nur wenn sie sich ändern. */
function mail_rahmen_spiegeln(string $name, string $logo, string $basis): void
{
    try {
        foreach (['rahmen_name' => $name, 'rahmen_logo' => $logo, 'rahmen_basis' => $basis] as $k => $v) {
            if (mail_pool_setting($k, "\0") !== $v) mail_pool_setting_set($k, $v);
        }
    } catch (\Throwable $e) { /* nie den Versand kosten */ }
}

function mail_rahmen_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Eine Textzeile als HTML: maskiert, **fett**, nackte Adressen klickbar. */
function mail_rahmen_zeile(string $zeile): string
{
    $x = mail_rahmen_h($zeile);
    $x = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $x);
    // Ein Punkt oder Komma am Satzende gehört nicht mehr zur Adresse.
    return (string)preg_replace('/(https?:\/\/[^\s<]*[^\s<.,;:!?)\]])/i',
        '<a href="$1" style="color:#0E5C73;text-decoration:underline;word-break:break-all">$1</a>', $x);
}

function mail_rahmen_ist_link(string $zeile): bool
{
    return (bool)preg_match('/^https?:\/\/\S+$/i', trim($zeile));
}

/** Kurz-Code wie „A1B2-C3D4" – bekommt einen eigenen Kasten. */
function mail_rahmen_ist_code(string $zeile): bool
{
    return (bool)preg_match('/^[A-Z0-9]{3,8}(-[A-Z0-9]{3,8})+$/', trim($zeile));
}

function mail_rahmen_knopf(string $url, string $text): string
{
    $u = mail_rahmen_h($url);
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px"><tr>'
        . '<td class="mr-knopf" bgcolor="#0E5C73" style="border-radius:10px;background:#0E5C73">'
        . '<a href="' . $u . '" style="display:inline-block;padding:12px 22px;font-weight:700;font-size:15px;white-space:nowrap;'
        . 'color:#ffffff;text-decoration:none;border-radius:10px">' . mail_rahmen_h($text) . ' &rarr;</a>'
        . '</td></tr></table>';
}

/**
 * Reinen Mailtext in HTML-Inhalt übersetzen.
 *
 * Ein Link allein auf seiner Zeile wird ein Knopf. Steht direkt davor eine kurze Zeile mit
 * Doppelpunkt („Hier eintragen:"), wird sie zur Aufschrift; sonst bleibt sie Text, und der
 * Knopf heißt „Link öffnen". So sehen die bestehenden Vorlagen ohne Umbau ordentlich aus.
 */
function mail_rahmen_inhalt(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    $absaetze = preg_split('/\n[ \t]*\n+/', $text) ?: [];
    $o = '';
    $p = 'margin:0 0 16px;font-size:15px;line-height:1.6;color:#1c2b30';
    foreach ($absaetze as $absatz) {
        $zeilen = explode("\n", rtrim($absatz));
        $puffer = [];
        $raus = function () use (&$puffer, &$o, $p) {
            if (!$puffer) return;
            $o .= '<p class="mr-text" style="' . $p . '">' . implode('<br>', array_map('mail_rahmen_zeile', $puffer)) . '</p>';
            $puffer = [];
        };
        foreach ($zeilen as $z) {
            if (mail_rahmen_ist_link($z)) {
                $vorher = $puffer ? rtrim((string)end($puffer)) : '';
                $aufschrift = preg_match('~/login\.php\?~', $z) ? 'Jetzt anmelden' : 'Link öffnen';
                if ($vorher !== '' && str_ends_with($vorher, ':') && mb_strlen($vorher) <= 31) {
                    array_pop($puffer);
                    $aufschrift = rtrim(substr($vorher, 0, -1));
                }
                $raus();
                $o .= mail_rahmen_knopf(trim($z), $aufschrift);
                continue;
            }
            if (mail_rahmen_ist_code($z) && count($zeilen) === 1) {
                $o .= '<div class="mr-code" style="margin:4px 0 18px;padding:14px 18px;background:#e3eef1;border-radius:10px;'
                    . 'font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:24px;font-weight:700;'
                    . 'letter-spacing:.12em;white-space:nowrap;color:#0A4757;text-align:center">'
                    // Knöpfe mit Skript gibt es in Mails nicht (jedes Mail-Programm entfernt es).
                    // user-select:all ist das Nächstbeste: Ein Tipp markiert den ganzen Code, dann
                    // „Kopieren". Der Bindestrich stört beim Einfügen nicht, die App filtert ihn.
                    . '<span style="-webkit-user-select:all;user-select:all">' . mail_rahmen_h(trim($z)) . '</span></div>';
                continue;
            }
            $puffer[] = $z;
        }
        $raus();
    }
    return $o;
}

/** Fertiges HTML (etwa eine eigene HTML-Vorlage) als Textfassung. */
function mail_rahmen_als_text(string $html): string
{
    $t = preg_replace('/<a\s[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $html);
    $t = preg_replace('/<br\s*\/?>/i', "\n", (string)$t);
    $t = preg_replace('/<\/(p|div|h[1-6]|li|tr|table)>/i', "\n\n", (string)$t);
    $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES, 'UTF-8');
    $t = preg_replace("/[ \t]+\n/", "\n", $t);
    return trim((string)preg_replace("/\n{3,}/", "\n\n", (string)$t));
}

/**
 * Die ganze Mail als HTML-Seite.
 * $o: name (Absender, im Kopf), logo (absolute Bild-Adresse oder ''), basis (App-Adresse für
 * den Fuß oder ''), vorschau (Text für die Vorschauzeile im Posteingang).
 */
function mail_rahmen_seite(string $inhaltHtml, array $o): string
{
    $name  = trim((string)($o['name'] ?? ''));
    $logo  = trim((string)($o['logo'] ?? ''));
    $basis = trim((string)($o['basis'] ?? ''));
    $vorschau = trim((string)($o['vorschau'] ?? ''));

    // Kopf: Logo und Name nebeneinander. Das Logo sitzt auf einer weißen Kachel, damit es
    // auch im Dunkelmodus auf seinem gewohnten Grund steht.
    $kopf = '';
    if ($logo !== '' || $name !== '') {
        $kopf = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>';
        if ($logo !== '') {
            $kopf .= '<td class="mr-logo" bgcolor="#ffffff" style="background:#ffffff;border-radius:10px;padding:6px 8px">'
                . '<img src="' . mail_rahmen_h($logo) . '" alt="" height="34" style="display:block;height:34px;width:auto;border:0;outline:none"></td>';
        }
        if ($name !== '') {
            $kopf .= '<td class="mr-name" style="padding-left:' . ($logo !== '' ? '12' : '0') . 'px;font-size:16px;font-weight:800;'
                . 'color:#0E5C73;letter-spacing:.01em">' . mail_rahmen_h($name) . '</td>';
        }
        $kopf .= '</tr></table>';
    }

    $fuss = 'Diese Mail wurde automatisch verschickt' . ($name !== '' ? ' – ' . mail_rahmen_h($name) : '') . '.';
    if ($basis !== '') {
        $fuss .= '<br><a href="' . mail_rahmen_h($basis) . '" style="color:#5f7178;text-decoration:underline">'
            . mail_rahmen_h((string)preg_replace('#^https?://#', '', $basis)) . '</a>';
    }

    return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
        . '<title>' . mail_rahmen_h($name) . '</title>'
        . '<style>'
        . '@media (prefers-color-scheme: dark){'
        . '.mr-bg{background:#0f1a1d!important}'
        . '.mr-karte{background:#172428!important;border-color:#27383d!important}'
        . '.mr-text,.mr-text strong{color:#e3ecee!important}'
        . '.mr-text a{color:#7cc4d6!important}'
        . '.mr-code{background:#0f2a31!important;color:#bfe3ec!important}'
        . '.mr-knopf,.mr-knopf a{background:#1f7f99!important}'
        . '.mr-fuss,.mr-fuss a{color:#8fa3a9!important}'
        . '.mr-logo{background:#ffffff!important}'
        . '.mr-name{color:#7cc4d6!important}'
        . '}'
        . '@media (max-width:620px){.mr-innen{padding:22px 20px!important}}'
        . '</style></head>'
        . '<body class="mr-bg" style="margin:0;padding:0;background:#eef4f5;-webkit-text-size-adjust:100%">'
        . ($vorschau !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . mail_rahmen_h($vorschau) . '</div>' : '')
        . '<table role="presentation" class="mr-bg" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef4f5" style="background:#eef4f5">'
        . '<tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;'
        . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif">'
        . ($kopf !== '' ? '<tr><td style="padding:0 4px 14px">' . $kopf . '</td></tr>' : '')
        . '<tr><td class="mr-karte" bgcolor="#ffffff" style="background:#ffffff;border:1px solid #d9e2e5;border-top:4px solid #0E5C73;border-radius:14px">'
        . '<div class="mr-innen" style="padding:30px 34px 16px">' . $inhaltHtml . '</div>'
        . '</td></tr>'
        . '<tr><td class="mr-fuss" style="padding:16px 6px 0;font-size:12px;line-height:1.5;color:#5f7178;text-align:center">' . $fuss . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Die erste richtige Zeile nach der Anrede – für die Vorschau im Posteingang. */
function mail_rahmen_vorschau(string $text): string
{
    foreach (preg_split('/\n\s*\n/', str_replace(["\r\n", "\r"], "\n", trim($text))) ?: [] as $a) {
        $a = trim($a);
        if ($a === '' || preg_match('/^(hallo|hi|liebe|lieber|moin|guten)\b[^\n]{0,40}$/iu', $a)) continue;
        $z = trim((string)preg_replace('/\s+/', ' ', strip_tags($a)));
        return mb_strlen($z) > 110 ? mb_substr($z, 0, 107) . '…' : $z;
    }
    return '';
}

/**
 * Text- und HTML-Fassung aus einem Mailtext bauen.
 * $istHtml: $body ist schon HTML (eigene HTML-Vorlage) – dann wandert er unverändert in den
 * Rahmen, und die Textfassung entsteht daraus. Eine komplette HTML-Seite bleibt, wie sie ist.
 * Rückgabe: ['text' => …, 'html' => …]
 */
function mail_rahmen_bauen(string $body, bool $istHtml, array $o = []): array
{
    $o += mail_rahmen_vorgaben();
    if ($istHtml) {
        $text = mail_rahmen_als_text($body);
        if (preg_match('/<html[\s>]/i', $body)) return ['text' => $text, 'html' => $body];
        return ['text' => $text, 'html' => mail_rahmen_seite($body, $o + ['vorschau' => mail_rahmen_vorschau($text)])];
    }
    return ['text' => $body, 'html' => mail_rahmen_seite(mail_rahmen_inhalt($body), $o + ['vorschau' => mail_rahmen_vorschau($body)])];
}

/**
 * Beides als multipart/alternative verpacken.
 * Rückgabe: [Content-Type-Kopfzeile, Mail-Rumpf]. Quoted-Printable hält jede Zeile unter der
 * Längengrenze von SMTP – das HTML besteht sonst aus sehr langen Zeilen.
 */
function mail_rahmen_mime(string $text, string $html): array
{
    $grenze = 'mr-' . bin2hex(random_bytes(12));
    $crlf = static fn (string $s) => str_replace(["\r\n", "\r", "\n"], "\r\n", $s);
    $rumpf = '--' . $grenze . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($crlf($text)) . "\r\n"
        . '--' . $grenze . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($crlf($html)) . "\r\n"
        . '--' . $grenze . "--\r\n";
    return ['Content-Type: multipart/alternative; boundary="' . $grenze . '"', $rumpf];
}

/**
 * Für die Bereiche ohne lib.php: Kopfzeilen samt reinem Text hineingeben, Kopfzeilen und
 * Rumpf fertig verpackt zurückbekommen. Die Content-Type-Zeile der Aufrufer wird ersetzt.
 * $ersatzName steht im Kopf, solange die App noch keinen Namen gespiegelt hat.
 */
function mail_rahmen_verpacken(array $kopfzeilen, string $text, string $ersatzName = ''): array
{
    $o = mail_rahmen_vorgaben();
    if ($o['name'] === '') $o['name'] = $ersatzName;
    $teile = mail_rahmen_bauen($text, false, $o);
    [$typ, $rumpf] = mail_rahmen_mime($teile['text'], $teile['html']);
    $kopfzeilen = array_values(array_filter($kopfzeilen, static fn ($z) => stripos((string)$z, 'Content-Type:') !== 0
        && stripos((string)$z, 'Content-Transfer-Encoding:') !== 0));
    $kopfzeilen[] = $typ;
    return [$kopfzeilen, $rumpf];
}
