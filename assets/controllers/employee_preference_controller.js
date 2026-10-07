import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        storageKey: { type: String, default: 'bakedesk:last-employee-id' },
    };

    connect() {
        this.rememberEmployee = (event) => {
            if (event.target.matches('input[type="radio"]')) {
                localStorage.setItem(this.storageKeyValue, event.target.value);
            }
        };

        this.element.addEventListener('change', this.rememberEmployee);
        const rememberedId = localStorage.getItem(this.storageKeyValue);
        if (rememberedId) {
            const input = this.element.querySelector(`input[type="radio"][value="${CSS.escape(rememberedId)}"]`);
            if (input) {
                input.checked = true;
            }
        }
    }

    disconnect() {
        this.element.removeEventListener('change', this.rememberEmployee);
    }
}
