import { startStimulusApp } from '@symfony/stimulus-bundle';
import CustomerAutocompleteController from './controllers/customer_autocomplete_controller.js';
import UserPreferenceController from './controllers/user_preference_controller.js';
import OrderItemsController from './controllers/order_items_controller.js';
import MercureStatusController from './controllers/mercure_status_controller.js';
import ConfirmActionController from './controllers/confirm_action_controller.js';

const app = startStimulusApp();
app.register('customer-autocomplete', CustomerAutocompleteController);
app.register('user-preference', UserPreferenceController);
app.register('order-items', OrderItemsController);
app.register('mercure-status', MercureStatusController);
app.register('confirm-action', ConfirmActionController);
