<?php
// Lokale Erweiterung (14.11.2026, nicht vom Hersteller): Anfragen-Limit pro Kurs für Lernende, einstellbar von der Lehrkraft im
// KI-Kontrollzentrum – höchstens bis zum Limit, das die Administration im AI Manager für die Basisrolle vorgibt.
// Gezählt werden die Anfragen der Person in diesem Kurs (request_log.coursecontextid) je Funktion im Zeitraum des AI Managers.
// Gespeichert im configdata der Blockinstanz (reist mit Kurssicherungen mit). Patch: ki-plugins-bayern/patches/.

namespace block_ai_control\local;

use local_ai_manager\base_purpose;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\userinfo;

class kurslimit {

    /** Blockinstanz des Kontrollzentrums im Kurs (oder null). */
    public static function blockinstanz(\context $coursecontext): ?\stdClass {
        global $DB;
        // Innerhalb einer Anfrage zwischenspeichern (wird je KI-Anfrage mehrfach gebraucht); Speichern leert den Speicher.
        if (!array_key_exists($coursecontext->id, self::$bi)) {
            self::$bi[$coursecontext->id] = $DB->get_record('block_instances',
                ['blockname' => 'ai_control', 'parentcontextid' => $coursecontext->id], '*', IGNORE_MULTIPLE) ?: null;
        }
        return self::$bi[$coursecontext->id];
    }

    /** Zwischenspeicher je Anfrage. */
    private static array $bi = [];
    private static ?array $verbindungen = null;

    /** configdata der Blockinstanz speichern (und Zwischenspeicher leeren). */
    private static function config_schreiben(\stdClass $bi, \stdClass $config): void {
        global $DB;
        $DB->set_field('block_instances', 'configdata', base64_encode(serialize($config)), ['id' => $bi->id]);
        self::$bi = [];
    }

    /** Von der Lehrkraft gesetztes Limit (0 = nur die Vorgabe der Administration). */
    public static function wert(\context $coursecontext): int {
        $bi = self::blockinstanz($coursecontext);
        if (!$bi || empty($bi->configdata)) {
            return 0;
        }
        $config = unserialize_object(base64_decode($bi->configdata));
        return max(0, (int)($config->kurslimit ?? 0));
    }

    /** Wert speichern (auf 0 … Vorgabe der Administration begrenzt). */
    public static function speichern(\stdClass $bi, int $wert): int {
        global $DB;
        $wert = max(0, min($wert, self::hoechstwert()));
        $config = !empty($bi->configdata) ? unserialize_object(base64_decode($bi->configdata)) : new \stdClass();
        $config->kurslimit = $wert;
        self::config_schreiben($bi, $config);
        return $wert;
    }

    /** Vorgabe der Administration für Lernende (Basisrolle) bei der Funktion „Chat“ bzw. einer bestimmten Funktion. */
    public static function hoechstwert(?base_purpose $zweck = null): int {
        $zweck = $zweck ?? \core\di::get(\local_ai_manager\local\connector_factory::class)->get_purpose_by_purpose_string('chat');
        return \core\di::get(config_manager::class)->get_max_requests($zweck, userinfo::ROLE_BASIC);
    }

    /** Zeitraum des AI Managers in Sekunden (Standard: 1 Tag). */
    public static function zeitraum(): int {
        return (int)\core\di::get(config_manager::class)->get_max_requests_period();
    }

    /** Anfragen der Person in diesem Kurs für diese Funktion im laufenden Zeitraum. */
    public static function verbraucht(int $userid, \context $coursecontext, string $zweck): int {
        global $DB;
        return $DB->count_records_select('local_ai_manager_request_log',
            'userid = ? AND coursecontextid = ? AND purpose = ? AND timecreated > ?',
            [$userid, $coursecontext->id, $zweck, time() - self::zeitraum()]);
    }

