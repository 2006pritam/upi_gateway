# FamPay / FamX automatic verification (by e-mail)

FamApp e-mails you "You received ₹X in your FamX account" for every incoming payment.
The gateway reads those e-mails and marks the matching order paid.

## Setup
1. Dashboard → UPI Accounts → Add → **FamPay / FamX (auto)**, enter your FamX UPI ID.
2. The *Email setup* page opens. Go to https://script.google.com (signed in as the Gmail that receives FamApp mail), create a project, paste the script shown, save.
3. Run `install` once and approve the permissions. A trigger then forwards new FamApp mail every minute.

## Security
- Requests are signed (HMAC-SHA256 over `timestamp.body`, 5 min window); wrong signatures get a uniform 401.
- Only mail from `no-reply@famapp.in` that Gmail itself marked `dkim=pass` and `dmarc=pass` for famapp.in is accepted.
- Each message and each UTR is processed once.

## Matching
- Exact amount, order created before the payment, status pending (or expired < 60 min ago).
- If the payer's note contains the order number it picks the order. A note naming another order is never guessed.
- One open order with that amount → matched. Several → left *ambiguous*; the customer can type their UTR on the checkout page and it is approved instantly.

## Caveats
FamPay is a prepaid/teen app; business use may breach its terms. Free Render instances sleep, so the first forward after idle is slow (the script retries).
