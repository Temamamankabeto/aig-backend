# End-to-End Backend Testing Checklist

## Order flow
- Public order creation
- Waiter order creation
- Waiter confirms order
- Kitchen/bar accept, delay, reject, ready
- Waiter serves order
- Bill issue and view

## Payment flow
- Direct cashier payment
- Waiter-submitted payment approval
- Partial then full payment
- Overpayment blocked
- Void bill blocked from payment

## Refund flow
- Refund request
- Refund approval / rejection
- Refund processing updates payment and bill

## Inventory flow
- PO creation, approval, receiving
- Manual adjustment and waste
- Recipe integrity report clean
- Auto deduction runs once on fully settled order

## Notifications and audit
- Waiter receives kitchen/bar ready alerts
- Cashier/finance receives payment submission alerts
- Notifications can be marked read
- Audit logs written for key actions
