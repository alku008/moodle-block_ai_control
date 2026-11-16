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
        return $DB->get_record('block_instances', ['blockname' => 'ai_control', 'parentcontextid' => $coursecontext->id]) ?: null;
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
        $DB->set_field('block_instances', 'configdata', base64_encode(serialize($config)), ['id' => $bi->id]);
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

    /** Text-Funktionen, für die ein Kurs-Modell gilt (Bild, Bildanalyse, Sprache brauchen andere Modelle). */
    public const TEXTZWECKE = ['chat', 'singleprompt', 'translate', 'feedback', 'questiongeneration', 'agent'];

    /**
     * Für Lehrkräfte wählbare KI-Verbindungen: die aktiven Text-Verbindungen des Mandanten, die die Administration in den
     * Einstellungen des Kontrollzentrums freigegeben hat (block_ai_control/kursmodelle; nie gespeichert = alle).
     */
    public static function waehlbare_verbindungen(): array {
        $alle = self::alle_textverbindungen();
        $freigabe = get_config('block_ai_control', 'kursmodelle');
        if ($freigabe === false) {
            return $alle;
        }
        $ids = array_map('intval', array_filter(explode(',', (string)$freigabe), 'strlen'));
        return array_intersect_key($alle, array_flip($ids));
    }

    /** Alle aktiven Text-Verbindungen des Mandanten (Auswahl für die Administration). */
    public static function alle_textverbindungen(): array {
        global $DB;
        $tenant = \core\di::get(config_manager::class)->get_tenant()->get_identifier();
        $liste = [];
        foreach ($DB->get_records('local_ai_manager_instance', ['tenant' => $tenant], 'name') as $r) {
            $instanz = \core\di::get(\local_ai_manager\local\connector_factory::class)->get_connector_instance_by_id((int)$r->id);
            $modell = $instanz->get_model_object();
            if ($instanz->is_enabled() && $modell && $modell->supports_textgeneration()) {
                $liste[(int)$r->id] = $r->name;
            }
        }
        return $liste;
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
        $DB->set_field('block_instances', 'configdata', base64_encode(serialize($config)), ['id' => $bi->id]);
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
            $url = new \moodle_url('/blocks/ai_control/kurslimit.php');
            $limit = self::titel('📊 Anfragen für Lernende begrenzen')
                . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
                . '<input type="hidden" name="blockcontextid" value="' . $blockcontext->id . '">'
                . '<label style="display: block; margin: 0 0 4px 0;">Höchstens '
                . '<input type="number" name="kurslimit" min="0" max="' . $max . '" value="' . ($wert ?: '') . '" placeholder="' . $max . '" '
                . 'style="width: 4.5em; padding: 2px 6px; border: 1px solid #8f959e; border-radius: 4px;"> Anfragen pro ' . $zeit
                . ' – je Person und Funktion</label>'
                . self::hinweis('Die Administration erlaubt höchstens ' . $max . '. Leeres Feld = ' . $max . '.');
            // Drei Abschnitte, getrennt durch eine dezente Linie (Wunsch der Nutzerin, 16.11.2026).
            $teile = array_filter([self::modell_auswahl($coursecontext), self::wissen_knopf($coursecontext), $limit]);
            $inhalt = implode('<hr style="border: 0; border-top: 1px solid #a9b6c6; opacity: 1; margin: 14px 0;">', $teile)
                . '<button type="submit" class="btn btn-secondary btn-sm" style="margin-top: 10px;">Einstellungen speichern</button>';
            // Außerhalb des Bearbeitungsmodus zugeklappt – oben bleiben die Schalter des Kontrollzentrums (Wunsch der Nutzerin, 16.11.2026).
            global $PAGE;
            $offen = $PAGE->user_is_editing() ? ' open' : '';
            $zusammenfassung = self::zusammenfassung($coursecontext, $wert, $max);
            return '<details' . $offen . ' style="' . $kasten . '">'
                . '<summary style="cursor: pointer; font-weight: bold; color: #1d2125;">⚙️ Weitere KI-Einstellungen für diesen Kurs'
                . '<div style="font-weight: normal; font-size: 0.8rem; color: #5b6670; margin: 2px 0 0 1.2rem;">' . $zusammenfassung . '</div></summary>'
                . '<form method="post" action="' . $url->out() . '" style="margin-top: 12px;">' . $inhalt . '</form></details>';
        }
        if ($wert <= 0) {
            return '';
        }
        $limit = min($wert, $max);
        $rest = max(0, $limit - self::verbraucht((int)$USER->id, $coursecontext, 'chat'));
        return '<div style="' . $kasten . '">💬 Chat in diesem Kurs: noch <strong>' . $rest . '</strong> von ' . $limit
            . ' Anfragen pro ' . $zeit . '.</div>';
    }

    /** Eine Zeile für die zugeklappte Ansicht: Modell · Wissensdateien · Limit. */
    private static function zusammenfassung(\context $coursecontext, int $wert, int $max): string {
        $id = self::modell($coursecontext);
        $teile = [$id ? s(self::waehlbare_verbindungen()[$id]) : 'Modell: Vorgabe'];
        if (class_exists('\\block_ai_chat\\local\\wissen') && \block_ai_chat\local\wissen::kursmaxdateien() > 0) {
            $teile[] = count(\block_ai_chat\local\wissen::kurs_dateinamen($coursecontext)) . ' Wissensdateien';
        }
        $teile[] = 'höchstens ' . ($wert > 0 ? min($wert, $max) : $max) . ' Anfragen/' . self::zeittext();
        return implode(' · ', $teile);
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
        $url = new \moodle_url('/blocks/ai_chat/kurswissen.php', ['courseid' => $coursecontext->instanceid]);
        if ($namen) {
            $liste = '<ul style="margin: 6px 0 8px 0; padding-left: 1.1rem; font-size: 0.82rem; color: #333;">'
                . implode('', array_map(fn($n) => '<li style="margin: 1px 0; overflow-wrap: anywhere;">' . str_replace('_', '_<wbr>', s($n)) . '</li>', $namen))
                . '</ul>';
        } else {
            $liste = '<div style="margin: 6px 0 8px 0; font-size: 0.82rem; color: #5b6670;">Noch keine Dateien hochgeladen.</div>';
        }
        return self::titel('📚 Wissen für den KI-Chat')
            . self::hinweis('Gilt nur in diesem Kurs, für jede Persona. Bei jeder Frage sucht der Chat hier die passenden Stellen heraus. '
                . 'Lade Aufgaben und Material hoch – keine Lösungen.')
            . '<div style="margin-top: 8px; font-size: 0.85rem; font-weight: bold;">Hochgeladen (' . count($namen) . ' von '
            . \block_ai_chat\local\wissen::kursmaxdateien() . '):</div>'
            . $liste
            . '<a class="btn btn-outline-secondary btn-sm" href="' . $url->out() . '">Dateien verwalten</a>';
    }


    /** Auswahlliste „KI-Modell für diesen Kurs“ (nur, wenn die Administration mehr als eine Verbindung angelegt hat). */
    private static function modell_auswahl(\context $coursecontext): string {
        $liste = self::waehlbare_verbindungen();
        if (!$liste) {
            return '';
        }
        $gewaehlt = self::modell($coursecontext);
        $optionen = '<option value="0">Vorgabe der Administration</option>';
        foreach ($liste as $id => $name) {
            $optionen .= '<option value="' . $id . '"' . ($id === $gewaehlt ? ' selected' : '') . '>' . s($name) . '</option>';
        }
        return self::titel('🧠 KI-Modell für diesen Kurs')
            . '<select name="kursmodell" class="custom-select form-select" style="width: 100%;">' . $optionen . '</select>'
            . self::hinweis('Gilt für Chat, Übersetzen, Feedback und Textaufgaben. Welche Modelle es gibt, legt die Administration fest.');
    }

}
