// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Local extension (AIS.chat Hamburg): settings below the AI control center.
 *
 * - "Einstellungen speichern" saves in the background: the page stays where it is and a short note next to the button
 *   confirms it (without JavaScript the form is posted normally).
 * - Outside the editing mode the main switch saves immediately (the other parts are hidden by styles.css).
 *
 * @module     block_ai_control/kurslimit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

let eingerichtet = false;

/**
 * Show a short status text next to the save button.
 *
 * @param {HTMLElement} form the settings form
 * @param {string} text the text
 * @param {boolean} ok success or error
 */
const zeigeStatus = (form, text, ok) => {
    const status = form.querySelector('.kurslimit-status');
    if (!status) {
        return;
    }
    status.textContent = (ok ? '✅ ' : '⚠️ ') + text;
    status.style.color = ok ? '#2e7d32' : '#b3261e';
    clearTimeout(status.kurslimitTimer);
    status.kurslimitTimer = setTimeout(() => {
        status.textContent = '';
    }, 8000);
};

/**
 * Save the form in the background.
 *
 * @param {HTMLFormElement} form the settings form
 */
const speichern = async(form) => {
    const knopf = form.querySelector('button[type="submit"]');
    const daten = new FormData(form);
    daten.append('ajax', '1');
    if (knopf) {
        knopf.disabled = true;
    }
    try {
        const antwort = await fetch(form.action, {method: 'POST', body: daten, credentials: 'same-origin'});
        const ergebnis = await antwort.json();
        if (!ergebnis.ok) {
            throw new Error('not ok');
        }
        zeigeStatus(form, 'Gespeichert. ' + ergebnis.meldung, true);
        const zusammenfassung = form.closest('.kurslimit-mehr')?.querySelector('.kurslimit-zusammenfassung');
        if (zusammenfassung && ergebnis.zusammenfassung) {
            zusammenfassung.innerHTML = ergebnis.zusammenfassung;
        }
    } catch (e) {
        zeigeStatus(form, 'Nicht gespeichert. Bitte die Seite neu laden und noch einmal versuchen.', false);
    } finally {
        if (knopf) {
            knopf.disabled = false;
        }
    }
};

/**
 * Set up the handlers (once per page).
 */
export const init = () => {
    if (eingerichtet) {
        return;
    }
    eingerichtet = true;
    document.addEventListener('submit', (e) => {
        const form = e.target.closest('form.kurslimit-form');
        if (!form || !window.fetch) {
            return;
        }
        e.preventDefault();
        speichern(form);
    });
    // Main switch outside the editing mode: click the hidden save button of the control center (capture phase, because the
    // switch fires a change event that does not bubble).
    document.addEventListener('change', (e) => {
        if (document.body.classList.contains('editing')) {
            return;
        }
        const schalter = e.target.closest('.block_ai_control [data-toggle-identifier="aiconfig_enabled"]');
        if (!schalter) {
            return;
        }
        const knopf = schalter.closest('.block_ai_control').querySelector('[data-aicontrol="submitbutton"]');
        if (knopf) {
            setTimeout(() => knopf.click(), 50);
        }
    }, true);
};
