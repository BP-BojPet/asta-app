<?php
/**
 * Umlaufverfahren des StuPa – Datenbank und Kernfunktionen.
 *
 * EIGENE SQLite-Datei (data/stupa-umlauf.sqlite), wie Pat:innenprogramm, Umfragen und
 * Terminplaner. Die Umläufe gehören dem StuPa: In der App des AStA gibt es keine Seite, die
 * sie zeigt, und die lib.php wird hier ABSICHTLICH NICHT eingebunden. Geladen wird diese
 * Datei vom StuPa-Bereich (stupa/umlauf.php, mit eigener StuPa-Anmeldung), von der
 * öffentlichen Abstimmungsseite (stupa/abstimmen.php, ganz ohne lib.php) und vom Cron.
 *
 * Ablauf: Das Präsidium pflegt eine Mitgliederliste (Name, Mail) und startet einen Umlauf.
 * Beim Start wird festgehalten, wer stimmberechtigt ist: Jedes aktive Mitglied bekommt einen
 * eigenen Schlüssel und damit einen eigenen Link per Mail. Wer später auf die Liste kommt,
 * stimmt bei diesem Umlauf nicht mit.
 *
 * NAMENTLICH oder GEHEIM (je Umlauf):
 *   • namentlich: die Wahl steht am Schlüssel der Person und lässt sich bis zum Ende ändern.
 *   • geheim: am Schlüssel steht nur „hat abgestimmt"; die Wahl landet ohne jeden Bezug in
 *     su_secret. Nicht einmal die Datenbank weiß dann, wer wie gestimmt hat – der Preis:
 *     Eine abgegebene Stimme lässt sich nicht mehr ändern.
 *
 * Der Schlüssel steht im Klartext in der Datenbank: Erinnerungen müssen denselben Link noch
 * einmal schicken können. Wer an die Datei käme, könnte ohnehin alles ändern – data/ ist per
 * .htaccess gesperrt.
 *
 * ACHTUNG beim Hochladen: data/stupa-umlauf.sqlite darf der rsync NIE mitnehmen.
 */

declare(strict_types=1);

require_once __DIR__ . '/mail-pool.php';
require_once __DIR__ . '/mail-rahmen.php';

/* Überschreibbar, damit der Selbsttest NIE die echte Datei anfasst. */
if (!defined('SU_DB_FILE')) define('SU_DB_FILE', __DIR__ . '/data/stupa-umlauf.sqlite');