    /** Prüfung für den Haken additional_user_restriction (nur für Personen ohne Steuerrecht aufrufen). */
    public static function pruefen(\local_ai_manager\hook\additional_user_restriction $hook, \context $coursecontext): void {
        global $USER;
        // Lokal (17.11.2026): während eines Tests kein KI-Chat.
        if (self::im_test((int)$USER->id, $coursecontext)) {
            $hook->set_access_allowed(false, 429, 'Während eines Tests ist die KI aus.');
            return;
        }
        // Lokal (17.11.2026): Zeitplan der Lehrkraft (z. B. nur im Unterricht).
        if (($meldung = self::zeitplan_sperre($coursecontext)) !== '') {
            $hook->set_access_allowed(false, 429, $meldung);
            return;
        }
        $limit = self::wert($coursecontext);
        if ($limit <= 0) {
            return;
        }
        $zweck = $hook->get_purpose();
        $limit = min($limit, self::hoechstwert($zweck));
        if (self::verbraucht((int)$USER->id, $coursecontext, $zweck->get_plugin_name()) >= $limit) {
            $hook->set_access_allowed(false, 429, 'In diesem Kurs sind ' . $limit . ' KI-Anfragen pro ' . format_time(self::zeitraum())
                . ' für „' . get_string('pluginname', 'aipurpose_' . $zweck->get_plugin_name()) . '“ erlaubt (Einstellung deiner Lehrkraft). '
                . 'Danach geht es wieder weiter.');
        }
    }

    /**
     * Läuft gerade ein Testversuch der Person in diesem Kurs? Geprüft auf dem Server (nicht über die aufrufende Seite, die der Browser
     * mitteilt): ein offener Versuch, der in den letzten 3 Stunden bearbeitet wurde, oder ein offener Versuch, während die Anfrage von
     * der Testseite kommt. Vorschauen der Lehrkräfte zählen nicht.
     */
    public static function im_test(int $userid, \context $coursecontext): bool {
        global $DB;
        $referer = get_local_referer(false);
        $vontestseite = $referer && preg_match('#/mod/quiz/(attempt|summary)\.php#', $referer);
        return $DB->record_exists_sql("SELECT 1 FROM {quiz_attempts} qa JOIN {quiz} q ON q.id = qa.quiz
            WHERE qa.userid = ? AND q.course = ? AND qa.preview = 0 AND qa.state IN ('inprogress', 'overdue')"
            . ($vontestseite ? '' : ' AND qa.timemodified > ?'),
            [$userid, $coursecontext->instanceid, time() - 3 * HOURSECS]);
    }

    /** Text-Funktionen, für die ein Kurs-Modell gilt (Bild, Bildanalyse, Sprache brauchen andere Modelle). */
    public const TEXTZWECKE = ['chat', 'singleprompt', 'translate', 'feedback', 'questiongeneration', 'agent'];

    /**
     * Für Lehrkräfte wählbare KI-Verbindungen: die aktiven Text-Verbindungen des Mandanten, die die Administration in den
     * Einstellungen des Kontrollzentrums freigegeben hat (block_ai_control/kursmodelle; nie gespeichert = alle).
     */
    public static function waehlbare_verbindungen(): array {
        // Ohne den Haken connector_instance_override (Erweiterung von local_ai_manager) wirkt eine Kurs-Auswahl nicht.
        if (!class_exists('\\local_ai_manager\\hook\\connector_instance_override')) {
            return [];
        }
        $alle = self::alle_textverbindungen();
        // Nie gespeichert = keine Auswahl (die Administration muss Modelle für Lehrkräfte ausdrücklich freigeben).
        $freigabe = get_config('block_ai_control', 'kursmodelle');
        $ids = array_map('intval', array_filter(explode(',', (string)$freigabe), 'strlen'));
        return array_intersect_key($alle, array_flip($ids));
    }

    /** Alle aktiven Text-Verbindungen des Mandanten (Auswahl für die Administration). */
    public static function alle_textverbindungen(): array {
        global $DB;
        if (self::$verbindungen !== null) {
            return self::$verbindungen;
        }
        $tenant = \core\di::get(config_manager::class)->get_tenant()->get_identifier();
        $liste = [];
        foreach ($DB->get_records('local_ai_manager_instance', ['tenant' => $tenant], 'name') as $r) {
            $instanz = \core\di::get(\local_ai_manager\local\connector_factory::class)->get_connector_instance_by_id((int)$r->id);
            $modell = $instanz->get_model_object();
            if ($instanz->is_enabled() && $modell && $modell->supports_textgeneration()) {
                $liste[(int)$r->id] = $r->name;
            }
        }
        return self::$verbindungen = $liste;
    }

