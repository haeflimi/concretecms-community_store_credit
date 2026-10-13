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
- The customer's account page is the one of community_store_open_orders (`/account/orders`, required): while this
  package is installed it is called "Credit & Orders" and shows the credit, the net of credit and open orders, and
  the credit history next to the open orders and their "Pay now" buttons. `CommunityStoreCredit\Service\AccountOverview::summary()`
  gives credit, debt and net in one call (theme navigation, event signup gate).

API: `CommunityStoreCredit\Service\CreditService` (`getBalance`, `getHistory`, `add`, `charge`, `findByReference`).
The table is kept when the package is uninstalled.

## Upgrading

* **1.3.0** the account page with the open orders and their online payment moved to community_store_open_orders
  (`/account/orders`), so customers can pay open orders without store credit. Install that package first; the upgrade
  deletes this package's `/account/credit` page (old links redirect) and names the new page "Credit & Orders".
  `AccountOverview` only has `summary()` now; `PayrexxCheckout` is `CommunityStoreOpenOrders\PayrexxCheckout`.