function su_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dir = dirname(SU_DB_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . SU_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 4000');
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS su_members (
        id         INTEGER PRIMARY KEY,
        name       TEXT NOT NULL,
        email      TEXT NOT NULL,
        active     INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE TABLE IF NOT EXISTS su_votes (
        id             INTEGER PRIMARY KEY,
        title          TEXT NOT NULL,
        description    TEXT NOT NULL DEFAULT '',
        deadline       TEXT NOT NULL,                  -- 'Y-m-d H:i'
        secret         INTEGER NOT NULL DEFAULT 0,
        rule           TEXT NOT NULL DEFAULT 'simple',
        quorum_pct     INTEGER NOT NULL DEFAULT 0,
        result_mail    INTEGER NOT NULL DEFAULT 1,     -- 1 = Ergebnis per Mail an alle, 0 = nur das Präsidium sieht es
        remind_auto    INTEGER NOT NULL DEFAULT 1,     -- 1 = Säumige 24 h vor der Frist einmal erinnern
        reminded_at    TEXT,
        link_basis     TEXT NOT NULL DEFAULT '',       -- Adresse der Abstimmungsseite, beim Start festgehalten
        reply_to       TEXT NOT NULL DEFAULT '',       -- Antworten auf die Mails gehen ans Präsidium
        created_by     INTEGER NOT NULL DEFAULT 0,     -- stupa_users.id (Nummernkreis der App-Datenbank)
        status         TEXT NOT NULL DEFAULT 'open',   -- open | closed | withdrawn
        result         TEXT,                           -- accepted | rejected | no_quorum
        eligible_count INTEGER NOT NULL DEFAULT 0,
        result_yes     INTEGER, result_no INTEGER, result_abstain INTEGER,
        created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        closed_at      TEXT
    );
    CREATE TABLE IF NOT EXISTS su_tokens (             -- je Umlauf und stimmberechtigtem Mitglied ein Link
        id         INTEGER PRIMARY KEY,
        vote_id    INTEGER NOT NULL REFERENCES su_votes(id) ON DELETE CASCADE,
        member_id  INTEGER NOT NULL DEFAULT 0,
        name       TEXT NOT NULL,                      -- beim Start festgehalten: das Archiv bleibt, auch wenn
        email      TEXT NOT NULL,                      -- das Mitglied später von der Liste geht
        token      TEXT NOT NULL UNIQUE,
        choice     TEXT,                               -- nur namentlich: yes | no | abstain
        voted_at   TEXT,
        mailed_at  TEXT
    );
    CREATE TABLE IF NOT EXISTS su_secret (             -- geheime Stimmen: OHNE Bezug zur Person
        vote_id INTEGER NOT NULL REFERENCES su_votes(id) ON DELETE CASCADE,
        choice  TEXT NOT NULL
    );
    ");
    return $pdo;
}

/** Annahme-Regeln: Schlüssel => [Name, Erklärung]. */
function su_rules(): array
{
    return [
        'simple'     => ['Einfache Mehrheit', 'mehr Ja als Nein unter den abgegebenen Stimmen (Enthaltungen zählen nicht)'],
        'zweidrittel' => ['Zwei-Drittel-Mehrheit', 'Ja-Stimmen mindestens zwei Drittel der abgegebenen Ja- und Nein-Stimmen (Enthaltungen zählen nicht)'],
        'absolute'   => ['Mehrheit aller Stimmberechtigten', 'Ja von mehr als der Hälfte ALLER Stimmberechtigten – wer nicht abstimmt, wirkt wie Nein'],
        'unanimous'  => ['Einstimmig', 'keine einzige Nein-Stimme und mindestens ein Ja (Enthaltungen sind ok)'],
    ];
}

/** Ergebnis nach Regel und Mindestbeteiligung: 'accepted' | 'rejected' | 'no_quorum'. */
function su_eval(string $rule, int $quorumPct, array $t, int $eligible): string
{
    $eligible = max(1, $eligible);
    if ($quorumPct > 0 && $t['total'] * 100 < $quorumPct * $eligible) return 'no_quorum';
    return match ($rule) {
        'absolute'    => $t['yes'] * 2 > $eligible ? 'accepted' : 'rejected',
        'unanimous'   => ($t['no'] === 0 && $t['yes'] > 0) ? 'accepted' : 'rejected',
        'zweidrittel' => ($t['yes'] > 0 && $t['yes'] * 3 >= ($t['yes'] + $t['no']) * 2) ? 'accepted' : 'rejected',
        default       => $t['yes'] > $t['no'] ? 'accepted' : 'rejected',
    };
}

function su_result_label(?string $r): string
{
    return ['accepted' => 'angenommen', 'rejected' => 'abgelehnt', 'no_quorum' => 'ungültig – Mindestbeteiligung verfehlt'][(string)$r] ?? '—';
}

function su_choice_label(string $c): string
{
    return ['yes' => 'Ja', 'no' => 'Nein', 'abstain' => 'Enthaltung'][$c] ?? $c;
}

function su_fmt(string $dt): string
{
    $t = strtotime($dt);
    return $t ? date('d.m.Y, H:i', $t) . ' Uhr' : $dt;
}

// ---- Mitgliederliste --------------------------------------------------------------------

function su_members(bool $nurAktiv = false): array
{
    return su_db()->query('SELECT * FROM su_members' . ($nurAktiv ? ' WHERE active = 1' : '') . ' ORDER BY name COLLATE NOCASE')->fetchAll();
}

/** Mitglied anlegen ($id = 0) oder ändern. Rückgabe: Fehler oder ''. */
function su_member_save(int $id, string $name, string $email, bool $aktiv = true): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name)); $email = trim($email);
    if ($name === '' || mb_strlen($name) > 100) return 'Bitte einen Namen angeben (bis 100 Zeichen).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) return 'Bitte eine gültige Mailadresse angeben (' . $name . ').';
    $dup = su_db()->prepare('SELECT id FROM su_members WHERE lower(email) = lower(?) AND id <> ?');
    $dup->execute([$email, $id]);
    if ($dup->fetchColumn()) return $email . ' steht schon auf der Liste.';
    if ($id > 0) {
        su_db()->prepare('UPDATE su_members SET name = ?, email = ?, active = ? WHERE id = ?')->execute([$name, $email, $aktiv ? 1 : 0, $id]);
    } else {
        su_db()->prepare('INSERT INTO su_members(name, email, active) VALUES(?,?,1)')->execute([$name, $email]);
    }
    return '';
}

function su_member_delete(int $id): void
{
    su_db()->prepare('DELETE FROM su_members WHERE id = ?')->execute([$id]);
}

