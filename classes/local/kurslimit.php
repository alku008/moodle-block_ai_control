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
        $zeit = format_time(self::zeitraum());
        $kasten = 'margin-top: 12px; padding: 10px 12px; border: 1px solid #d5e2f2; border-radius: 8px; background: #f8fafd; font-size: 0.9rem;';
        if ($steuern) {
            $url = new \moodle_url('/blocks/ai_control/kurslimit.php');
            return '<form method="post" action="' . $url->out() . '" style="' . $kasten . '">'
                . self::modell_auswahl($coursecontext)
                . '<div style="font-weight: bold; margin-bottom: 4px;">📊 Anfragen für Lernende begrenzen</div>'
                . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
                . '<input type="hidden" name="blockcontextid" value="' . $blockcontext->id . '">'
                . '<label style="display: block; margin: 0 0 6px 0;">Höchstens '
                . '<input type="number" name="kurslimit" min="0" max="' . $max . '" value="' . ($wert ?: '') . '" placeholder="' . $max . '" '
                . 'style="width: 5em; padding: 2px 6px; border: 1px solid #8f959e; border-radius: 4px;"> Anfragen pro ' . $zeit
                . ' und Funktion in diesem Kurs</label>'
                . '<div style="color: #555; font-size: 0.85rem; margin-bottom: 6px;">Vorgabe der Administration: höchstens ' . $max
                . ' (leer lassen = ' . $max . ').</div>'
                . '<button type="submit" class="btn btn-secondary btn-sm">Einstellungen speichern</button></form>';
        }
        if ($wert <= 0) {
            return '';
        }
        $limit = min($wert, $max);
        $rest = max(0, $limit - self::verbraucht((int)$USER->id, $coursecontext, 'chat'));
        return '<div style="' . $kasten . '">💬 Chat in diesem Kurs: noch <strong>' . $rest . '</strong> von ' . $limit
            . ' Anfragen pro ' . $zeit . '.</div>';
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
        return '<div style="font-weight: bold; margin-bottom: 4px;">🧠 KI-Modell für diesen Kurs</div>'
            . '<select name="kursmodell" class="custom-select form-select" style="width: 100%; margin-bottom: 4px;">' . $optionen . '</select>'
            . '<div style="color: #555; font-size: 0.85rem; margin-bottom: 10px;">Gilt für Chat, Übersetzen, Feedback und Textaufgaben in diesem Kurs. '
            . 'Die Modelle stellt die Administration bereit (AIS.chat).</div>';
    }
}
