import { Controller } from '@hotwired/stimulus';

const scripts = new Map();

/**
 * A GLS térkép-widget betöltője (`gls-dpm.js`), oldalanként egyszer.
 *
 * Klasszikus szkript, nem modul: a betöltő a `document.currentScript`-ből
 * számolja a saját modul-darabjai helyét. Ha az elem már definiált (pl. a
 * host maga tölti be), nem tesz be semmit.
 */
export function loadWidgetScript(url, elementName) {
    if (customElements.get(elementName)) {
        return Promise.resolve();
    }
    if (!scripts.has(url)) {
        scripts.set(url, new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = url;
            script.async = true;
            script.addEventListener('load', () => resolve());
            script.addEventListener('error', () => {
                scripts.delete(url);
                reject(new Error(`A GLS térkép nem tölthető be: ${url}`));
            });
            document.head.appendChild(script);
        }));
    }

    return scripts.get(url);
}

// A betöltő a modul-darabokat a saját `load` eseménye UTÁN tölti; ha azok
// elakadnak (CSP, hálózat), az elem sosem lesz definiált.
const DEFINE_TIMEOUT_MS = 15000;

/**
 * A GLS csomagpont- vagy csomagautomata-választója a pénztárban (#255).
 *
 * A közös `codeconjure/sylius-carrier-bundle` `carrier-shipment`
 * hatókörében ül, fajtánként egy példány (`kind`: `parcel-shop` vagy
 * `parcel-locker`, a widget `filter-type`-ja). A GLS `<gls-dpm-dialog>`
 * elemét nyitja meg; a választott pontot NEM írja a mezőbe, hanem a
 * `carrier:pickup-point-selected` eseménnyel adja át — a mezőt a közös
 * controller tölti.
 */
export default class extends Controller {
    static targets = ['dialog', 'display', 'pointName', 'pointAddress', 'button', 'cashNotice', 'error'];

    static values = { scriptUrl: String, kind: String, pointId: String };

    connect() {
        // A közös controller fajtaváltáskor üríti a mezőt — ezt a hatókör
        // `change` eseményein követjük, a közös controller kezelője UTÁN.
        this.carrierScope = this.element.closest('[data-controller~="carrier-shipment"]');
        this.onScopeChange = () => queueMicrotask(() => this.syncWithField());
        this.carrierScope?.addEventListener('change', this.onScopeChange);
    }

    disconnect() {
        this.carrierScope?.removeEventListener('change', this.onScopeChange);
    }

    async open() {
        if (!this.hasDialogTarget) {
            return;
        }
        const name = this.dialogTarget.localName;
        try {
            await loadWidgetScript(this.scriptUrlValue, name);
            await Promise.race([
                customElements.whenDefined(name),
                new Promise((_, reject) => setTimeout(() => reject(new Error('A GLS térkép nem töltődött be.')), DEFINE_TIMEOUT_MS)),
            ]);
        } catch (error) {
            if (this.hasErrorTarget) {
                this.errorTarget.hidden = false;
            }
            console.error(error);
            return;
        }
        if (this.hasErrorTarget) {
            this.errorTarget.hidden = true;
        }
        this.dialogTarget.showModal();
    }

    select(event) {
        const point = event.detail;
        if (!point || typeof point.id !== 'string' || point.id === '') {
            return;
        }
        if (this.kindValue && point.type && point.type !== this.kindValue) {
            return;
        }

        this.pointIdValue = point.id;
        this.show(point);
        this.element.dispatchEvent(new CustomEvent('carrier:pickup-point-selected', {
            bubbles: true,
            detail: { id: point.id, label: point.name || point.id },
        }));
        this.dialogTarget.close?.();
    }

    show(point) {
        const contact = point.contact ?? {};
        const address = [contact.postalCode, contact.city].filter(Boolean).join(' ');
        if (this.hasPointNameTarget) {
            this.pointNameTarget.textContent = point.name || point.id;
        }
        if (this.hasPointAddressTarget) {
            this.pointAddressTarget.textContent = [address, contact.address].filter(Boolean).join(', ');
        }
        if (this.hasDisplayTarget) {
            this.displayTarget.hidden = false;
        }
        if (this.hasCashNoticeTarget) {
            this.cashNoticeTarget.hidden = (point.features ?? []).includes('acceptsCash');
        }
        this.label('labelChange');
    }

    syncWithField() {
        const field = this.carrierScope?.querySelector('[data-carrier-shipment-target="pickupPointInput"]');
        if (!field || field.value === this.pointIdValue) {
            return;
        }
        this.pointIdValue = '';
        if (this.hasDisplayTarget) {
            this.displayTarget.hidden = true;
        }
        if (this.hasCashNoticeTarget) {
            this.cashNoticeTarget.hidden = true;
        }
        this.label('labelSelect');
    }

    label(key) {
        const text = this.hasButtonTarget ? this.buttonTarget.dataset[key] : undefined;
        if (text) {
            this.buttonTarget.textContent = text;
        }
    }
}
