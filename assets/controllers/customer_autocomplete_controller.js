import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['name', 'phone', 'customer', 'results'];
    static values = { url: String };

    connect() {
        this.abortController = null;
        this.highlightedIndex = -1;
        this.resultButtons = [];
        this.closeWhenClickedOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.hideResults();
            }
        };
        document.addEventListener('click', this.closeWhenClickedOutside);
    }

    disconnect() {
        this.abortController?.abort();
        document.removeEventListener('click', this.closeWhenClickedOutside);
    }

    search(event) {
        const query = event.currentTarget.value.trim();
        this.highlightedIndex = -1;
        this.customerTarget.value = '';

        if (query.length < 2) {
            this.hideResults();
            return;
        }

        this.abortController?.abort();
        this.abortController = new AbortController();

        fetch(`${this.urlValue}?q=${encodeURIComponent(query)}`, {
            headers: { Accept: 'application/json' },
            signal: this.abortController.signal,
        })
            .then((response) => response.ok ? response.json() : [])
            .then((customers) => this.renderResults(customers))
            .catch((error) => {
                if (error.name !== 'AbortError') {
                    this.hideResults();
                }
            });
    }

    navigate(event) {
        const buttons = this.resultButtons;
        if (!buttons.length) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            this.highlightedIndex = (this.highlightedIndex + direction + buttons.length) % buttons.length;
            buttons.forEach((button, index) => button.setAttribute('aria-selected', String(index === this.highlightedIndex)));
            buttons[this.highlightedIndex].focus();
        }

        if (event.key === 'Escape') {
            this.hideResults();
        }
    }

    choose(event) {
        const button = event.currentTarget;
        this.nameTarget.value = button.dataset.customerName;
        this.phoneTarget.value = button.dataset.customerPhone;
        this.customerTarget.value = button.dataset.customerId;
        this.hideResults();
        this.nameTarget.focus();
    }

    renderResults(customers) {
        this.resultsTarget.replaceChildren();
        this.resultButtons = customers.map((customer) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action text-start';
            button.setAttribute('role', 'option');
            button.dataset.customerName = customer.name;
            button.dataset.customerPhone = customer.phone;
            button.dataset.customerId = customer.id;
            button.innerHTML = `<span class="d-block fw-semibold"></span><span class="small text-body-secondary"></span>`;
            button.children[0].textContent = customer.name;
            button.children[1].textContent = customer.phone;
            button.addEventListener('click', (event) => this.choose(event));
            this.resultsTarget.append(button);
            return button;
        });

        this.resultsTarget.classList.toggle('d-none', this.resultButtons.length === 0);
    }

    hideResults() {
        if (this.hasResultsTarget) {
            this.resultsTarget.classList.add('d-none');
            this.resultsTarget.replaceChildren();
        }
        this.resultButtons = [];
        this.highlightedIndex = -1;
    }
}
