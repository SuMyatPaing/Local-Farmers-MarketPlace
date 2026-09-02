# Order Workflow

1. Customer adds available Products to Cart.
2. Checkout validates/locks stock, creates one `purchase_process` master order and one `vendor_orders` record for each Vendor, then reserves stock.
3. Customer chooses **Market Pickup** or **Delivery**. Delivery requires recipient name, phone and address.
4. Each Vendor confirms or rejects only their own order portion. Rejection restores that Vendor's reserved stock.
5. When all Vendor decisions are complete and at least one Vendor confirmed, Customer can pay the confirmed amount using **KBZPay** or **Wave Money**.
6. Customer submits transaction ID and a validated image payment slip.
7. Admin opens the protected slip and verifies/rejects payment.
8. Confirmed Vendors can mark their portion Completed only after payment status becomes `paid`.
9. Customer can submit/update one Product Review only when that Product belongs to a **paid + completed** order.