    /** Für den Kurs gewähltes Modell (Instanz-id) oder 0 = Vorgabe der Administration. */
    public static function modell(\context $coursecontext): int {
        $bi = self::blockinstanz($coursecontext);
        $config = ($bi && !empty($bi->configdata)) ? unserialize_object(base64_decode($bi->configdata)) : new \stdClass();
        $id = (int)($config->kursmodell ?? 0);
        return isset(self::waehlbare_verbindungen()[$id]) ? $id : 0;
    }

    public static function modell_speichern(\stdClass $bi, int $id): int {
        global $DB;
        $id = isset(self::waehlbare_verbindungen()[$id]) ? $id : 0;
        $config = !empty($bi->configdata) ? unserialize_object(base64_decode($bi->configdata)) : new \stdClass();
        $config->kursmodell = $id;
        self::config_schreiben($bi, $config);
        return $id;
    }

    /** Haken connector_instance_override: im Kurs gewähltes Modell für Text-Funktionen verwenden. */
    public static function modell_einsetzen(\local_ai_manager\hook\connector_instance_override $hook): void {
        if (!in_array($hook->get_purpose()->get_plugin_name(), self::TEXTZWECKE) || !$hook->get_context()) {
            return;
        }
        $coursecontext = \local_ai_manager\ai_manager_utils::find_closest_parent_course_context($hook->get_context());
        if ($coursecontext && ($id = self::modell($coursecontext))) {
            $hook->set_instanceid($id);
        }
    }

    /** Formular für Lehrkräfte bzw. Anzeige für Lernende unter dem Kontrollzentrum. */
    public static function html(\context $coursecontext, \context_block $blockcontext, bool $steuern): string {
        global $USER;
        $wert = self::wert($coursecontext);
        $max = self::hoechstwert();
        $zeit = self::zeittext();
        $kasten = 'margin-top: 12px; padding: 12px 14px; border: 1px solid #d5e2f2; border-radius: 8px; background: #f8fafd; font-size: 0.9rem;';
        if ($steuern) {
            global $PAGE;
            $url = new \moodle_url('/blocks/ai_control/kurslimit.php');
            $limit = self::titel('📊 Anfragen für Lernende begrenzen')
                . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
                . '<input type="hidden" name="blockcontextid" value="' . $blockcontext->id . '">'
                . '<label style="display: block; margin: 0 0 4px 0;">Höchstens '
                . '<input type="number" name="kurslimit" min="0" max="' . $max . '" value="' . ($wert ?: '') . '" placeholder="' . $max . '" '
                . 'style="width: 4.5em; padding: 2px 6px; border-radius: 4px;"> Anfragen pro ' . $zeit
                . ' – je Person und Funktion</label>'
                . self::hinweis('Die Administration erlaubt höchstens ' . $max . '. Leeres Feld = ' . $max . '.');
            // Drei Abschnitte, getrennt durch eine dezente Linie (Wunsch der Nutzerin, 16.11.2026).
            $teile = array_filter([self::modell_auswahl($coursecontext), self::persona_auswahl($coursecontext),
                self::wissen_knopf($coursecontext), $limit,
                self::zeitplan_feld($coursecontext),
                $PAGE->user_is_editing() ? self::auswertung($coursecontext) : '']);
            $inhalt = implode('<hr style="border: 0; border-top: 1px solid #a9b6c6; opacity: 1; margin: 14px 0;">', $teile)
                . '<div style="margin-top: 10px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">'
                . '<button type="submit" class="btn btn-primary btn-sm">Einstellungen speichern</button>'
                . '<span class="kurslimit-status" role="status" aria-live="polite" style="font-size: 0.85rem;"></span></div>';
            // Immer zugeklappt (auch im Bearbeitungsmodus; außerhalb ist der Bereich ganz ausgeblendet, Wunsch der Nutzerin 16.11.2026).
            $zusammenfassung = self::zusammenfassung($coursecontext, $wert, $max);
            // Außerhalb des Bearbeitungsmodus speichert der Hauptschalter sofort (die übrigen Teile sind per styles.css ausgeblendet).
            // Speichern ohne Neuladen (die Seite bleibt an der Stelle, ein Hinweis neben dem Knopf bestätigt es), amd/src/kurslimit.js.
            $PAGE->requires->js_call_amd('block_ai_control/kurslimit', 'init');
            return '<details class="kurslimit-mehr" style="' . $kasten . '">'
                . '<summary style="cursor: pointer; font-weight: bold; color: #1d2125;">⚙️ Weitere KI-Einstellungen für diesen Kurs'
                . '<div class="kurslimit-zusammenfassung" style="font-weight: normal; font-size: 0.8rem; color: #5b6670; margin: 2px 0 0 1.2rem; line-height: 1.4;">'
                . $zusammenfassung . '</div></summary>'
                . '<form class="kurslimit-form" method="post" action="' . $url->out() . '" style="margin-top: 12px;">' . $inhalt . '</form></details>';
        }
        if ($wert <= 0) {
            return '';
        }
        $limit = min($wert, $max);
        $rest = max(0, $limit - self::verbraucht((int)$USER->id, $coursecontext, 'chat'));
        return '<div style="' . $kasten . '">💬 Chat in diesem Kurs: noch <strong>' . $rest . '</strong> von ' . $limit
            . ' Anfragen pro ' . $zeit . '.</div>';
    }


