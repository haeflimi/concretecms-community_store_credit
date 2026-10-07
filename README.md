# community_store_credit

Store credit for [Community Store](https://github.com/concretecms-community-store/community_store).

- `csCreditEntries`: append-only ledger per customer (positive = credit added, negative = spent). Every entry
  carries a `source` + `externalRef` pair under a unique index, so a checkout, refund, migration or manual
  booking is booked at most once. Spending locks the customer's entries and refuses to go below zero.
- Payment method **Store Credit** (`community_store_credit`): offered at checkout when the customer is signed
  in and the credit covers the whole order. The credit is charged when the order is submitted (per-checkout
  nonce, so a double submit charges once); the order id is attached on payment completion. Cancelling such an
  order refunds the credit.
- Dashboard › Store › Store Credit: balances, history per customer, manual adjustments (token protected).
- Dashboard › Store › Customers (between Orders and Products): one row per customer with debt (sum of unpaid, not cancelled or
  refunded orders), store credit and the net, with search, filters, sorting, totals, CSV export and a detail
  page with the customer's complete order history (unpaid, paid, refunded, cancelled, payment pending) and
  credit entries.
- `/account/credit` ("Credit & Orders"): the customer's balance and history, their standing event tabs with
  contents (when community_event_manager is installed), other open orders with contents, and the paid orders.
  Open orders and open tabs get a "Pay now" button when community_store_payrexx is installed and configured
  (`community_store_payrexx.instanceName` / `.secret` / `.currency`): `CommunityStoreCredit\Service\PayrexxCheckout`
  creates a Payrexx gateway per order (reference `csorder_<oID>_…` stored as the order's transaction reference,
  so the add-on's webhook completes the order) and, when the customer returns, verifies the gateway with the
  Payrexx API before marking the order paid. Paying a tab online settles it (the event manager marks the tab
  "online" on the store's payment event).

API: `CommunityStoreCredit\Service\CreditService` (`getBalance`, `getHistory`, `add`, `charge`, `findByReference`).
The table is kept when the package is uninstalled.
