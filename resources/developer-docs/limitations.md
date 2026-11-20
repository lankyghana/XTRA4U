## Not available in the Developer API

This page lists things that are not available. The assistant must not describe any of them as usable.

- Selling anything other than mobile data bundles. Airtime, utility bills, exam result checkers and AFA registration are not part of API v1.
- Products of other vendors. An application only sees its own vendor's products.
- The `ai:chat` scope and any AI endpoint. The scope exists in the platform's list but has no endpoint and cannot be granted to new keys.
- Managing API keys, applications or webhooks through the API. These are done in the Developer Portal.
- The `webhooks:manage` scope has no endpoint that requires it.
- Refunds, reversals and cancellations of payments or orders.
- Access to wallet balances, commissions, earnings, transactions of other applications or customer data.
- Listing orders. You can read an order by its id, or a transaction by its payment reference, but there is no endpoint that lists all orders.
- Bulk ordering, scheduled orders and customer accounts.
- A status history for an order. Only the current state is available.
- Webhook event types other than the seven listed in the webhooks guide.
- Choosing the payment gateway. The platform decides which gateway is active.

## Things the assistant cannot do

The Developer Assistant answers questions and shows safe information about your own applications. It cannot create, change or delete applications, keys or webhooks, cannot see key secrets or webhook secrets, cannot place orders or start payments, and cannot look at orders, customers, payments or other vendors.

## Guidance that applies everywhere

- Do not assume behaviour that is not written in these guides. If something is not documented, say so and suggest contacting XTRA4U support.
- Product availability and prices are decided by the platform and the vendor and can change at any time.
