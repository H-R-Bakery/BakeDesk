import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2';

export default class extends Controller {
    static values = {
        message: String,
    };

    async submit(event) {
        if (!this.hasMessageValue) {
            return;
        }

        event.preventDefault();

        const result = await Swal.fire({
            title: 'Complete unpaid order?',
            text: this.messageValue,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Mark completed',
            cancelButtonText: 'Go back',
            focusCancel: true,
        });

        if (result.isConfirmed) {
            HTMLFormElement.prototype.submit.call(this.element);
        }
    }
}
