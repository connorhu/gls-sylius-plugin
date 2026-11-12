import { Application } from '@hotwired/stimulus';
import { afterEach, beforeAll, describe, expect, test, vi } from 'vitest';
import GlsPickupPointController, { loadWidgetScript } from '../controllers/gls_pickup_point_controller.js';

// A GLS `<gls-dpm-dialog>` helyettese: ugyanaz a felület, amit a valódi
// elem ad (mérve a `gls-dpm-modern.js`-ből, 2026-11-12) — `showModal()`,
// `close()`, és a választáskor egy NEM buborékozó `change` CustomEvent, a
// pont objektumával a `detail`-ben.
class FakeDialog extends HTMLElement {
    opened = 0;
    closed = 0;
    showModal() { this.opened += 1; }
    close() { this.closed += 1; }
    choose(point) { this.dispatchEvent(new CustomEvent('change', { detail: point })); }
}

beforeAll(() => {
    customElements.define('gls-dpm-dialog', FakeDialog);
});

const SHOP = {
    id: '1027-CSOMAGPONT05',
    name: 'Tölgyfa ABC',
    type: 'parcel-shop',
    contact: { countryCode: 'HU', postalCode: '1027', city: 'BUDAPEST II. KER.', address: 'Tölgyfa utca 9' },
    features: ['acceptsCash', 'acceptsCard', 'pickup', 'delivery'],
};
const CARD_ONLY_SHOP = { ...SHOP, id: '2000-KARTYAS', features: ['acceptsCard', 'pickup'] };
const LOCKER = { ...SHOP, id: 'HU1012-PLOCKER001', name: 'GLS Automata', type: 'parcel-locker', features: ['acceptsCard'] };

// A DOM a plugin `pickup_point_widget.html.twig`-jének egy blokkja, a közös
// `carrier-shipment` hatókörben (annak rejtett mezőjével).
function render({ kind = 'parcel-shop', pointId = '' } = {}) {
    document.body.innerHTML = `
        <div id="scope" data-controller="carrier-shipment">
            <input type="radio" name="m" value="a">
            <div data-controller="gls-pickup-point"
                 data-gls-pickup-point-script-url-value="https://map.gls-hungary.com/widget/gls-dpm.js"
                 data-gls-pickup-point-kind-value="${kind}"
                 data-gls-pickup-point-point-id-value="${pointId}">
                <div data-gls-pickup-point-target="display" ${pointId ? '' : 'hidden'}>
                    <span data-gls-pickup-point-target="pointName">${pointId ? 'Régi pont' : ''}</span>
                    <span data-gls-pickup-point-target="pointAddress"></span>
                </div>
                <p data-gls-pickup-point-target="cashNotice" hidden>Csak kártya</p>
                <p data-gls-pickup-point-target="error" hidden>Nem tölthető be</p>
                <button type="button" data-gls-pickup-point-target="button"
                        data-label-select="Csomagpont kiválasztása" data-label-change="Másik csomagpont"
                        data-action="gls-pickup-point#open">Csomagpont kiválasztása</button>
                <gls-dpm-dialog data-gls-pickup-point-target="dialog"
                                data-action="change->gls-pickup-point#select"></gls-dpm-dialog>
            </div>
            <input type="hidden" data-carrier-shipment-target="pickupPointInput" value="${pointId}">
        </div>`;
}

let application;
let selections;

async function start(options) {
    render(options);
    selections = [];
    document.getElementById('scope').addEventListener('carrier:pickup-point-selected', (e) => selections.push(e.detail));
    application = Application.start();
    application.register('gls-pickup-point', GlsPickupPointController);
    await Promise.resolve();
}

const dialog = () => document.querySelector('gls-dpm-dialog');
const target = (name) => document.querySelector(`[data-gls-pickup-point-target="${name}"]`);
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

afterEach(async () => {
    document.body.innerHTML = '';
    document.head.querySelectorAll('script').forEach((script) => script.remove());
    await flush();
    application?.stop();
});