/**
 * Mehrere auf einmal: eine Person je Zeile, „Name, Mail", „Name <Mail>" oder „Name;Mail".
 * Rückgabe: [Anzahl angelegt, Fehlerliste].
 */
function su_members_import(string $text): array
{
    $n = 0; $fehler = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $zeile) {
        $zeile = trim($zeile);
        if ($zeile === '') continue;
        if (!preg_match('/([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $zeile, $m)) { $fehler[] = 'keine Mailadresse in „' . $zeile . '"'; continue; }
        $name = trim(str_replace([$m[1], '<', '>', ',', ';'], ' ', $zeile));
        $e = su_member_save(0, $name, $m[1]);
        if ($e === '') $n++; else $fehler[] = $e;
    }
    return [$n, $fehler];
}

// ---- Umläufe ----------------------------------------------------------------------------

function su_vote_get(int $id): ?array
{
    $st = su_db()->prepare('SELECT * FROM su_votes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function su_votes_all(): array
{
    return su_db()->query("SELECT * FROM su_votes ORDER BY (status = 'open') DESC, created_at DESC, id DESC")->fetchAll();
}

function su_vote_open(array $v): bool
{
    return (string)$v['status'] === 'open' && date('Y-m-d H:i') <= substr((string)$v['deadline'], 0, 16);
}

function su_tokens(int $voteId): array
{
    $st = su_db()->prepare('SELECT * FROM su_tokens WHERE vote_id = ? ORDER BY name COLLATE NOCASE');
    $st->execute([$voteId]);
    return $st->fetchAll();
}

/** Zählung. Beendete lesen den eingefrorenen Stand. */
function su_tally(array $v): array
{
    if ((string)$v['status'] === 'closed' && $v['result_yes'] !== null) {
        $y = (int)$v['result_yes']; $n = (int)$v['result_no']; $a = (int)$v['result_abstain'];
        return ['yes' => $y, 'no' => $n, 'abstain' => $a, 'total' => $y + $n + $a];
    }
    $st = su_db()->prepare(!empty($v['secret'])
        ? 'SELECT choice, COUNT(*) n FROM su_secret WHERE vote_id = ? GROUP BY choice'
        : 'SELECT choice, COUNT(*) n FROM su_tokens WHERE vote_id = ? AND choice IS NOT NULL GROUP BY choice');
    $st->execute([(int)$v['id']]);
    $t = ['yes' => 0, 'no' => 0, 'abstain' => 0];
    foreach ($st->fetchAll() as $r) if (isset($t[$r['choice']])) $t[$r['choice']] = (int)$r['n'];
    $t['total'] = $t['yes'] + $t['no'] + $t['abstain'];
    return $t;
}

/**
 * Umlauf starten: anlegen, Stimmberechtigte festhalten, Links verschicken (wenn $mail).
 * Rückgabe: ['id' => …, 'error' => '', 'mails' => Anzahl verschickt].
 */
function su_vote_create(array $d, int $byId, string $linkBasis, string $replyTo, bool $mail = true): array
{
    $titel = trim((string)($d['title'] ?? ''));
    $frist = trim((string)($d['deadline'] ?? ''));
    $rule = isset(su_rules()[(string)($d['rule'] ?? '')]) ? (string)$d['rule'] : 'simple';
    if ($titel === '' || mb_strlen($titel) > 200) return ['id' => 0, 'error' => 'Bitte einen Titel angeben (bis 200 Zeichen).', 'mails' => 0];
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $frist) || $frist <= date('Y-m-d H:i')) {
        return ['id' => 0, 'error' => 'Bitte eine Frist in der Zukunft angeben.', 'mails' => 0];
    }
    $mitglieder = su_members(true);
    if (!$mitglieder) return ['id' => 0, 'error' => 'Auf der Mitgliederliste steht noch niemand (aktiv).', 'mails' => 0];
    $pdo = su_db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->prepare('INSERT INTO su_votes(title, description, deadline, secret, rule, quorum_pct, result_mail, remind_auto, link_basis, reply_to, created_by, eligible_count)
                       VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$titel, trim((string)($d['description'] ?? '')), $frist, !empty($d['secret']) ? 1 : 0, $rule,
                       max(0, min(100, (int)($d['quorum_pct'] ?? 0))), !empty($d['result_mail']) ? 1 : 0, !empty($d['remind_auto']) ? 1 : 0,
                       rtrim($linkBasis, '/'), $replyTo, $byId, count($mitglieder)]);
        $id = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO su_tokens(vote_id, member_id, name, email, token) VALUES(?,?,?,?,?)');
        foreach ($mitglieder as $m) $ins->execute([$id, (int)$m['id'], (string)$m['name'], (string)$m['email'], bin2hex(random_bytes(16))]);
        $pdo->exec('COMMIT');
    } catch (\Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (\Throwable $egal) {}
        throw $e;
    }
    $n = $mail ? su_send_links($id, 'einladung') : 0;
    return ['id' => $id, 'error' => '', 'mails' => $n];
}

