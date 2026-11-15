<?php
// Lokale Erweiterung (14.11.2026): speichert das Anfragen-Limit für Lernende aus dem Formular im KI-Kontrollzentrum.
require_once(__DIR__ . '/../../config.php');

$blockcontextid = required_param('blockcontextid', PARAM_INT);
$wert = optional_param('kurslimit', 0, PARAM_INT);
require_sesskey();

$blockcontext = context::instance_by_id($blockcontextid, MUST_EXIST);
if (!$blockcontext instanceof context_block) {
    throw new moodle_exception('invalidcontext');
}
$coursecontext = $blockcontext->get_course_context();
require_login($coursecontext->instanceid);
require_capability('block/ai_control:control', $blockcontext);

$bi = $DB->get_record('block_instances', ['id' => $blockcontext->instanceid, 'blockname' => 'ai_control'], '*', MUST_EXIST);
$gespeichert = \block_ai_control\local\kurslimit::speichern($bi, $wert);
$bi = $DB->get_record('block_instances', ['id' => $bi->id], '*', MUST_EXIST);
$modell = \block_ai_control\local\kurslimit::modell_speichern($bi, optional_param('kursmodell', 0, PARAM_INT));
$text = ($gespeichert > 0 ? "Limit: höchstens $gespeichert Anfragen." : 'Limit: Vorgabe der Administration.')
    . ' Modell: ' . ($modell ? \block_ai_control\local\kurslimit::waehlbare_verbindungen()[$modell] : 'Vorgabe der Administration') . '.';
redirect(new moodle_url('/course/view.php', ['id' => $coursecontext->instanceid]), $text, null, \core\output\notification::NOTIFY_SUCCESS);
