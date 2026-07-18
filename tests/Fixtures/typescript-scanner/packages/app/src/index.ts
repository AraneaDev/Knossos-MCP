export { PaymentService } from './service.js';

export async function loadService() {
  return import('./service.js');
}