/** Link eines Schlüssels. */
function su_link(array $v, string $token): string
{
    $b = rtrim((string)$v['link_basis'], '/');
    return ($b !== '' ? $b . '/' : '') . 'abstimmen.php?t=' . rawurlencode($token);
}

/** Schlüssel → [Schlüssel-Zeile, Umlauf] oder null. */
function su_token_find(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $st = su_db()->prepare('SELECT * FROM su_tokens WHERE token = ?');
    $st->execute([$token]);
    $t = $st->fetch();
    if (!$t) return null;
    $v = su_vote_get((int)$t['vote_id']);
    return $v ? [$t, $v] : null;
}

/**
 * Abstimmen über den eigenen Link. Rückgabe: Fehler oder ''.
 * Haben danach alle abgestimmt, wird sofort ausgewertet.
 */
function su_cast(string $token, string $choice, bool $mail = true): string
{
    if (!in_array($choice, ['yes', 'no', 'abstain'], true)) return 'Bitte Ja, Nein oder Enthaltung wählen.';
    $pdo = su_db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $f = su_token_find($token);
        if (!$f) { $pdo->exec('ROLLBACK'); return 'Dieser Abstimmungslink ist ungültig.'; }
        [$t, $v] = $f;
        if (!su_vote_open($v)) { $pdo->exec('ROLLBACK'); return 'Dieser Umlauf ist schon beendet.'; }
        if (!empty($v['secret'])) {
            if ($t['voted_at'] !== null) { $pdo->exec('ROLLBACK'); return 'Du hast schon abgestimmt. Bei geheimen Umläufen lässt sich die Stimme nicht ändern.'; }
            $pdo->prepare('INSERT INTO su_secret(vote_id, choice) VALUES(?, ?)')->execute([(int)$v['id'], $choice]);
            $pdo->prepare("UPDATE su_tokens SET voted_at = datetime('now','localtime') WHERE id = ?")->execute([(int)$t['id']]);
        } else {
            $pdo->prepare("UPDATE su_tokens SET choice = ?, voted_at = datetime('now','localtime') WHERE id = ?")->execute([$choice, (int)$t['id']]);
        }
        $offen = (int)$pdo->query('SELECT COUNT(*) FROM su_tokens WHERE vote_id = ' . (int)$v['id'] . ' AND voted_at IS NULL')->fetchColumn();
        $pdo->exec('COMMIT');
    } catch (\Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (\Throwable $egal) {}
        throw $e;
    }
    if ($offen === 0) su_close((int)$v['id'], $mail);
    return '';
}

