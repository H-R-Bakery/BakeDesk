import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2';

export default class extends Controller {
    static values = {
        message: String,
        title: String,
        confirmButtonText: String,
        cancelButtonText: String,
    };

    async submit(event) {
        if (!this.hasMessageValue) {
            return;
        }

        event.preventDefault();

        const result = await Swal.fire({
            title: this.hasTitleValue ? this.titleValue : 'Complete unpaid order?',
            text: this.messageValue,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: this.hasConfirmButtonTextValue ? this.confirmButtonTextValue : 'Mark completed',
            cancelButtonText: this.hasCancelButtonTextValue ? this.cancelButtonTextValue : 'Go back',
            focusCancel: true,
        });

        if (result.isConfirmed) {
            HTMLFormElement.prototype.submit.call(this.element);
        }
    }
}