describe('a GLS választó', () => {
    test('a gomb megnyitja a GLS térképét', async () => {
        await start();
        target('button').click();
        await flush();

        expect(dialog().opened).toBe(1);
    });

    test('a választott pontot a közös esemény adja át — azonosító és név', async () => {
        await start();
        dialog().choose(SHOP);

        expect(selections).toEqual([{ id: '1027-CSOMAGPONT05', label: 'Tölgyfa ABC' }]);
        expect(dialog().closed).toBe(1);
    });

    test('a választás után a pont neve, címe és a gomb „másik" felirata látszik', async () => {
        await start();
        dialog().choose(SHOP);

        expect(target('display').hidden).toBe(false);
        expect(target('pointName').textContent).toBe('Tölgyfa ABC');
        expect(target('pointAddress').textContent).toBe('1027 BUDAPEST II. KER., Tölgyfa utca 9');
        expect(target('button').textContent).toBe('Másik csomagpont');
    });

    test('készpénzt nem fogadó csomagpontnál megjelenik az utánvét-figyelmeztetés', async () => {
        await start();
        dialog().choose(CARD_ONLY_SHOP);
        expect(target('cashNotice').hidden).toBe(false);

        dialog().choose(SHOP);
        expect(target('cashNotice').hidden).toBe(true);
    });

    // A widget `filter-type`-ja a GLS kódjában hat; ha mégis másik fajtájú
    // pont jönne, azt nem adjuk tovább (a szerver úgyis elutasítaná).
    test('másik fajtájú pontot nem ad át', async () => {
        await start({ kind: 'parcel-shop' });
        dialog().choose(LOCKER);

        expect(selections).toEqual([]);
    });

    test('azonosító nélküli választás nem ír semmit', async () => {
        await start();
        dialog().choose({ name: 'nincs id' });
        dialog().choose(null);

        expect(selections).toEqual([]);
    });

    // A közös controller fajtaváltáskor törli a mezőt; a választó kijelzése
    // nem mutathat tovább egy pontot, ami már nincs a mezőben.
    test('ha a közös mező kiürül, a kijelzés is visszaáll', async () => {
        await start({ pointId: '1027-CSOMAGPONT05' });
        expect(target('display').hidden).toBe(false);

        document.querySelector('[data-carrier-shipment-target="pickupPointInput"]').value = '';
        document.querySelector('input[type="radio"]').dispatchEvent(new Event('change', { bubbles: true }));
        await flush();

        expect(target('display').hidden).toBe(true);
        expect(target('button').textContent).toBe('Csomagpont kiválasztása');
    });

    test('amíg a mező a választott pontot tartja, a kijelzés marad', async () => {
        await start({ pointId: '1027-CSOMAGPONT05' });

        document.querySelector('input[type="radio"]').dispatchEvent(new Event('change', { bubbles: true }));
        await flush();

        expect(target('display').hidden).toBe(false);
    });
});

describe('a widget betöltése', () => {
    test('a betöltő szkriptet egyszer teszi be, és a betöltésre vár', async () => {
        const first = loadWidgetScript('https://map.gls-hungary.com/widget/gls-dpm.js', 'gls-nincs-definialva');
        const second = loadWidgetScript('https://map.gls-hungary.com/widget/gls-dpm.js', 'gls-nincs-definialva');
        const scripts = document.head.querySelectorAll('script[src="https://map.gls-hungary.com/widget/gls-dpm.js"]');

        expect(scripts.length).toBe(1);
        expect(first).toBe(second);
        scripts[0].dispatchEvent(new Event('load'));
        await expect(first).resolves.toBeUndefined();
    });

    test('már definiált elemnél nem tölt semmit', async () => {
        await loadWidgetScript('https://map.gls-hungary.com/widget/masik.js', 'gls-dpm-dialog');

        expect(document.head.querySelectorAll('script').length).toBe(0);
    });

    test('ha a térkép nem tölthető be, a választó hibát mutat', async () => {
        vi.spyOn(customElements, 'whenDefined').mockRejectedValue(new Error('CSP'));
        await start();
        target('button').click();
        await flush();

        expect(target('error').hidden).toBe(false);
        vi.restoreAllMocks();
    });
});
