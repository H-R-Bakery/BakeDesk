import { startStimulusApp } from '@symfony/stimulus-bundle';
import CustomerAutocompleteController from './controllers/customer_autocomplete_controller.js';
import UserPreferenceController from './controllers/user_preference_controller.js';
import OrderItemsController from './controllers/order_items_controller.js';

const app = startStimulusApp();
app.register('customer-autocomplete', CustomerAutocompleteController);
app.register('user-preference', UserPreferenceController);
app.register('order-items', OrderItemsController);