/** Auswerten und schließen (nur solange offen). Rückgabe: true, wenn JETZT geschlossen. */
function su_close(int $id, bool $mail = true): bool
{
    $v = su_vote_get($id);
    if (!$v || (string)$v['status'] !== 'open') return false;
    $t = su_tally($v);
    $erg = su_eval((string)$v['rule'], (int)$v['quorum_pct'], $t, (int)$v['eligible_count']);
    $upd = su_db()->prepare("UPDATE su_votes SET status = 'closed', result = ?, result_yes = ?, result_no = ?, result_abstain = ?,
                             closed_at = datetime('now','localtime') WHERE id = ? AND status = 'open'");
    $upd->execute([$erg, $t['yes'], $t['no'], $t['abstain'], $id]);
    if ($upd->rowCount() < 1) return false;
    if ($mail && !empty($v['result_mail'])) su_send_links($id, 'ergebnis');
    return true;
}

function su_withdraw(int $id): bool
{
    $st = su_db()->prepare("UPDATE su_votes SET status = 'withdrawn', closed_at = datetime('now','localtime') WHERE id = ? AND status = 'open'");
    $st->execute([$id]);
    return $st->rowCount() > 0;
}

function su_vote_delete(int $id): void
{
    su_db()->prepare('DELETE FROM su_votes WHERE id = ?')->execute([$id]);
}

/** Namen je Wahl (nur namentlich). */
function su_names(array $v): array
{
    $out = ['yes' => [], 'no' => [], 'abstain' => []];
    if (!empty($v['secret'])) return $out;
    foreach (su_tokens((int)$v['id']) as $t) if ($t['choice'] !== null && isset($out[$t['choice']])) $out[$t['choice']][] = (string)$t['name'];
    return $out;
}

// ---- Mails ------------------------------------------------------------------------------

/** Eine Mail im Rahmen der App, von der App-Adresse; Antworten gehen ans Präsidium. */
function su_mail(string $to, string $subject, string $text, string $replyTo = ''): bool
{
    $from = mail_pool_absender();
    $to = trim(str_replace(["\r", "\n"], '', $to));
    if ($from === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $reply = filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : $from;
    $headers = [
        'From: =?UTF-8?B?' . base64_encode('StuPa · Umlaufverfahren') . '?= <' . $from . '>',
        'Reply-To: ' . $reply,
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
        'X-Mailer: AStA-App',
        'Auto-Submitted: auto-generated',
    ];
    [$headers, $body] = mail_rahmen_verpacken($headers, $text, 'StuPa');
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
    if ($ok) mail_pool_note('stupa');
    return (bool)$ok;
}

/**
 * Links verschicken. $art: 'einladung' (alle), 'erinnerung' (nur wer noch nicht abgestimmt hat),
 * 'ergebnis' (alle). Rückgabe: Anzahl verschickter Mails.
 */
function su_send_links(int $id, string $art): int
{
    $v = su_vote_get($id);
    if (!$v) return 0;
    $regel = su_rules()[(string)$v['rule']][0] ?? '';
    $n = 0;
    foreach (su_tokens($id) as $t) {
        if ($art === 'erinnerung' && $t['voted_at'] !== null) continue;
        $vorname = explode(' ', trim((string)$t['name']))[0];
        $link = su_link($v, (string)$t['token']);
        if ($art === 'ergebnis') {
            $z = su_tally($v);
            $betreff = 'Ergebnis: ' . $v['title'];
            $text = "Hallo $vorname,\n\nder Umlauf „" . $v['title'] . '" ist beendet: **' . su_result_label($v['result']) . "**.\n\n"
                . 'Ja ' . $z['yes'] . ' · Nein ' . $z['no'] . ' · Enthaltung ' . $z['abstain'] . ' (Beteiligung ' . $z['total'] . ' von ' . (int)$v['eligible_count'] . ")\n\n"
                . "Einzelheiten:\n$link\n\nViele Grüße\nDas Präsidium";
        } else {
            $betreff = ($art === 'erinnerung' ? 'Erinnerung: ' : 'Umlaufverfahren: ') . $v['title'];
            $text = "Hallo $vorname,\n\n"
                . ($art === 'erinnerung' ? 'beim Umlauf „' . $v['title'] . '" fehlt noch deine Stimme.' : 'das Präsidium hat einen Umlauf gestartet: „' . $v['title'] . '".')
                . "\n\nAbstimmen bis " . su_fmt((string)$v['deadline']) . '. Angenommen bei: ' . $regel
                . (!empty($v['secret']) ? '. Die Abstimmung ist geheim – niemand sieht, wie du stimmst.' : '. Die Abstimmung ist namentlich.')
                . "\n\nDein persönlicher Link (bitte nicht weitergeben):\n$link\n\nViele Grüße\nDas Präsidium";
        }
        if (su_mail((string)$t['email'], $betreff, $text, (string)$v['reply_to'])) {
            $n++;
            if ($art !== 'ergebnis') su_db()->prepare("UPDATE su_tokens SET mailed_at = datetime('now','localtime') WHERE id = ?")->execute([(int)$t['id']]);
        }
    }
    if ($art === 'erinnerung') su_db()->prepare("UPDATE su_votes SET reminded_at = datetime('now','localtime') WHERE id = ?")->execute([$id]);
    return $n;
}

/**
 * Wartung (Cron und Seitenaufrufe): abgelaufene Umläufe auswerten, automatische Erinnerung
 * 24 Stunden vor der Frist. Rückgabe: ['ausgewertet' => n, 'erinnert' => n].
 */
function su_maintain(bool $mail = true): array
{
    $out = ['ausgewertet' => 0, 'erinnert' => 0];
    if (!is_file(SU_DB_FILE)) return $out;              // nichts anlegen, solange es keine Umläufe gibt
    foreach (su_db()->query("SELECT * FROM su_votes WHERE status = 'open'")->fetchAll() as $v) {
        if (!su_vote_open($v)) { if (su_close((int)$v['id'], $mail)) $out['ausgewertet']++; continue; }
        $bald = strtotime((string)$v['deadline']) - time() <= 24 * 3600;
        if ($mail && $bald && !empty($v['remind_auto']) && $v['reminded_at'] === null) $out['erinnert'] += su_send_links((int)$v['id'], 'erinnerung');
    }
    return $out;
}