    /** Verbindungsname ohne „AIS.chat – “ (für kurze Anzeigen). */
    private static function kurzname(string $name): string {
        return preg_replace('/^AIS\.chat\s*[–-]\s*/u', '', $name);
    }

    /**
     * „Vorgabe der Administration: Qwen 3.5 (lokal)“ – bzw. getrennt für Lernende und Lehrkräfte, wenn die Administration im AI Manager
     * dem Zweck „Chat“ für die beiden Rollen verschiedene Verbindungen zugeordnet hat (Wunsch der Nutzerin, 16.11.2026).
     */
    private static function vorgabe_text(bool $kurz = false): string {
        global $DB;
        $konfig = \core\di::get(config_manager::class);
        $name = function (string $rolle) use ($konfig, $DB): string {
            $id = (int)$konfig->get_config('purpose_chat_tool_' . $rolle);
            $n = $id ? $DB->get_field('local_ai_manager_instance', 'name', ['id' => $id]) : false;
            return $n ? self::kurzname($n) : '–';
        };
        $basis = $name('role_basic');
        $erweitert = $name('role_extended');
        $modelle = $basis === $erweitert ? $basis : 'Lernende: ' . $basis . ', Lehrkräfte: ' . $erweitert;
        return $kurz ? 'Vorgabe: ' . $modelle : 'Vorgabe der Administration: ' . $modelle;
    }

    /** Kurzübersicht für die zugeklappte Ansicht (Aufzählung): Modell, Wissensdateien, Limit, Zeitplan. */
    public static function zusammenfassung(\context $coursecontext, int $wert, int $max): string {
        $id = self::modell($coursecontext);
        $teile = [$id ? s(self::kurzname(self::waehlbare_verbindungen()[$id])) : s(self::vorgabe_text(true))];
        if (class_exists('\\block_ai_chat\\local\\wissen') && \block_ai_chat\local\wissen::kursmaxdateien() > 0) {
            $teile[] = count(\block_ai_chat\local\wissen::kurs_dateinamen($coursecontext)) . ' Wissensdateien';
        }
        $teile[] = 'höchstens ' . ($wert > 0 ? min($wert, $max) : $max) . ' Anfragen/' . self::zeittext();
        if (self::zeitplan($coursecontext) !== '') {
            $teile[] = '⏰ Zeitplan';
        }
        // Als Aufzählung (Wunsch der Nutzerin, 18.11.2026).
        return '<ul style="margin: 2px 0 0 0; padding-left: 1.1rem;">'
            . implode('', array_map(fn($t) => '<li style="margin: 0;">' . $t . '</li>', $teile)) . '</ul>';
    }

