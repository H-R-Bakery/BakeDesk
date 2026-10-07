import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['container'];
    static values = {
        index: Number,
        prototype: String,
    };

    add() {
        const item = this.prototypeValue.replace(/__name__/g, this.indexValue);
        this.containerTarget.insertAdjacentHTML('beforeend', item);
        this.indexValue += 1;
    }

    remove(event) {
        event.currentTarget.closest('[data-order-items-row]').remove();
    }
}
