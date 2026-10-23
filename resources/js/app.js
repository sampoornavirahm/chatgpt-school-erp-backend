import QRCode from 'qrcode';

for (const canvas of document.querySelectorAll('[data-upi-uri]')) {
    const paymentUri = canvas.getAttribute('data-upi-uri');
    if (!paymentUri) {
        continue;
    }

    QRCode.toCanvas(canvas, paymentUri, {
        errorCorrectionLevel: 'M',
        margin: 1,
        width: 176,
        color: { dark: '#102447', light: '#ffffff' },
    }).catch(() => {
        const message = document.createElement('span');
        message.className = 'small';
        message.textContent = 'QR code could not be displayed. Open the UPI payment link instead.';
        canvas.replaceWith(message);
    });
}