    /**
     * Lokal (17.11.2026, Wunsch der Nutzerin): Wonach wurde in den letzten 7 Tagen gefragt? Gezählt werden die Themen (Titel der
     * Wissensdatei-Abschnitte, block_ai_chat_quellen) der Chat-Antworten in diesem Kurs – ohne einzelne Fragen und ohne Namen.
     */
    private static function auswertung(\context $coursecontext): string {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('block_ai_chat_quellen')) {
            return '';
        }
        $seit = time() - 7 * DAYSECS;
        $anfragen = $DB->get_record_sql("SELECT COUNT(*) AS anzahl, COUNT(DISTINCT userid) AS personen
            FROM {local_ai_manager_request_log} WHERE coursecontextid = ? AND purpose = 'chat' AND timecreated > ?",
            [$coursecontext->id, $seit]);
        $themen = [];
        $rs = $DB->get_recordset_sql("SELECT q.quellen FROM {block_ai_chat_quellen} q
            JOIN {local_ai_manager_request_log} l ON l.id = q.logid WHERE l.coursecontextid = ? AND l.timecreated > ?",
            [$coursecontext->id, $seit]);
        foreach ($rs as $r) {
            // Pro Antwort zählt jedes Thema einmal; als Thema gilt der vordere Teil des Titels (z. B. „Lernpfad 7: Listen“).
            $proantwort = [];
            foreach ((array)json_decode($r->quellen, true) as $titel) {
                $titel = explode("\t", $titel)[0];   // Webseiten: „Titel<Tab>Adresse“
                $proantwort[trim(explode(' › ', preg_replace('/\s+[–-]\s+.*$/u', '', $titel))[0])] = true;
            }
            foreach (array_keys($proantwort) as $t) {
                $themen[$t] = ($themen[$t] ?? 0) + 1;
            }
        }
        $rs->close();
        arsort($themen);
        $html = self::titel('📈 Wonach in den letzten 7 Tagen gefragt wurde')
            . self::hinweis((int)$anfragen->anzahl . ' Chat-Anfragen von ' . (int)$anfragen->personen . ' Personen. Themen aus den '
                . 'Wissensdateien, ohne einzelne Fragen und ohne Namen.');
        if (!$themen) {
            return $html . self::hinweis('Noch keine Fragen mit Bezug zu den Wissensdateien.');
        }
        // Datenschutz: Themen erst ab 5 Personen zeigen (sonst ließe sich auf einzelne Lernende schließen).
        if ((int)$anfragen->personen < 5) {
            return $html . self::hinweis('Themen werden erst angezeigt, wenn mindestens 5 Personen gefragt haben.');
        }
        $max = max($themen);
        $html .= '<div style="margin-top: 8px;">';
        foreach (array_slice($themen, 0, 6, true) as $t => $n) {
            $breite = max(6, (int)round(100 * $n / $max));
            $html .= '<div style="font-size: 0.82rem; margin: 4px 0 1px 0; overflow-wrap: anywhere;">' . s($t) . ' <span style="color: #5b6670;">(' . $n . ')</span></div>'
                . '<div style="height: 6px; border-radius: 3px; background: var(--format-tiles-colour-1, #e7eef7);">'
                . '<div style="height: 6px; width: ' . $breite . '%; border-radius: 3px; background: var(--format-tiles-colour, #0f6cbf);"></div></div>';
        }
        return $html . '</div>';
    }

    // ---- Zeitplan (17.11.2026): KI für Lernende nur zu festen Zeiten, z. B. „Di 09:50-11:20“ (eine Zeile je Zeitraum). ----

    private const TAGE = ['mo' => 1, 'di' => 2, 'mi' => 3, 'do' => 4, 'fr' => 5, 'sa' => 6, 'so' => 7];

    /** Gespeicherter Zeitplan (Text, eine Zeile je Zeitraum; leer = keine Einschränkung). */
    public static function zeitplan(\context $coursecontext): string {
        $bi = self::blockinstanz($coursecontext);
        $config = ($bi && !empty($bi->configdata)) ? unserialize_object(base64_decode($bi->configdata)) : new \stdClass();
        return trim((string)($config->zeitplan ?? ''));
    }

    /**
     * Zeitplan prüfen und speichern; ungültige Zeilen werden verworfen.
     *
     * @return array [gespeicherter Text, Anzahl verworfener Zeilen]
     */
    public static function zeitplan_speichern(\stdClass $bi, string $text): array {
        global $DB;
        $gut = [];
        $schlecht = 0;
        foreach (preg_split('/\R/', $text) as $zeile) {
            $zeile = trim($zeile);
            if ($zeile === '') {
                continue;
            }
            if (self::zeile_lesen($zeile)) {
                $gut[] = $zeile;
            } else {
                $schlecht++;
            }
        }
        $config = !empty($bi->configdata) ? unserialize_object(base64_decode($bi->configdata)) : new \stdClass();
        $config->zeitplan = implode("\n", $gut);
        self::config_schreiben($bi, $config);
        return [$config->zeitplan, $schlecht];
    }

    /** „Mo,Di 08:00-09:30“ → [[1,2], 480, 570] oder null. */
    private static function zeile_lesen(string $zeile): ?array {
        if (!preg_match('/^([A-Za-zÄÖÜäöü,\s]+?)\s+(\d{1,2})[:.](\d{2})\s*[-–]\s*(\d{1,2})[:.](\d{2})$/u', $zeile, $m)) {
            return null;
        }
        $tage = [];
        foreach (preg_split('/[,\s]+/', \core_text::strtolower($m[1])) as $t) {
            $t = \core_text::substr($t, 0, 2);
            if (!isset(self::TAGE[$t])) {
                return null;
            }
            $tage[] = self::TAGE[$t];
        }
        if ((int)$m[3] > 59 || (int)$m[5] > 59) {
            return null;
        }
        $von = (int)$m[2] * 60 + (int)$m[3];
        $bis = (int)$m[4] * 60 + (int)$m[5];
        return ($von < $bis && $bis <= 24 * 60) ? [$tage, $von, $bis] : null;
    }

    /** Leer, wenn die KI jetzt laut Zeitplan erlaubt ist, sonst die Meldung für Lernende. */
    public static function zeitplan_sperre(\context $coursecontext): string {
        $plan = self::zeitplan($coursecontext);
        if ($plan === '') {
            return '';
        }
        $jetzt = new \DateTime('now', \core_date::get_server_timezone_object());
        $tag = (int)$jetzt->format('N');
        $minute = (int)$jetzt->format('G') * 60 + (int)$jetzt->format('i');
        foreach (preg_split('/\R/', $plan) as $zeile) {
            $z = self::zeile_lesen(trim($zeile));
            if ($z && in_array($tag, $z[0]) && $minute >= $z[1] && $minute < $z[2]) {
                return '';
            }
        }
        return 'Die KI ist in diesem Kurs nur zu diesen Zeiten freigeschaltet: ' . str_replace("\n", '; ', $plan) . '.';
    }

    /** Abschnitt „⏰ Zeitplan“ im Formular. */
    private static function zeitplan_feld(\context $coursecontext): string {
        $plan = self::zeitplan($coursecontext);
        $status = $plan === '' ? 'Kein Zeitplan: Es gilt nur der Schalter oben.'
            : (self::zeitplan_sperre($coursecontext) === '' ? '🟢 Gerade freigeschaltet.' : '⚪ Gerade außerhalb des Zeitplans.');
        return self::titel('⏰ Zeitplan für Lernende (optional)')
            . '<textarea name="zeitplan" rows="3" style="width: 100%; font-size: 0.85rem; padding: 4px 6px; border-radius: 4px;" '
            . 'placeholder="Di 09:50-11:20&#10;Do 08:00-09:30">' . s($plan) . '</textarea>'
            . self::hinweis('Eine Zeile je Zeitraum, z. B. „Di 09:50-11:20“ oder „Mo,Mi 08:00-09:30“. Außerhalb ist die KI für Lernende aus '
                . '(zusätzlich zum Schalter oben). Während eines Tests ist sie immer aus. ' . $status);
    }

    // ---- Persona für den Kurs (17.11.2026): gilt für den KI-Chat-Block des Kurses; Lernende können sie nicht ändern. ----

    /** Kontext des KI-Chat-Blocks im Kurs (oder null). */
    public static function chatblock(\context $coursecontext): ?\context_block {
        global $DB;
        $bi = $DB->get_records('block_instances', ['blockname' => 'ai_chat', 'parentcontextid' => $coursecontext->id], 'id', 'id', 0, 1);
        return $bi ? \context_block::instance(reset($bi)->id) : null;
    }

    private static function persona_auswahl(\context $coursecontext): string {
        global $USER, $DB;
        $chat = self::chatblock($coursecontext);
        if (!$chat || !class_exists('\\block_ai_chat\\local\\persona') || !has_capability('block/ai_chat:edit', $chat)) {
            return '';
        }
        $gewaehlt = \block_ai_chat\local\persona::get_current_persona_id($chat->id);
        $optionen = '<option value="0">Keine Persona</option>';
        foreach (\block_ai_chat\local\persona::get_all_personas((int)$USER->id) as $p) {
            $optionen .= '<option value="' . (int)$p->id . '"' . ((int)$p->id === (int)$gewaehlt ? ' selected' : '') . '>' . s($p->name) . '</option>';
        }
        // Auch eine Persona, die eine andere Lehrkraft gewählt hat, soll in der Liste stehen.
        if ($gewaehlt && strpos($optionen, 'value="' . (int)$gewaehlt . '"') === false
                && ($name = $DB->get_field('block_ai_chat_personas', 'name', ['id' => $gewaehlt]))) {
            $optionen .= '<option value="' . (int)$gewaehlt . '" selected>' . s($name) . '</option>';
        }
        return '<div>' . self::titel('🎭 Persona für den KI-Chat')
            . '<select name="kurspersona" class="custom-select form-select" style="width: 100%;" data-chatcontextid="' . $chat->id . '">' . $optionen . '</select>'
            . self::hinweis('Gilt für alle in diesem Kurs; Lernende können sie nicht ändern. Personas anlegen und bearbeiten: im KI-Chat über ⋮.')
            . '</div>';
    }

    /** Persona speichern (nur mit Bearbeitungsrecht für den KI-Chat). */
    public static function persona_speichern(\context $coursecontext, int $personaid): ?string {
        global $DB, $USER;
        $chat = self::chatblock($coursecontext);
        if (!$chat || !has_capability('block/ai_chat:edit', $chat) || $personaid < 0) {
            return null;
        }
        if ($personaid === (int)\block_ai_chat\local\persona::get_current_persona_id($chat->id)) {
            return null;
        }
        if ($personaid !== 0 && !array_key_exists($personaid, \block_ai_chat\local\persona::get_all_personas((int)$USER->id))) {
            return null;
        }
        $DB->delete_records('block_ai_chat_personas_selected', ['contextid' => $chat->id]);
        if ($personaid === 0) {
            return 'Keine Persona';
        }
        $DB->insert_record('block_ai_chat_personas_selected', ['contextid' => $chat->id, 'personasid' => $personaid]);
        return $DB->get_field('block_ai_chat_personas', 'name', ['id' => $personaid]);
    }

    /** Zeitraum als Text: „Tag“, „Woche“, „Stunde“ statt „1 Tag“. */
    private static function zeittext(): string {
        $sek = self::zeitraum();
        $namen = [DAYSECS => 'Tag', WEEKSECS => 'Woche', HOURSECS => 'Stunde'];
        return $namen[$sek] ?? format_time($sek);
    }

    private static function titel(string $text): string {
        return '<div style="font-weight: bold; margin-bottom: 6px;">' . $text . '</div>';
    }

    private static function hinweis(string $text): string {
        return '<div style="color: #5b6670; font-size: 0.85rem; line-height: 1.4; margin: 4px 0 0 0;">' . $text . '</div>';
    }


    /** Lokal (15.11.2026): Knopf zu den Wissensdateien des KI-Chats für diesen Kurs (block_ai_chat/kurswissen.php). */
    private static function wissen_knopf(\context $coursecontext): string {
        if (!class_exists('\\block_ai_chat\\local\\wissen') || \block_ai_chat\local\wissen::kursmaxdateien() === 0
                || !has_capability('moodle/course:update', $coursecontext)) {
            return '';
        }
        $namen = \block_ai_chat\local\wissen::kurs_dateinamen($coursecontext);
        $links = method_exists('\\block_ai_chat\\local\\wissen', 'kurs_links') ? \block_ai_chat\local\wissen::kurs_links($coursecontext) : [];
        $url = new \moodle_url('/blocks/ai_chat/kurswissen.php', ['courseid' => $coursecontext->instanceid]);
        // Nur die Anzahl, keine Liste (Wunsch der Nutzerin, 18.11.2026: sonst zu unübersichtlich); die einzelnen Dateien und
        // Webseiten stehen auf der Verwaltungsseite. Nicht lesbare Webseiten bleiben als ⚠️ sichtbar.
        $teile = [count($namen) . ' von ' . \block_ai_chat\local\wissen::kursmaxdateien() . ' Dateien hochgeladen'];
        if (\block_ai_chat\local\wissen::webmax() > 0) {
            $teile[] = count($links) . ' von ' . \block_ai_chat\local\wissen::webmax() . ' Webseiten ausgewählt';
        }
        // Lokal (19.11.2026): Dateien ohne lesbaren Text (z. B. eingescanntes PDF) – die KI kennt sie nicht.
        if (method_exists('\\block_ai_chat\\local\\wissen', 'kurs_dateistatus')) {
            $ohnetext = count(\block_ai_chat\local\wissen::ohne_text(\block_ai_chat\local\wissen::kurs_dateistatus($coursecontext)));
            if ($ohnetext) {
                $teile[] = '<span style="color: #b3261e;">⚠️ ' . $ohnetext . ' Datei(en) ohne lesbaren Text (z. B. Scan)</span>';
            }
        }
        $fehler = count(array_filter($links, fn($n) => $n === 0));
        if ($fehler) {
            $teile[] = '<span style="color: #b3261e;">⚠️ ' . $fehler . ' Webseite(n) nicht lesbar</span>';
        }
        // Kleine graue Aufzählung wie die Kurzübersicht oben (Wunsch der Nutzerin, 18.11.2026).
        $stand = '<ul style="margin: 6px 0 8px 0; padding-left: 1.1rem; font-size: 0.8rem; color: #5b6670; line-height: 1.4;">'
            . implode('', array_map(fn($t) => '<li style="margin: 0;">' . $t . '</li>', $teile)) . '</ul>';
        return self::titel('📚 Wissen für den KI-Chat')
            . self::hinweis('Gilt nur in diesem Kurs, für jede Persona. Bei jeder Frage sucht der Chat hier die passenden Stellen heraus. '
                . 'Lade Aufgaben und Material hoch oder nenne Webseiten. Welche Dateien und Webseiten hinterlegt sind, '
                . 'siehst und änderst du unter „Dateien und Webseiten verwalten“.')
            . $stand
            . '<a class="btn btn-primary btn-sm" href="' . $url->out() . '">📚 Dateien und Webseiten verwalten</a>';
    }


    /** Auswahlliste „KI-Modell für diesen Kurs“ (nur, wenn die Administration mehr als eine Verbindung angelegt hat). */
    private static function modell_auswahl(\context $coursecontext): string {
        $liste = self::waehlbare_verbindungen();
        if (!$liste) {
            return '';
        }
        $gewaehlt = self::modell($coursecontext);
        $optionen = '<option value="0">' . s(self::vorgabe_text()) . '</option>';
        foreach ($liste as $id => $name) {
            $optionen .= '<option value="' . $id . '"' . ($id === $gewaehlt ? ' selected' : '') . '>' . s($name) . '</option>';
        }
        return self::titel('🧠 KI-Modell für diesen Kurs')
            . '<select name="kursmodell" class="custom-select form-select" style="width: 100%;">' . $optionen . '</select>'
            . self::hinweis('Gilt für Chat, Übersetzen, Feedback und Textaufgaben. Welche Modelle es gibt, legt die Administration fest.');
    }

}
