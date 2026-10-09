import { Controller } from '@hotwired/stimulus';

const statusLabels = {
    queued: 'Queued',
    processing: 'Rendering',
    rendered: 'Rendered',
    submitted: 'Sent to printer',
    completed: 'Completed',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

const orderStatusLabels = {
    open: 'Open',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export default class MercureStatusController extends Controller {
    static values = {
        subscriptionUrl: String,
        orderId: Number,
    };

    connect() {
        if (!this.hasSubscriptionUrlValue || typeof EventSource === 'undefined') {
            return;
        }

        this.eventSource = new EventSource(this.subscriptionUrlValue);
        this.eventSource.addEventListener('message', (event) => this.handleMessage(event));
    }

    disconnect() {
        this.eventSource?.close();
    }

    handleMessage(event) {
        let payload;
        try {
            payload = JSON.parse(event.data);
        } catch {
            return;
        }

        if (!payload || typeof payload !== 'object') {
            return;
        }

        if (payload.type === 'order.updated') {
            this.updateOrder(payload);
        } else if (payload.type === 'print-job.updated') {
            this.updatePrintJob(payload);
        }
    }

    updateOrder(payload) {
        if (!this.isRelevantOrder(payload.orderId)) {
            return;
        }

        if (typeof payload.paid === 'boolean') {
            this.findOrderElements(payload.orderId, 'paid-status').forEach((element) => {
                element.textContent = payload.paid ? 'Paid' : 'Not Paid';
                this.setBadgeClass(element, payload.paid ? 'success' : 'secondary');
            });
        }

        if (typeof payload.status === 'string' && orderStatusLabels[payload.status]) {
            this.findOrderElements(payload.orderId, 'order-status').forEach((element) => {
                element.textContent = orderStatusLabels[payload.status];
                this.setBadgeClass(element, payload.status === 'open' ? 'primary' : (payload.status === 'completed' ? 'success' : 'danger'));
            });
        }
    }

    updatePrintJob(payload) {
        if (!this.isRelevantOrder(payload.orderId) || payload.documentType !== 'label') {
            return;
        }
        if (!Number.isInteger(payload.printJobId) || !statusLabels[payload.status]) {
            return;
        }
        if (!Number.isInteger(payload.orderItemId) || !Number.isInteger(payload.packageNumber)) {
            return;
        }

        const packageElement = Array.from(this.element.querySelectorAll('[data-role="package-print-status"]'))
            .find((element) => element.dataset.orderItemId === String(payload.orderItemId)
                && element.dataset.packageNumber === String(payload.packageNumber));
        if (!packageElement) {
            return;
        }

        const currentJobId = Number(packageElement.dataset.printJobId || 0);
        const incomingJobId = payload.printJobId;
        if (incomingJobId < currentJobId) {
            return;
        }

        packageElement.dataset.printJobId = String(incomingJobId);
        const statusElement = packageElement.querySelector('[data-role="print-status"]');
        if (!statusElement) {
            return;
        }
        statusElement.textContent = statusLabels[payload.status];
        this.setBadgeClass(statusElement, payload.status === 'failed' ? 'danger' : (payload.status === 'completed' ? 'success' : 'secondary'));

        const errorElement = packageElement.querySelector('[data-role="print-error"]');
        if (!errorElement) {
            return;
        }
        const errorMessage = payload.status === 'failed' && typeof payload.errorMessage === 'string' ? payload.errorMessage : '';
        errorElement.textContent = errorMessage;
        errorElement.hidden = '' === errorMessage;
    }

    isRelevantOrder(orderId) {
        if (!Number.isInteger(orderId)) {
            return false;
        }

        return !this.hasOrderIdValue || this.orderIdValue === orderId;
    }

    findOrderElements(orderId, role) {
        return Array.from(this.element.querySelectorAll(`[data-role="${role}"]`))
            .filter((element) => element.dataset.orderId === String(orderId));
    }

    setBadgeClass(element, color) {
        element.classList.remove('text-bg-primary', 'text-bg-success', 'text-bg-secondary', 'text-bg-danger');
        element.classList.add(`text-bg-${color}`);
    }
}
